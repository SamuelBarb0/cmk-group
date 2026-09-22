<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recomendación que deja un simulacro. Sin tenant propio: cuelga del
 * simulacro, igual que los miembros cuelgan del comité.
 */
class EmergencyDrillRecommendation extends Model
{
    protected $fillable = [
        'descripcion', 'responsable', 'fecha_limite', 'implementada',
        'fecha_implementacion', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'fecha_limite' => 'date:Y-m-d',
            'fecha_implementacion' => 'date:Y-m-d',
            'implementada' => 'boolean',
        ];
    }

    /** @return BelongsTo<EmergencyDrill, $this> */
    public function drill(): BelongsTo
    {
        return $this->belongsTo(EmergencyDrill::class, 'emergency_drill_id');
    }
}
