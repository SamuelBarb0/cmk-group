<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\ContextIssue;
use App\Models\ControlledDocument;
use App\Models\Norm;
use App\Models\Process;
use App\Models\RiskOpportunity;
use App\Services\ControlDocumental\CicloDocumental;
use App\Services\Riesgos\DocumentosRiesgos;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M04 — Riesgos y oportunidades de los procesos (ISO 45001 6.1.1, ISO 9001
 * 6.1, ISO 14001 6.1.1) de la empresa activa. Se alimenta de la DOFA del
 * contexto (M02) y trata lo que sale alto con acciones en ACPM.
 *
 * Permisos: ver -> sst.view | gestionar -> sst.manage
 */
class RiesgoOportunidadController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DocumentosRiesgos $documentos,
    ) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('riesgos-oportunidades/index', [
                'needsClient' => true, 'filas' => [], 'procesos' => [], 'dofaPendientes' => 0,
                'stats' => ['riesgos' => 0, 'oportunidades' => 0, 'altos' => 0, 'sin_tratar' => 0, 'sin_eficacia' => 0],
                'documento' => null, 'tratamientos' => RiskOpportunity::TRATAMIENTOS,
            ]);
        }

        $filas = RiskOpportunity::query()
            ->with(['process:id,sigla,nombre', 'contextIssue:id,dofa,descripcion', 'acpmAction:id,codigo,estado'])
            ->orderBy('orden')->orderBy('id')->get();

        $vinculadas = $filas->pluck('context_issue_id')->filter()->all();

        return Inertia::render('riesgos-oportunidades/index', [
            'needsClient' => false,
            'filas' => $filas->map(fn (RiskOpportunity $r) => $r->toArray() + ['sin_tratar' => $r->sinTratar()])->values(),
            'procesos' => Process::query()->orderBy('orden')->get(['id', 'sigla', 'nombre']),
            'dofaPendientes' => ContextIssue::query()->whereNotIn('id', $vinculadas)->count(),
            'stats' => [
                'riesgos' => $filas->where('tipo', 'riesgo')->count(),
                'oportunidades' => $filas->where('tipo', 'oportunidad')->count(),
                'altos' => $filas->where('tipo', 'riesgo')->whereIn('nivel', ['alto', 'critico'])->count(),
                'sin_tratar' => $filas->filter(fn (RiskOpportunity $r) => $r->sinTratar())->count(),
                // Tratados o cerrados sin evaluar si lo hecho sirvió (9001 6.1.2 b).
                'sin_eficacia' => $filas->where('estado', 'cerrado')->filter(fn ($r) => blank($r->eficacia))->count(),
            ],
            'documento' => $this->estadoDocumento('riesgos'),
            'tratamientos' => RiskOpportunity::TRATAMIENTOS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $datos = $this->validated($request);
        $datos['evaluado_at'] = filled($datos['eficacia'] ?? null) ? now()->toDateString() : null;

        RiskOpportunity::create($datos + ['orden' => (RiskOpportunity::query()->max('orden') ?? 0) + 1]);

        return back()->with('success', 'Registrado.');
    }

    public function update(Request $request, RiskOpportunity $fila): RedirectResponse
    {
        $datos = $this->validated($request);
        // La fecha de la evaluación de eficacia es la del día en que se escribe
        // o se cambia; editar otra cosa no la mueve.
        if (($datos['eficacia'] ?? null) !== $fila->eficacia) {
            $datos['evaluado_at'] = filled($datos['eficacia'] ?? null) ? now()->toDateString() : null;
        }

        $fila->update($datos);

        return back()->with('success', 'Actualizado.');
    }

    public function destroy(RiskOpportunity $fila): RedirectResponse
    {
        $fila->delete();

        return back()->with('success', 'Eliminado.');
    }

    /**
     * Trae de la DOFA del contexto lo que todavía no está aquí: debilidades y
     * amenazas como riesgos, fortalezas y oportunidades como oportunidades. El
     * impacto se hereda y la probabilidad queda en 3 para que la valoren.
     */
    public function desdeDofa(): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }

        $vinculadas = RiskOpportunity::query()->whereNotNull('context_issue_id')->pluck('context_issue_id');
        $nuevas = ContextIssue::query()->whereNotIn('id', $vinculadas)->orderBy('orden')->orderBy('id')->get();
        $orden = RiskOpportunity::query()->max('orden') ?? 0;

        foreach ($nuevas as $c) {
            $riesgo = in_array($c->dofa, ['debilidad', 'amenaza'], true);
            RiskOpportunity::create([
                'context_issue_id' => $c->id,
                'tipo' => $riesgo ? 'riesgo' : 'oportunidad',
                'descripcion' => $c->descripcion,
                'probabilidad' => 3,
                'impacto' => ['alto' => 4, 'medio' => 3, 'bajo' => 2][$c->impacto] ?? 3,
                'tratamiento' => $riesgo ? 'reducir' : 'aprovechar',
                'acciones' => $c->tratamiento,
                'sistemas' => $c->sistemas,
                'orden' => ++$orden,
            ]);
        }

        return back()->with('success', $nuevas->isEmpty()
            ? 'Todo lo de la DOFA ya está en la matriz.'
            : "{$nuevas->count()} cuestiones de la DOFA traídas. Valora su probabilidad e impacto.");
    }

    /** El tratamiento de un riesgo u oportunidad como acción en ACPM. */
    public function crearAccion(Request $request, RiskOpportunity $fila): RedirectResponse
    {
        if ($fila->acpm_action_id) {
            return back()->withErrors(['acpm' => 'Ya tiene una acción en ACPM.']);
        }

        $datos = $request->validate([
            'accion' => ['required', 'string', 'max:2000'],
            'responsable' => ['required', 'string', 'max:255'],
            'fecha_limite' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $acpm = AcpmAction::create([
            'tipo' => $fila->tipo === 'riesgo' ? 'preventiva' : 'mejora',
            'origen_tipo' => 'riesgo_oportunidad',
            'origen_id' => $fila->id,
            'hallazgo' => ucfirst($fila->tipo).' '.$fila->nivel.': '.$fila->descripcion,
            'causa' => $fila->causa,
            'accion' => $datos['accion'],
            'responsable' => $datos['responsable'],
            'fecha_deteccion' => now()->toDateString(),
            'fecha_limite' => $datos['fecha_limite'],
            'estado' => 'abierta',
        ]);

        // El vínculo no es asignable desde un formulario: se pone aquí, a mano.
        $fila->forceFill(['acpm_action_id' => $acpm->id]);
        $fila->update([
            'acciones' => $fila->acciones ?: $datos['accion'],
            'responsable' => $fila->responsable ?: $datos['responsable'],
            'fecha_limite' => $fila->fecha_limite ?? $datos['fecha_limite'],
            'estado' => $fila->estado === 'abierto' ? 'en_tratamiento' : $fila->estado,
        ]);

        return back()->with('success', "Acción {$acpm->codigo} creada en ACPM.");
    }

    public function enviar(Request $request, CicloDocumental $ciclo): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente.']);
        }
        $entrada = $this->documentos->entrada('riesgos');
        if (! $entrada) {
            return back()->withErrors(['documento' => 'El catálogo del SIG no está cargado en esta instalación: falta correr SigCatalogSeeder.']);
        }

        $doc = $ciclo->recibirDeModulo($this->context->id(), $entrada, $this->documentos->contenido('riesgos'),
            'Actualizado desde la matriz de riesgos y oportunidades.', $request->user(), $this->documentos->claves('riesgos'));

        return back()->with('success', "{$doc->codigo} quedó en borrador en el control documental. Revísalo y apruébalo allí.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = $this->context->id();
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(RiskOpportunity::TIPOS)],
            'process_id' => ['nullable', 'integer', Rule::exists('processes', 'id')->where('tenant_id', $tenant)],
            'context_issue_id' => ['nullable', 'integer', Rule::exists('context_issues', 'id')->where('tenant_id', $tenant)],
            'descripcion' => ['required', 'string', 'max:2000'],
            'causa' => ['nullable', 'string', 'max:2000'],
            'efecto' => ['nullable', 'string', 'max:2000'],
            'probabilidad' => ['required', 'integer', 'between:1,5'],
            'impacto' => ['required', 'integer', 'between:1,5'],
            'tratamiento' => ['required', Rule::in(RiskOpportunity::TRATAMIENTOS[$request->input('tipo')] ?? [])],
            'acciones' => ['nullable', 'string', 'max:3000'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'fecha_limite' => ['nullable', 'date'],
            'estado' => ['required', Rule::in(RiskOpportunity::ESTADOS)],
            'eficacia' => ['nullable', 'string', 'max:2000'],
            'sistemas' => ['required', 'array', 'min:1'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
        ], [
            'tratamiento.in' => 'Ese tratamiento no corresponde al tipo (a un riesgo se le evita, reduce, comparte o acepta; una oportunidad se aprovecha, potencia, comparte o acepta).',
        ]);

        return $datos;
    }

    /** @return array<string, mixed>|null */
    private function estadoDocumento(string $clave): ?array
    {
        $entrada = $this->documentos->entrada($clave);
        if (! $entrada) {
            return null;
        }
        $doc = ControlledDocument::query()->where('document_catalog_id', $entrada->id)->first();

        return ['titulo' => $entrada->nombre, 'id' => $doc?->id, 'codigo' => $doc?->codigo, 'estado' => $doc?->estado];
    }
}
