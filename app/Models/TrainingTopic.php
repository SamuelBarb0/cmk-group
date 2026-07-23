<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Tema de capacitación del catálogo GLOBAL (biblioteca modelo de CMK).
 */
class TrainingTopic extends Model
{
    protected $fillable = [
        'codigo',
        'titulo',
        'categoria',
        'descripcion',
        'archivo',
        'duracion_sugerida',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'duracion_sugerida' => 'integer',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /** ¿Tiene la presentación cargada y disponible en disco? */
    public function tieneArchivo(): bool
    {
        return filled($this->archivo) && Storage::disk('local')->exists($this->archivo);
    }
}
