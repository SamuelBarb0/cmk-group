<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de una empresa a una pregunta de la lista de verificación.
 *
 * Los cuatro estados son los de la Tabla 16. No lleva BelongsToTenant:
 * cuelga de PesvPlan, que sí está segregado.
 */
class PesvPlanCriterion extends Model
{
    public const ESTADOS = ['cumple', 'no_cumple', 'no_aplica', 'no_verificado'];

    protected $fillable = ['pesv_plan_id', 'pesv_criterion_id', 'estado', 'observaciones', 'verificado_at', 'verificado_por'];

    protected function casts(): array
    {
        return ['verificado_at' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<PesvPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PesvPlan::class, 'pesv_plan_id');
    }

    /** @return BelongsTo<PesvCriterion, $this> */
    public function criterio(): BelongsTo
    {
        return $this->belongsTo(PesvCriterion::class, 'pesv_criterion_id');
    }
}
