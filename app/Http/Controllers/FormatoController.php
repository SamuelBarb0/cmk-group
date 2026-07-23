<?php

namespace App\Http\Controllers;

use App\Models\FormFormat;
use App\Models\FormRecord;
use App\Services\FormRecordExporter;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                'id', 'form_format_id', 'codigo', 'titulo', 'categoria', 'grupo',
                'estado', 'fecha', 'responsable', 'generado_por', 'updated_at',
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
                'id', 'form_format_id', 'codigo', 'titulo', 'categoria', 'grupo',
                'estado', 'fecha', 'responsable', 'generado_por', 'updated_at',
            ]),
            'open' => $formato->only(['id', 'form_format_id', 'codigo', 'titulo', 'categoria', 'grupo', 'schema', 'data', 'estado', 'fecha', 'responsable']),
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
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'fecha' => ['nullable', 'date'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
            'estado' => ['required', Rule::in(['borrador', 'completado'])],
        ]);

        $formato->update($data);

        return back()->with('success', 'Registro guardado.');
    }

    public function destroy(FormRecord $formato): RedirectResponse
    {
        $formato->delete();

        return back()->with('success', 'Registro eliminado.');
    }

    /** Descarga el registro como .docx con membrete de CMK. */
    public function export(FormRecord $formato, FormRecordExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->export($formato);

        return response()->download($path, basename($path))->deleteFileAfterSend();
    }
}
