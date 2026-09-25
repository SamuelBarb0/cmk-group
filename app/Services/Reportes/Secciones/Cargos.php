<?php

namespace App\Services\Reportes\Secciones;

use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use App\Services\Talento\MatrizCompetencias;

/**
 * Perfiles de cargo y competencia: foto actual. Lo que un auditor pregunta
 * de 7.2 es si cada cargo tiene definida su competencia y si la gente la
 * demuestra; las brechas de formación son la entrada del plan de capacitación.
 */
class Cargos extends Seccion
{
    public function __construct(private readonly MatrizCompetencias $matriz) {}

    public function clave(): string
    {
        return 'cargos';
    }

    public function titulo(): string
    {
        return 'Perfiles de cargo y competencias';
    }

    public function modulo(): ?string
    {
        return 'cargos';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $r = $this->matriz->resumen();
        $cargos = collect($r['cargos']);
        $sinRequisitos = $cargos->where('requisitos', 0)->count();
        $incompletos = $cargos->where('perfil_completo', false)->count();
        $celdas = $cargos->sum('celdas');
        $personasConBrecha = collect($r['brechas'])->flatMap(fn ($b) => collect($b['personas'])->pluck('id'))->unique()->count();

        $this->cifra('Cargos con perfil', $cargos->count());
        $this->cifra('Perfiles sin funciones o responsabilidades', $incompletos,
            $incompletos ? 'Hay cargos sin funciones o responsabilidades en el SIG definidas (5.3).' : null);
        $this->cifra('Cargos sin requisitos de competencia', $sinRequisitos,
            $sinRequisitos ? 'Hay cargos sin la competencia necesaria definida (7.2 a).' : null);
        $this->cifra('Trabajadores con cargo sin perfil', $r['sin_cargo'],
            $r['sin_cargo'] ? 'Hay trabajadores cuyo cargo no tiene perfil.' : null);
        $this->cifra('Cumplimiento de la matriz', $celdas ? round(100 * $cargos->sum('cumple') / $celdas).' %' : '—');
        $this->cifra('Personas con brechas de formación', $personasConBrecha);

        $this->tabla('Competencia por cargo', ['Cargo', 'Trabajadores', 'Requisitos', 'Cumplimiento'],
            $cargos->map(fn (array $c) => [
                $c['nombre'], $c['trabajadores'], $c['requisitos'], $c['porcentaje'] === null ? '—' : "{$c['porcentaje']} %",
            ]),
            'Sin cargos definidos.');

        $this->tabla('Necesidades de formación', ['Cargo', 'Requisito', 'Personas'],
            collect($r['brechas'])->map(fn (array $b) => [$b['cargo'], self::corto($b['requisito'], 70), count($b['personas'])]),
            'Sin brechas de formación.');
    }
}
