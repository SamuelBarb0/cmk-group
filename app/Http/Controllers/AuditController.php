<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Auditorías del sistema de gestión del cliente activo.
 *
 * Permisos: ver -> audit.view | gestionar -> sst.manage
 *
 * `audit.view` ya existía en el seeder de roles pero no lo usaba nadie: la
 * entrada `/auditoria` de la barra lateral llevaba a una ruta inexistente.
 */
class AuditController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('auditoria/index', [
                'needsClient' => true,
                'auditorias' => [],
                'acciones' => [],
                'stats' => ['total' => 0, 'programadas' => 0, 'no_conformidades' => 0, 'sin_accion' => 0],
                'catalogos' => $this->catalogos(),
            ]);
        }

        $auditorias = Audit::query()->with('findings')->orderByDesc('fecha_programada')->get();

        // No conformidades que todavía no tienen una acción correctiva
        // enlazada. Es el hueco que un auditor externo encuentra primero.
        $sinAccion = $auditorias->flatMap->findings
            ->whereIn('tipo', AuditFinding::NO_CONFORMIDADES)
            ->whereNull('acpm_action_id')
            ->count();

        return Inertia::render('auditoria/index', [
            'needsClient' => false,
            'auditorias' => $auditorias,
            'acciones' => AcpmAction::query()->get(['id', 'codigo', 'accion']),
            'stats' => [
                'total' => $auditorias->count(),
                'programadas' => $auditorias->where('estado', 'programada')->count(),
                'no_conformidades' => $auditorias->sum('no_conformidades'),
                'sin_accion' => $sinAccion,
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de programar una auditoría.']);
        }

        // Se valida TODO antes de tocar la base: si los hallazgos vienen mal,
        // no se puede quedar creada la auditoria sin ellos.
        $datos = $this->validated($request);
        $hallazgos = $this->validatedHallazgos($request);

        DB::transaction(function () use ($datos, $hallazgos): void {
            $auditoria = Audit::create($datos);
            $this->reemplazarHallazgos($auditoria, $hallazgos);
        });

        return back()->with('success', 'Auditoría registrada.');
    }

    public function update(Request $request, Audit $auditoria): RedirectResponse
    {
        $datos = $this->validated($request);
        $hallazgos = $this->validatedHallazgos($request);

        DB::transaction(function () use ($auditoria, $datos, $hallazgos): void {
            $auditoria->update($datos);
            $this->reemplazarHallazgos($auditoria, $hallazgos);
        });

        return back()->with('success', 'Auditoría actualizada.');
    }

    public function destroy(Audit $auditoria): RedirectResponse
    {
        $auditoria->delete();

        return back()->with('success', 'Auditoría eliminada.');
    }

    /** @return array<int, array<string, mixed>> */
    private function validatedHallazgos(Request $request): array
    {
        $datos = $request->validate([
            'findings' => ['nullable', 'array'],
            'findings.*.tipo' => ['required', Rule::in(AuditFinding::TIPOS)],
            'findings.*.proceso' => ['nullable', 'string', 'max:255'],
            'findings.*.requisito' => ['nullable', 'string', 'max:255'],
            'findings.*.descripcion' => ['required', 'string', 'max:2000'],
            'findings.*.evidencia' => ['nullable', 'string', 'max:2000'],
            // La acción tiene que ser del mismo cliente: sin esto se podría
            // enlazar un hallazgo con el ACPM de otra empresa.
            'findings.*.acpm_action_id' => ['nullable', 'integer', Rule::exists('acpm_actions', 'id')
                ->where('tenant_id', $this->context->id())],
        ]);

        return $datos['findings'] ?? [];
    }

    /** Reemplaza los hallazgos en bloque; misma razón que en CommitteeController. */
    private function reemplazarHallazgos(Audit $auditoria, array $hallazgos): void
    {
        $auditoria->findings()->delete();
        foreach ($hallazgos as $f) {
            $auditoria->findings()->create($f);
        }
    }

    private function catalogos(): array
    {
        return [
            'tipos' => Audit::TIPOS,
            'estados' => Audit::ESTADOS,
            'tipos_hallazgo' => AuditFinding::TIPOS,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in(Audit::TIPOS)],
            'objetivo' => ['required', 'string', 'max:255'],
            'alcance' => ['nullable', 'string', 'max:2000'],
            'criterios' => ['nullable', 'string', 'max:2000'],
            'procesos' => ['nullable', 'string', 'max:255'],
            'fecha_programada' => ['required', 'date'],
            'fecha_inicio' => ['nullable', 'date'],
            // Una auditoría no puede terminar antes de empezar.
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'auditor_lider' => ['nullable', 'string', 'max:255'],
            'equipo_auditor' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::in(Audit::ESTADOS)],
            'conclusiones' => ['nullable', 'string', 'max:4000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
