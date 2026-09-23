<?php

namespace App\Services\Reportes\Secciones;

use App\Models\Indicator;
use App\Models\IndicatorGoal;
use App\Models\IndicatorReading;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use App\Support\TenantContext;

/**
 * Indicadores del SG-SST con lecturas en el periodo.
 *
 * El valor del periodo se calcula COMO LA PANTALLA de Indicadores (promedio
 * de los meses con lectura), para que el informe y la plataforma den la
 * misma cifra. Meta: la de la empresa si la cambió; si no, la del indicador.
 */
class Indicadores extends Seccion
{
    public function clave(): string
    {
        return 'indicadores';
    }

    public function titulo(): string
    {
        return 'Indicadores';
    }

    public function modulo(): ?string
    {
        return 'indicadores';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $tenantId = app(TenantContext::class)->id();
        $indicadores = Indicator::where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('orden')->orderBy('id')->get();
        $metas = IndicatorGoal::pluck('meta', 'indicator_id');

        $lecturas = IndicatorReading::where(function ($q) use ($periodo) {
            foreach ($periodo->meses() as $anio => $meses) {
                $q->orWhere(fn ($q) => $q->where('anio', $anio)->whereIn('mes', $meses));
            }
        })->get()->groupBy('indicator_id');

        $filas = [];
        $cumplen = 0;
        $noCumplen = [];
        foreach ($indicadores as $ind) {
            $valores = collect($lecturas->get($ind->id, []))
                ->map(fn ($l) => $ind->calcular((float) $l->numerador, (float) $l->denominador))
                ->reject(fn ($v) => $v === null);
            if ($valores->isEmpty()) {
                continue;
            }
            $valor = round($valores->avg(), 2);
            $meta = $metas->has($ind->id) ? (float) $metas[$ind->id] : ($ind->meta !== null ? (float) $ind->meta : null);
            $cumple = $meta === null ? null : ($ind->sentido === 'asc' ? $valor >= $meta : $valor <= $meta);
            if ($cumple === true) {
                $cumplen++;
            } elseif ($cumple === false) {
                $noCumplen[] = $ind->codigo;
            }

            $filas[] = [
                $ind->codigo, self::corto($ind->nombre, 70), $this->valor($ind, $valor), $this->valor($ind, $meta),
                $cumple === null ? 'Sin meta' : ($cumple ? 'Cumple' : 'No cumple'), $valores->count(),
            ];
        }

        $this->cifra('Indicadores con medición', count($filas), $filas ? null : 'No hay lecturas de indicadores en el periodo.');
        $this->cifra('Cumplen la meta', $cumplen);
        $this->cifra('No cumplen la meta', count($noCumplen),
            $noCumplen ? count($noCumplen).' indicador(es) por fuera de la meta: '.implode(', ', $noCumplen).'.' : null);
        $this->cifra('Sin medir en el periodo', $indicadores->count() - count($filas));

        $this->tabla('Resultado del periodo', ['Código', 'Indicador', 'Resultado', 'Meta', 'Estado', 'Meses medidos'],
            $filas, 'Sin lecturas en el periodo.');
        $this->nota('El resultado es el promedio de los meses con lectura, igual que en la pantalla de Indicadores.');
    }

    private function valor(Indicator $ind, ?float $v): string
    {
        return $v === null ? '—' : ($ind->unidad === '%' ? self::porcentaje($v) : self::numero($v, 2));
    }
}
