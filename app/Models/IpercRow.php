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
        'clasificacion', 'peligro', 'efectos', 'peor_consecuencia',
        'control_fuente', 'control_medio', 'control_individuo',
        'nd', 'ne', 'nc', 'np', 'nr', 'nivel_riesgo', 'aceptabilidad',
        'criterio_controles',
        'med_eliminacion', 'med_sustitucion', 'med_ingenieria',
        'med_administrativos', 'med_epp',
        'medidas', 'expuestos',
    ];

    /**
     * La jerarquía de controles de la GTC 45, de mayor a menor eficacia. El
     * orden importa: es el que decide si una propuesta de control es aceptable.
     */
    public const JERARQUIA = [
        'med_eliminacion',
        'med_sustitucion',
        'med_ingenieria',
        'med_administrativos',
        'med_epp',
    ];

    /** Va al front con cada fila: la tabla marca ahí el aviso de EPP como único control. */
    protected $appends = ['solo_epp'];

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

    /**
     * Marca las filas donde el EPP quedó como ÚNICO control propuesto.
     *
     * La GTC 45 pone el EPP en el último escalón: proteger a la persona no
     * elimina el peligro, solo la interpone. Una matriz donde el único control
     * de un riesgo alto es «usar guantes» es la observación más repetida en una
     * auditoría, y hasta ahora no se podía detectar porque las cinco medidas
     * vivían fundidas en un solo campo de texto.
     *
     * No es un error que bloquee el guardado: a veces el EPP es de verdad lo
     * único viable. Es un aviso para que el consultor lo justifique.
     */
    public function getSoloEppAttribute(): bool
    {
        if (blank($this->med_epp)) {
            return false;
        }

        foreach (array_slice(self::JERARQUIA, 0, -1) as $campo) {
            if (filled($this->{$campo})) {
                return false;
            }
        }

        return true;
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
