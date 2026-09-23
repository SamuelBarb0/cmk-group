<?php

namespace Database\Seeders;

use App\Models\PesvCriterion;
use App\Models\PesvStep;
use Illuminate\Database\Seeder;

/**
 * Lista de verificación OFICIAL del PESV: Tabla 16 del anexo de la
 * Res. 40595 de 2022 (texto completo en docs/normativa). Es la que usan las
 * autoridades en la visita de verificación, pregunta por pregunta, con
 * respuesta Cumple / No cumple / No aplica / No verificado.
 *
 * Transcripción literal salvo errores evidentes de digitación de la fuente
 * («arto» → «año», «ivel» → «nivel», ««n» → «en»), y dos arreglos de
 * numeración: la fuente repite «19.2» (la segunda es la 19.3) y deja sin
 * número las únicas preguntas de los pasos 9 y 15 (aquí 9.1 y 15.1). La 21.3
 * no trae nivel en la fuente; se le da el del paso (avanzado).
 *
 * Catálogo GLOBAL, como los 24 pasos. Idempotente por código.
 */
class PesvCriteriaSeeder extends Seeder
{
    private const TODOS = ['basico', 'estandar', 'avanzado'];

    private const EST_AV = ['estandar', 'avanzado'];

    private const AV = ['avanzado'];

    public function run(): void
    {
        $pasos = PesvStep::pluck('id', 'numero');

        foreach (self::criterios() as $orden => [$codigo, $niveles, $pregunta]) {
            $paso = (int) explode('.', $codigo)[0];
            PesvCriterion::updateOrCreate(['codigo' => $codigo], [
                'pesv_step_id' => $pasos[$paso],
                'pregunta' => $pregunta,
                'niveles' => $niveles,
                'orden' => $orden + 1,
            ]);
        }
    }

