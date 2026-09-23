<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvCommitteeMember;
use App\Models\PesvContractor;
use App\Models\PesvCriterion;
use App\Models\PesvEvidence;
use App\Models\PesvPlan;
use App\Models\PesvPlanCriterion;
use App\Models\PesvPlanStep;
use App\Models\PesvRoute;
use App\Models\PesvStep;
use App\Models\PesvVehicle;
use App\Services\PesvFeed;
use App\Support\LimiteSubida;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan Estratégico de Seguridad Vial (Res. 40595 de 2022) del cliente activo.
 *
 * Presenta los 24 pasos agrupados en sus 4 fases. Cada paso se abre en su
 * propia pantalla, donde además de su estado se muestran los INSUMOS que la
 * plataforma ya tiene para ese paso (ver App\Services\PesvFeed): el objetivo
 * es que el consultor no vuelva a escribir lo que la empresa ya cargó.
 *
 * Permisos: ver -> pesv.view | gestionar -> pesv.manage
 */
class PesvController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** Resumen del plan: avance, nivel, líder, comité y los 24 pasos por fase. */
    public function show(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/index', [
                'needsClient' => true,
                'plan' => null,
                'fases' => [],
                'resumen' => null,
            ]);
        }

        $plan = $this->plan();
        $pasos = $this->pasosDelPlan($plan);

        return Inertia::render('pesv/index', [
            'needsClient' => false,
            'plan' => [
                ...$plan->only([
                    'nivel', 'misionalidad', 'periodo_inicio', 'periodo_fin',
                    'lider_nombre', 'lider_cargo', 'lider_documento',
                ]),
                'lider_designacion_fecha' => $plan->lider_designacion_fecha?->toDateString(),
                'avance' => (float) $plan->avance,
            ],
            'fases' => $this->agruparPorFase($pasos),
            'resumen' => $this->resumen($pasos),
            'comite' => $plan->comite()->orderBy('rol_comite')->get(),
            'empleados' => Employee::where('is_active', true)->orderBy('apellidos')->orderBy('nombres')
                ->get(['id', 'nombres', 'apellidos', 'numero_documento', 'cargo'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'nombre' => trim($e->nombres.' '.$e->apellidos), 'documento' => $e->numero_documento, 'cargo' => $e->cargo]),
            'niveles' => PesvPlan::NIVELES,
            'misionalidades' => PesvPlan::MISIONALIDADES,
            'nivelSugerido' => $this->nivelSugerido($plan),
        ]);
    }

    /** Detalle de un paso, con sus insumos ya disponibles en la plataforma. */
    public function paso(int $numero): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('pesv/paso', ['needsClient' => true, 'paso' => null]);
        }

        $step = PesvStep::where('numero', $numero)->firstOrFail();
        $plan = $this->plan();

        $planStep = PesvPlanStep::firstOrCreate([
            'pesv_plan_id' => $plan->id,
            'pesv_step_id' => $step->id,
        ]);

        $feed = new PesvFeed($this->context->get());

        return Inertia::render('pesv/paso', [
            'needsClient' => false,
            'paso' => [
                'numero' => $step->numero,
                'fase' => $step->fase,
                'fase_nombre' => $step->fase_nombre,
                'titulo' => $step->titulo,
                'descripcion' => $step->descripcion,
                'aplica' => $step->aplicaA($plan->nivel),
                'niveles' => $step->niveles ?? PesvStep::nivelesDe($step->numero),
                'nivel_plan' => $plan->nivel,
                'estado' => $planStep->estado,
                'observaciones' => $planStep->observaciones,
                'responsable' => $planStep->responsable,
                'fecha_cumplimiento' => $planStep->fecha_cumplimiento?->toDateString(),
            ],
            'insumos' => $feed->paraPaso($numero),
            'criterios' => $this->criteriosDelPaso($plan, $step),
            'estadosCriterio' => PesvPlanCriterion::ESTADOS,
            'evidencia' => ['extensiones' => PesvEvidence::EXTENSIONES, 'maxBytes' => LimiteSubida::bytes()],
            'vecinos' => [
                'anterior' => $numero > 1 ? $numero - 1 : null,
                'siguiente' => $numero < 24 ? $numero + 1 : null,
            ],
        ]);
    }

    /** Guarda nivel, periodo y datos del líder designado (pasos 1 y 5). */
    public function savePlan(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de editar el PESV.']);
        }

        $datos = $request->validate([
            'nivel' => ['required', Rule::in(PesvPlan::NIVELES)],
            'misionalidad' => ['nullable', 'integer', Rule::in(array_keys(PesvPlan::MISIONALIDADES))],
            'periodo_inicio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'periodo_fin' => ['nullable', 'integer', 'min:2000', 'max:2100', 'gte:periodo_inicio'],
            'lider_nombre' => ['nullable', 'string', 'max:255'],
            'lider_cargo' => ['nullable', 'string', 'max:255'],
            'lider_documento' => ['nullable', 'string', 'max:30'],
            'lider_designacion_fecha' => ['nullable', 'date'],
        ]);

        $plan = $this->plan();
        $plan->update($datos);
        // El nivel decide qué pasos cuentan: cambiarlo cambia el avance.
        $plan->recalcular();

        return back()->with('success', 'Plan actualizado.');
    }

    /** Guarda el estado de un paso y recalcula el avance del plan. */
    public function saveStep(Request $request, int $numero): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de editar el PESV.']);
        }

        // El estado ya no se elige: sale de las preguntas del paso
        // (PesvVerificacionController). Aquí van los datos generales.
        $datos = $request->validate([
            'observaciones' => ['nullable', 'string'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'fecha_cumplimiento' => ['nullable', 'date'],
        ]);

        $step = PesvStep::where('numero', $numero)->firstOrFail();
        $plan = $this->plan();

        PesvPlanStep::updateOrCreate(
            ['pesv_plan_id' => $plan->id, 'pesv_step_id' => $step->id],
            $datos,
        );

        return back()->with('success', "Paso {$numero} actualizado.");
    }

    /** Agrega un integrante al Comité de Seguridad Vial (Paso 2). */
    public function storeMiembro(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de editar el comité.']);
        }

        $this->plan()->comite()->create($this->validarMiembro($request));

        return back()->with('success', 'Integrante agregado al comité.');
    }

    public function updateMiembro(Request $request, PesvCommitteeMember $miembro): RedirectResponse
    {
        abort_unless($this->context->has() && $miembro->pesv_plan_id === $this->plan()->id, 404);

        $miembro->update($this->validarMiembro($request));

        return back()->with('success', 'Integrante actualizado.');
    }

    /** @return array<string, mixed> */
    private function validarMiembro(Request $request): array
    {
        return $request->validate([
            // Solo empleados de ESTA empresa: un `exists` pelado aceptaba el id
            // de un trabajador de otro cliente.
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $this->context->id())],
            'nombre' => ['required', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:30'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'rol_comite' => ['required', Rule::in(PesvCommitteeMember::ROLES)],
            'es_representante_direccion' => ['boolean'],
        ]);
    }

    public function destroyMiembro(PesvCommitteeMember $miembro): RedirectResponse
    {
        // El miembro cuelga del plan del tenant activo; comprobamos que no se
        // esté borrando el de otro cliente por id adivinado.
        abort_unless($miembro->pesv_plan_id === $this->plan()->id, 404);

        $miembro->delete();

        return back()->with('success', 'Integrante retirado del comité.');
    }

    // ---- Helpers -----------------------------------------------------------

    /** Plan del cliente activo; se crea vacío la primera vez que se entra. */
    private function plan(): PesvPlan
    {
        return PesvPlan::firstOrCreate([], ['nivel' => 'basico']);
    }

    /**
     * Preguntas del paso con la respuesta de la empresa y sus evidencias.
     *
     * @return list<array<string, mixed>>
     */
    private function criteriosDelPaso(PesvPlan $plan, PesvStep $step): array
    {
        $respuestas = $plan->criterios()->get()->keyBy('pesv_criterion_id');
        $evidencias = PesvEvidence::whereIn('pesv_criterion_id', $step->criterios->pluck('id'))->latest('id')->get()->groupBy('pesv_criterion_id');

        return $step->criterios->map(function (PesvCriterion $c) use ($plan, $respuestas, $evidencias) {
            $r = $respuestas->get($c->id);

            return [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'pregunta' => $c->pregunta,
                'niveles' => $c->niveles,
                'aplica' => $c->aplicaA($plan->nivel),
                'estado' => $r->estado ?? 'no_verificado',
                'observaciones' => $r?->observaciones,
                'verificado_at' => $r?->verificado_at?->toDateString(),
                'verificado_por' => $r?->verificado_por,
                'evidencias' => $evidencias->get($c->id, collect())->map(fn (PesvEvidence $e) => [
                    'id' => $e->id, 'nombre' => $e->nombre, 'bytes' => $e->bytes,
                    'subido_por' => $e->subido_por, 'fecha' => $e->created_at?->toDateString(),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Los 24 pasos con el estado que tengan en este plan.
     *
     * Se hace con un left join en memoria en vez de crear las 24 filas al
     * entrar: un cliente que solo mira no debería escribir en la base.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pasosDelPlan(PesvPlan $plan): Collection
    {
        $estados = $plan->pasos()->get()->keyBy('pesv_step_id');
        $respuestas = $plan->criterios()->pluck('estado', 'pesv_criterion_id');
        $criterios = PesvCriterion::all()->filter(fn (PesvCriterion $c) => $c->aplicaA($plan->nivel))->groupBy('pesv_step_id');

        return PesvStep::orderBy('orden')->get()->map(fn (PesvStep $step) => [
            'numero' => $step->numero,
            'aplica' => $step->aplicaA($plan->nivel),
            'criterios' => $criterios->get($step->id, collect())->count(),
            'criterios_cumple' => $criterios->get($step->id, collect())->filter(fn ($c) => ($respuestas[$c->id] ?? null) === 'cumple')->count(),
            'fase' => $step->fase,
            'fase_nombre' => $step->fase_nombre,
            'titulo' => $step->titulo,
            'descripcion' => $step->descripcion,
            'estado' => $estados[$step->id]->estado ?? 'pendiente',
            'responsable' => $estados[$step->id]->responsable ?? null,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pasos
     * @return array<int, array<string, mixed>>
     */
    private function agruparPorFase(Collection $pasos): array
    {
        return $pasos->groupBy('fase')->map(fn ($grupo, $fase) => [
            'fase' => (int) $fase,
            'nombre' => $grupo->first()['fase_nombre'],
            'pasos' => $grupo->values()->all(),
            'cumplidos' => $grupo->where('aplica', true)->where('estado', 'cumple')->count(),
            'total' => $grupo->where('aplica', true)->count(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pasos
     * @return array<string, int>
     */
    private function resumen(Collection $pasos): array
    {
        $noExigidos = $pasos->where('aplica', false)->count();
        $pasos = $pasos->where('aplica', true);

        return [
            'no_exigidos' => $noExigidos,
            'total' => $pasos->count(),
            'cumple' => $pasos->where('estado', 'cumple')->count(),
            'en_proceso' => $pasos->where('estado', 'en_proceso')->count(),
            'no_cumple' => $pasos->where('estado', 'no_cumple')->count(),
            'no_aplica' => $pasos->where('estado', 'no_aplica')->count(),
            'pendiente' => $pasos->where('estado', 'pendiente')->count(),
        ];
    }

    /**
     * Nivel que exige la Res. 40595 (Tabla 1) con lo que hay cargado.
     *
     * Flota = vehículos del inventario + los que los contratistas declaran
     * aportar y no están registrados uno por uno. Conductores = propios + los
     * de contratistas: la norma cuenta a todo el que conduce al servicio de la
     * organización, «independientemente del modelo de contratación».
     *
     * Sigue siendo una SUGERENCIA: el nivel del plan lo fija el consultor,
     * porque el inventario puede estar incompleto. La pantalla avisa si no
     * coinciden.
     *
     * @return array<string, mixed>
     */
    private function nivelSugerido(PesvPlan $plan): array
    {
        $inventario = PesvVehicle::where('is_active', true)->count();
        $deContratistasRegistrados = PesvVehicle::where('is_active', true)->where('propiedad', 'contratista')->count();
        $declarados = (int) PesvContractor::where('is_active', true)->sum('num_vehiculos');
        $extra = max(0, $declarados - $deContratistasRegistrados);

        $propios = Employee::where('is_active', true)->conductores()->count();
        $deContratistas = (int) PesvContractor::where('is_active', true)->sum('num_conductores');

        $flota = $inventario + $extra;
        $conductores = $propios + $deContratistas;
        $nivel = $plan->misionalidad ? PesvPlan::nivelPorNorma($plan->misionalidad, $flota, $conductores) : null;

        return [
            'nivel' => $nivel,
            'calculado' => $plan->misionalidad !== null,
            'obligada' => $plan->misionalidad === null ? null : $nivel !== null,
            'flota' => $flota,
            'vehiculos' => $inventario,
            'vehiculos_contratistas' => $extra,
            'conductores' => $conductores,
            'conductores_propios' => $propios,
            'conductores_contratistas' => $deContratistas,
            'contratistas' => PesvContractor::where('is_active', true)->count(),
            'rutas' => PesvRoute::where('is_active', true)->count(),
        ];
    }
}
