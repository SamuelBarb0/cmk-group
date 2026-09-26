<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entrega de residuos a un gestor (ISO 14001 8.1). Segregado por tenant.
 *
 * Con los peligrosos se calcula la categoría de generador RESPEL: Decreto
 * 1076 de 2015, art. 2.2.6.1.6.2 (antes Dec. 4741 de 2005, art. 28), con la
 * media móvil de los últimos seis meses. Desde 10 kg/mes el generador debe
 * inscribirse en el Registro de Generadores de RESPEL del IDEAM ante su
 * autoridad ambiental (Res. 1362 de 2007).
 */
class WasteRecord extends Model
{
    use BelongsToTenant;

    // `archivo` y `archivo_nombre` los pone el controlador al guardar el certificado.
    protected $fillable = [
        'fecha', 'tipo', 'corriente', 'cantidad_kg', 'gestor', 'licencia_gestor', 'disposicion', 'certificado', 'observaciones',
    ];

    protected $hidden = ['archivo'];

    protected $appends = ['tiene_archivo'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'cantidad_kg' => 'float',
        ];
    }

    public const TIPOS = [
        'aprovechable' => 'Aprovechable (reciclable)',
        'organico' => 'Orgánico aprovechable',
        'ordinario' => 'Ordinario (no aprovechable)',
        'peligroso' => 'Peligroso (RESPEL)',
        'raee' => 'RAEE (aparatos eléctricos y electrónicos)',
        'especial' => 'Especial (escombros, voluminosos)',
    ];

    public const DISPOSICIONES = [
        'aprovechamiento' => 'Aprovechamiento o reciclaje',
        'posconsumo' => 'Programa posconsumo',
        'tratamiento' => 'Tratamiento',
        'incineracion' => 'Incineración',
        'celda_seguridad' => 'Celda de seguridad',
        'relleno' => 'Relleno sanitario',
    ];

    /** Tipos cuya entrega exige certificado de disposición del gestor. */
    public const CON_CERTIFICADO = ['peligroso', 'raee'];

    /** Destinos que cuentan como aprovechamiento para el indicador. */
    public const APROVECHADOS = ['aprovechamiento', 'posconsumo'];

    /** Umbrales de la categoría de generador (kg/mes, media móvil de 6 meses). */
    public const CATEGORIAS_RESPEL = [
        'grande' => 1000,
        'mediano' => 100,
        'pequeno' => 10,
    ];

    public function getTieneArchivoAttribute(): bool
    {
        return filled($this->archivo);
    }

    /** Peligroso o RAEE entregado sin certificado de disposición. */
    public function sinCertificado(): bool
    {
        return in_array($this->tipo, self::CON_CERTIFICADO, true) && blank($this->certificado) && ! $this->tiene_archivo;
    }

    /**
     * Categoría de generador de RESPEL con la media móvil de los seis meses
     * que terminan en `$hasta` (el mes de `$hasta` incluido).
     *
     * @return array{media_kg: float, categoria: string, registro_obligatorio: bool, desde: string, hasta: string}
     */
    public static function categoriaRespel(?CarbonInterface $hasta = null): array
    {
        $hasta = Carbon::parse($hasta ?? Carbon::today())->endOfMonth();
        $desde = $hasta->copy()->startOfMonth()->subMonths(5);
        $total = (float) self::query()->where('tipo', 'peligroso')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->sum('cantidad_kg');
        $media = round($total / 6, 2);

        $categoria = 'no_obligado';
        foreach (self::CATEGORIAS_RESPEL as $nombre => $minimo) {
            if ($media >= $minimo) {
                $categoria = $nombre;
                break;
            }
        }

        return [
            'media_kg' => $media,
            'categoria' => $categoria,
            'registro_obligatorio' => $categoria !== 'no_obligado',
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
        ];
    }

    public const ETIQUETA_CATEGORIA = [
        'grande' => 'Gran generador (1.000 kg/mes o más)',
        'mediano' => 'Mediano generador (100 a menos de 1.000 kg/mes)',
        'pequeno' => 'Pequeño generador (10 a menos de 100 kg/mes)',
        'no_obligado' => 'Menos de 10 kg/mes: no obligado a inscribirse',
    ];
}
