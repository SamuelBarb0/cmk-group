<?php

namespace App\Services\Reportes\Secciones;

use App\Models\PesvPlan;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use Carbon\Carbon;

/** Plan Estratégico de Seguridad Vial: avance, siniestros del periodo y documentos de la flota. */
class Pesv extends Seccion
{
    public function clave(): string
    {
        return 'pesv';
    }

    public function titulo(): string
    {
        return 'Seguridad vial (PESV)';
    }

    public function modulo(): ?string
    {
        return 'pesv';
    }

    public function permiso(): string
    {
        return 'pesv.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $plan = PesvPlan::latest('id')->first();
        $siniestros = $periodo->filtrar(PesvSiniestro::query(), 'fecha')->with('vehiculo:id,placa')->orderBy('fecha')->get();
        $fatales = $siniestros->where('gravedad', 'fatal')->count();
        $flota = PesvVehicle::where('is_active', true)->get();
        $vencidos = $flota->map(fn ($v) => ['v' => $v, 'a' => collect($v->alertas(0))->where('dias', '<', 0)])->filter(fn ($x) => $x['a']->isNotEmpty());

        $this->cifra('Nivel del PESV', $plan ? self::etiqueta($plan->nivel) : 'Sin plan');
        $this->cifra('Avance de los pasos', $plan ? self::porcentaje((float) $plan->avance) : '—');
        $this->cifra('Siniestros viales', $siniestros->count());
        $this->cifra('Siniestros con heridos', $siniestros->where('gravedad', 'con_heridos')->count());
        $this->cifra('Siniestros fatales', $fatales, $fatales ? "{$fatales} siniestro(s) vial(es) fatal(es) en el periodo." : null);
        $this->cifra('Vehículos con documentos vencidos', $vencidos->count(),
            $vencidos->isNotEmpty() ? $vencidos->count().' vehículo(s) con SOAT, tecnomecánica o póliza vencidos.' : null);

        $this->tabla('Siniestros del periodo', ['Fecha', 'Tipo', 'Gravedad', 'Vehículo', 'Días de incapacidad'],
            $siniestros->map(fn ($s) => [self::fecha($s->fecha), self::etiqueta($s->tipo), self::etiqueta($s->gravedad), $s->vehiculo?->placa ?? '—', $s->dias_incapacidad ?? '—']),
            'Sin siniestros viales en el periodo.');
        $this->tabla('Vehículos con documentos vencidos', ['Placa', 'Documento', 'Venció'],
            $vencidos->flatMap(fn ($x) => $x['a']->map(fn ($a) => [$x['v']->placa, $a['documento'], self::fecha(Carbon::parse($a['vence']))])),
            'Todos los documentos de la flota están al día.');
    }
}
