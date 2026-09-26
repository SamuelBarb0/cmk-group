<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\ControlledDocument;
use App\Models\CustomerRequest;
use App\Models\NonconformingOutput;
use App\Models\Process;
use App\Models\SatisfactionSurvey;
use App\Services\Calidad\DocumentosCalidad;
use App\Services\ControlDocumental\CicloDocumental;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M17 — Calidad (ISO 9001) de la empresa activa: PQRS (8.2.1), salidas no
 * conformes (8.7) y satisfacción del cliente (9.1.2).
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class CalidadController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentosCalidad $documentos,
    ) {}

    public function index(): Response
    {
        $catalogos = [
            'tiposPqrs' => CustomerRequest::TIPOS,
            'canales' => CustomerRequest::CANALES,
            'estadosPqrs' => CustomerRequest::ESTADOS,
            'deteccion' => NonconformingOutput::DETECCION,
            'tratamientos' => NonconformingOutput::TRATAMIENTOS,
            'verificables' => NonconformingOutput::VERIFICABLES,
            'criterios' => SatisfactionSurvey::CRITERIOS,
            'meta' => SatisfactionSurvey::META,
            'plazoDias' => CustomerRequest::PLAZO_DIAS_HABILES,
        ];
        if (! $this->context->has()) {
            return Inertia::render('calidad/index', $catalogos + [
                'needsClient' => true, 'pqrs' => [], 'salidas' => [], 'encuestas' => [], 'resumen' => null, 'procesos' => [], 'documentos' => [],
            ]);
        }

        $pqrs = CustomerRequest::query()->with(['process:id,sigla', 'acpmAction:id,codigo,estado'])->orderByDesc('fecha')->orderByDesc('id')->get();
        $salidas = NonconformingOutput::query()->with(['process:id,sigla', 'acpmAction:id,codigo,estado'])->orderByDesc('fecha')->orderByDesc('id')->get();
        $encuestas = SatisfactionSurvey::query()->orderByDesc('fecha')->orderByDesc('id')->get();
        $anio = $encuestas->where('fecha', '>=', Carbon::today()->subYear());
        $respondidas = $pqrs->whereNotNull('fecha_respuesta');

        return Inertia::render('calidad/index', $catalogos + [
            'needsClient' => false,
            'pqrs' => $pqrs->values(),
            'salidas' => $salidas->values(),
            'encuestas' => $encuestas->values(),
            'resumen' => [
                'pqrs_abiertas' => $pqrs->where('estado', '!=', 'cerrada')->count(),
                'pqrs_vencidas' => $pqrs->where('vencida', true)->count(),
                'a_tiempo' => $respondidas->isEmpty() ? null : (int) round(100 * $respondidas->where('a_tiempo', true)->count() / $respondidas->count()),
                'salidas_abiertas' => $salidas->where('estado', 'abierta')->count(),
                'satisfaccion' => SatisfactionSurvey::resumen($anio),
            ],
            'procesos' => Process::query()->orderBy('orden')->get(['id', 'sigla', 'nombre']),
            'documentos' => collect(array_keys(DocumentosCalidad::DOCUMENTOS))->map(fn (string $d) => $this->estadoDocumento($d))->filter()->values(),
        ]);
    }

    // ── PQRS ────────────────────────────────────────────────────────────────

    public function storePqrs(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $r = CustomerRequest::create($this->validatedPqrs($request));

        return back()->with('success', "PQRS radicada con el número {$r->radicado}. Plazo: {$r->fecha_limite->toDateString()}.");
    }

    public function updatePqrs(Request $request, CustomerRequest $pqrs): RedirectResponse
    {
        $pqrs->update($this->validatedPqrs($request));

        return back()->with('success', "{$pqrs->radicado} actualizada.");
    }

    public function destroyPqrs(CustomerRequest $pqrs): RedirectResponse
    {
        $pqrs->delete();

        return back()->with('success', "{$pqrs->radicado} eliminada.");
    }

    public function accionPqrs(Request $request, CustomerRequest $pqrs): RedirectResponse
    {
        if (! in_array($pqrs->tipo, CustomerRequest::EVALUABLES, true) || $pqrs->procede !== true) {
            return back()->withErrors(['acpm' => 'Solo una queja o un reclamo que procede lleva acción correctiva.']);
        }

        return $this->crearAccion($request, $pqrs, 'pqrs',
            CustomerRequest::TIPOS[$pqrs->tipo]." {$pqrs->radicado} de {$pqrs->cliente}: {$pqrs->descripcion}", $pqrs->fecha);
    }

    // ── Salidas no conformes ────────────────────────────────────────────────

    public function storeSalida(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $s = NonconformingOutput::create($this->validatedSalida($request));

        return back()->with('success', "Salida no conforme {$s->codigo} registrada.");
    }

    public function updateSalida(Request $request, NonconformingOutput $salida): RedirectResponse
    {
        $salida->update($this->validatedSalida($request));

        return back()->with('success', "{$salida->codigo} actualizada.");
    }

    public function destroySalida(NonconformingOutput $salida): RedirectResponse
    {
        $salida->delete();

        return back()->with('success', "{$salida->codigo} eliminada.");
    }

    public function accionSalida(Request $request, NonconformingOutput $salida): RedirectResponse
    {
        return $this->crearAccion($request, $salida, 'salida_no_conforme',
            "Salida no conforme {$salida->codigo} ({$salida->producto}): {$salida->descripcion}", $salida->fecha);
    }

    // ── Encuestas de satisfacción ───────────────────────────────────────────

    public function storeEncuesta(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        SatisfactionSurvey::create($this->validatedEncuesta($request));

        return back()->with('success', 'Encuesta registrada.');
    }

    public function updateEncuesta(Request $request, SatisfactionSurvey $encuesta): RedirectResponse
    {
        $encuesta->update($this->validatedEncuesta($request));

        return back()->with('success', 'Encuesta actualizada.');
    }

    public function destroyEncuesta(SatisfactionSurvey $encuesta): RedirectResponse
    {
        $encuesta->delete();

        return back()->with('success', 'Encuesta eliminada.');
    }

    // ── Documentos ──────────────────────────────────────────────────────────

    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $documento = $request->validate(['documento' => ['required', Rule::in(array_keys(DocumentosCalidad::DOCUMENTOS))]])['documento'];
        $entrada = $this->documentos->entrada($documento);
        if (! $entrada) {
            return back()->withErrors(['documento' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo($this->context->id(), $entrada, $this->documentos->contenido($documento),
            'Actualizado desde el módulo de calidad.', $request->user(), $this->documentos->claves($documento));

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** Acción correctiva en ACPM enlazada al registro de origen. */
    private function crearAccion(Request $request, Model $origen, string $tipoOrigen, string $hallazgo, Carbon $fecha): RedirectResponse
    {
        if ($origen->acpm_action_id) {
            return back()->withErrors(['acpm' => 'Ya tiene una acción en ACPM.']);
        }
        $datos = $request->validate([
            'causa' => ['nullable', 'string', 'max:2000'],
            'accion' => ['required', 'string', 'max:2000'],
            'responsable' => ['required', 'string', 'max:255'],
            'fecha_limite' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $acpm = AcpmAction::create([
            'tipo' => 'correctiva',
            'origen_tipo' => $tipoOrigen,
            'origen_id' => $origen->getKey(),
            'hallazgo' => mb_substr($hallazgo, 0, 2000),
            'causa' => $datos['causa'] ?? null,
            'accion' => $datos['accion'],
            'responsable' => $datos['responsable'],
            'fecha_deteccion' => $fecha->toDateString(),
            'fecha_limite' => $datos['fecha_limite'],
            'estado' => 'abierta',
        ]);
        $origen->forceFill(['acpm_action_id' => $acpm->id])->save();

        return back()->with('success', "Acción {$acpm->codigo} creada en ACPM.");
    }

    /** @return array<string, mixed> */
    private function validatedPqrs(Request $request): array
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'tipo' => ['required', Rule::in(array_keys(CustomerRequest::TIPOS))],
            'cliente' => ['required', 'string', 'max:255'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'canal' => ['nullable', Rule::in(array_keys(CustomerRequest::CANALES))],
            'descripcion' => ['required', 'string', 'max:5000'],
            'process_id' => ['nullable', 'integer', Rule::exists('processes', 'id')->where('tenant_id', $this->context->id())],
            'responsable' => ['nullable', 'string', 'max:255'],
            'fecha_limite' => ['nullable', 'date', 'after_or_equal:fecha'],
            'respuesta' => ['nullable', 'string', 'max:5000'],
            'fecha_respuesta' => ['nullable', 'date', 'after_or_equal:fecha', 'before_or_equal:today'],
            'procede' => ['nullable', 'boolean'],
            'estado' => ['required', Rule::in(array_keys(CustomerRequest::ESTADOS))],
        ], [
            'fecha_respuesta.after_or_equal' => 'La respuesta no puede ser anterior a la radicación.',
        ]);

        $datos['fecha_limite'] ??= CustomerRequest::plazo($datos['fecha'])->toDateString();
        if (! in_array($datos['tipo'], CustomerRequest::EVALUABLES, true)) {
            $datos['procede'] = null;
        }
        // Cerrar sin respuesta deja la PQRS sin evidencia de que se atendió.
        if ($datos['estado'] === 'cerrada' && (blank($datos['respuesta'] ?? null) || blank($datos['fecha_respuesta'] ?? null))) {
            throw ValidationException::withMessages(['respuesta' => 'Para cerrarla registra la respuesta y su fecha.']);
        }

        return $datos;
    }

    /** @return array<string, mixed> */
    private function validatedSalida(Request $request): array
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'producto' => ['required', 'string', 'max:255'],
            'process_id' => ['nullable', 'integer', Rule::exists('processes', 'id')->where('tenant_id', $this->context->id())],
            'detectado_en' => ['required', Rule::in(array_keys(NonconformingOutput::DETECCION))],
            'descripcion' => ['required', 'string', 'max:5000'],
            'cantidad' => ['nullable', 'string', 'max:60'],
            'tratamiento' => ['required', Rule::in(array_keys(NonconformingOutput::TRATAMIENTOS))],
            'detalle_tratamiento' => ['nullable', 'string', 'max:3000'],
            'autorizado_por' => ['nullable', 'required_if:tratamiento,concesion', 'string', 'max:255'],
            'verificado_por' => ['nullable', 'string', 'max:255'],
            'fecha_verificacion' => ['nullable', 'date', 'after_or_equal:fecha', 'before_or_equal:today'],
            'estado' => ['required', Rule::in(array_keys(NonconformingOutput::ESTADOS))],
        ], [
            'autorizado_por.required_if' => 'Una concesión exige registrar quién la autorizó (ISO 9001 8.7.2).',
        ]);

        if ($datos['estado'] === 'cerrada' && in_array($datos['tratamiento'], NonconformingOutput::VERIFICABLES, true)
            && (blank($datos['verificado_por'] ?? null) || blank($datos['fecha_verificacion'] ?? null))) {
            throw ValidationException::withMessages(['verificado_por' => 'Después de corregir o reprocesar hay que verificar de nuevo la conformidad antes de cerrar: registra quién verificó y cuándo.']);
        }

        return $datos;
    }

    /** @return array<string, mixed> */
    private function validatedEncuesta(Request $request): array
    {
        return $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'cliente' => ['required', 'string', 'max:255'],
            'producto' => ['nullable', 'string', 'max:255'],
            'comentario' => ['nullable', 'string', 'max:3000'],
        ] + array_fill_keys(array_keys(SatisfactionSurvey::CRITERIOS), ['required', 'integer', 'between:1,5']));
    }

    /** @return array<string, mixed>|null */
    private function estadoDocumento(string $clave): ?array
    {
        $entrada = $this->documentos->entrada($clave);
        if (! $entrada) {
            return null;
        }
        $doc = ControlledDocument::query()->where('document_catalog_id', $entrada->id)->first();

        return ['clave' => $clave, 'titulo' => $entrada->nombre, 'id' => $doc?->id, 'codigo' => $doc?->codigo, 'estado' => $doc?->estado];
    }
}
