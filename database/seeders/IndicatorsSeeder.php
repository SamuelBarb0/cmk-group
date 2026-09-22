<?php

namespace Database\Seeders;

use App\Models\Indicator;
use Illuminate\Database\Seeder;

/**
 * Presets GLOBALES de indicadores (tenant_id = null → visibles para todos los
 * clientes). Las metas son valores por defecto, editables por el consultor.
 * Fórmula: (num/den) × constante.
 *
 * Dos bloques:
 *
 *  1. LEGALES — Resolución 0312 de 2019 / Decreto 1072 de 2015, art. 2.2.4.6.22.
 *     Son los que pide una visita del Ministerio y NO se tocan.
 *
 *  2. CMK — las 29 fichas del libro «DASHBOARD Indicadores para el SGI 2024 v3».
 *     Se cargaron todas las que no duplican a una legal.
 *
 * ⚠️ OJO CON LOS ÍNDICES DE ACCIDENTALIDAD. El dashboard de CMK calcula el
 * índice de frecuencia y el de severidad sobre el NÚMERO DE TRABAJADORES, no
 * sobre las horas-hombre trabajadas. La Resolución 0312 los define sobre HHT
 * con constante 240.000. No son la misma cifra y no se pueden comparar entre
 * empresas. Se cargan LOS DOS, con nombre distinto, para que el consultor elija
 * — pero hay que preguntarle a Cristian cuál quiere que sea el oficial, porque
 * tener los dos visibles a la vez confunde al cliente.
 */
