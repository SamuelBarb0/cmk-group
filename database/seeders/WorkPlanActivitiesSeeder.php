<?php

namespace Database\Seeders;

use App\Models\WorkPlanActivity;
use Illuminate\Database\Seeder;

/**
 * Catálogo GLOBAL de actividades del Plan de Trabajo Anual del SGI, por cláusulas
 * ISO 4→10. Fuente: hoja «6.2 PLAN DE TRABAJO SGI» de la herramienta modelo de CMK.
 * Se omiten cláusulas de manufactura (diseño 8.3, producción 8.5.x, liberación 8.6)
 * que no aplican al alcance SST/HSEQ/PESV.
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
            ['4.1', $C, 'Comprensión de la organización y su contexto', ['9001', '14001', '45001'], 'Contexto estratégico; Matriz de oportunidades; Matriz DOFA / PESTEL'],
            ['4.2', $C, 'Comprensión de las necesidades y expectativas de las partes interesadas', ['9001', '14001', '45001'], 'Análisis de necesidades y expectativas de las partes interesadas'],
            ['4.3', $C, 'Determinación del alcance del sistema de gestión integral', ['9001', '14001', '45001'], 'Alcance del sistema de gestión integral'],
            ['4.4', $C, 'Sistema de gestión integral y sus procesos', ['9001', '14001', '45001'], 'Mapa de procesos; Caracterización de los procesos'],

            ['5.1', $L, 'Liderazgo y compromiso', ['9001', '14001', '45001'], 'Política SGI; Plan de comunicación y responsabilidades; Manual del SGI; Matriz de responsabilidades; Informes de desempeño'],
            ['5.1.2', $L, 'Enfoque al cliente', ['9001'], 'Procedimiento de atención a PQRS; Encuestas de satisfacción del cliente; Registro de quejas y reclamaciones'],
            ['5.2', $L, 'Política del SGI', ['9001', '14001', '45001'], 'Documento de política de gestión integral; Divulgación de la política'],
            ['5.3', $L, 'Roles, responsabilidades y autoridades', ['9001'], 'Matriz de roles y responsabilidades; Participación y consulta; Informe de rendición de cuentas; Organigrama'],

            ['6.1', $P, 'Acciones para tratar riesgos y oportunidades', ['9001', '14001', '45001'], 'Procedimiento y matriz de riesgos y oportunidades; Matriz de aspectos e impactos ambientales; Matriz de identificación de peligros (IPERC)'],
            ['6.2', $P, 'Objetivos del SGI y planificación para lograrlos', ['9001', '14001', '45001'], 'Objetivos del SGI; Despliegue de objetivos; Indicadores de gestión SST; Ficha técnica de los indicadores'],
            ['6.3', $P, 'Planificación de los cambios', ['9001', '45001'], 'Procedimiento de gestión del cambio; Matriz de gestión del cambio'],

            ['7.1', $A, 'Recursos', ['9001', '14001', '45001'], 'Presupuesto proyectado para la implementación del SGI'],
            ['7.1.2', $A, 'Personas, infraestructura y recursos de seguimiento', ['9001'], 'Plan de mantenimiento preventivo y correctivo; Procedimiento de calibración de equipos; Manual de funciones y responsabilidades'],
            ['7.2', $A, 'Competencia', ['9001', '14001', '45001'], 'Procedimiento de administración de personal; Procedimiento de capacitación/formación e inducción; Plan de capacitaciones y su seguimiento'],
            ['7.3', $A, 'Toma de conciencia', ['9001', '14001', '45001'], 'Matriz de competencias; Toma de conciencia; Conocimientos de la organización'],
            ['7.4', $A, 'Comunicación', ['9001', '14001', '45001'], 'Procedimiento de comunicación, participación y consulta; Matriz de comunicaciones'],
            ['7.5', $A, 'Información documentada', ['9001', '14001', '45001'], 'Manual y matriz de control de documentos y registros; Matriz de control de documentos externos'],

            ['8.1', $O, 'Planificación y control operacional', ['9001', '14001', '45001'], 'Manual de contratistas (incluye requisitos ambientales)'],
            ['8.2', $O, 'Requisitos para los productos y servicios', ['9001'], 'Comunicación con el cliente; Determinación y revisión de requisitos'],
            ['8.2E', $O, 'Preparación y respuesta ante emergencias', ['14001', '45001'], 'Plan de preparación y respuesta ante emergencias'],
            ['8.4', $O, 'Control de procesos, productos y servicios externos', ['9001', '45001'], 'Procedimiento de selección, evaluación y reevaluación de proveedores; Requisitos para proveedores externos'],
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
                    'fase' => $fase,
                    'nombre' => $nombre,
                    'normas' => $normas,
                    'soporte' => $soporte,
                    'orden' => $i + 1,
                ],
            );
        }

        $this->command?->info('Actividades del Plan de Trabajo: '.count($acts).' cargadas.');
    }
}
