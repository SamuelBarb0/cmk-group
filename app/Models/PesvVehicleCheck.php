<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Requisitos del vehículo (RE-SST-50). Uno por vehículo. */
class PesvVehicleCheck extends Model
{
    use BelongsToTenant;

    protected $fillable = ['pesv_vehicle_id', 'fecha', 'respuestas', 'resultado', 'verificado_por', 'observaciones'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'respuestas' => 'array'];
    }

    /** @return BelongsTo<PesvVehicle, $this> */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(PesvVehicle::class, 'pesv_vehicle_id');
    }
}
