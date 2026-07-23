<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento archivado de la empresa cliente (repositorio documental por tenant):
 * exports .docx de Documentos IA que quedan guardados + archivos subidos a mano
 * (versiones firmadas, evidencias, soportes).
 */
class TenantDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'nombre',
        'categoria',
        'origen',
        'generated_document_id',
        'path',
        'size',
        'mime',
        'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<GeneratedDocument, $this> */
    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }
}
