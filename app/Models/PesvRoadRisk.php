<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Pesv\RiesgosViales;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Riesgo de la matriz de riesgos viales (paso 6, RE-SST-45). El valor y el
 * nivel se calculan al guardar: exposición × probabilidad y mapa de calor.
 */
class PesvRoadRisk extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'desempeno', 'factor', 'perfil', 'cargo', 'rol_via', 'tipo_vehiculo', 'exposicion', 'probabilidad',
        'accion', 'controles', 'lineas', 'eficaz', 'fecha_identificacion', 'fecha_cierre', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'exposicion' => 'integer', 'probabilidad' => 'integer', 'valor' => 'integer',
            'controles' => 'array', 'lineas' => 'array', 'eficaz' => 'boolean',
            'fecha_identificacion' => 'date:Y-m-d', 'fecha_cierre' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PesvRoadRisk $r): void {
            $r->valor = $r->exposicion * $r->probabilidad;
            $r->nivel = RiesgosViales::nivel($r->valor);
        });
    }

    /** ¿Estaba en la matriz en esa fecha? (identificado y sin cerrar) */
    public function vigenteEn(CarbonInterface $fecha): bool
    {
        return $this->fecha_identificacion->lte($fecha) && ($this->fecha_cierre === null || $this->fecha_cierre->gt($fecha));
    }
}
