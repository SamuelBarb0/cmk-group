<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Activo sujeto a mantenimiento: máquina, equipo, herramienta, instalación o
 * vehículo. Un vehículo se enlaza al de la caracterización del PESV.
 */
class MaintenanceAsset extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tipo', 'nombre', 'codigo', 'marca', 'modelo', 'serie', 'ubicacion',
        'pesv_vehicle_id', 'unidad_lectura', 'lectura_actual', 'fecha_ingreso',
        'responsable', 'activo', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date:Y-m-d',
            'lectura_actual' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public const TIPOS = [
        'vehiculo' => 'Vehículo',
        'maquina' => 'Máquina',
        'equipo' => 'Equipo',
        'herramienta' => 'Herramienta',
        'locativo' => 'Instalación (locativo)',
        'tecnologia' => 'Tecnología',
        'otro' => 'Otro',
    ];

    public const UNIDADES_LECTURA = ['km', 'horas'];

    /** @return HasMany<MaintenancePlanItem, $this> */
    public function planItems(): HasMany
    {
        return $this->hasMany(MaintenancePlanItem::class)->orderBy('orden');
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return BelongsTo<PesvVehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(PesvVehicle::class, 'pesv_vehicle_id');
    }

    /**
     * Estado de cada ítem del plan, con el último registro de ese ítem.
     * Espera `planItems` y `records` cargados, para no consultar por ítem.
     *
     * @return list<array<string, mixed>>
     */
    public function estadoDelPlan(): array
    {
        return $this->planItems->map(function (MaintenancePlanItem $item) {
            $ultimo = $this->records
                ->where('maintenance_plan_item_id', $item->id)
                ->sortByDesc(fn (MaintenanceRecord $r) => $r->fecha->format('Y-m-d').sprintf('%010d', $r->id))
                ->first();

            return ['item' => $item, 'ultimo' => $ultimo] + $item->vencimiento($ultimo, $this->lectura_actual);
        })->all();
    }

    /**
     * Copia al vehículo del PESV lo que su ficha resume: kilometraje, último
     * mantenimiento y el próximo por fecha. Así el Paso 17 del PESV y su
     * insumo en PesvFeed ven lo mismo que este módulo, sin que el consultor
     * lo escriba dos veces. El próximo por km no tiene fecha y no se copia.
     */
    public function sincronizarVehiculo(): void
    {
        if (! $this->pesv_vehicle_id || ! $this->vehicle) {
            return;
        }

        $this->loadMissing(['planItems', 'records']);
        $ultimo = $this->records->max(fn (MaintenanceRecord $r) => $r->fecha->format('Y-m-d'));
        $proximo = collect($this->estadoDelPlan())->pluck('proxima_fecha')->filter()->min();

        $v = $this->vehicle;
        $v->ultimo_mantenimiento = $ultimo;
        $v->proximo_mantenimiento = $proximo;
        if ($this->unidad_lectura === 'km' && $this->lectura_actual !== null) {
            $v->kilometraje = max((int) $v->kilometraje, $this->lectura_actual);
        }
        $v->save();
    }
}
