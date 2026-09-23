<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una actividad del plan de acción de un cambio. Sin tenant propio: cuelga
 * del cambio, igual que las actividades de un comité.
 */
class ChangeRequestAction extends Model
{
    protected $fillable = [
        'descripcion', 'responsable', 'fecha_compromiso', 'ejecutada',
        'fecha_ejecucion', 'observacion', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'fecha_compromiso' => 'date:Y-m-d',
            'fecha_ejecucion' => 'date:Y-m-d',
            'ejecutada' => 'boolean',
        ];
    }

    /** @return BelongsTo<ChangeRequest, $this> */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }
}
