<?php

namespace Database\Seeders;

use App\Models\PesvStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Los 24 pasos del PESV (Resolución 40595 de 2022), agrupados en sus 4 fases.
 *
 * Catálogo GLOBAL: es el mismo para todas las empresas cliente, igual que los
 * 60 estándares de la Res. 0312. Lo que cambia por empresa es el estado de
 * cada paso, que vive en pesv_plan_steps.
 */
class PesvStepsSeeder extends Seeder
{
    /** @var array<int, array{0:int, 1:string, 2:string}> [fase, título, descripción] por número de paso */
    private const PASOS = [
        // ---- Fase 1 · Planificación ----------------------------------------
        1 => [1, 'Líder del PESV',
            'Designación del responsable del diseño e implementación del PESV, con acto de designación, funciones y competencias definidas.'],
        2 => [1, 'Comité de Seguridad Vial',
            'Conformación del comité, integrantes, roles y periodicidad de las reuniones. Se soporta con actas.'],
        3 => [1, 'Política de Seguridad Vial',
            'Política suscrita por la alta dirección, divulgada a todos los niveles y revisada periódicamente.'],
        4 => [1, 'Liderazgo y corresponsabilidad directiva',
            'Compromiso demostrable de la alta dirección: recursos, participación en el comité y rendición de cuentas.'],
        5 => [1, 'Diagnóstico del PESV',
            'Caracterización de la organización: sedes, colaboradores y conductores, contratistas y terceros, flota de vehículos y rutas.'],
        6 => [1, 'Evaluación y control de riesgos viales',
            'Identificación de peligros y valoración de los riesgos viales, con sus controles. Se articula con la matriz IPERC.'],
        7 => [1, 'Objetivos y metas del PESV',
            'Objetivos medibles con sus metas e indicadores, coherentes con los riesgos identificados.'],
        8 => [1, 'Programas de gestión de riesgos críticos',
            'Programas para los riesgos críticos priorizados (velocidad, alcohol y drogas, distracción, fatiga, cinturón y casco).'],

        // ---- Fase 2 · Implementación y ejecución ---------------------------
        9 => [2, 'Plan anual de trabajo',
            'Cronograma anual con actividades, responsables, recursos y presupuesto asignado al PESV.'],
        10 => [2, 'Competencia y plan de formación',
            'Plan de capacitación en seguridad vial por rol, con cobertura y evaluación de la eficacia.'],
        11 => [2, 'Responsabilidad y comportamiento seguro',
            'Selección y evaluación de conductores y terceros, pruebas de idoneidad y control a infractores de tránsito.'],
        12 => [2, 'Plan de preparación ante emergencias viales',
            'Protocolo de atención de emergencias viales, con roles, cadena de llamadas y simulacros.'],
        13 => [2, 'Investigación interna de siniestros viales',
            'Procedimiento de investigación de siniestros, con causas raíz y acciones derivadas.'],
        14 => [2, 'Vías seguras administradas',
            'Condiciones de seguridad de las vías e infraestructura bajo administración de la organización.'],
        15 => [2, 'Planificación de desplazamientos laborales',
            'Planificación de viajes y rutas: jornadas, descansos, condiciones de la vía y autorización de desplazamientos.'],
        16 => [2, 'Inspección preoperacional de vehículos',
            'Inspección documentada antes de operar el vehículo, con registro y tratamiento de los hallazgos.'],
        17 => [2, 'Mantenimiento de vehículos',
            'Programa de mantenimiento preventivo y correctivo de la flota, con hojas de vida y trazabilidad.'],
        18 => [2, 'Gestión del cambio y contratistas',
            'Control de los cambios que afectan el PESV y requisitos de seguridad vial exigidos a contratistas y terceros.'],
        19 => [2, 'Archivo y retención documental',
            'Control de documentos y registros del PESV: identificación, vigencia, custodia y tiempos de retención.'],

        // ---- Fase 3 · Seguimiento y evaluación -----------------------------
        20 => [3, 'Indicadores de gestión y reporte de autogestión',
            'Medición de los indicadores del PESV y elaboración del reporte anual de autogestión.'],
        21 => [3, 'Análisis estadístico de siniestros viales',
            'Análisis de la siniestralidad: frecuencia, severidad y tendencia, como insumo de las decisiones.'],
        22 => [3, 'Auditoría anual del PESV',
            'Auditoría interna anual del PESV, con programa, criterios, hallazgos e informe.'],

        // ---- Fase 4 · Mejora continua --------------------------------------
        23 => [4, 'Mejora continua y acciones correctivas',
            'Tratamiento de no conformidades, acciones correctivas y preventivas, y verificación de su eficacia.'],
        24 => [4, 'Comunicación y participación',
            'Mecanismos de participación, consulta y comunicación con trabajadores, contratistas y partes interesadas.'],
    ];

    /** @var array<int, string> */
    public const FASES = [
        1 => 'Planificación',
        2 => 'Implementación y ejecución',
        3 => 'Seguimiento y evaluación',
        4 => 'Mejora continua',
    ];

    public function run(): void
    {
        foreach (self::PASOS as $numero => [$fase, $titulo, $descripcion]) {
            PesvStep::updateOrCreate(
                ['numero' => $numero],
                [
                    'fase' => $fase,
                    'fase_nombre' => self::FASES[$fase],
                    'titulo' => $titulo,
                    'descripcion' => $descripcion,
                    'orden' => $numero,
                ],
            );
        }

        // El sidebar sirve el árbol desde esta clave; si no se limpia, seguiría
        // mostrando los títulos anteriores hasta que se reinicie la caché.
        Cache::forget('pesv.pasos.nav');
    }
}
