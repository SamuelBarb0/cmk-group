<?php

namespace App\Services\Reportes\Secciones;

use App\Models\IpercRow;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Matriz IPERC (GTC 45): perfil de riesgo a la fecha. */
class Iperc extends Seccion
{
    public function clave(): string
    {
        return 'iperc';
    }

    public function titulo(): string
    {
        return 'Identificación de peligros (IPERC)';
    }

    public function modulo(): ?string
    {
        return 'iperc';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $filas = IpercRow::all();
        if ($filas->isEmpty()) {
            $this->nota('La matriz IPERC está vacía.');

            return;
        }
        $noAceptables = $filas->whereIn('nivel_riesgo', ['I', 'II']);
        $soloEpp = $filas->filter(fn ($r) => $r->solo_epp)->count();

        $this->cifra('Peligros identificados', $filas->count());
        $this->cifra('Riesgos no aceptables (I y II)', $noAceptables->count(),
            $noAceptables->isNotEmpty() ? $noAceptables->count().' riesgo(s) no aceptable(s) en la matriz IPERC.' : null);
        $this->cifra('Controlados solo con EPP', $soloEpp,
            $soloEpp ? "{$soloEpp} peligro(s) controlado(s) solo con EPP, el último nivel de la jerarquía de controles." : null);
        $this->cifra('Trabajadores expuestos (suma)', (int) $filas->sum('expuestos'));

        $this->tabla('Por nivel de riesgo', ['Nivel', 'Peligros', 'Aceptabilidad'],
            collect(['I' => 'No aceptable', 'II' => 'No aceptable o aceptable con control específico', 'III' => 'Mejorable', 'IV' => 'Aceptable'])
                ->map(fn ($texto, $nivel) => [$nivel, $filas->where('nivel_riesgo', $nivel)->count(), $texto]),
            '');
        $this->tabla('Riesgos no aceptables', ['Proceso', 'Actividad', 'Peligro', 'Nivel'],
            $noAceptables->sortBy('nivel_riesgo')->map(fn ($r) => [self::corto($r->proceso, 40), self::corto($r->actividad, 50), self::corto($r->peligro, 60), $r->nivel_riesgo]),
            'No hay riesgos no aceptables.');
        $this->nota('Estado de la matriz a la fecha del informe.');
    }
}
