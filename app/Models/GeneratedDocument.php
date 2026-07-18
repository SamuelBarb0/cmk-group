<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * Documento SGI generado con IA para una empresa cliente (segregado por tenant).
 * Flujo human-in-the-loop: borrador (IA) → en_revision → aprobado.
 */
class GeneratedDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'document_template_id',
        'titulo',
        'contenido',
        'estado',
        'version',
        'generado_por',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
