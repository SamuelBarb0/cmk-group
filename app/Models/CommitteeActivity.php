<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividad del plan de trabajo anual de un comité.
 *
 * Los dos scopes de abajo SON el indicador: `programadas()` es el denominador y
 * `ejecutadas()` el numerador de CUMP-COPASST / CUMP-COCOLAB. Viven aquí para
 * que no se cuenten de dos formas distintas en dos pantallas.
 */
class CommitteeActivity extends Model
{
    protected $fillable = [
        'committee_id', 'descripcion', 'orden',
        'programada', 'ejecutada', 'fecha_ejecucion', 'evidencia',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'programada' => 'boolean',
            'ejecutada' => 'boolean',
            'fecha_ejecucion' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CommitteeActivity $act): void {
            // Marcar ejecutada sin fecha deja la actividad contando en el
            // indicador sin poder demostrar cuando se hizo.
            if ($act->ejecutada && $act->fecha_ejecucion === null) {
                $act->fecha_ejecucion = now()->toDateString();
            }

            if (! $act->ejecutada) {
                $act->fecha_ejecucion = null;
            }
        });
    }

    /** Denominador del indicador. */
    public function scopeProgramadas(Builder $query): Builder
    {
        return $query->where('programada', true);
    }

    /** Numerador. */
    public function scopeEjecutadas(Builder $query): Builder
    {
        return $query->where('ejecutada', true);
    }

    /** @return BelongsTo<Committee, $this> */
    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }
}
