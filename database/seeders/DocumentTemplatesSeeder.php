<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de plantillas de documentos SGI (transversales).
 * Cada plantilla trae el prompt de estructura para la generación con IA.
 */
class DocumentTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $plantillas = [
            [
                'codigo' => 'POL-SGI',
                'nombre' => 'Política del Sistema de Gestión Integral',
                'tipo' => 'Política',
                'categoria' => 'SGI',
                'normas' => ['ISO 9001', 'ISO 14001', 'ISO 45001', 'Res. 0312', 'Res. 40595'],
                'descripcion' => 'Política integrada única que cubre Calidad, Ambiente, SST y Seguridad Vial (documento transversal).',
                'prompt' => 'Redacta una POLÍTICA DEL SISTEMA DE GESTIÓN INTEGRAL (SGI) integrada, que abarque de forma conjunta Calidad (ISO 9001), Ambiente (ISO 14001), Seguridad y Salud en el Trabajo (ISO 45001 y Decreto 1072 / Resolución 0312) y Seguridad Vial (Resolución 40595 - PESV). Incluye: propósito, alcance, compromisos de la alta dirección (cumplimiento legal, prevención de lesiones y enfermedades, protección del ambiente, mejora continua, seguridad vial), y una nota de aprobación por la alta dirección. Debe ser adecuada al tamaño y nivel de riesgo de la empresa.',
                'orden' => 1,
            ],
            [
                'codigo' => 'MAN-SGSST',
                'nombre' => 'Manual del SG-SST',
                'tipo' => 'Manual',
                'categoria' => 'SST',
                'normas' => ['ISO 45001', 'Decreto 1072', 'Res. 0312'],
                'descripcion' => 'Manual del Sistema de Gestión de Seguridad y Salud en el Trabajo.',
                'prompt' => 'Redacta el MANUAL DEL SG-SST conforme al Decreto 1072 de 2015 y la Resolución 0312 de 2019. Estructura: 1) Introducción y objetivo, 2) Alcance, 3) Definiciones clave, 4) Estructura del SG-SST bajo el ciclo PHVA (Planear-Hacer-Verificar-Actuar), 5) Roles y responsabilidades, 6) Política y objetivos, 7) Documentación y control, 8) Mejora continua. Redacta de forma formal y aplicable a la empresa descrita.',
                'orden' => 2,
            ],
            [
                'codigo' => 'PR-IPERC',
                'nombre' => 'Procedimiento de Identificación de Peligros y Valoración de Riesgos (IPERC)',
                'tipo' => 'Procedimiento',
                'categoria' => 'SST',
                'normas' => ['ISO 45001', 'Res. 0312', 'GTC 45'],
                'descripcion' => 'Metodología para identificar peligros, evaluar y valorar riesgos.',
                'prompt' => 'Redacta el PROCEDIMIENTO PARA LA IDENTIFICACIÓN DE PELIGROS, EVALUACIÓN Y VALORACIÓN DE RIESGOS (IPERC) basado en la GTC 45 y la Resolución 0312. Estructura: 1) Objetivo, 2) Alcance, 3) Definiciones, 4) Responsables, 5) Metodología paso a paso (clasificación de procesos, identificación de peligros, evaluación del riesgo, valoración, determinación de controles según la jerarquía: eliminación, sustitución, controles de ingeniería, administrativos, EPP), 6) Revisión y actualización, 7) Registros asociados.',
                'orden' => 3,
            ],
            [
                'codigo' => 'PR-INV-ACC',
                'nombre' => 'Procedimiento de Investigación de Incidentes y Accidentes',
                'tipo' => 'Procedimiento',
                'categoria' => 'SST',
                'normas' => ['ISO 45001', 'Res. 1401', 'Res. 0312'],
                'descripcion' => 'Procedimiento para investigar incidentes, accidentes y enfermedades laborales.',
                'prompt' => 'Redacta el PROCEDIMIENTO PARA LA INVESTIGACIÓN DE INCIDENTES, ACCIDENTES DE TRABAJO Y ENFERMEDADES LABORALES conforme a la Resolución 1401 de 2007. Estructura: 1) Objetivo, 2) Alcance, 3) Definiciones (incidente, accidente, causa básica, causa inmediata), 4) Responsables (incluye el equipo investigador y el COPASST), 5) Metodología (reporte, conformación del equipo, recolección de evidencia, análisis de causas — método de árbol de causas o 5 porqués, plan de acción), 6) Plazos legales, 7) Registros.',
                'orden' => 4,
            ],
            [
                'codigo' => 'POL-PESV',
                'nombre' => 'Política de Seguridad Vial',
                'tipo' => 'Política',
                'categoria' => 'PESV',
                'normas' => ['Res. 40595', 'ISO 39001'],
                'descripcion' => 'Política del Plan Estratégico de Seguridad Vial.',
                'prompt' => 'Redacta la POLÍTICA DE SEGURIDAD VIAL en el marco del Plan Estratégico de Seguridad Vial (PESV) según la Resolución 40595 de 2022. Incluye: compromiso de la alta dirección con la prevención de siniestros viales, cumplimiento normativo, gestión de riesgos viales, capacitación de conductores y actores viales, y mejora continua. Adecuada a la empresa descrita.',
                'orden' => 5,
            ],
        ];

        foreach ($plantillas as $p) {
            DocumentTemplate::updateOrCreate(['codigo' => $p['codigo']], $p);
        }
    }
}
