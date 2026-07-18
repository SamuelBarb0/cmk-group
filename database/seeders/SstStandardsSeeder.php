<?php

namespace Database\Seeders;

use App\Models\SstStandard;
use Illuminate\Database\Seeder;

/**
 * Catálogo de los 60 Estándares Mínimos del SG-SST (Resolución 0312 de 2019).
 * Extraído de la herramienta modelo de CMK. Suma de valores = 100.
 */
class SstStandardsSeeder extends Seeder
{
    public function run(): void
    {
        $estandares = [
            ['codigo' => '1.1.1', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Responsable del Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST', 'valor' => 0.5, 'orden' => 1],
            ['codigo' => '1.1.2', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Responsabilidades en el Sistema de Gestión de Seguridad y Salud en el Trabajo – SG-SST', 'valor' => 0.5, 'orden' => 2],
            ['codigo' => '1.1.3', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Asignación de recursos para el Sistema de Gestión en Seguridad y Salud en el Trabajo – SG-SST', 'valor' => 0.5, 'orden' => 3],
            ['codigo' => '1.1.4', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Afiliación al Sistema General de Riesgos Laborales', 'valor' => 0.5, 'orden' => 4],
            ['codigo' => '1.1.5', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Identificación de trabajadores de alto riesgo y cotización de pensión especial', 'valor' => 0.5, 'orden' => 5],
            ['codigo' => '1.1.6', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Conformación COPASST / Vigía', 'valor' => 0.5, 'orden' => 6],
            ['codigo' => '1.1.7', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Capacitación COPASST / Vigía', 'valor' => 0.5, 'orden' => 7],
            ['codigo' => '1.1.8', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Conformación Comité de Convivencia', 'valor' => 0.5, 'orden' => 8],
            ['codigo' => '1.2.1', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Programa Capacitación promoción y prevención PYP', 'valor' => 2.0, 'orden' => 9],
            ['codigo' => '1.2.2', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Inducción y Reinducción en Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST, actividades de Promoción y Prevención PyP', 'valor' => 2.0, 'orden' => 10],
            ['codigo' => '1.2.3', 'ciclo' => 'I. Planear', 'grupo' => 'Recursos', 'peso_grupo' => 10, 'item' => 'Responsables del Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST con curso (50 horas)', 'valor' => 2.0, 'orden' => 11],
            ['codigo' => '2.1.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Política del Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST firmada, fechada y comunicada al COPASST/Vigía', 'valor' => 1.0, 'orden' => 12],
            ['codigo' => '2.2.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Objetivos definidos, claros, medibles, cuantificables, con metas, documentados, revisados del SG-SST', 'valor' => 1.0, 'orden' => 13],
            ['codigo' => '2.3.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Evaluación e identificación de prioridades', 'valor' => 1.0, 'orden' => 14],
            ['codigo' => '2.4.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Plan que identifica objetivos, metas, responsabilidad, recursos con cronograma y firmado', 'valor' => 2.0, 'orden' => 15],
            ['codigo' => '2.5.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Archivo o retención documental del Sistema de Gestión en Seguridad y Salud en el Trabajo SG-SST', 'valor' => 2.0, 'orden' => 16],
            ['codigo' => '2.6.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Rendición sobre el desempeño', 'valor' => 1.0, 'orden' => 17],
            ['codigo' => '2.7.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Matriz legal', 'valor' => 2.0, 'orden' => 18],
            ['codigo' => '2.8.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Mecanismos de comunicación, auto reporte en Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST', 'valor' => 1.0, 'orden' => 19],
            ['codigo' => '2.9.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Identificación, evaluación, para adquisición de productos y servicios en Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST', 'valor' => 1.0, 'orden' => 20],
            ['codigo' => '2.10.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Evaluación y selección de proveedores y contratistas', 'valor' => 2.0, 'orden' => 21],
            ['codigo' => '2.11.1', 'ciclo' => 'I. Planear', 'grupo' => 'Gestión Integral del SG-SST', 'peso_grupo' => 15, 'item' => 'Evaluación del impacto de cambios internos y externos en el Sistema de Gestión de Seguridad y Salud en el Trabajo SG-SST', 'valor' => 1.0, 'orden' => 22],
            ['codigo' => '3.1.1', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Descripción sociodemográfica – Diagnóstico de condiciones de salud', 'valor' => 1.0, 'orden' => 23],
            ['codigo' => '3.1.2', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Actividades de Promoción y Prevención en Salud', 'valor' => 1.0, 'orden' => 24],
            ['codigo' => '3.1.3', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Información al médico de los perfiles de cargo', 'valor' => 1.0, 'orden' => 25],
            ['codigo' => '3.1.4', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Realización de los exámenes médicos ocupacionales: preingreso, periódicos', 'valor' => 1.0, 'orden' => 26],
            ['codigo' => '3.1.5', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Custodia de Historias Clínicas', 'valor' => 1.0, 'orden' => 27],
            ['codigo' => '3.1.6', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Restricciones y recomendaciones médico laborales', 'valor' => 1.0, 'orden' => 28],
            ['codigo' => '3.1.7', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Estilos de vida y entornos saludables (controles tabaquismo, alcoholismo, farmacodependencia y otros)', 'valor' => 1.0, 'orden' => 29],
            ['codigo' => '3.1.8', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Agua potable, servicios sanitarios y disposición de basuras', 'valor' => 1.0, 'orden' => 30],
            ['codigo' => '3.1.9', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Eliminación adecuada de residuos sólidos, líquidos o gaseosos', 'valor' => 1.0, 'orden' => 31],
            ['codigo' => '3.2.1', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Reporte de los accidentes de trabajo y enfermedad laboral a la ARL, EPS y Dirección Territorial del Ministerio de Trabajo', 'valor' => 2.0, 'orden' => 32],
            ['codigo' => '3.2.2', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Investigación de incidentes, accidentes y enfermedades laborales', 'valor' => 2.0, 'orden' => 33],
            ['codigo' => '3.2.3', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Registro y análisis estadístico de accidentes y enfermedades laborales', 'valor' => 1.0, 'orden' => 34],
            ['codigo' => '3.3.1', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Medición de la frecuencia Enfermedad Laboral', 'valor' => 1.0, 'orden' => 35],
            ['codigo' => '3.3.2', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Medición de la frecuencia de los Incidentes, Accidentes de Trabajo y Enfermedad Laboral', 'valor' => 1.0, 'orden' => 36],
            ['codigo' => '3.3.3', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Medición de la mortalidad de Accidentes de Trabajo', 'valor' => 1.0, 'orden' => 37],
            ['codigo' => '3.3.4', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Medición de la prevalencia de Enfermedad Laboral', 'valor' => 1.0, 'orden' => 38],
            ['codigo' => '3.3.5', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Medición de la incidencia de Enfermedad Laboral', 'valor' => 1.0, 'orden' => 39],
            ['codigo' => '3.3.6', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de la Salud', 'peso_grupo' => 20, 'item' => 'Medición del ausentismo por causa medica', 'valor' => 1.0, 'orden' => 40],
            ['codigo' => '4.1.1', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Metodología para la identificación, evaluación y valoración de peligros', 'valor' => 4.0, 'orden' => 41],
            ['codigo' => '4.1.2', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Identificación de peligros con participación de todos los niveles de la empresa', 'valor' => 4.0, 'orden' => 42],
            ['codigo' => '4.1.3', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Identificación de sustancias catalogadas como carcinógenas o con toxicidad aguda', 'valor' => 3.0, 'orden' => 43],
            ['codigo' => '4.1.4', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Realización mediciones ambientales, químicos, físicos y biológicos', 'valor' => 4.0, 'orden' => 44],
            ['codigo' => '4.2.1', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Implementación de medidas de prevención y control de peligros/riesgos identificados', 'valor' => 2.5, 'orden' => 45],
            ['codigo' => '4.2.2', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Verificación de aplicación de medidas de prevención y control por parte de los trabajadores', 'valor' => 2.5, 'orden' => 46],
            ['codigo' => '4.2.3', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Elaboración de procedimientos, instructivos, fichas, protocolos', 'valor' => 2.5, 'orden' => 47],
            ['codigo' => '4.2.4', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Realización de inspecciones sistemáticas a las instalaciones, maquinaria o equipos con la participación del COPASST', 'valor' => 2.5, 'orden' => 48],
            ['codigo' => '4.2.5', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Mantenimiento periódico de instalaciones, equipos, máquinas, herramientas', 'valor' => 2.5, 'orden' => 49],
            ['codigo' => '4.2.6', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Peligros y Riesgos', 'peso_grupo' => 30, 'item' => 'Entrega de Elementos de Protección Persona EPP, se verifica con contratistas y subcontratistas', 'valor' => 2.5, 'orden' => 50],
            ['codigo' => '5.1.1', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Amenazas', 'peso_grupo' => 10, 'item' => 'Se cuenta con el Plan de Prevención, Preparación y Respuesta ante emergencias', 'valor' => 5.0, 'orden' => 51],
            ['codigo' => '5.1.2', 'ciclo' => 'II. Hacer', 'grupo' => 'Gestión de Amenazas', 'peso_grupo' => 10, 'item' => 'Brigada de prevención conformada, capacitada y dotada', 'valor' => 5.0, 'orden' => 52],
            ['codigo' => '6.1.1', 'ciclo' => 'III. Verificar', 'grupo' => 'Verificación del SG-SST', 'peso_grupo' => 5, 'item' => 'Definición de indicadores del SG-SST de acuerdo con las condiciones de la empresa', 'valor' => 1.25, 'orden' => 53],
            ['codigo' => '6.1.2', 'ciclo' => 'III. Verificar', 'grupo' => 'Verificación del SG-SST', 'peso_grupo' => 5, 'item' => 'Las empresa adelanta auditoría por lo menos una vez al año', 'valor' => 1.25, 'orden' => 54],
            ['codigo' => '6.1.3', 'ciclo' => 'III. Verificar', 'grupo' => 'Verificación del SG-SST', 'peso_grupo' => 5, 'item' => 'Revisión anual de la alta dirección resultados de la auditoría', 'valor' => 1.25, 'orden' => 55],
            ['codigo' => '6.1.4', 'ciclo' => 'III. Verificar', 'grupo' => 'Verificación del SG-SST', 'peso_grupo' => 5, 'item' => 'Planificar auditoría con el COPASST', 'valor' => 1.25, 'orden' => 56],
            ['codigo' => '7.1.1', 'ciclo' => 'IV. Actuar', 'grupo' => 'Mejoramiento', 'peso_grupo' => 10, 'item' => 'Definición de acciones preventivas y correctivas con base en los resultados del SG-SST', 'valor' => 2.5, 'orden' => 57],
            ['codigo' => '7.1.2', 'ciclo' => 'IV. Actuar', 'grupo' => 'Mejoramiento', 'peso_grupo' => 10, 'item' => 'Acciones de mejora conforme a revisión de la alta dirección', 'valor' => 2.5, 'orden' => 58],
            ['codigo' => '7.1.3', 'ciclo' => 'IV. Actuar', 'grupo' => 'Mejoramiento', 'peso_grupo' => 10, 'item' => 'Acciones de mejora con base en investigaciones de accidentes de trabajo y enfermedades laborales', 'valor' => 2.5, 'orden' => 59],
            ['codigo' => '7.1.4', 'ciclo' => 'IV. Actuar', 'grupo' => 'Mejoramiento', 'peso_grupo' => 10, 'item' => 'Elaboración Plan de Mejoramiento e implementación de medidas y acciones correctivas solicitadas por autoridades y ARL', 'valor' => 2.5, 'orden' => 60],
        ];

        foreach ($estandares as $e) {
            SstStandard::updateOrCreate(['codigo' => $e['codigo']], $e);
        }
    }
}
