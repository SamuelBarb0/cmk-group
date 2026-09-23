<?php

namespace App\Services\Reportes\Secciones;

use App\Models\Absence;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Ausentismo del periodo, solo agregado por tipo. El informe se entrega a la
 * gerencia: no lleva nombres ni diagnósticos.
 */
class Ausentismo extends Seccion
{
    public function clave(): string
    {
        return 'ausentismo';
    }

    public function titulo(): string
    {
        return 'Ausentismo';
    }

    public function modulo(): ?string
    {
        return 'ausentismo';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $ausencias = Absence::enPeriodo($periodo->desde->toDateString(), $periodo->hasta->toDateString())
            ->get(['id', 'employee_id', 'tipo', 'dias']);
        $medicas = $ausencias->whereIn('tipo', Absence::CAUSA_MEDICA);

        $this->cifra('Registros', $ausencias->count());
        $this->cifra('Días de ausencia', (int) $ausencias->sum('dias'));
        $this->cifra('Días por causa médica', (int) $medicas->sum('dias'));
        $this->cifra('Días por accidente de trabajo', (int) $ausencias->where('tipo', 'accidente_trabajo')->sum('dias'));
        $this->cifra('Casos de enfermedad laboral', $ausencias->where('tipo', 'enfermedad_laboral')->count());
        $this->cifra('Trabajadores con ausencias', $ausencias->pluck('employee_id')->unique()->count());

        $this->tabla('Ausentismo por tipo', ['Tipo', 'Registros', 'Días', 'Causa médica'],
            collect(Absence::TIPOS)->map(fn ($tipo) => [
                self::etiqueta($tipo),
                $ausencias->where('tipo', $tipo)->count(),
                (int) $ausencias->where('tipo', $tipo)->sum('dias'),
                in_array($tipo, Absence::CAUSA_MEDICA, true),
            ])->filter(fn ($f) => $f[1] > 0),
            'Sin ausencias registradas en el periodo.');
        $this->nota('Se cuentan las ausencias que INICIAN en el periodo, como en las hojas de CMK. Sin nombres ni diagnósticos.');
    }
}
