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
        'editado_at',
        'editado_por',
    ];

    public const CATEGORIAS = ['SST', 'PESV', 'HSEQ'];

    /** Formatos de material aceptados al cargar desde la plataforma. */
    public const EXTENSIONES = ['pptx', 'ppt', 'pdf', 'docx', 'doc', 'xlsx', 'mp4'];

    protected function casts(): array
    {
        return [
            'duracion_sugerida' => 'integer',
            'orden' => 'integer',
            'activo' => 'boolean',
            'editado_at' => 'datetime',
        ];
    }

    /** ¿Tiene la presentación cargada y disponible en disco? */
    public function tieneArchivo(): bool
    {
        return filled($this->archivo) && Storage::disk('local')->exists($this->archivo);
    }
}
