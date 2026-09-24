<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lectura confirmada de una versión publicada (divulgación). */
class ControlledDocumentRead extends Model
{
    protected $fillable = ['controlled_document_version_id', 'user_id', 'user_nombre', 'leido_at'];

    protected function casts(): array
    {
        return ['leido_at' => 'datetime'];
    }

    /** @return BelongsTo<ControlledDocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ControlledDocumentVersion::class, 'controlled_document_version_id');
    }
}
