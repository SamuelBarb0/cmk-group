<?php

namespace Database\Seeders;

use App\Models\FormFormat;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial del motor de formatos (Tier 4). Formatos reales del SGI
 * de CMK: inspecciones y actas. CMK puede añadir más desde la plataforma.
 *
 * Tipos de campo del esquema:
 *   text | textarea | date | number | select(opciones) | checklist(items) | firma
 */
class FormFormatsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->formatos() as $f) {
            FormFormat::updateOrCreate(['codigo' => $f['codigo']], $f);
        }
    }

    private function formatos(): array
    {
        return [
            [
                'codigo' => 'FT-INS-EXT',
                'nombre' => 'Inspección de Extintores',
                'categoria' => 'SST',
                'grupo' => 'inspeccion',
                'descripcion' => 'Verificación periódica del estado y ubicación de los extintores.',
                'orden' => 10,
                'schema' => [
                    'secciones' => [
                        [
                            'titulo' => 'Datos generales',
                            'campos' => [
                                ['key' => 'area', 'label' => 'Área / sede', 'tipo' => 'text', 'requerido' => true],
                                ['key' => 'responsable_area', 'label' => 'Responsable del área', 'tipo' => 'text'],
                            ],
                        ],
                        [
                            'titulo' => 'Verificación por extintor',
                            'campos' => [
                                ['key' => 'items', 'label' => 'Aspectos a verificar', 'tipo' => 'checklist', 'items' => [
                                    'Señalización visible y demarcada',
                                    'Acceso libre de obstáculos',
                                    'Manómetro en rango (aguja en verde)',
                                    'Manguera y boquilla en buen estado',
                                    'Pasador y precinto de seguridad intactos',
                                    'Etiqueta de recarga vigente',
                                    'Soporte/base a la altura reglamentaria',
                                ]],
                            ],
                        ],
                        [
                            'titulo' => 'Cierre',
                            'campos' => [
                                ['key' => 'observaciones', 'label' => 'Observaciones generales', 'tipo' => 'textarea'],
                                ['key' => 'firma', 'label' => 'Inspector', 'tipo' => 'firma'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'codigo' => 'FT-INS-EPP',
                'nombre' => 'Inspección de Elementos de Protección Personal (EPP)',
                'categoria' => 'SST',
                'grupo' => 'inspeccion',
                'descripcion' => 'Estado y uso de los EPP entregados a los trabajadores.',
                'orden' => 20,
                'schema' => [
                    'secciones' => [
                        [
                            'titulo' => 'Datos generales',
                            'campos' => [
                                ['key' => 'trabajador', 'label' => 'Trabajador', 'tipo' => 'text', 'requerido' => true],
                                ['key' => 'cargo', 'label' => 'Cargo', 'tipo' => 'text'],
                                ['key' => 'area', 'label' => 'Área / proceso', 'tipo' => 'text'],
                            ],
                        ],
                        [
                            'titulo' => 'Elementos verificados',
                            'campos' => [
                                ['key' => 'items', 'label' => 'EPP', 'tipo' => 'checklist', 'items' => [
                                    'Casco de seguridad',
                                    'Protección visual (gafas / monogafas)',
                                    'Protección auditiva',
                                    'Protección respiratoria',
                                    'Guantes según riesgo',
                                    'Calzado de seguridad',
                                    'Ropa de trabajo / dotación',
                                    'Arnés y eslinga (trabajo en altura)',
                                ]],
                            ],
                        ],
                        [
                            'titulo' => 'Cierre',
                            'campos' => [
                                ['key' => 'observaciones', 'label' => 'Observaciones', 'tipo' => 'textarea'],
                                ['key' => 'firma', 'label' => 'Inspector', 'tipo' => 'firma'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'codigo' => 'FT-INS-LOC',
                'nombre' => 'Inspección Locativa / Orden y Aseo',
                'categoria' => 'SST',
                'grupo' => 'inspeccion',
                'descripcion' => 'Condiciones locativas, orden, aseo y seguridad de las instalaciones.',
                'orden' => 30,
                'schema' => [
                    'secciones' => [
                        [
                            'titulo' => 'Datos generales',
                            'campos' => [
                                ['key' => 'area', 'label' => 'Área inspeccionada', 'tipo' => 'text', 'requerido' => true],
                            ],
                        ],
                        [
                            'titulo' => 'Condiciones',
                            'campos' => [
                                ['key' => 'items', 'label' => 'Aspectos', 'tipo' => 'checklist', 'items' => [
                                    'Pisos limpios, secos y sin obstáculos',
                                    'Rutas de evacuación despejadas y señalizadas',
                                    'Iluminación adecuada',
                                    'Instalaciones eléctricas en buen estado',
                                    'Almacenamiento seguro de materiales',
                                    'Áreas demarcadas',
                                    'Botiquín dotado y vigente',
                                    'Puntos de encuentro señalizados',
                                ]],
                            ],
                        ],
                        [
                            'titulo' => 'Cierre',
                            'campos' => [
                                ['key' => 'hallazgos', 'label' => 'Hallazgos y acciones propuestas', 'tipo' => 'textarea'],
                                ['key' => 'firma', 'label' => 'Inspector', 'tipo' => 'firma'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'codigo' => 'FT-ACTA-COPASST',
                'nombre' => 'Acta de Reunión COPASST',
                'categoria' => 'SST',
                'grupo' => 'acta',
                'descripcion' => 'Acta de reunión del Comité Paritario de Seguridad y Salud en el Trabajo.',
                'orden' => 40,
                'schema' => [
                    'secciones' => [
                        [
                            'titulo' => 'Encabezado',
                            'campos' => [
                                ['key' => 'numero_acta', 'label' => 'N.° de acta', 'tipo' => 'text'],
                                ['key' => 'lugar', 'label' => 'Lugar', 'tipo' => 'text'],
                                ['key' => 'hora_inicio', 'label' => 'Hora de inicio', 'tipo' => 'text'],
                                ['key' => 'hora_fin', 'label' => 'Hora de finalización', 'tipo' => 'text'],
                            ],
                        ],
                        [
                            'titulo' => 'Desarrollo',
                            'campos' => [
                                ['key' => 'asistentes', 'label' => 'Asistentes (nombre y cargo)', 'tipo' => 'textarea'],
                                ['key' => 'agenda', 'label' => 'Orden del día', 'tipo' => 'textarea'],
                                ['key' => 'desarrollo', 'label' => 'Desarrollo de la reunión', 'tipo' => 'textarea'],
                                ['key' => 'compromisos', 'label' => 'Compromisos (qué / responsable / fecha)', 'tipo' => 'textarea'],
                            ],
                        ],
                        [
                            'titulo' => 'Cierre',
                            'campos' => [
                                ['key' => 'proxima_reunion', 'label' => 'Fecha próxima reunión', 'tipo' => 'date'],
                                ['key' => 'firma', 'label' => 'Presidente / Secretario', 'tipo' => 'firma'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
