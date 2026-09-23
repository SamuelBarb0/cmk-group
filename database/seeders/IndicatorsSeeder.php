<?php

namespace Database\Seeders;

use App\Models\Indicator;
use Illuminate\Database\Seeder;

/**
 * Presets GLOBALES de indicadores (tenant_id = null → visibles para todos los
 * clientes). Las metas son valores por defecto, editables por el consultor.
 * Fórmula: (num/den) × constante.
 *
 * Tres bloques:
 *
 *  1. LEGALES — Resolución 0312 de 2019 / Decreto 1072 de 2015, art. 2.2.4.6.22.
 *     Son los que pide una visita del Ministerio y NO se tocan.
 *
 *  2. PESV — los mínimos de la Tabla 10 de la Res. 40595. Solo los ven las
 *     empresas con el módulo PESV contratado.
 *
 *  3. CMK — las 29 fichas del libro «DASHBOARD Indicadores para el SGI 2024 v3».
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

        // Indicadores MÍNIMOS del PESV: Tabla 10 del anexo de la Res. 40595
        // (texto en docs/normativa). La TSV se mide por nivel de pérdida, así
        // que son cuatro. K = 1.000.000 km: las copias en línea de la norma
        // dicen «5000.000», un error de transcripción; la guía de la ANSV y el
        // procedimiento del PASO 20 de CMK usan 1.000.000.
        // Quedan fuera los que NO son un cociente num/den y no caben en este
        // modelo: $SV (costos = suma de directos e indirectos) y RSVI / GRV
        // (diferencias de la matriz de riesgos viales). Llegan con esas piezas.
        // Metas: la norma no las fija; se usan las del PASO 20 de CMK y el
        // cliente las ajusta en su pantalla.
        $pesv = [
            ['PESV-TSV-FAT', 'Tasa de siniestros viales con fatalidades (TSV)', 'PESV',
                'N.° de siniestros viales con fatalidades en el trimestre', 'Kilómetros recorridos por toda la flota en el trimestre', 1000000, 'tasa', 'desc', 0],
            ['PESV-TSV-GRA', 'Tasa de siniestros viales con heridos graves (TSV)', 'PESV',
                'N.° de siniestros con heridos de más de 30 días de incapacidad', 'Kilómetros recorridos por toda la flota en el trimestre', 1000000, 'tasa', 'desc', 0],
            ['PESV-TSV-LEV', 'Tasa de siniestros viales con heridos leves (TSV)', 'PESV',
                'N.° de siniestros con heridos de hasta 30 días de incapacidad', 'Kilómetros recorridos por toda la flota en el trimestre', 1000000, 'tasa', 'desc', 0],
            ['PESV-TSV-CHS', 'Tasa de siniestros viales con choques simples (TSV)', 'PESV',
                'N.° de choques simples en el trimestre', 'Kilómetros recorridos por toda la flota en el trimestre', 1000000, 'tasa', 'desc', 0],
            ['PESV-CM', 'Cumplimiento de metas del PESV (CM PESV)', 'PESV',
                'N.° de metas del PESV alcanzadas en el trimestre', 'N.° total de metas del PESV definidas', 100, '%', 'asc', 100],
            ['PESV-CPLAN', 'Cumplimiento del plan anual de trabajo PESV', 'PESV',
                'N.° de actividades del plan PESV ejecutadas en el trimestre', 'N.° de actividades del plan PESV programadas', 100, '%', 'asc', 90],
            ['PESV-EJL', 'Exceso de jornada laboral de conductores (%EJL)', 'PESV',
                'N.° de excesos de la jornada diaria de conductores en el mes', 'Días trabajados por todos los conductores en el mes', 100, '%', 'desc', 0],
            ['PESV-GVE', 'Cobertura del programa de gestión de la velocidad (GVE) — estándar y avanzado', 'PESV',
                'N.° de vehículos incluidos en el programa de velocidad', 'N.° de vehículos usados en desplazamientos laborales', 100, '%', 'asc', 100],
            ['PESV-ELVL', 'Excesos del límite de velocidad laboral (ELVL) — avanzado', 'PESV',
                'N.° de desplazamientos con exceso de velocidad en el mes', 'N.° total de desplazamientos laborales en el mes', 100, '%', 'desc', 0],
            ['PESV-IDP', 'Inspecciones diarias preoperacionales (IDP)', 'PESV',
                'N.° de vehículos inspeccionados diariamente', 'N.° de vehículos que trabajan diariamente', 100, '%', 'asc', 100],
            ['PESV-CPMV', 'Cumplimiento del plan de mantenimiento preventivo de vehículos (CPMVh)', 'PESV',
                'N.° de mantenimientos preventivos ejecutados en el trimestre', 'N.° de mantenimientos preventivos programados', 100, '%', 'asc', 90],
            ['PESV-CPF', 'Cumplimiento del plan de formación en seguridad vial', 'PESV',
                'N.° de capacitaciones en seguridad vial ejecutadas en el trimestre', 'N.° de capacitaciones en seguridad vial programadas', 100, '%', 'asc', 90],
            ['PESV-COBF', 'Cobertura del plan de formación en seguridad vial', 'PESV',
                'N.° de colaboradores capacitados en seguridad vial', 'N.° total de colaboradores', 100, '%', 'asc', 90],
            ['PESV-NCAC', 'No conformidades de auditoría cerradas (NCAC)', 'PESV',
                'N.° de no conformidades gestionadas y cerradas', 'N.° de no conformidades identificadas y analizadas', 100, '%', 'asc', 100],
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

            // Estándar 3.1.4: evaluaciones médicas ocupacionales. Lo alimenta el
            // módulo de salud ocupacional (trabajadores con el examen al día).
            ['COB-EMO', 'Cobertura de exámenes médicos ocupacionales al día', 'SST',
                'Número de trabajadores con examen médico vigente', 'Número de trabajadores activos', 100, '%', 'asc', 100],

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

        $presets = [...$legales, ...$pesv, ...$cmk];

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
                    // es_legal marca los exigidos por norma (Res. 0312 y los
                    // mínimos del PESV de la Res. 40595): no se borran.
                    'es_legal' => $n < count($legales) + count($pesv),
                    'orden' => $n + 1,
                ],
            );
        }

        $this->command?->info(
            'Indicadores: '.count($legales).' legales + '.count($pesv).' mínimos del PESV + '.count($cmk).' del dashboard de CMK = '
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
