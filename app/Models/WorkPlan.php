<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan de Trabajo Anual del SGI de una empresa cliente (segregado por tenant).
 * El % de cumplimiento se recalcula a partir de la ejecución mensual de sus ítems.
 */
class WorkPlan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'anio',
        'responsable',
        'cumplimiento',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'cumplimiento' => 'decimal:2',
        ];
    }

    /** @return HasMany<WorkPlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkPlanItem::class);
    }

    /**
     * Recalcula el % de cumplimiento = meses ejecutados / meses programados.
     * Un mes ejecutado solo cuenta si estaba programado.
     */
    public function recalcular(): void
    {
        $programados = 0;
        $ejecutados = 0;

        foreach ($this->items()->get() as $item) {
            $prog = $item->meses_programados ?? [];
            $ejec = $item->meses_ejecutados ?? [];
            $programados += count($prog);
            $ejecutados += count(array_intersect($prog, $ejec));
        }

        $this->cumplimiento = $programados > 0
            ? round($ejecutados / $programados * 100, 2)
            : 0;
        $this->save();
    }
}
