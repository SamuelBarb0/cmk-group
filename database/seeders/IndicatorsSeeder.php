<?php

namespace Database\Seeders;

use App\Models\Indicator;
use Illuminate\Database\Seeder;

/**
 * Presets GLOBALES de indicadores obligatorios del SG-SST
 * (Resolución 0312 de 2019 / Decreto 1072 de 2015, art. 2.2.4.6.22).
 * tenant_id = null → visibles para todos los clientes. Las metas son valores
 * por defecto (editables por el consultor). Fórmula: (num/den) × constante.
 */
class IndicatorsSeeder extends Seeder
{
    public function run(): void
    {
        // codigo, nombre, categoria, num, den, constante, unidad, sentido, meta
        $presets = [
            ['IF-AT', 'Índice de frecuencia de accidentes de trabajo', 'SST',
                'N.° de accidentes de trabajo en el periodo', 'Horas-hombre trabajadas (HHT)', 240000, 'tasa', 'desc', 5],
            ['IS-AT', 'Índice de severidad de accidentes de trabajo', 'SST',
                'N.° de días perdidos y cargados por AT', 'Horas-hombre trabajadas (HHT)', 240000, 'tasa', 'desc', 50],
            ['PL-AT', 'Proporción de letalidad de accidentes de trabajo', 'SST',
                'N.° de AT mortales en el periodo', 'N.° total de AT en el periodo', 100, '%', 'desc', 0],
            ['PREV-EL', 'Prevalencia de la enfermedad laboral', 'SST',
                'N.° de casos de enfermedad laboral', 'Promedio de trabajadores en el periodo', 100000, 'tasa', 'desc', 0],
            ['INC-EL', 'Incidencia de la enfermedad laboral', 'SST',
                'N.° de casos nuevos de enfermedad laboral', 'Promedio de trabajadores en el periodo', 100000, 'tasa', 'desc', 0],
            ['AUS-CM', 'Ausentismo por causa médica', 'SST',
                'N.° de días de ausencia por causa médica', 'N.° de días programados de trabajo', 100, '%', 'desc', 5],
            ['CUMP-PT', 'Cumplimiento del plan de trabajo anual', 'Proceso',
                'N.° de actividades ejecutadas del plan', 'N.° de actividades programadas del plan', 100, '%', 'asc', 90],
            ['COB-CAP', 'Cobertura de capacitación', 'Proceso',
                'N.° de trabajadores capacitados', 'N.° total de trabajadores', 100, '%', 'asc', 90],
            ['EJE-CAP', 'Ejecución del cronograma de capacitaciones', 'Proceso',
                'N.° de capacitaciones realizadas', 'N.° de capacitaciones programadas', 100, '%', 'asc', 90],
        ];

        foreach ($presets as $i => [$codigo, $nombre, $cat, $num, $den, $k, $unidad, $sentido, $meta]) {
            Indicator::updateOrCreate(
                ['codigo' => $codigo, 'tenant_id' => null],
                [
                    'nombre' => $nombre,
                    'categoria' => $cat,
                    'numerador_label' => $num,
                    'denominador_label' => $den,
                    'constante' => $k,
                    'unidad' => $unidad,
                    'sentido' => $sentido,
                    'meta' => $meta,
                    'es_legal' => true,
                    'orden' => $i + 1,
                ],
            );
        }

        $this->command?->info('Indicadores legales del SG-SST: '.count($presets).' cargados.');
    }
}
