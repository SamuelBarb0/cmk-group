<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Indicador del SGI. tenant_id NULL = preset legal global (Res. 0312 / Dec. 1072);
 * con tenant_id = indicador propio de una empresa cliente.
 *
 * Fórmula genérica: valor = (numerador / denominador) × constante.
 * sentido: 'asc' (mayor es mejor) | 'desc' (menor es mejor).
 */
class Indicator extends Model
{
    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'categoria',
        'numerador_label',
        'denominador_label',
        'constante',
        'unidad',
        'sentido',
        'meta',
        'es_legal',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'constante' => 'integer',
            'meta' => 'decimal:2',
            'es_legal' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /** @return HasMany<IndicatorReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(IndicatorReading::class);
    }

    /** Calcula el valor del indicador a partir de un numerador y denominador. */
    public function calcular(float $numerador, float $denominador): ?float
    {
        if ($denominador == 0.0) {
            return null;
        }

        return round($numerador / $denominador * $this->constante, 2);
    }

    /** ¿El valor cumple la meta según el sentido del indicador? */
    public function cumpleMeta(?float $valor): ?bool
    {
        if ($valor === null) {
            return null;
        }

        return $this->sentido === 'asc'
            ? $valor >= (float) $this->meta
            : $valor <= (float) $this->meta;
    }
}
