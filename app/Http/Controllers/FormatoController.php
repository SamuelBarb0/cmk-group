<?php

namespace App\Http\Controllers;

use App\Models\FormFormat;
use App\Models\FormRecord;
use App\Services\FormRecordExporter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Motor genérico de formatos (Tier 4): inspecciones, actas y listas de chequeo.
 *
 * Un formato (FormFormat) define secciones + campos en un esquema JSON; los
 * consultores/inspectores crean registros (FormRecord) diligenciados por
 * empresa cliente. Un solo módulo cubre la "cola larga" de formatos del SGI.
 *
 * Permisos: ver -> inspections.view | diligenciar -> inspections.perform
 */
class FormatoController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('formatos/index', [
                'needsClient' => true,
                'formats' => [],
                'records' => [],
            ]);
        }

        return Inertia::render('formatos/index', [
            'needsClient' => false,
            'formats' => FormFormat::where('activo', true)->orderBy('orden')->orderBy('id')
                ->get(['id', 'codigo', 'nombre', 'categoria', 'grupo', 'descripcion', 'schema']),
            'records' => FormRecord::latest()->get([
                'id', 'form_format_id', 'codigo', 'consecutivo', 'titulo', 'categoria', 'grupo',
                'estado', 'fecha', 'responsable', 'generado_por', 'updated_at', 'reemplaza_id', 'motivo_anulacion',
            ]),
        ]);
    }

    /** Devuelve el registro completo (schema + data) para diligenciar/editar. */
    public function show(FormRecord $formato): Response
    {
        return Inertia::render('formatos/index', [
            'needsClient' => false,
            'formats' => FormFormat::where('activo', true)->orderBy('orden')->orderBy('id')
                ->get(['id', 'codigo', 'nombre', 'categoria', 'grupo', 'descripcion', 'schema']),
            'records' => FormRecord::latest()->get([
                'id', 'form_format_id', 'codigo', 'consecutivo', 'titulo', 'categoria', 'grupo',
                'estado', 'fecha', 'responsable', 'generado_por', 'updated_at', 'reemplaza_id', 'motivo_anulacion',
            ]),
            'open' => array_merge($formato->only([
                'id', 'form_format_id', 'codigo', 'consecutivo', 'titulo', 'categoria', 'grupo', 'schema', 'data', 'estado', 'fecha', 'responsable',
                'completado_at', 'completado_por', 'anulado_at', 'anulado_por', 'motivo_anulacion',
            ]), [
                // only() devuelve el Carbon crudo, que viaja como
                // «2026-09-23T05:00:00.000000Z»: el campo de fecha no lo pinta
                // y, al guardar, MySQL lo rechaza. El cast date:Y-m-d solo
                // aplica en toArray(), así que se formatea aquí.
                'fecha' => $formato->fecha?->toDateString(),
                'reemplaza' => $formato->reemplaza?->only(['id', 'consecutivo']),
            ]),
        ]);
    }

    /** Crea un registro nuevo a partir de un formato (copia el esquema como snapshot). */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de diligenciar formatos.']);
        }

        $data = $request->validate([
            'form_format_id' => ['required', 'integer', 'exists:form_formats,id'],
        ]);

        $format = FormFormat::findOrFail($data['form_format_id']);

        $record = FormRecord::create([
            'form_format_id' => $format->id,
            'codigo' => $format->codigo,
            'titulo' => $format->nombre,
            'categoria' => $format->categoria,
            'grupo' => $format->grupo,
            'schema' => $format->schema,      // snapshot
            'data' => [],
            'estado' => 'borrador',
            'fecha' => now()->toDateString(),
            'generado_por' => $request->user()?->name,
        ]);

        return redirect()->route('formatos.show', $record)->with('success', "Registro de «{$format->nombre}» creado.");
    }

    /** Guarda los valores diligenciados del registro. */
    public function update(Request $request, FormRecord $formato): RedirectResponse
    {
        // Un registro completado es evidencia: no se edita, se anula y se
        // reemplaza. Antes se podía devolver a borrador y cambiar sin rastro.
        if ($formato->estado !== 'borrador') {
            throw ValidationException::withMessages([
                'estado' => "El registro {$formato->consecutivo} está {$formato->estado} y no se puede modificar. Anúlalo y crea uno nuevo.",
            ]);
        }

        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'fecha' => ['nullable', 'date'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
            'estado' => ['required', Rule::in(['borrador', 'completado'])],
        ]);

        $completar = $data['estado'] === 'completado';
        unset($data['estado']);
        // Cualquier formato de fecha válido se guarda como Y-m-d: MySQL
        // rechaza el ISO con hora que SQLite (los tests) sí acepta.
        $data['fecha'] = isset($data['fecha']) ? Carbon::parse($data['fecha'])->toDateString() : null;
        $formato->update($data);

        if ($completar) {
            $formato->completar((string) auth()->user()?->name);

            return back()->with('success', "Registro {$formato->consecutivo} completado. Desde ahora solo se puede anular.");
        }

        return back()->with('success', 'Registro guardado.');
    }

    public function destroy(FormRecord $formato): RedirectResponse
    {
        if ($formato->estado !== 'borrador') {
            return back()->withErrors(['estado' => "El registro {$formato->consecutivo} está {$formato->estado}: no se elimina, se anula."]);
        }

        $formato->delete();

        return back()->with('success', 'Registro eliminado.');
    }

    /**
     * Anula un registro completado. Con `reemplazar`, crea al tiempo un
     * borrador nuevo con los mismos valores para corregir lo que estaba mal:
     * el anulado queda como estaba, con el motivo, y el nuevo apunta a él.
     */
    public function anular(Request $request, FormRecord $formato): RedirectResponse
    {
        if ($formato->estado !== 'completado') {
            throw ValidationException::withMessages(['estado' => 'Solo se anula un registro completado. Un borrador se edita o se elimina.']);
        }

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:2000'],
            'reemplazar' => ['boolean'],
        ], ['motivo.required' => 'Indica por qué se anula el registro.']);

        $por = (string) auth()->user()?->name;

        $nuevo = DB::transaction(function () use ($formato, $datos, $request, $por) {
            $formato->anular($datos['motivo'], $por);

            if (! $request->boolean('reemplazar')) {
                return null;
            }

            return FormRecord::create([
                'form_format_id' => $formato->form_format_id,
                'codigo' => $formato->codigo,
                'titulo' => $formato->titulo,
                'categoria' => $formato->categoria,
                'grupo' => $formato->grupo,
                // El reemplazo conserva el esquema del original, no el del
                // catálogo actual: corrige ese registro, no lo rehace.
                'schema' => $formato->schema,
                'data' => $formato->data,
                'estado' => 'borrador',
                'fecha' => $formato->fecha,
                'responsable' => $formato->responsable,
                'generado_por' => $por,
                'reemplaza_id' => $formato->id,
            ]);
        });

        if ($nuevo) {
            return redirect()->route('formatos.show', $nuevo)
                ->with('success', "Registro {$formato->consecutivo} anulado. Corrige el borrador que lo reemplaza y complétalo.");
        }

        return redirect()->route('formatos.index')->with('success', "Registro {$formato->consecutivo} anulado.");
    }

    /** Descarga el registro como .docx con membrete de CMK. */
    public function export(FormRecord $formato, FormRecordExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->export($formato);

        return response()->download($path, basename($path))->deleteFileAfterSend();
    }
}
