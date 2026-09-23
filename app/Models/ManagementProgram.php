<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Programa de gestión del catálogo GLOBAL de CMK (sembrado desde los Excel).
 * Una empresa no lo usa directamente: lo adopta, y eso crea un ProgramPlan con
 * copia de sus textos, actividades e indicadores.
 */
class ManagementProgram extends Model
{
    protected $fillable = [
        'codigo', 'nombre', 'categoria', 'objetivo', 'alcance', 'recursos',
        'formato_codigo', 'actividades', 'indicadores', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'actividades' => 'array',
            'indicadores' => 'array',
        ];
    }

    public const CATEGORIAS = [
        'pve' => 'Vigilancia epidemiológica',
        'sst' => 'Seguridad y salud en el trabajo',
        'pesv' => 'Seguridad vial',
        'ambiental' => 'Ambiental',
    ];
}
