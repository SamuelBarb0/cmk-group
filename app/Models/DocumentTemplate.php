<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de documento SGI (catálogo global, transversal a varias normas).
 */
class DocumentTemplate extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'categoria',
        'normas',
        'descripcion',
        'contenido_base',
        'archivo',
        'prompt',
        'orden',
    ];

    /** ¿Tiene documento modelo base para rellenar (vs. generar desde cero)? */
    public function tieneBase(): bool
    {
        return filled($this->contenido_base);
    }

    protected function casts(): array
    {
        return [
            'normas' => 'array',
            'orden' => 'integer',
        ];
    }
}
