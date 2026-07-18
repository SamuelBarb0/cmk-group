<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Actividad estándar del Plan de Trabajo del SGI (catálogo GLOBAL, transversal
 * a las normas). Fuente: hoja «6.2 PLAN DE TRABAJO SGI» de la herramienta CMK.
 */
class WorkPlanActivity extends Model
{
    protected $fillable = [
        'codigo',
        'fase',
        'nombre',
        'normas',
        'soporte',
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
