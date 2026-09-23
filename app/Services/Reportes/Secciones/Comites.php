<?php

namespace App\Services\Reportes\Secciones;

use App\Models\Committee;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** COPASST y comité de convivencia: vigencia, composición y actividades del periodo. */
class Comites extends Seccion
{
    private const NOMBRES = ['copasst' => 'COPASST', 'cocolab' => 'Comité de convivencia laboral'];

    public function clave(): string
    {
        return 'comites';
    }

    public function titulo(): string
    {
        return 'Comités';
    }

    public function modulo(): ?string
    {
        return 'comites';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        // El vigente de cada tipo: el de conformación más reciente.
        $comites = Committee::with(['members', 'activities'])->orderByDesc('fecha_conformacion')->get()->unique('tipo');

        foreach (Committee::TIPOS as $tipo) {
            $c = $comites->firstWhere('tipo', $tipo);
            $nombre = self::NOMBRES[$tipo];
            if (! $c) {
                $this->cifra($nombre, 'Sin conformar', "El {$nombre} no está conformado.");

                continue;
            }
            $this->cifra($nombre, $c->vencido ? 'Vencido' : 'Vigente hasta '.self::fecha($c->fecha_vencimiento),
                $c->vencido ? "El {$nombre} está vencido desde el ".self::fecha($c->fecha_vencimiento).'.' : null);
            if (! $c->composicion_correcta) {
                $this->cifra("Composición del {$nombre}", 'Incompleta', "El {$nombre} no tiene la composición paritaria que exige la norma.");
            }
        }

        $this->tabla('Actividades del periodo', ['Comité', 'Periodo', 'Programadas', 'Ejecutadas en el periodo', 'Cumplimiento anual'],
            $comites->map(function ($c) use ($periodo) {
                $programadas = $c->activities->where('programada', true);
                $ejecutadas = $programadas->where('ejecutada', true);
                $enPeriodo = $ejecutadas->filter(fn ($a) => $a->fecha_ejecucion
                    && $a->fecha_ejecucion->betweenIncluded($periodo->desde, $periodo->hasta));

                return [self::NOMBRES[$c->tipo] ?? $c->tipo, $c->periodo, $programadas->count(), $enPeriodo->count(),
                    self::porcentaje(self::pct($ejecutadas->count(), $programadas->count()))];
            }),
            'Sin comités registrados.');
    }
}
