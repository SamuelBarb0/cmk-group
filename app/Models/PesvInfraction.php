<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Infracción de tránsito (comparendo) de un conductor: RE-SST-52 de CMK.
 * El reporte de autogestión del PESV pide el número de infracciones por
 * tipo o código (Res. 40595, paso 20, literal k).
 */
class PesvInfraction extends Model
{
    use BelongsToTenant;

    public const ESTADOS = [
        'pendiente' => 'Pendiente de pago',
        'curso_pedagogico' => 'En curso pedagógico',
        'acuerdo_pago' => 'Acuerdo de pago',
        'pagada' => 'Pagada',
        'impugnada' => 'Impugnada',
        'exonerada' => 'Exonerada',
    ];

    /** Estados que la cierran: el conductor quedó a paz y salvo. */
    public const CERRADAS = ['pagada', 'exonerada'];

    protected $fillable = ['employee_id', 'pesv_vehicle_id', 'fecha', 'codigo', 'descripcion', 'valor', 'estado', 'registrada_simit', 'acciones'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'valor' => 'decimal:2', 'registrada_simit' => 'boolean'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<PesvVehicle, $this> */
    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(PesvVehicle::class, 'pesv_vehicle_id');
    }
}
