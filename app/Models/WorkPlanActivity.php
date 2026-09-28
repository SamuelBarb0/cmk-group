<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Actividad estándar de un plan de trabajo (catálogo GLOBAL). `plan` dice de
 * cuál: `sgi` (hoja «6.2 PLAN DE TRABAJO SGI», por cláusulas ISO) o `sst`
 * (hoja «2.4.1 Plan de trabajo», por ciclo PHVA).
 */
class WorkPlanActivity extends Model
{
    /** Plan SST-PESV (Res. 0312, estándar 2.4.1) y plan del SGI (cláusulas ISO). */
    public const PLANES = ['sst' => 'SG-SST / PESV', 'sgi' => 'SGI (ISO)'];

    protected $fillable = [
        'plan',
        'codigo',
        'fase',
        'nombre',
        'normas',
        'soporte',
        'frecuencia',
        'responsable_sugerido',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'normas' => 'array',
            'orden' => 'integer',
        ];
    }
}