    /** @return list<array{0: string, 1: list<string>, 2: string}> */
    public static function criterios(): array
    {
        $T = self::TODOS;
        $E = self::EST_AV;
        $A = self::AV;

        return [
            ['1.1', $T, '¿Se tiene designada una persona con poder de decisión en los temas relacionados con la gestión de seguridad vial, para que lidere el diseño e implementación del PESV y lo articule con el SG-SST?'],
            ['1.2', $T, 'El líder del diseño e implementación del PESV es el responsable de diligenciar el reporte de autogestión anual y los resultados de la medición de los indicadores del Plan Estratégico de Seguridad Vial.'],

            ['2.1', $E, '¿El nivel directivo designó los miembros del Comité de Seguridad Vial (CSV)? ¿Este comité está conformado por al menos tres (3) personas con poder de decisión (incluyendo al líder del diseño e implementación del PESV; se recomienda número impar de participantes)?'],
            ['2.2', $E, 'En caso de que el Comité de Seguridad Vial (CSV) esté integrado con el COPASST, ¿cumple los requisitos definidos en la normatividad vigente en materia de Seguridad y Salud en el Trabajo?'],
            ['2.3', $E, '¿El Comité de Seguridad Vial (CSV) cumple con las responsabilidades y funciones del paso 2?'],

            ['3.1', $T, '¿Se cuenta con Política de Seguridad Vial documentada con alcance sobre los desplazamientos laborales y los trayectos in itinere para todos los colaboradores de la organización?'],
            ['3.2', $T, '¿La Política de Seguridad Vial documentada cumple con los requisitos definidos en el paso 3?'],

            ['4.1', $T, '¿El nivel directivo demuestra liderazgo, compromiso y corresponsabilidad? ¿Se cumplen los requisitos definidos en el paso 4?'],

            ['5.1', $T, '¿La organización definió la línea base para identificar los problemas de seguridad vial, conforme con los requisitos definidos en el paso 21 de la guía metodológica del PESV?'],
            ['5.2', $T, '¿El diagnóstico del PESV contiene al menos los requisitos definidos en el paso 5 de la Metodología del PESV?'],
            ['5.3', $T, 'En caso de que aplique, ¿el diagnóstico del PESV se actualiza al menos una vez al año?'],

            ['6.1', $T, '¿Se tiene definido y aplicado un procedimiento de evaluación y control de riesgos en seguridad vial, y contiene al menos los requisitos definidos en el paso 6?'],
            ['6.2', $T, '¿La organización cuenta con una herramienta para la evaluación y control de los riesgos en seguridad vial, y se actualiza como mínimo una (1) vez al año y/o cada vez que ocurra un siniestro vial?'],

            ['7.1', $T, '¿Están definidos los objetivos y metas del PESV, enfocados a la prevención en seguridad vial, y son claros, medibles y cuantificables?'],
            ['7.2', $T, '¿Los objetivos y metas del PESV son coherentes con la Política de Seguridad Vial, la evaluación y control de riesgos en seguridad vial, el plan de trabajo anual del PESV y los programas de gestión de riesgos críticos y factores de desempeño en seguridad vial?'],
            ['7.3', $T, '¿Los objetivos y metas del PESV fueron comunicados a todos los colaboradores de la organización, así como actualizados, revisados y evaluados mínimo una (1) vez al año?'],

            ['8.1', $T, '¿La organización tiene definidos como mínimo los siguientes programas de gestión de riesgos críticos y factores de desempeño del PESV: gestión de la velocidad segura, prevención de la fatiga, prevención de la distracción, cero tolerancia a la conducción bajo los efectos de alcohol y de sustancias psicoactivas, protección de actores viales vulnerables, y otros programas relacionados con el SG-SST, y cumplen con los requisitos definidos en el paso 8?'],
            ['8.2', $T, '¿Los programas de gestión de riesgos críticos y factores de desempeño del PESV son actualizados como mínimo una (1) vez al año?'],
            ['8.3', $T, '¿Los programas de gestión de riesgos críticos y factores de desempeño del PESV fueron divulgados a todos los colaboradores de la organización, y se les realizó análisis y evaluación de resultados de manera trimestral en el Comité de Seguridad Vial?'],

            ['9.1', $T, '¿El plan anual del PESV está documentado, contiene los objetivos, metas, responsabilidades, recursos y cronograma de actividades del año, está articulado con el plan anual de actividades del SG-SST y cumple con los requisitos definidos en el paso 9?'],

            ['10.1', $T, '¿La organización definió y documentó la competencia en seguridad vial de los colaboradores de la organización y de los siguientes cargos y roles: 1) líder del diseño e implementación del PESV; 2) miembros del Comité de Seguridad Vial; 3) capacitadores en seguridad vial; 4) planificadores de rutas o personas que coordinan desplazamientos laborales; 5) coordinadores y técnicos de mantenimiento de vehículos; 6) auditores de seguridad vial; 7) brigadista vial e investigadores de siniestros viales; 8) colaboradores que conducen un vehículo para sus desplazamientos laborales, conforme con lo indicado en el paso 10 (según corresponda)?'],
            ['10.2', $T, '¿La organización definió los lineamientos generales de sensibilización y capacitación para promover la formación de hábitos, comportamientos y conductas seguras en la vía?'],
            ['10.3', $T, '¿El plan anual de formación incluye los temas de seguridad vial por cada actor vial, independientemente del cargo o rol que desempeña, está enfocado en los riesgos identificados en el paso 6 y cumple con los requisitos definidos en el paso 10?'],

            ['11.1', $A, '¿La organización asignó, documentó y comunicó en debida forma las funciones y responsabilidades en materia de seguridad vial de todos los actores viales de la organización? ¿Contiene como mínimo lo indicado en el paso 11?'],
            ['11.2', $A, '¿La organización realizó la evaluación de la competencia en seguridad vial a los colaboradores que realizan desplazamientos laborales, al menos una (1) vez al año? ¿Contiene como mínimo lo indicado en el paso 11?'],
            ['11.3', $A, '¿La organización cuenta con el procedimiento documentado de evaluación de la competencia de los conductores?'],
            ['11.4', $A, '¿La organización definió la metodología para lograr comportamientos interdependientes y promover la formación de hábitos y conductas seguras en la vía?'],

            ['12.1', $T, '¿La organización elaboró un plan de preparación y respuesta ante emergencias viales que incluye como mínimo los requisitos mencionados en el paso 12?'],
            ['12.2', $T, '¿El plan de preparación y respuesta ante emergencias viales tiene en cuenta a todos los colaboradores de la organización, los riesgos de las rutas, la ubicación de los centros de atención médica y los organismos de socorro en rutas frecuentes?'],

            ['13.1', $E, '¿La organización documentó y aplicó una técnica, metodología o procedimiento para reportar, registrar, investigar, analizar y divulgar los siniestros viales en los que se ven involucrados los colaboradores en los desplazamientos laborales y en el entorno próximo de la organización, que incluye como mínimo los requisitos mencionados en el paso 13?'],
            ['13.2', $E, '¿La organización divulgó las lecciones aprendidas de los siniestros viales?'],

            ['14.1', $T, '¿La organización documentó un protocolo de operación y mantenimiento de las vías públicas y/o privadas que tenga a cargo, que administre o que controle directamente, que incluye como mínimo los requisitos mencionados en el paso 14?'],
            ['14.2', $T, '¿La organización documentó los siniestros viales que se presentan por parte de terceros o de colaboradores que utilizan las vías que administra y controla la organización, conforme con los requisitos mencionados en el paso 14?'],
            ['14.3', $T, 'Para el caso de las concesiones viales, ¿se aplican los requisitos mínimos mencionados en el paso 14?'],

            ['15.1', $T, '¿Se tiene documentado el procedimiento que utiliza la organización para la planificación de viajes misionales de los colaboradores, teniendo en cuenta los riesgos en relación con la seguridad vial? ¿Contiene como mínimo los requisitos mencionados en el paso 15?'],

            ['16.1', $T, '¿La organización definió un procedimiento y un formato de registro para la inspección preoperacional diaria de los vehículos automotores y no automotores que se utilizan para desplazamientos laborales, teniendo en cuenta el nivel de riesgo vial de la operación?'],
            ['16.2', $T, '¿La inspección preoperacional diaria contiene al menos la disponibilidad de los elementos a inspeccionar, el buen funcionamiento del vehículo, su estado y los niveles aceptables para el funcionamiento y la seguridad del vehículo y de sus ocupantes, y demás requisitos mencionados en el paso 16?'],

            ['17.1', $T, '¿La organización diseñó e implementó un plan de mantenimiento preventivo para los vehículos automotores y no automotores que se utilizan en los desplazamientos laborales al servicio de la organización, que contempla los requisitos mencionados en el paso 17?'],
            ['17.2', $T, '¿La organización documentó y mantiene la hoja de vida de cada vehículo automotor y no automotor que se utiliza para los fines misionales de la organización, conforme con los requisitos mencionados en el paso 17?'],
            ['17.3', $T, '¿La organización documenta el mantenimiento de los vehículos de propiedad de los colaboradores puestos al servicio de la organización para el cumplimiento de sus funciones?'],

            ['18.1', $E, '¿La organización dispone de un procedimiento para evaluar los impactos que puedan generar los cambios externos e internos en la seguridad vial?'],
            ['18.2', $E, '¿La organización realizó el análisis del impacto de los cambios planeados y no planeados, y realizó las gestiones de manera previa para prevenir riesgos y consecuencias en seguridad vial?'],
            ['18.3', $E, '¿La organización verificó que los contratistas obligados a diseñar e implementar el PESV, de conformidad con el artículo 12 de la Ley 1503 de 2011 (modificado por el artículo 110 del Decreto Ley 2106 de 2019) o las normas que lo modifiquen o sustituyan, cumplan con dicha obligación?'],
            ['18.4', $E, '¿La organización estableció las disposiciones en seguridad vial que deben cumplir los contratistas, subcontratistas y terceros, incluyendo conductores y propietarios de vehículos permanentes y ocasionales que no están obligados a diseñar e implementar un PESV, conforme con las disposiciones mínimas mencionadas en el paso 18?'],
            ['18.5', $E, '¿La organización definió los responsables de supervisar el cumplimiento de las obligaciones en seguridad vial establecidas a los contratistas que realizan desplazamientos laborales?'],

            ['19.1', $E, '¿La organización mantiene disponible, debidamente controlada y actualizada la documentación del Plan Estratégico de Seguridad Vial?'],
            ['19.2', $E, '¿La organización realizó la retención documental de los registros y evidencias que soportan la implementación del PESV, asegurando su identificación, legibilidad, accesibilidad y protección contra daños o pérdida, y se almacenan al menos por cinco (5) años, salvo norma especial en contrario?'],
            ['19.3', $E, '¿La organización almacena los registros de las inspecciones preoperacionales por un (1) año?'],

            ['20.1', $T, '¿La organización realizó el registro, la medición y el análisis de los indicadores mínimos de gestión del PESV de acuerdo con el nivel aplicable, según lo establecido en la Tabla 10 del Capítulo I del anexo?'],
            ['20.2', $T, '¿La organización definió indicadores adicionales a los mínimos de la Tabla 10? ¿Cada indicador adicional fue construido con las características mencionadas en el paso 20?'],
            ['20.3', $T, '¿La organización realizó el reporte de autogestión anual a la entidad verificadora que le corresponde, con los resultados de la medición y análisis de los indicadores de la Tabla 10 y la información listada en el paso 20, con corte al 31 de diciembre de cada año y a más tardar el 31 de enero siguiente?'],

            ['21.1', $A, '¿La organización definió su línea base de siniestralidad vial, en la cual se determina su posición actual con relación a la seguridad vial de acuerdo con su nivel de pérdida?'],
            ['21.2', $A, '¿La organización lleva registro estadístico, tendencia, proyección y análisis de los siniestros viales, diferenciándolos por la gravedad del evento según el nivel de pérdida y separando los análisis de los desplazamientos laborales de los desplazamientos cotidianos / no laborales?'],
            ['21.3', $A, '¿El Comité de Seguridad Vial analiza los resultados de la siniestralidad vial de acuerdo con lo definido en el paso 21?'],

            ['22.1', $T, '¿La organización realizó al menos una auditoría interna anual para evaluar el cumplimiento y las evidencias de la planificación, implementación, seguimiento y mejora del PESV, de acuerdo con lo establecido en el Capítulo I del anexo? (La organización puede optar por auditorías integradas.)'],
            ['22.2', $T, '¿La organización documentó y aplicó un procedimiento para la realización de las auditorías internas al PESV que contempla lo mencionado en el paso 22?'],
            ['22.3', $T, '¿La organización definió la competencia de los auditores internos del PESV, siguiendo los requisitos del paso 10 (competencia y plan anual de formación)?'],
            ['22.4', $T, '¿El (los) auditor(es) interno(s) son persona(s) diferente(s) al líder del diseño e implementación del PESV, y las auditorías fueron planificadas con la participación del Comité de Seguridad Vial?'],

            ['23.1', $T, '¿La organización definió e implementó las acciones preventivas y/o correctivas necesarias con base en los resultados de la medición y análisis de los indicadores y de las auditorías del PESV?'],

            ['24.1', $T, '¿La organización definió y puso a disposición los mecanismos de comunicación y participación en relación con la seguridad vial, así como la frecuencia de las comunicaciones —por lo menos trimestral— con la promoción de la seguridad vial, de acuerdo con lo definido en el paso 24?'],
        ];
    }
}
