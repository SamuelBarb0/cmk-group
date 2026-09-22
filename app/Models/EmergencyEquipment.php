<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Equipo o elemento de emergencias: extintor, botiquín, camilla, señalización…
 * La inspección periódica de cada uno vive en los formatos; esto es el
 * inventario de qué hay y dónde.
 */
class EmergencyEquipment extends Model
{
    use BelongsToTenant;

    protected $table = 'emergency_equipment';

    protected $fillable = [
        'ubicacion', 'ciudad', 'direccion', 'elemento', 'cantidad',
        'ubicacion_exacta', 'tipo', 'estado', 'fecha_revision',
        'fecha_vencimiento', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'fecha_revision' => 'date:Y-m-d',
            'fecha_vencimiento' => 'date:Y-m-d',
        ];
    }

    public const TIPOS = ['primeros_auxilios', 'contra_incendios', 'evacuacion'];

    public const ESTADOS = ['bueno', 'regular', 'malo'];

    /** Con cuánta anticipación se avisa de un vencimiento. */
    public const DIAS_AVISO = 30;

    protected $appends = ['vencido', 'por_vencer'];

    public function getVencidoAttribute(): bool
    {
        return $this->fecha_vencimiento !== null
            && $this->fecha_vencimiento->isBefore(now()->startOfDay());
    }

    public function getPorVencerAttribute(): bool
    {
        return $this->fecha_vencimiento !== null
            && ! $this->vencido
            && $this->fecha_vencimiento->lte(now()->startOfDay()->addDays(self::DIAS_AVISO));
    }
}