class IndicatorsSeeder extends Seeder
{
    public function run(): void
    {
        // codigo, nombre, categoria, num, den, constante, unidad, sentido, meta
        $legales = [
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

        // Fichas del dashboard de CMK. El numerador y el denominador van
        // TEXTUALES como los escribe CMK: son los que el consultor reconoce del
        // Excel y los que sustentan la cifra ante el cliente.
        //
        // No se cargan tres del dashboard, a propósito:
        //  · IND1 (plan de trabajo), IND4 y IND5 (capacitaciones) → ya están
        //    arriba como legales, con la misma fórmula.
        //  · IND10 (accidentes mortales) → es PL-AT, misma fórmula.
        //  · ILI (índice de lesiones incapacitantes) → NO cabe en este modelo:
        //    se calcula IF × IS / 1000, o sea sobre otros DOS indicadores, no
        //    sobre un numerador y un denominador. Necesita un tipo «derivado»
        //    que hoy no existe. Queda pendiente.
        $cmk = [
            ['RED-AC', 'Reducción de actos y condiciones inseguras', 'SST',
                'Número de actos y condiciones peligrosas intervenidas', 'Número de actos y condiciones peligrosas reportadas', 100, '%', 'asc', 90],
            ['EFI-CAP', 'Eficacia de las capacitaciones', 'Proceso',
                'Número de evaluaciones eficaces', 'Número de personas evaluadas', 100, '%', 'asc', 90],
            ['CUMP-COCOLAB', 'Cumplimiento del plan de trabajo del COCOLAB', 'Proceso',
                'Número de actividades ejecutadas', 'Número de actividades programadas', 100, '%', 'asc', 90],
            ['GEST-PA', 'Gestión de planes de acción', 'Proceso',
                'Número de planes de acción ejecutados en un periodo', 'Número total de planes de acción en el periodo', 100, '%', 'asc', 90],
            ['CUMP-LEG', 'Cumplimiento de requisitos legales', 'Proceso',
                'Número de requisitos legales cumplidos', 'Número total de requisitos legales aplicables', 100, '%', 'asc', 100],
            ['CUMP-COPASST', 'Cumplimiento del plan de trabajo del COPASST', 'Proceso',
                'Número de actividades ejecutadas', 'Número de actividades programadas', 100, '%', 'asc', 90],

            // Los tres del «Programa de emergencias» (hojas 5.1.1 / 8.2). Los
            // alimenta el módulo de emergencias. El Excel fija como meta 1
            // «mínimo 2 simulacros al año»: como cifra absoluta no cabe en
            // num/den, así que se mide realizados sobre programados y la meta
            // de los dos al año la vigila la pantalla del módulo.
            ['CUMP-SIM', 'Cumplimiento de simulacros de emergencia', 'SST',
                'Número de simulacros realizados', 'Número de simulacros programados', 100, '%', 'asc', 100],
            ['REC-SIM', 'Recomendaciones de simulacros implementadas', 'SST',
                'Número de recomendaciones implementadas', 'Número total de recomendaciones de los simulacros', 100, '%', 'asc', 90],
            // El texto de la meta dice 80 %; la tabla del mismo Excel pone 90 %.
            // Se toma el texto, que es lo que firma la gerencia; es editable.
            ['PART-EMERG', 'Participación en simulacros y socializaciones del plan de emergencias', 'SST',
                'Número de participantes', 'Número de personas convocadas', 100, '%', 'asc', 80],

            // Las dos versiones «sobre trabajadores» del dashboard de CMK. Ver
            // la advertencia de la cabecera: no son las de la Resolución 0312.
            ['IF-TRAB', 'Índice de frecuencia sobre n.° de trabajadores (dashboard CMK)', 'SST',
                'Número de accidentes de trabajo en el último periodo', 'Total de trabajadores en el mismo periodo', 100, '%', 'desc', 0],
            ['IS-TRAB', 'Índice de severidad sobre n.° de trabajadores (dashboard CMK)', 'SST',
                'Número de días perdidos por A.T. + cargados en el último periodo', 'Total de trabajadores en el mismo periodo', 100, '%', 'desc', 0],
        ];

        // IND13 a IND29: diecisiete indicadores de cumplimiento, uno por
        // proceso. Comparten fórmula y se guardan por separado porque cada
        // proceso responde por su propia cifra ante la gerencia — que es como
        // CMK los lleva en el dashboard y como Samuel los confirmó.
        $procesos = [
            'Recursos, política y organización',
            'Gerencia',
            'HSEQ',
            'Mantenimiento',
            'Producción',
            'Facturación',
            'Compras',
            'Comunicaciones',
            'Logística',
            'Gestión social',
            'Cartera',
            'Archivo',
            'Almacén',
            'Licitaciones',
            'Gestión financiera',
            'Auditoría',
            'Mejora continua',
        ];

        foreach ($procesos as $etiqueta) {
            // El código sale del nombre del proceso y no del número de hoja del
            // Excel: «CUMP-PROC-04» no le dice nada a nadie dentro de seis meses.
            $cmk[] = [
                'CUMP-'.static::slug($etiqueta),
                'Cumplimiento de actividades — '.$etiqueta,
                'Proceso',
                'Número de actividades ejecutadas',
                'Número de actividades programadas',
                100, '%', 'asc', 90,
            ];
        }

        $presets = [...$legales, ...$cmk];

        foreach ($presets as $n => [$codigo, $nombre, $cat, $num, $den, $k, $unidad, $sentido, $meta]) {
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
                    // es_legal marca los exigidos por la Resolución 0312: son
                    // los que no se pueden desactivar ni borrar.
                    'es_legal' => $n < count($legales),
                    'orden' => $n + 1,
                ],
            );
        }

        $this->command?->info(
            'Indicadores: '.count($legales).' legales + '.count($cmk).' del dashboard de CMK = '
            .count($presets).' cargados.',
        );
    }

    /** Código corto, en mayúsculas y sin tildes, a partir del nombre del proceso. */
    protected static function slug(string $texto): string
    {
        $sinTildes = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        $palabras = preg_split('/[^A-Za-z]+/', $sinTildes, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Una sola palabra: los 6 primeros caracteres. Varias: la inicial de
        // cada una. Así «Mantenimiento» → MANTEN y «Gestión social» → GS.
        $corto = count($palabras) === 1
            ? substr($palabras[0], 0, 6)
            : implode('', array_map(static fn (string $p): string => $p[0], $palabras));

        return strtoupper($corto);
    }
}
