<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrega de un EPP a un trabajador.
 *
 * La firma no es un adorno: sin el registro firmado, para una auditoría el EPP
 * NO se entregó. De ahí el scope `sinFirmar()`, que es lo que el consultor
 * tiene que perseguir antes de la visita.
 */
class PpeDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'ppe_item_id', 'fecha_entrega', 'cantidad', 'talla',
        'entregado_por', 'recibido_por', 'fecha_firma', 'motivo', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_entrega' => 'date:Y-m-d',
            'fecha_firma' => 'date:Y-m-d',
            'cantidad' => 'integer',
        ];
    }

    public const MOTIVOS = ['dotacion', 'reposicion'];

    protected $appends = ['firmada'];

    protected static function booted(): void
    {
        static::saving(function (PpeDelivery $entrega): void {
            // Si dicen quién recibió pero no cuándo firmó, se fecha con la
            // entrega: es lo que hace el formato en papel de CMK, donde el
            // trabajador firma en el momento.
            if (filled($entrega->recibido_por) && $entrega->fecha_firma === null) {
                $entrega->fecha_firma = $entrega->fecha_entrega;
            }
        });
    }

    public function getFirmadaAttribute(): bool
    {
        return filled($this->recibido_por) && $this->fecha_firma !== null;
    }

    /** Entregas sin constancia: las que no sirven como evidencia. */
    public function scopeSinFirmar(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('recibido_por')->orWhereNull('fecha_firma'));
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<PpeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PpeItem::class, 'ppe_item_id');
    }
}
