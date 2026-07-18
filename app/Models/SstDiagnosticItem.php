<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * Respuesta a un estándar dentro de un diagnóstico SG-SST.
 * estado: cumple | no_cumple | no_aplica | pendiente
 */
class SstDiagnosticItem extends Model
{
    protected $fillable = [
        'sst_diagnostic_id',
        'sst_standard_id',
        'estado',
        'justificacion',
    ];

    /** @return BelongsTo<SstDiagnostic, $this> */
    public function diagnostic(): BelongsTo
    {
        return $this->belongsTo(SstDiagnostic::class, 'sst_diagnostic_id');
    }

    /** @return BelongsTo<SstStandard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(SstStandard::class, 'sst_standard_id');
    }
}
