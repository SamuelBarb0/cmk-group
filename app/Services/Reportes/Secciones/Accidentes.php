<?php

namespace App\Services\Reportes\Secciones;

use App\Models\WorkAccident;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Accidentalidad del periodo e investigación (Res. 1401). */
class Accidentes extends Seccion
{
    public function clave(): string
    {
        return 'accidentes';
    }

    public function titulo(): string
    {
        return 'Accidentalidad';
    }

    public function modulo(): ?string
    {
        return 'accidentes';
    }

    public function permiso(): string
    {
        return 'incidents.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $eventos = WorkAccident::with(['employee:id,nombres,apellidos', 'ausencia:id,dias'])
            ->enPeriodo($periodo->desde->toDateString(), $periodo->hasta->toDateString())
            ->orderBy('fecha')->get();
        $accidentes = $eventos->where('clase', 'accidente');

        $mortales = $eventos->where('mortal', true)->count();
        $vencidas = $eventos->filter(fn ($e) => $e->investigacion_vencida)->count();
        $sinArl = $accidentes->where('reportado_arl', false)->count();

        $this->cifra('Accidentes de trabajo', $accidentes->count());
        $this->cifra('Incidentes', $eventos->where('clase', 'incidente')->count());
        $this->cifra('Casi accidentes', $eventos->where('clase', 'casi_accidente')->count());
        $this->cifra('Accidentes mortales', $mortales, $mortales ? "{$mortales} accidente(s) mortal(es) en el periodo." : null);
        $this->cifra('Accidentes graves', $eventos->where('grave', true)->count());
        $this->cifra('Días perdidos', (int) $accidentes->sum(fn ($e) => $e->ausencia?->dias ?? 0));
        $this->cifra('Sin reportar a la ARL', $sinArl,
            $sinArl ? "{$sinArl} accidente(s) sin reporte a la ARL (plazo: ".WorkAccident::DIAS_REPORTE_ARL.' días hábiles).' : null);
        $this->cifra('Investigación vencida', $vencidas,
            $vencidas ? "{$vencidas} evento(s) sin investigar pasados los ".WorkAccident::DIAS_INVESTIGACION.' días de la Res. 1401.' : null);

        $this->tabla('Eventos del periodo', ['Fecha', 'Código', 'Clase', 'Trabajador', 'Lesión', 'Días perdidos', 'Investigado'],
            $eventos->map(fn ($e) => [
                self::fecha($e->fecha), $e->codigo, self::etiqueta($e->clase),
                $e->employee ? trim("{$e->employee->nombres} {$e->employee->apellidos}") : '—',
                self::corto($e->tipo_lesion, 40), $e->ausencia?->dias ?? '—', (bool) $e->investigado,
            ]),
            'Sin accidentes ni incidentes registrados en el periodo.');
    }
}
