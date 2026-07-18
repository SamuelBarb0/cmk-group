<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la Matriz IPERC (peligro valorado según GTC 45). Segregada por tenant.
 * El nivel de riesgo y la aceptabilidad se recalculan automáticamente al guardar.
 */
class IpercRow extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'proceso', 'zona', 'actividad', 'tarea', 'rutinaria',
        'clasificacion', 'peligro', 'efectos',
        'control_fuente', 'control_medio', 'control_individuo',
        'nd', 'ne', 'nc', 'np', 'nr', 'nivel_riesgo', 'aceptabilidad',
        'medidas', 'expuestos',
    ];

    protected function casts(): array
    {
        return [
            'rutinaria' => 'boolean',
            'nd' => 'integer', 'ne' => 'integer', 'nc' => 'integer',
            'np' => 'integer', 'nr' => 'integer', 'expuestos' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Recalcula NP, NR, nivel y aceptabilidad a partir de ND, NE, NC.
        static::saving(function (IpercRow $row): void {
            $row->np = $row->nd * $row->ne;
            $row->nr = $row->np * $row->nc;
            $row->nivel_riesgo = self::nivelRiesgo($row->nr);
            $row->aceptabilidad = self::aceptabilidad($row->nivel_riesgo);
        });
    }

    /** Nivel de Riesgo (GTC 45) a partir del NR. */
    public static function nivelRiesgo(int $nr): string
    {
        return match (true) {
            $nr >= 600 => 'I',
            $nr >= 150 => 'II',
            $nr >= 40 => 'III',
            default => 'IV',
        };
    }

    /** Aceptabilidad del riesgo según el nivel (GTC 45). */
    public static function aceptabilidad(string $nivel): string
    {
        return match ($nivel) {
            'I' => 'No Aceptable',
            'II' => 'No Aceptable o Aceptable con control específico',
            'III' => 'Mejorable',
            default => 'Aceptable',
        };
    }
}
