<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de un paso del PESV dentro del plan de una empresa.
 *
 * No lleva BelongsToTenant: cuelga de PesvPlan, que sí está segregado.
 */
class PesvPlanStep extends Model
{
    public const ESTADOS = ['pendiente', 'en_proceso', 'cumple', 'no_cumple', 'no_aplica'];

    protected $fillable = [
        'pesv_plan_id',
        'pesv_step_id',
        'estado',
        'observaciones',
        'responsable',
        'fecha_cumplimiento',
    ];

    protected function casts(): array
    {
        return ['fecha_cumplimiento' => 'date'];
    }

    /** @return BelongsTo<PesvPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PesvPlan::class, 'pesv_plan_id');
    }

    /** @return BelongsTo<PesvStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(PesvStep::class, 'pesv_step_id');
    }
}
