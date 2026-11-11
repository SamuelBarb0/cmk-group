<?php

namespace App\Services\Indicadores;

use App\Models\Indicator;
use App\Models\IndicatorGoal;
use App\Models\IndicatorReading;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * Los indicadores de la empresa activa en un año: lecturas mes a mes, el
 * resultado acumulado y si cumple su meta.
 *
 * FUENTE ÚNICA del cálculo para la pantalla de Indicadores y el dashboard:
 * si cada una lo calculara por su lado, tarde o temprano el panel diría
 * «cumple» donde la pantalla dice «no cumple».
 *
 * Espera el TenantContext de la empresa ya puesto (las lecturas y las metas
 * propias se filtran por él).
 */
class Tablero
{
    /** Los mínimos de la Res. 0312 que van siempre arriba en el dashboard. */
    public const MINIMOS_0312 = ['IF-AT', 'IS-AT', 'PL-AT', 'PREV-EL', 'INC-EL', 'AUS-CM'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Un registro por indicador, en el orden del catálogo.
     *
     * El resultado es el PROMEDIO de los meses con lectura (un mes sin lectura
     * no cuenta como cero). La meta es la propia del cliente si la fijó; si
     * no, la legal.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function indicadores(int $anio): Collection
    {
        $tenantId = $this->context->id();

        $indicators = Indicator::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            // Los mínimos del PESV solo le sirven a quien tiene PESV contratado.
            ->when(! $this->context->get()?->moduloHabilitado('pesv'), fn ($q) => $q->where('categoria', '!=', 'PESV'))
            ->orderBy('orden')->orderBy('id')->get();

        $readings = IndicatorReading::where('anio', $anio)->get()
            ->groupBy('indicator_id');

        // Metas propias del cliente para los presets (el scope filtra por tenant).
        $metasPropias = IndicatorGoal::pluck('meta', 'indicator_id');

        return $indicators->map(function (Indicator $ind) use ($readings, $metasPropias) {
            $meses = [];
            $suma = 0;
            $conteo = 0;

            $porMes = ($readings->get($ind->id) ?? collect())->keyBy('mes');
            for ($m = 1; $m <= 12; $m++) {
                $r = $porMes->get($m);
                $num = $r ? (float) $r->numerador : 0.0;
                $den = $r ? (float) $r->denominador : 0.0;
                $valor = $r ? $ind->calcular($num, $den) : null;
                if ($valor !== null) {
                    $suma += $valor;
                    $conteo++;
                }
                $meses[$m] = ['numerador' => $num, 'denominador' => $den, 'valor' => $valor];
            }

            $promedio = $conteo > 0 ? round($suma / $conteo, 2) : null;

            // Meta efectiva: la propia del cliente si existe; si no, la legal.
            $metaLegal = (float) $ind->meta;
            $metaPropia = $metasPropias->has($ind->id);
            $meta = $metaPropia ? (float) $metasPropias->get($ind->id) : $metaLegal;
            $cumple = $promedio === null ? null : ($ind->sentido === 'asc' ? $promedio >= $meta : $promedio <= $meta);

            return [
                'id' => $ind->id,
                'codigo' => $ind->codigo,
                'nombre' => $ind->nombre,
                'categoria' => $ind->categoria,
                'numerador_label' => $ind->numerador_label,
                'denominador_label' => $ind->denominador_label,
                'constante' => $ind->constante,
                'unidad' => $ind->unidad,
                'sentido' => $ind->sentido,
                'meta' => $meta,
                'meta_legal' => $metaLegal,
                'meta_propia' => $metaPropia,
                'es_legal' => $ind->es_legal,
                'propio' => $ind->tenant_id !== null,
                'meses' => $meses,
                'promedio' => $promedio,
                'cumple' => $cumple,
            ];
        });
    }

    /**
     * Ids de los indicadores que tienen lectura en un mes dado: con esto se
     * avisa del mes que se dejó sin reportar.
     *
     * @return list<int>
     */
    public function conLecturaEn(int $anio, int $mes): array
    {
        return IndicatorReading::where('anio', $anio)->where('mes', $mes)
            ->pluck('indicator_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
