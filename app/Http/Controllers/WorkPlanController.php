<?php

namespace App\Http\Controllers;

use App\Models\WorkPlan;
use App\Models\WorkPlanActivity;
use App\Models\WorkPlanItem;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan de Trabajo Anual del SGI (cronograma por cláusulas ISO 4→10) de la
 * empresa cliente activa. Cada actividad se programa/ejecuta por mes.
 * Incluye metas/objetivos/recursos, selección de actividades aplicables y
 * firma digital del representante legal y del responsable del SG-SST.
 *
 * Permisos: ver -> sst.view | diligenciar/firmar -> sst.manage
 */
class WorkPlanController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('plan-trabajo/index', [
                'needsClient' => true,
                'activities' => [],
                'plan' => null,
                'firmantes' => null,
            ]);
        }

        $plan = WorkPlan::firstOrCreate(['anio' => (int) now()->year]);
        $items = $plan->items()->get()->keyBy('work_plan_activity_id');

        $activities = WorkPlanActivity::orderBy('orden')->get()->map(function (WorkPlanActivity $a) use ($items, $plan) {
            $item = $items->get($a->id);

            return [
                'id' => $a->id,
                'codigo' => $a->codigo,
                'fase' => $a->fase,
                'nombre' => $a->nombre,
                'normas' => $a->normas,
                'soporte' => $a->soporte,
                'aplica' => $plan->actividadAplica($a->id),
                'programados' => $item?->meses_programados ?? [],
                'ejecutados' => $item?->meses_ejecutados ?? [],
                'responsable' => $item?->responsable ?? '',
                'observaciones' => $item?->observaciones ?? '',
            ];
        });

        $tenant = $this->context->get();

        return Inertia::render('plan-trabajo/index', [
            'needsClient' => false,
            'activities' => $activities,
            'plan' => [
                'id' => $plan->id,
                'anio' => $plan->anio,
                'responsable' => $plan->responsable,
                'cumplimiento' => (float) $plan->cumplimiento,
                'metas' => $plan->metas,
                'objetivos' => $plan->objetivos,
                'recursos' => $plan->recursos,
                'firma_rep' => $plan->firma_rep_at ? [
                    'nombre' => $plan->firma_rep_nombre,
                    'cc' => $plan->firma_rep_cc,
                    'fecha' => $plan->firma_rep_at->format('d/m/Y H:i'),
                ] : null,
                'firma_resp' => $plan->firma_resp_at ? [
                    'nombre' => $plan->firma_resp_nombre,
                    'cc' => $plan->firma_resp_cc,
                    'fecha' => $plan->firma_resp_at->format('d/m/Y H:i'),
                ] : null,
            ],
            // Datos de Organización para prellenar las firmas.
            'firmantes' => [
                'representante' => ['nombre' => $tenant?->representante_legal, 'cc' => $tenant?->representante_cc],
                'responsable' => ['nombre' => $tenant?->responsable_sgsst, 'cc' => null],
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de diligenciar el plan de trabajo.']);
        }

        $data = $request->validate([
            'responsable' => ['nullable', 'string', 'max:255'],
            'metas' => ['nullable', 'string', 'max:5000'],
            'objetivos' => ['nullable', 'string', 'max:5000'],
            'recursos' => ['nullable', 'string', 'max:5000'],
            // Actividades del catálogo que APLICAN a este plan.
            'seleccionadas' => ['required', 'array'],
            'seleccionadas.*' => ['integer', 'exists:work_plan_activities,id'],
            'items' => ['array'],
            'items.*.activity_id' => ['required', 'integer', 'exists:work_plan_activities,id'],
            'items.*.programados' => ['array'],
            'items.*.programados.*' => ['integer', 'between:1,12'],
            'items.*.ejecutados' => ['array'],
            'items.*.ejecutados.*' => ['integer', 'between:1,12'],
            'items.*.responsable' => ['nullable', 'string', 'max:255'],
            'items.*.observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = WorkPlan::firstOrCreate(['anio' => (int) now()->year]);

        $seleccionadas = array_values(array_unique($data['seleccionadas']));

        // Si el plan incluye TODAS las actividades del catálogo, guarda null (= todas).
        $plan->actividades_seleccionadas = count($seleccionadas) === WorkPlanActivity::count()
            ? null
            : $seleccionadas;

        // Los ítems de actividades que ya no aplican se eliminan (no cuentan al cumplimiento).
        $plan->items()->whereNotIn('work_plan_activity_id', $seleccionadas)->delete();

        foreach ($data['items'] ?? [] as $it) {
            if (! in_array((int) $it['activity_id'], $seleccionadas, true)) {
                continue;
            }

            WorkPlanItem::updateOrCreate(
                ['work_plan_id' => $plan->id, 'work_plan_activity_id' => $it['activity_id']],
                [
                    'meses_programados' => array_values(array_unique($it['programados'] ?? [])),
                    'meses_ejecutados' => array_values(array_unique($it['ejecutados'] ?? [])),
                    'responsable' => $it['responsable'] ?? null,
                    'observaciones' => $it['observaciones'] ?? null,
                ],
            );
        }

        $plan->responsable = $data['responsable'] ?? $plan->responsable;
        $plan->metas = $data['metas'] ?? null;
        $plan->objetivos = $data['objetivos'] ?? null;
        $plan->recursos = $data['recursos'] ?? null;
        $plan->save();
        $plan->recalcular();

        return back()->with('success', "Plan de trabajo guardado. Cumplimiento: {$plan->cumplimiento}%.");
    }

    /**
     * Firma digital del plan: registra nombre + cédula + sello de fecha/hora
     * del representante legal o del responsable del SG-SST.
     */
    public function firmar(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente antes de firmar el plan.']);
        }

        $data = $request->validate([
            'rol' => ['required', Rule::in(['representante', 'responsable'])],
            'nombre' => ['required', 'string', 'max:255'],
            'cc' => ['nullable', 'string', 'max:30'],
        ]);

        $plan = WorkPlan::firstOrCreate(['anio' => (int) now()->year]);
        $campo = $data['rol'] === 'representante' ? 'firma_rep' : 'firma_resp';

        $plan->update([
            "{$campo}_nombre" => $data['nombre'],
            "{$campo}_cc" => $data['cc'] ?? null,
            "{$campo}_at" => now(),
        ]);

        $rolLabel = $data['rol'] === 'representante' ? 'representante legal' : 'responsable del SG-SST';

        return back()->with('success', "Plan {$plan->anio} firmado por el {$rolLabel}.");
    }

    /** Retira una firma del plan (solo gestión). */
    public function quitarFirma(Request $request): RedirectResponse
    {
        if (! $this->context->has()) {
            return back()->withErrors(['tenant' => 'Selecciona un cliente primero.']);
        }

        $data = $request->validate([
            'rol' => ['required', Rule::in(['representante', 'responsable'])],
        ]);

        $plan = WorkPlan::firstOrCreate(['anio' => (int) now()->year]);
        $campo = $data['rol'] === 'representante' ? 'firma_rep' : 'firma_resp';

        $plan->update([
            "{$campo}_nombre" => null,
            "{$campo}_cc" => null,
            "{$campo}_at" => null,
        ]);

        return back()->with('success', 'Firma retirada del plan.');
    }
}
