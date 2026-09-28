<?php

namespace Database\Seeders;

use App\Models\WorkPlanActivity;
use Illuminate\Database\Seeder;

/**
 * Catálogo GLOBAL de actividades de los dos planes de trabajo: el del SGI por
 * cláusulas ISO 4→10 y el SST-PESV por ciclo PHVA (ver sembrarSst).
 *
 * SGI: cláusulas ISO 4→10. Fuente: hoja «6.2 PLAN DE TRABAJO SGI» de la herramienta modelo de CMK.
 * Van las 34 filas de la hoja, también 8.3, 8.5.x y 8.6: la ISO 9001 habla de
 * productos Y servicios, así que una empresa de servicios también las tiene.
 * Excluirlas es decisión de cada empresa (casilla «Aplica»), no del catálogo.
 * Los soportes son los de la hoja; donde la hoja no trae (8.2 en adelante)
 * se redactaron a partir de la norma.
 */
class WorkPlanActivitiesSeeder extends Seeder
{
    public function run(): void
    {
        $C = '4. Contexto de la organización';
        $L = '5. Liderazgo';
        $P = '6. Planificación';
        $A = '7. Apoyo';
        $O = '8. Operación';
        $E = '9. Evaluación del desempeño';
        $M = '10. Mejora';

        $acts = [
            ['4.1', $C, 'Comprensión de la organización y su contexto', ['9001', '14001', '45001'], 'Contexto estratégico; Matriz de Oportunidades; Matriz DOFA / PESTEL'],
            ['4.2', $C, 'Comprensión de las necesidades y expectativas de las partes interesadas', ['9001', '14001', '45001'], 'Análisis de las necesidades y expectativas de las partes interesadas'],
            ['4.3', $C, 'Determinación del alcance del sistema de gestión integral', ['9001', '14001', '45001'], 'Alcance del Sistema de gestión Integral'],
            ['4.4', $C, 'Sistema de gestión integral y sus procesos', ['9001', '14001', '45001'], 'Los procesos necesarios para el Sistema de Gestión Integral; Mapa de procesos; Caracterización de los procesos'],

            ['5.1', $L, 'Liderazgo y compromiso', ['9001', '14001', '45001'], 'Política SGI; Plan de Comunicación y responsabilidades; Manual del SGI; Matriz de responsabilidades; Informes de desempeño'],
            ['5.1.2', $L, 'Enfoque al cliente', ['9001'], 'Procedimiento de atención a PQRS; Encuestas de Satisfacción del Cliente; Registro de Quejas y Reclamaciones; Comunicaciones con el Cliente'],
            ['5.2', $L, 'Política del SGI', ['9001', '14001', '45001'], 'Documento de Política de Gestión Integral; Divulgación de la política'],
            ['5.3', $L, 'Roles, responsabilidades y autoridades', ['9001'], 'Matriz de Roles y responsabilidades; Participación y consulta; Modelo de Informe de Rendición de cuentas sobre el desempeño; Organigrama'],

            ['6.1', $P, 'Acciones para tratar riesgos y oportunidades', ['9001', '14001', '45001'], 'Procedimiento de Identificación y evaluación de riesgos y Oportunidades del sistema de gestión; Matriz de Identificación y evaluación de riesgos y Oportunidades del sistema de gestión; Matriz de aspectos e impactos ambientales; Matriz de identificación de peligros'],
            ['6.2', $P, 'Objetivos del SGI y planificación para lograrlos', ['9001', '14001', '45001'], 'Objetivos del Sistema de Gestión Integral; Despliegue de objetivos; Indicadores de gestión SST; Ficha técnica de los indicadores'],
            ['6.3', $P, 'Planificación de los cambios', ['9001', '45001'], 'Procedimiento de gestión del cambio; Matriz de gestión del cambio'],

            ['7.1', $A, 'Recursos', ['9001', '14001', '45001'], 'Establecer presupuesto proyectado para la implementación del SGI'],
            ['7.1.2', $A, 'Personas, infraestructura y recursos de seguimiento', ['9001'], 'Plan de mantenimiento preventivo y correctivo de las instalaciones, equipo y maquinaria; Procedimiento de calibración de equipos; Manual de funciones y responsabilidades'],
            ['7.2', $A, 'Competencia', ['9001', '14001', '45001'], 'Procedimiento para la administración de personal; Competencia de responsable del SGI; Procedimiento de Capacitación/formación – Inducción; Definir el plan de capacitaciones y el seguimiento a realizar; Ejecución de capacitaciones del SGI; Determinar las necesidades de capacitación Manual de funciones y responsabilidades'],
            ['7.3', $A, 'Toma de conciencia', ['9001', '14001', '45001'], 'Matriz de competencias; Toma de conciencia; Conocimientos de la organización'],
            ['7.4', $A, 'Comunicación', ['9001', '14001', '45001'], 'Procedimiento de comunicación participación y Consulta; Matriz de comunicaciones'],
            ['7.5', $A, 'Información documentada', ['9001', '14001', '45001'], 'Manual de Control de Documentos y registros; Matriz de control de documentos y registros; Matriz de control de documentos externos'],

            ['8.1', $O, 'Planificación y control operacional', ['9001', '14001', '45001'], 'Manual de contratistas, incluir requisitos ambientales a contratistas'],
            ['8.2', $O, 'Requisitos para los productos y servicios', ['9001'], 'Comunicación con el cliente; Determinación y revisión de requisitos'],
            ['8.2E', $O, 'Preparación y respuesta ante emergencias', ['14001', '45001'], 'Plan de preparación y respuesta ante emergencias'],
            ['8.3', $O, 'Diseño y desarrollo de los productos y servicios', ['9001'], 'Procedimiento de diseño y desarrollo, o justificación de su exclusión en el alcance'],
            ['8.4', $O, 'Control de procesos, productos y servicios externos', ['9001', '45001'], 'Procedimiento de selección, evaluación y reevaluación de proveedores; Requisitos para proveedores externos'],
            ['8.5', $O, 'Producción y provisión del servicio', ['9001'], 'Procedimiento de prestación del servicio; Control de los cambios en la prestación del servicio'],
            ['8.5.2', $O, 'Identificación y trazabilidad', ['9001'], 'Registros de identificación y trazabilidad del servicio'],
            ['8.5.3', $O, 'Propiedad del cliente', ['9001'], 'Registro y control de la propiedad del cliente (información, documentos, equipos)'],
            ['8.5.4', $O, 'Preservación', ['9001'], 'Condiciones de preservación de las salidas (almacenamiento, protección de la información)'],
            ['8.6', $O, 'Liberación de los productos y servicios', ['9001'], 'Registros de liberación y aprobación del servicio antes de entregarlo'],
            ['8.7', $O, 'Control de las salidas no conformes', ['9001'], 'Procedimiento de control de salidas no conformes'],

            ['9.1', $E, 'Seguimiento, medición, análisis y evaluación', ['9001', '14001', '45001'], 'Indicadores de gestión; Análisis y evaluación del desempeño; Satisfacción del cliente'],
            ['9.2', $E, 'Auditoría interna', ['9001', '14001', '45001'], 'Programa y plan de auditoría interna; Informe de auditoría'],
            ['9.3', $E, 'Revisión por la dirección', ['9001', '14001', '45001'], 'Acta de revisión por la dirección; Entradas y salidas de la revisión'],

            ['10.1', $M, 'Generalidades de la mejora', ['9001', '14001', '45001'], 'Oportunidades de mejora identificadas'],
            ['10.2', $M, 'No conformidad y acción correctiva', ['9001', '14001', '45001'], 'Procedimiento de acciones correctivas; Matriz de ACPM'],
            ['10.3', $M, 'Mejora continua', ['9001', '14001', '45001'], 'Plan de mejora continua del SGI'],
        ];

        foreach ($acts as $i => [$codigo, $fase, $nombre, $normas, $soporte]) {
            WorkPlanActivity::updateOrCreate(
                ['codigo' => $codigo],
                [
                    'plan' => 'sgi',
                    'fase' => $fase,
                    'nombre' => $nombre,
                    'normas' => $normas,
                    'soporte' => $soporte,
                    'orden' => $i + 1,
                ],
            );
        }

        $sst = $this->sembrarSst();

        $this->command?->info('Actividades del Plan de Trabajo: '.count($acts).' del SGI y '.$sst.' del SST-PESV cargadas.');
    }

    /**
     * Plan de Trabajo Anual SST-PESV (estándar 2.4.1 de la Res. 0312), de la
     * hoja «2.4.1 Plan de trabajo» de la herramienta SG-SST-PESV de CMK, por
     * ciclo PHVA. Los datos viven en `data/plan-trabajo-sst.json` (extraídos de
     * la hoja, con los errores de tipeo corregidos). Código SST-01…SST-88 por
     * orden de la hoja.
     */
    private function sembrarSst(): int
    {
        $acts = json_decode((string) file_get_contents(__DIR__.'/data/plan-trabajo-sst.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($acts as $i => $a) {
            WorkPlanActivity::updateOrCreate(
                ['codigo' => sprintf('SST-%02d', $i + 1)],
                [
                    'plan' => 'sst',
                    'fase' => $a['fase'],
                    'nombre' => mb_substr($a['nombre'], 0, 255),
                    'normas' => [],
                    'soporte' => $a['detalle'],
                    'frecuencia' => $a['frecuencia'],
                    'responsable_sugerido' => $a['responsable'],
                    'orden' => $i + 1,
                ],
            );
        }

        return count($acts);
    }
}
