<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Ruta o desplazamiento habitual de la organización.
 *
 * Caracterización del Paso 5; insumo del Paso 15 (planificación de
 * desplazamientos) y del Paso 6 (riesgos viales por ruta).
 */
class PesvRoute extends Model
{
    use BelongsToTenant;

    public const NIVELES_RIESGO = ['bajo', 'medio', 'alto', 'critico'];

    public const TIPOS_VIA = ['urbana', 'rural', 'nacional', 'mixta'];

    public const FRECUENCIAS = ['diaria', 'semanal', 'mensual', 'ocasional'];

    protected $fillable = [
        'nombre',
        'origen',
        'destino',
        'tipo_via',
        'distancia_km',
        'duracion_min',
        'frecuencia',
        'horario',
        'peligros',
        'controles',
        'plan',
        'nivel_riesgo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'distancia_km' => 'decimal:2',
            'duracion_min' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
