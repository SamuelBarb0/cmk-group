<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decisión (salida) de una revisión por la dirección. Se alcanza siempre a
 * través de su revisión, que es la que lleva el tenant.
 */
class ManagementReviewDecision extends Model
{
    protected $fillable = ['tipo', 'descripcion', 'responsable', 'fecha_limite', 'estado', 'seguimiento', 'acpm_action_id'];

    protected function casts(): array
    {
        return ['fecha_limite' => 'date:Y-m-d'];
    }

    /** Salidas de ISO 9.3.3: mejora, cambios al sistema y recursos. */
    public const TIPOS = ['mejora', 'cambio', 'recursos', 'otro'];

    public const ESTADOS = ['pendiente', 'en_proceso', 'cumplida', 'cancelada'];

    /** @return BelongsTo<ManagementReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ManagementReview::class, 'management_review_id');
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function accion(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class, 'acpm_action_id');
    }
}
