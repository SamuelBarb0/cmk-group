<?php

namespace App\Services\Reportes\Secciones;

use App\Models\SstDiagnostic;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use Carbon\Carbon;

/**
 * Autoevaluación de estándares mínimos (Res. 0312). Hay UNA por empresa y se
 * sobrescribe al guardar: el informe muestra la última, con su fecha.
 */
class Diagnostico extends Seccion
{
    public function clave(): string
    {
        return 'diagnostico';
    }

    public function titulo(): string
    {
        return 'Estándares mínimos (Res. 0312)';
    }

    public function modulo(): ?string
    {
        return 'diagnostico';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $d = SstDiagnostic::with('items.standard')->first();
        if (! $d || $d->items->isEmpty()) {
            $this->nota('La empresa aún no tiene autoevaluación de estándares mínimos.');

            return;
        }

        $puntaje = (float) $d->puntaje;
        $alerta = match (true) {
            $puntaje < 60 => 'Estándares mínimos en nivel crítico ('.self::porcentaje($puntaje).'): la Res. 0312 exige plan de mejoramiento inmediato.',
            $puntaje <= 85 => 'Estándares mínimos moderadamente aceptables ('.self::porcentaje($puntaje).'): requiere plan de mejoramiento.',
            default => null,
        };
        $this->cifra('Puntaje', self::porcentaje($puntaje), $alerta);
        $this->cifra('Valoración', $d->clasificacion);
        $this->cifra('Fecha de la autoevaluación', $d->fecha ? self::fecha(Carbon::parse($d->fecha)) : null);
        $this->cifra('Evaluador', $d->evaluador);

        $this->tabla('Resultado por ciclo PHVA', ['Ciclo', 'Estándares', 'Cumple', 'No cumple', 'Pendiente', 'No aplica'],
            $d->items->groupBy(fn ($i) => $i->standard?->ciclo ?? '—')->map(fn ($items, $ciclo) => [
                $ciclo, $items->count(),
                $items->where('estado', 'cumple')->count(),
                $items->where('estado', 'no_cumple')->count(),
                $items->where('estado', 'pendiente')->count(),
                $items->where('estado', 'no_aplica')->count(),
            ]),
            'Sin estándares evaluados.');

        $this->tabla('Estándares que no se cumplen', ['Código', 'Estándar'],
            $d->items->where('estado', 'no_cumple')->sortBy(fn ($i) => $i->standard?->orden)
                ->map(fn ($i) => [$i->standard?->codigo, self::corto($i->standard?->item, 120)]),
            'Todos los estándares evaluados se cumplen o no aplican.');
        $this->nota('Resultado de la última autoevaluación registrada; no depende del periodo del informe.');
    }
}
