<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Consumo mensual de un recurso (agua, energía, gas o combustible). Una
 * lectura por mes y recurso. Segregado por tenant.
 */
class ResourceReading extends Model
{
    use BelongsToTenant;

    protected $fillable = ['periodo', 'recurso', 'cantidad', 'costo', 'trabajadores', 'observaciones'];

    protected function casts(): array
    {
        return [
            'periodo' => 'date:Y-m',
            'cantidad' => 'float',
            'costo' => 'float',
            'trabajadores' => 'integer',
        ];
    }

    protected $appends = ['por_persona'];

    /** recurso => [etiqueta, unidad] */
    public const RECURSOS = [
        'agua' => ['Agua', 'm³'],
        'energia' => ['Energía eléctrica', 'kWh'],
        'gas' => ['Gas natural', 'm³'],
        'combustible' => ['Combustible', 'gal'],
    ];

    public function getPorPersonaAttribute(): ?float
    {
        return $this->trabajadores ? round($this->cantidad / $this->trabajadores, 2) : null;
    }
}
