<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comunicación registrada (enviada o recibida). Segregada por tenant.
 */
class CommunicationLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fecha', 'tipo', 'direccion', 'parte_interesada', 'asunto', 'medio', 'responsable', 'detalle',
        'requiere_respuesta', 'fecha_limite_respuesta', 'fecha_respuesta', 'respuesta', 'communication_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_limite_respuesta' => 'date:Y-m-d',
            'fecha_respuesta' => 'date:Y-m-d',
            'requiere_respuesta' => 'boolean',
        ];
    }

    public const DIRECCIONES = ['entrante', 'saliente'];

    protected $appends = ['pendiente', 'respuesta_vencida'];

    /** Pide respuesta y todavía no la tiene. */
    public function getPendienteAttribute(): bool
    {
        return $this->requiere_respuesta && $this->fecha_respuesta === null;
    }

    public function getRespuestaVencidaAttribute(): bool
    {
        return $this->pendiente
            && $this->fecha_limite_respuesta !== null
            && $this->fecha_limite_respuesta->isBefore(now()->startOfDay());
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('requiere_respuesta', true)->whereNull('fecha_respuesta');
    }

    /** @return BelongsTo<CommunicationPlanItem, $this> */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(CommunicationPlanItem::class, 'communication_plan_id');
    }
}
