<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versión de un documento controlado. No lleva tenant_id propio: pertenece a
 * un ControlledDocument y siempre se alcanza a través de él (las rutas usan
 * scopeBindings), que es donde está el filtro por empresa.
 *
 * Estados: borrador → en_revision → en_aprobacion → vigente → obsoleta.
 * Solo el borrador se edita; las transiciones las hace CicloDocumental.
 */
class ControlledDocumentVersion extends Model
{
    protected $fillable = [
        'version', 'estado', 'contenido', 'archivo', 'archivo_nombre', 'generated_document_id', 'descripcion_cambio', 'observaciones',
        'elaboro_user_id', 'elaboro_nombre', 'enviado_revision_at',
        'reviso_user_id', 'reviso_nombre', 'revisado_at',
        'aprobo_user_id', 'aprobo_nombre', 'aprobado_at',
        'obsoleto_at', 'motivo_obsoleto',
    ];

    protected function casts(): array
    {
        return [
            'enviado_revision_at' => 'datetime',
            'revisado_at' => 'datetime',
            'aprobado_at' => 'datetime',
            'obsoleto_at' => 'datetime',
        ];
    }

    public const ESTADOS = ['borrador', 'en_revision', 'en_aprobacion', 'vigente', 'obsoleta'];

    /** Estados de una versión que todavía no se ha publicado. */
    public const EN_CURSO = ['borrador', 'en_revision', 'en_aprobacion'];

    /** @return BelongsTo<ControlledDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ControlledDocument::class, 'controlled_document_id');
    }

    /** @return HasMany<ControlledDocumentRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(ControlledDocumentRead::class)->orderByDesc('leido_at');
    }

    /** @return BelongsTo<GeneratedDocument, $this> */
    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }

    public function esEditable(): bool
    {
        return $this->estado === 'borrador';
    }
}
