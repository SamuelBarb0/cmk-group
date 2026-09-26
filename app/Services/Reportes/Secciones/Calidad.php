<?php

namespace App\Services\Reportes\Secciones;

use App\Models\CustomerRequest;
use App\Models\NonconformingOutput;
use App\Models\SatisfactionSurvey;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Calidad (ISO 9001) en el periodo: PQRS y su oportunidad de respuesta,
 * salidas no conformes y satisfacción del cliente. Es la entrada 9.3.2 c 1 y
 * c 3 de la revisión por la dirección.
 */
class Calidad extends Seccion
{
    public function clave(): string
    {
        return 'calidad';
    }

    public function titulo(): string
    {
        return 'Calidad: clientes y salidas no conformes';
    }

    public function modulo(): ?string
    {
        return 'calidad';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $rango = [$periodo->desde->toDateString(), $periodo->hasta->toDateString()];
        $pqrs = CustomerRequest::query()->whereBetween('fecha', $rango)->get();
        $respondidas = $pqrs->whereNotNull('fecha_respuesta');
        $aTiempo = $respondidas->where('a_tiempo', true)->count();
        $vencidas = CustomerRequest::query()->whereNull('fecha_respuesta')->get()->where('vencida', true);
        $salidas = NonconformingOutput::query()->whereBetween('fecha', $rango)->get();
        $encuestas = SatisfactionSurvey::resumen(SatisfactionSurvey::query()->whereBetween('fecha', $rango)->get());

        $this->cifra('PQRS recibidas', $pqrs->count());
        $this->cifra('Quejas y reclamos', $pqrs->whereIn('tipo', CustomerRequest::EVALUABLES)->count());
        $this->cifra('Respondidas a tiempo', $respondidas->isEmpty() ? '—' : round(100 * $aTiempo / $respondidas->count()).' %');
        $this->cifra('PQRS vencidas sin respuesta hoy', $vencidas->count(),
            $vencidas->count() ? 'Hay PQRS con el plazo de respuesta vencido.' : null);
        $this->cifra('Salidas no conformes', $salidas->count());
        $this->cifra('Detectadas por el cliente', $salidas->where('detectado_en', 'cliente')->count(),
            $salidas->where('detectado_en', 'cliente')->count() ? 'Salidas no conformes que llegaron al cliente: el control interno no las detectó.' : null);
        $this->cifra('Encuestas de satisfacción', $encuestas['n']);
        $this->cifra('Índice de satisfacción', $encuestas['indice'] === null ? '—' : $encuestas['indice'].' %',
            $encuestas['indice'] !== null && $encuestas['indice'] < SatisfactionSurvey::META
                ? 'El índice de satisfacción está por debajo del '.SatisfactionSurvey::META.' %.' : null);

        $this->tabla('PQRS por tipo', ['Tipo', 'Recibidas', 'Respondidas', 'Proceden'],
            collect(CustomerRequest::TIPOS)->map(fn ($etiqueta, $tipo) => [
                $etiqueta,
                $pqrs->where('tipo', $tipo)->count(),
                $pqrs->where('tipo', $tipo)->whereNotNull('fecha_respuesta')->count(),
                in_array($tipo, CustomerRequest::EVALUABLES, true) ? $pqrs->where('tipo', $tipo)->where('procede', true)->count() : '—',
            ])->filter(fn ($f) => $f[1] > 0)->values(),
            'Sin PQRS en el periodo.');

        $this->tabla('Satisfacción por aspecto (1 a 5)', ['Aspecto', 'Promedio'],
            $encuestas['n'] ? collect(SatisfactionSurvey::CRITERIOS)->map(fn ($l, $c) => [$l, $encuestas['criterios'][$c]])->values() : collect(),
            'Sin encuestas en el periodo.');
    }
}
