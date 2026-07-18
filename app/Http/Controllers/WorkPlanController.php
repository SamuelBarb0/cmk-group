<?php

namespace App\Http\Controllers;

use App\Models\WorkPlan;
use App\Models\WorkPlanActivity;
use App\Models\WorkPlanItem;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan de Trabajo Anual del SGI (cronograma por cláusulas ISO 4→10) de la
 * empresa cliente activa. Cada actividad se programa/ejecuta por mes.
 *
 * Permisos: ver -> sst.view | diligenciar -> sst.manage
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
            ]);
        }

        $plan = WorkPlan::firstOrCreate(['anio' => (int) now()->year]);
        $items = $plan->items()->get()->keyBy('work_plan_activity_id');

        $activities = WorkPlanActivity::orderBy('orden')->get()->map(function (WorkPlanActivity $a) use ($items) {
            $item = $items->get($a->id);

            return [
                'id' => $a->id,
                'codigo' => $a->codigo,
                'fase' => $a->fase,
                'nombre' => $a->nombre,
                'normas' => $a->normas,
                'soporte' => $a->soporte,
                'programados' => $item?->meses_programados ?? [],
                'ejecutados' => $item?->meses_ejecutados ?? [],
                'responsable' => $item?->responsable ?? '',
                'observaciones' => $item?->observaciones ?? '',
            ];
        });

        return Inertia::render('plan-trabajo/index', [
            'needsClient' => false,
            'activities' => $activities,
            'plan' => [
                'id' => $plan->id,
                'anio' => $plan->anio,
                'responsable' => $plan->responsable,
                'cumplimiento' => (float) $plan->cumplimiento,
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
            'items' => ['required', 'array'],
            'items.*.activity_id' => ['required', 'integer', 'exists:work_plan_activities,id'],
            'items.*.programados' => ['array'],
            'items.*.programados.*' => ['integer', 'between:1,12'],
            'items.*.ejecutados' => ['array'],
            'items.*.ejecutados.*' => ['integer', 'between:1,12'],
            'items.*.responsable' => ['nullable', 'string', 'max:255'],
            'items.*.observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = WorkPlan::firstOrCreate(['anio' => (int) now()->year]);

        foreach ($data['items'] as $it) {
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
        $plan->save();
        $plan->recalcular();

        return back()->with('success', "Plan de trabajo guardado. Cumplimiento: {$plan->cumplimiento}%.");
    }
}
