<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta propia del cliente para un indicador preset (global).
 * Sobrescribe la meta legal por defecto SOLO para ese tenant.
 */
class IndicatorGoal extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'indicator_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Indicator, $this> */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
