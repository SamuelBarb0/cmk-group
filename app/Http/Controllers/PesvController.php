<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PesvCommitteeMember;
use App\Models\PesvContractor;
use App\Models\PesvPlan;
use App\Models\PesvPlanStep;
use App\Models\PesvRoute;
use App\Models\PesvStep;
use App\Models\PesvVehicle;
use App\Services\PesvFeed;
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
                    'nivel', 'periodo_inicio', 'periodo_fin',
                    'lider_nombre', 'lider_cargo', 'lider_documento',
                ]),
                'lider_designacion_fecha' => $plan->lider_designacion_fecha?->toDateString(),
                'avance' => (float) $plan->avance,
            ],
            'fases' => $this->agruparPorFase($pasos),
            'resumen' => $this->resumen($pasos),
            'comite' => $plan->comite()->orderBy('rol_comite')->get(),
            'niveles' => PesvPlan::NIVELES,
            'nivelSugerido' => $this->nivelSugerido(),
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
                'estado' => $planStep->estado,
                'observaciones' => $planStep->observaciones,
                'responsable' => $planStep->responsable,
                'fecha_cumplimiento' => $planStep->fecha_cumplimiento?->toDateString(),
            ],
            'insumos' => $feed->paraPaso($numero),
            'estados' => PesvPlanStep::ESTADOS,
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
            'periodo_inicio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'periodo_fin' => ['nullable', 'integer', 'min:2000', 'max:2100', 'gte:periodo_inicio'],
            'lider_nombre' => ['nullable', 'string', 'max:255'],
            'lider_cargo' => ['nullable', 'string', 'max:255'],
            'lider_documento' => ['nullable', 'string', 'max:30'],
            'lider_designacion_fecha' => ['nullable', 'date'],
        ]);

        $this->plan()->update($datos);

        return back()->with('success', 'Plan actualizado.');
    }

    /** Guarda el estado de un paso y recalcula el avance del plan. */
    public function saveStep(Request $request, int $numero): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de editar el PESV.']);
        }

        $datos = $request->validate([
            'estado' => ['required', Rule::in(PesvPlanStep::ESTADOS)],
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

        $plan->recalcular();

        return back()->with('success', "Paso {$numero} actualizado.");
    }

    /** Agrega un integrante al Comité de Seguridad Vial (Paso 2). */
    public function storeMiembro(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de editar el comité.']);
        }

        $datos = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:30'],
            'cargo' => ['nullable', 'string', 'max:255'],
            'rol_comite' => ['required', Rule::in(PesvCommitteeMember::ROLES)],
            'es_representante_direccion' => ['boolean'],
        ]);

        $this->plan()->comite()->create($datos);

        return back()->with('success', 'Integrante agregado al comité.');
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

        return PesvStep::orderBy('orden')->get()->map(fn (PesvStep $step) => [
            'numero' => $step->numero,
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
            'cumplidos' => $grupo->where('estado', 'cumple')->count(),
            'total' => $grupo->count(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $pasos
     * @return array<string, int>
     */
    private function resumen(Collection $pasos): array
    {
        return [
            'total' => $pasos->count(),
            'cumple' => $pasos->where('estado', 'cumple')->count(),
            'en_proceso' => $pasos->where('estado', 'en_proceso')->count(),
            'no_cumple' => $pasos->where('estado', 'no_cumple')->count(),
            'no_aplica' => $pasos->where('estado', 'no_aplica')->count(),
            'pendiente' => $pasos->where('estado', 'pendiente')->count(),
        ];
    }

    /**
     * Nivel que sugiere la caracterización cargada.
     *
     * OJO: es una AYUDA, no la norma. La Res. 40595 fija el nivel por la
     * misionalidad del transporte y el tamaño de la flota; aquí solo se mira
     * lo que hay en la plataforma para orientar al consultor, que es quien
     * decide. Por eso el campo `nivel` del plan se edita a mano.
     *
     * @return array<string, mixed>
     */
    private function nivelSugerido(): array
    {
        $vehiculos = PesvVehicle::where('is_active', true)->count();
        $conductores = Employee::where('is_active', true)->conductores()->count();
        $contratistas = PesvContractor::where('is_active', true)->count();

        $sugerido = match (true) {
            $vehiculos > 10 || $conductores > 10 => 'avanzado',
            $vehiculos > 0 || $conductores > 1 => 'estandar',
            default => 'basico',
        };

        return [
            'nivel' => $sugerido,
            'vehiculos' => $vehiculos,
            'conductores' => $conductores,
            'contratistas' => $contratistas,
            'rutas' => PesvRoute::where('is_active', true)->count(),
        ];
    }
}
