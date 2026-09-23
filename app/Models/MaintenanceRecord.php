<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un mantenimiento realizado («17. SEGUIMIENTO MTO» / formato de mantenimiento de activos). */
class MaintenanceRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'maintenance_asset_id', 'maintenance_plan_item_id', 'fecha', 'tipo', 'descripcion',
        'lectura', 'realizado_por', 'proveedor_idoneo', 'factura', 'valor', 'verificado_por',
        'hallazgos', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'lectura' => 'integer',
            'proveedor_idoneo' => 'boolean',
            'valor' => 'decimal:2',
        ];
    }

    public const TIPOS = ['preventivo', 'correctivo'];

    public const ESTADOS = ['abierta', 'cerrada'];

    /** @return BelongsTo<MaintenanceAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MaintenanceAsset::class, 'maintenance_asset_id');
    }

    /** @return BelongsTo<MaintenancePlanItem, $this> */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlanItem::class, 'maintenance_plan_item_id');
    }
}
