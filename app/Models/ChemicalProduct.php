<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Producto químico del inventario, con sus pictogramas SGA (Res. 773 de 2021)
 * y la fecha de su hoja de datos de seguridad. Segregado por tenant.
 */
class ChemicalProduct extends Model
{
    use BelongsToTenant;

    protected $fillable = ['nombre', 'proveedor', 'uso', 'cantidad', 'ubicacion', 'peligros', 'hds_fecha', 'epp', 'activo', 'observaciones'];

    protected function casts(): array
    {
        return [
            'peligros' => 'array',
            'hds_fecha' => 'date:Y-m-d',
            'activo' => 'boolean',
        ];
    }

    protected $appends = ['estado_hds'];

    public const PICTOGRAMAS = [
        'GHS01' => 'Explosivo',
        'GHS02' => 'Inflamable',
        'GHS03' => 'Comburente',
        'GHS04' => 'Gas a presión',
        'GHS05' => 'Corrosivo',
        'GHS06' => 'Toxicidad aguda',
        'GHS07' => 'Nocivo o irritante',
        'GHS08' => 'Peligro para la salud',
        'GHS09' => 'Peligro para el medio ambiente',
    ];

    /**
     * Criterio de la plataforma para pedir una HDS nueva al proveedor: una de
     * más de 5 años casi seguro no refleja la clasificación vigente.
     */
    public const HDS_ANIOS = 5;

    /**
     * Reglas de almacenamiento entre clases de peligro. Es una guía
     * ORIENTATIVA: la decisión se toma con las secciones 7 y 10 de cada HDS
     * (un ácido y una base comparten el pictograma de corrosivo y no se
     * guardan juntos). Los explosivos no están aquí: van aparte de todo lo
     * demás (ver `regla`).
     *
     * @var array<string, array<string, 'incompatible'|'separar'>>
     */
    public const REGLAS = [
        'GHS02' => ['GHS03' => 'incompatible', 'GHS04' => 'separar', 'GHS05' => 'separar', 'GHS06' => 'separar'],
        'GHS03' => ['GHS04' => 'separar', 'GHS05' => 'separar', 'GHS06' => 'separar'],
    ];

    /** sin_hds / desactualizada / vigente */
    public function getEstadoHdsAttribute(): string
    {
        if (! $this->hds_fecha) {
            return 'sin_hds';
        }

        return $this->hds_fecha->lt(Carbon::today()->subYears(self::HDS_ANIOS)) ? 'desactualizada' : 'vigente';
    }

    /** Resultado de guardar juntos dos productos: compatible / separar / incompatible. */
    public static function compatibilidad(self $a, self $b): string
    {
        $peor = 'compatible';
        foreach ($a->peligros ?? [] as $x) {
            foreach ($b->peligros ?? [] as $y) {
                $r = self::regla($x, $y);
                if ($r === 'incompatible') {
                    return 'incompatible';
                }
                if ($r === 'separar') {
                    $peor = 'separar';
                }
            }
        }

        return $peor;
    }

    private static function regla(string $x, string $y): ?string
    {
        // Un explosivo va aparte de todo lo que no sea explosivo.
        if (($x === 'GHS01') !== ($y === 'GHS01')) {
            return 'incompatible';
        }

        return self::REGLAS[$x][$y] ?? self::REGLAS[$y][$x] ?? null;
    }

    /**
     * Matriz de todos contra todos de los productos activos.
     *
     * @param  Collection<int, ChemicalProduct>  $productos
     * @return array<string, string> «idA-idB» => resultado, solo pares distintos
     */
    public static function matriz(Collection $productos): array
    {
        $m = [];
        foreach ($productos as $a) {
            foreach ($productos as $b) {
                if ($a->id < $b->id) {
                    $m["{$a->id}-{$b->id}"] = self::compatibilidad($a, $b);
                }
            }
        }

        return $m;
    }
}
