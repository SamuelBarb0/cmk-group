<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archivo que soporta una pregunta de la lista de verificación del PESV
 * (acta, política firmada, procedimiento, registro…). En el disco privado:
 * solo se baja por la ruta, que pasa por el filtro de empresa.
 */
class PesvEvidence extends Model
{
    use BelongsToTenant;

    protected $table = 'pesv_evidences';

    /** Formatos de evidencia aceptados. */
    public const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp4'];

    protected $fillable = ['pesv_plan_id', 'pesv_criterion_id', 'archivo', 'nombre', 'bytes', 'subido_por'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    /** @return BelongsTo<PesvCriterion, $this> */
    public function criterio(): BelongsTo
    {
        return $this->belongsTo(PesvCriterion::class, 'pesv_criterion_id');
    }
}
