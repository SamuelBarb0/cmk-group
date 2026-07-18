<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lectura mensual de un indicador para una empresa cliente (segregada por tenant).
 * Guarda numerador y denominador; el valor se calcula desde el indicador.
 */
class IndicatorReading extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'indicator_id',
        'anio',
        'mes',
        'numerador',
        'denominador',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'numerador' => 'decimal:2',
            'denominador' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Indicator, $this> */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
