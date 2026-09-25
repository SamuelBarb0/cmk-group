<?php

namespace App\Services\Reportes\Secciones;

use App\Models\EnvironmentalAspect;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Aspectos e impactos ambientales: foto actual de la matriz y sus significativos. */
class AspectosAmbientales extends Seccion
{
    public function clave(): string
    {
        return 'aspectos-ambientales';
    }

    public function titulo(): string
    {
        return 'Aspectos ambientales';
    }

    public function modulo(): ?string
    {
        return 'aspectos-ambientales';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $aspectos = EnvironmentalAspect::query()->get();
        $significativos = $aspectos->where('significativo', true);
        $sinControl = $significativos->filter(fn ($a) => blank($a->controles));

        $this->cifra('Aspectos identificados', $aspectos->count());
        $this->cifra('Significativos', $significativos->count());
        $this->cifra('En condición de emergencia', $aspectos->where('condicion', 'emergencia')->count());
        $this->cifra('Con requisito legal', $aspectos->where('requisito_legal', true)->count());
        $this->cifra('Significativos sin controles', $sinControl->count(),
            $sinControl->count() ? 'Hay aspectos significativos sin controles operacionales definidos.' : null);

        $this->tabla('Aspectos significativos', ['Aspecto', 'Impacto', 'Condición', 'F × S', 'Controles'],
            $significativos->sortByDesc('valor')->map(fn (EnvironmentalAspect $a) => [
                self::corto($a->aspecto, 60), self::corto($a->impacto, 50), ucfirst($a->condicion),
                "{$a->frecuencia} × {$a->severidad}", self::corto($a->controles ?: 'Sin controles', 60),
            ]),
            'Sin aspectos significativos.');
    }
}
