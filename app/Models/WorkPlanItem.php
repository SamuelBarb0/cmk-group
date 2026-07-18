<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Programación y ejecución mensual de una actividad dentro de un plan de trabajo.
 * meses_programados / meses_ejecutados: arreglos de meses (1..12).
 */
class WorkPlanItem extends Model
{
    protected $fillable = [
        'work_plan_id',
        'work_plan_activity_id',
        'meses_programados',
        'meses_ejecutados',
        'responsable',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'meses_programados' => 'array',
            'meses_ejecutados' => 'array',
        ];
    }

    /** @return BelongsTo<WorkPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkPlan::class, 'work_plan_id');
    }

    /** @return BelongsTo<WorkPlanActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(WorkPlanActivity::class, 'work_plan_activity_id');
    }
}
