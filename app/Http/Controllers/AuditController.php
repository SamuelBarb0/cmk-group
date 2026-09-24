<?php

namespace App\Http\Controllers;

use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\AuditCheck;
use App\Models\AuditFinding;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
                'requisitos' => [],
                'stats' => ['total' => 0, 'programadas' => 0, 'no_conformidades' => 0, 'sin_accion' => 0],
                'catalogos' => $this->catalogos(),
            ]);
        }

        $auditorias = Audit::query()->with('findings.requirements:id,clave_comun')->orderByDesc('fecha_programada')->get();

        // El formulario trabaja con claves comunes (SIG-08), no con las filas
        // por norma: una clave marcada se expande a las normas del alcance.
        $auditorias->each(fn (Audit $a) => $a->findings->each(function (AuditFinding $f): void {
            $f->setAttribute('claves', $f->requirements->pluck('clave_comun')->unique()->values());
            $f->unsetRelation('requirements');
        }));

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
            'requisitos' => $this->requisitosComunes(),
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

    /** Lista de verificación de la auditoría e informe por norma. */
    public function show(Audit $auditoria): Response
    {
        $auditoria->load('findings.requirements:id,clave_comun');
        $respuestas = $auditoria->checks()->get()->keyBy('clave_comun');

        $hallazgosPorClave = $auditoria->findings
            ->flatMap(fn (AuditFinding $f) => $f->requirements->pluck('clave_comun')->unique()
                ->map(fn ($clave) => ['clave' => $clave, 'hallazgo' => $f->only(['id', 'tipo', 'descripcion'])]))
            ->groupBy('clave')
            ->map(fn ($g) => $g->pluck('hallazgo')->values());

        $lista = $auditoria->requisitosDelAlcance()->groupBy('clave_comun')->map(fn ($filas, $clave) => [
            'clave_comun' => $clave,
            'titulo' => $filas->first()->titulo,
            'etapa' => $filas->first()->etapa,
            'modulo' => $filas->first()->modulo,
            'referencias' => $filas->map(fn ($f) => ['norma' => $f->norm->clave, 'referencia' => $f->referencia])->values(),
            'resultado' => $respuestas[$clave]->resultado ?? null,
            'evidencia' => $respuestas[$clave]->evidencia ?? null,
            'hallazgos' => $hallazgosPorClave[$clave] ?? [],
        ])->values();

        return Inertia::render('auditoria/show', [
            'auditoria' => $auditoria->only(['id', 'codigo', 'tipo', 'objetivo', 'alcance', 'procesos', 'fecha_programada', 'estado', 'auditor_lider', 'sistemas']),
            'lista' => $lista,
            'cumplimiento' => $auditoria->cumplimientoPorNorma(),
            'etapas' => NormRequirement::ETAPAS,
            'resultados' => AuditCheck::RESULTADOS,
        ]);
    }

    /** Guarda en bloque las respuestas de la lista de verificación. */
    public function verificacion(Request $request, Audit $auditoria): RedirectResponse
    {
        $alcance = $auditoria->requisitosDelAlcance()->pluck('clave_comun')->unique()->all();

        $datos = $request->validate([
            'respuestas' => ['present', 'array'],
            'respuestas.*.clave_comun' => ['required', 'string', Rule::in($alcance)],
            'respuestas.*.resultado' => ['nullable', Rule::in(AuditCheck::RESULTADOS)],
            'respuestas.*.evidencia' => ['nullable', 'string', 'max:2000'],
        ], ['respuestas.*.clave_comun.in' => 'Ese requisito no está en el alcance de la auditoría.']);

        DB::transaction(function () use ($auditoria, $datos): void {
            foreach ($datos['respuestas'] as $r) {
                if (empty($r['resultado'])) {
                    $auditoria->checks()->where('clave_comun', $r['clave_comun'])->delete();

                    continue;
                }

                $auditoria->checks()->updateOrCreate(
                    ['clave_comun' => $r['clave_comun']],
                    ['resultado' => $r['resultado'], 'evidencia' => $r['evidencia'] ?? null],
                );
            }
        });

        return back()->with('success', 'Lista de verificación guardada.');
    }

    /** Registra un hallazgo desde la lista, ya vinculado a su requisito. */
    public function hallazgo(Request $request, Audit $auditoria): RedirectResponse
    {
        $alcance = $auditoria->requisitosDelAlcance()->pluck('clave_comun')->unique()->all();

        $datos = $request->validate([
            'clave_comun' => ['required', 'string', Rule::in($alcance)],
            'tipo' => ['required', Rule::in(AuditFinding::TIPOS)],
            'descripcion' => ['required', 'string', 'max:2000'],
            'evidencia' => ['nullable', 'string', 'max:2000'],
        ]);

        $hallazgo = $auditoria->findings()->create([
            'tipo' => $datos['tipo'],
            'descripcion' => $datos['descripcion'],
            'evidencia' => $datos['evidencia'] ?? null,
            'requisito' => NormRequirement::where('clave_comun', $datos['clave_comun'])->value('titulo'),
        ]);
        $hallazgo->requirements()->sync($auditoria->requisitosDeClaves([$datos['clave_comun']]));

        return back()->with('success', 'Hallazgo registrado.');
    }

    /**
     * Los 62 requisitos comunes con la referencia de cada norma, para vincular
     * hallazgos desde el formulario.
     */
    private function requisitosComunes(): Collection
    {
        return NormRequirement::query()
            ->with('norm:id,clave')
            ->whereHas('norm', fn ($q) => $q->where('vigente', true))
            ->orderBy('orden')
            ->get()
            ->groupBy('clave_comun')
            ->map(fn ($g) => [
                'clave_comun' => $g->first()->clave_comun,
                'titulo' => $g->first()->titulo,
                'referencias' => $g->map(fn ($f) => ['norma' => $f->norm->clave, 'referencia' => $f->referencia])->values(),
            ])
            ->values();
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
            'findings.*.claves' => ['nullable', 'array'],
            'findings.*.claves.*' => ['string', 'max:20'],
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
            $claves = $f['claves'] ?? [];
            unset($f['claves']);
            $auditoria->findings()->create($f)->requirements()->sync($auditoria->requisitosDeClaves($claves));
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
            'sistemas' => ['nullable', 'array'],
            'sistemas.*' => [Rule::in(Norm::SISTEMAS)],
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
