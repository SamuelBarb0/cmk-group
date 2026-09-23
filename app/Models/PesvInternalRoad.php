<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Vía o zona interna administrada por la empresa (paso 14): zonas de
 * conflicto de tránsito (hoja «14. RUTAS INTERNAS DE CONFLIC.») y su
 * cronograma de mantenimiento (RE-SST-68 «Cronograma de mtto. a vías»).
 */
class PesvInternalRoad extends Model
{
    use BelongsToTenant;

    /** Actividades del cronograma de mantenimiento de vías de CMK. */
    public const ACTIVIDADES = [
        'Mantenimiento rutinario', 'Mantenimiento periódico', 'Mantenimiento preventivo', 'Obras de drenaje y subdrenaje',
        'Drenaje superficial', 'Mantenimiento a zanjas', 'Mantenimiento a alcantarillas', 'Mantenimiento a subdrenajes y filtros',
        'Mantenimiento y limpieza de la señalización vial', 'Mantenimiento a elementos de seguridad vial',
        'Limpieza de canales y aliviaderos', 'Limpieza de cauces',
    ];

    protected $fillable = [
        'nombre', 'descripcion', 'riesgos_criticos', 'km', 'tiempo_min', 'frecuente', 'veces_mes', 'plan_accion',
        'anio_cronograma', 'cronograma', 'activa',
    ];

    protected function casts(): array
    {
        return ['km' => 'decimal:2', 'frecuente' => 'boolean', 'activa' => 'boolean', 'cronograma' => 'array', 'anio_cronograma' => 'integer'];
    }

    /**
     * Cumplimiento del cronograma: meses ejecutados que estaban programados,
     * sobre los programados (la misma regla que el plan de trabajo).
     *
     * @return array{programados: int, ejecutados: int, porcentaje: ?float}
     */
    public function cumplimiento(?int $hastaMes = null): array
    {
        $p = 0;
        $e = 0;
        foreach ($this->cronograma ?? [] as $fila) {
            $prog = array_filter($fila['programados'] ?? [], fn ($m) => $hastaMes === null || $m <= $hastaMes);
            $p += count($prog);
            $e += count(array_intersect($prog, $fila['ejecutados'] ?? []));
        }

        return ['programados' => $p, 'ejecutados' => $e, 'porcentaje' => $p ? round($e * 100 / $p, 1) : null];
    }
}
