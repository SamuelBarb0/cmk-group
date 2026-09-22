<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de la matriz de EPP: qué elemento le corresponde a qué cargo.
 *
 * La matriz se define por CARGO y no por persona; quién lo recibió vive en
 * `ppe_deliveries`.
 */
class PpeAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['ppe_item_id', 'cargo', 'area', 'requerimiento', 'observaciones'];

    /** La R y la S de la matriz de CMK. */
    public const REQUERIMIENTOS = ['requerido', 'segun_necesidad'];

    /** @return BelongsTo<PpeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PpeItem::class, 'ppe_item_id');
    }
}
