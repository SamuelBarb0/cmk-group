<?php

namespace App\Support;

/**
 * Formularios de selección, evaluación y verificación de contratistas y
 * proveedores, y cómo se califica cada uno. Todo sale de los Excel de CMK:
 *
 * - SELECCION: «8.4 SELEC PROVEED» (libro SGI). Criterios PONDERADOS que
 *   suman 100 % (comercial 40 %, HSE 60 %); cada uno vale 5, 3 o 1. El
 *   resultado va en escala de 1 a 5 y el proveedor entra al listado maestro
 *   con 3,8 o más (lo dice la hoja).
 * - EVALUACION: «8.4 EVALUACIÓN REEVLAUACIÓN». 9 criterios por PUNTOS
 *   (siempre 5 / casi siempre 3 / nunca 1; sí 5 / no 0).
 * - SST_JURIDICA y SST_NATURAL: «2.10.1 Lista contratistas Juri / Natu»
 *   (RE-SST-15 y RE-SST-16). Listas de CHEQUEO sí / no / N/A.
 * - TERCERO_CONDUCTOR y TERCERO_VEHICULO: los criterios del procedimiento
 *   de selección, evaluación y reevaluación de terceros (PR-TERCEROS).
 *
 * Regla común, escrita por CMK en la propia hoja de selección: «los
 * criterios que no apliquen se les asignará la máxima calificación». Es la
 * misma que usa el diagnóstico de la Res. 0312 con «no aplica».
 */
final class EvaluacionesContratistas
{
    /**
     * Bandas de la evaluación por puntos, en % del máximo. La hoja las da en
     * puntos para 8 criterios (35-40 confiable, 20-30 regular, < 20 no
     * confiable), pero la plantilla tiene 9 (máximo 45) y los tramos 31-34 y
     * 41-45 quedaron sin banda. Se pasan a porcentaje sobre los 40 originales
     * y el hueco va a la banda de abajo. PENDIENTE de confirmar con CMK.
     */
    public const CONFIABLE = 87.5;

    public const REGULAR = 50.0;

    /** Umbral del listado maestro en la selección ponderada (escala 1-5). */
    public const UMBRAL_SELECCION = 3.8;

    /** Evaluación «máximo cada cuatro meses» (PR-TERCEROS, 5.3). */
    public const MESES_ENTRE_EVALUACIONES = 4;

    public const RESULTADOS = [
        'apto' => 'Apto: ingresa al listado maestro',
        'no_apto' => 'No apto',
        'confiable' => 'Confiable',
        'regular' => 'Regular',
        'no_confiable' => 'No confiable',
        'cumple' => 'Cumple',
        'no_cumple' => 'Con incumplimientos',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function formatos(): array
    {
        $siempre = [['label' => 'Siempre', 'puntos' => 5], ['label' => 'Casi siempre', 'puntos' => 3], ['label' => 'Nunca', 'puntos' => 1]];
        $siNo5 = [['label' => 'Sí', 'puntos' => 5], ['label' => 'No', 'puntos' => 0]];
        $siNo1 = [['label' => 'Sí', 'puntos' => 1], ['label' => 'No', 'puntos' => 0]];
        $chequeo = [['label' => 'Cumple', 'puntos' => 1], ['label' => 'No cumple', 'puntos' => 0]];

        return [
            'SELECCION' => [
                'nombre' => 'Selección de proveedores (criterios ponderados)',
                'fuente' => '8.4 Selección de proveedores',
                'uso' => 'seleccion',
                'escala' => 'ponderada',
                'secciones' => [
                    self::seccion('Criterio de calidad y comercial (40 %)', [
                        self::ponderado('tiempo_respuesta', 'Tiempo de respuesta de la cotización', 0.05, ['Entre 0 y 4 horas', 'De 4 horas a 1 día', 'Más de 1 día']),
                        self::ponderado('precios', 'Precios', 0.06, ['Menor que el promedio del mercado', 'Igual al promedio del mercado', 'Mayor que el promedio del mercado']),
                        self::ponderado('experiencia', 'Experiencia', 0.04, ['Más de 3 años', 'Entre 1 y 3 años', 'Menos de 1 año']),
                        self::ponderado('forma_pago', 'Forma de pago', 0.06, ['Entre 45 y 90 días', 'Entre 30 y 45 días', 'Contado y sin posibilidad de crédito']),
                        self::ponderado('garantia', 'Garantía', 0.07, ['Otorga garantía total', 'Otorga garantía parcial', 'No otorga garantía']),
                        self::ponderado('atencion', 'Atención del proveedor', 0.06, ['Excelente', 'Buena', 'Mala']),
                        self::ponderado('disponibilidad', 'Disponibilidad de la mercancía', 0.06, ['Entre 0 y 7 días', 'Entre 8 y 15 días', '20 días o más']),
                    ]),
                    self::seccion('Criterios HSE (60 %)', [
                        self::ponderado('seguridad_social', 'Seguridad social', 0.10, [
                            'Realiza aportes de seguridad social de los empleados oportunamente (ARL, EPS)',
                            null,
                            'No hace aportes de seguridad social',
                        ]),
                        self::ponderado('gestion_ambiental', 'Gestión ambiental', 0.10, [
                            'Cuenta con un plan de gestión de residuos y lo implementa; gestiona su tratamiento y disposición final',
                            null,
                            'No realiza ninguna gestión para la recolección y disposición final de sus residuos',
                        ]),
                        self::ponderado('riesgos', 'Administración de riesgos', 0.10, [
                            'El reporte de ATEL se registra en cero (0)',
                            'Registra accidentes menores',
                            'Registra ATEL con consecuencias graves y/o accidentes mortales',
                        ]),
                        self::ponderado('requisitos_legales', 'Requisitos legales', 0.15, [
                            'Dispone de licencias, certificados, resoluciones y demás documentos legales que apliquen a su objeto social',
                            null,
                            'No cuenta con los documentos mínimos requeridos para prestar el servicio',
                        ]),
                        self::ponderado('sgsst', 'Decreto 1072 de 2015 y Resolución 0312 de 2019', 0.15, [
                            'Certifica que implementa el SG-SST',
                            null,
                            'No cuenta con el diseño e implementación del SG-SST',
                        ]),
                    ]),
                ],
            ],

            'EVALUACION' => [
                'nombre' => 'Evaluación y reevaluación de proveedores',
                'fuente' => '8.4 Evaluación y reevaluación',
                'uso' => 'evaluacion',
                'escala' => 'puntos',
                'secciones' => [
                    self::seccion('Criterios de evaluación', [
                        self::item('fichas_seguridad', 'Cumplimiento en fichas de seguridad: entrega las fichas de seguridad', $siNo5),
                        self::item('especificaciones', 'Cumplimiento de especificaciones: atiende las indicaciones desde la requisición hasta el registro de la factura', $siempre),
                        self::item('oportunidad', 'Oportunidad de entrega: entrega los productos conforme a lo acordado', $siempre),
                        self::item('cantidades', 'Cumplimiento de las cantidades: cumple lo estipulado en la requisición sin necesidad de correcciones', $siempre),
                        self::item('calidad', 'Calidad del servicio: cumple las especificaciones de la compañía', $siempre),
                        self::item('quejas', 'Atención y tratamiento de quejas o reclamos', $siempre),
                        self::item('precios', 'Precios de acuerdo con la calidad y el mercado', $siempre),
                        self::item('ambiental', 'Ambiental: mantiene al día y vigentes las licencias de funcionamiento según su actividad', $siNo5),
                        self::item('sst', 'SST: cumple los requisitos solicitados en SST', $siNo5),
                    ]),
                ],
            ],

            'SST_JURIDICA' => [
                'nombre' => 'Requisitos SG-SST — persona jurídica (RE-SST-15)',
                'fuente' => '2.10.1 Lista de chequeo contratistas persona jurídica',
                'uso' => 'requisitos_sst',
                'escala' => 'chequeo',
                'secciones' => [
                    self::seccion('Documentos que el proponente entrega con la propuesta', [
                        self::item('j1_arl', 'Certificación de su ARL con el porcentaje de avance del SG-SST según la última revisión y el nivel de riesgo al que está expuesto', $chequeo),
                        self::item('j1_induccion', 'Todo el personal del contratista, sin excepción, acredita haber recibido inducción en temas SST de la empresa', $chequeo),
                    ]),
                    self::seccion('Durante la ejecución del contrato u orden contractual', [
                        self::item('j2_politicas', 'Conoce, comunica y cumple las políticas de la empresa, y las hace cumplir a sus subcontratistas', $chequeo),
                        self::item('j2_alcohol', 'Conoce y cumple la política de prevención del consumo de alcohol, tabaco y sustancias psicoactivas', $chequeo),
                        self::item('j2_matriz', 'Presenta matriz de peligros (identificación, valoración y controles) para el objeto del contrato, incluidos los subcontratistas', $chequeo),
                        self::item('j2_listado', 'Cuenta con el listado de nombres y cédulas de sus trabajadores y subcontratistas', $chequeo),
                        self::item('j2_examenes', 'Exámenes médicos de ingreso, periódicos y de egreso', $chequeo),
                        self::item('j2_cambios', 'Notifica al supervisor del contrato cada cambio en el personal', $chequeo),
                        self::item('j2_seguridad_social', 'Paga y exige a sus subcontratistas los aportes de seguridad social que exige la ley', $chequeo),
                        self::item('j2_carne', 'Su personal porta el carné de EPS, ARL, cédula y carné de la empresa contratista', $chequeo),
                        self::item('j2_epp', 'Entrega y controla el uso de ropa adecuada y EPP según la actividad y los peligros', $chequeo),
                        self::item('j2_normas_epp', 'Los EPP cumplen las normas técnicas NTC, NIOSH (protección respiratoria), ANSI y demás exigidas por la ley colombiana', $chequeo),
                        self::item('j2_inventario_epp', 'Inspecciona y mantiene inventario suficiente de EPP para reponerlos por deterioro o pérdida', $chequeo),
                        self::item('j2_traslado', 'Tiene un procedimiento para el traslado y la atención inmediata de un accidentado', $chequeo),
                        self::item('j2_estadisticas', 'Lleva actualizadas las estadísticas de accidentalidad de sus actividades', $chequeo),
                        self::item('j2_estadisticas_min', 'Las estadísticas incluyen como mínimo: accidentes del mes, días de incapacidad, tipo, causas y medidas correctivas', $chequeo),
                        self::item('j2_certifica', 'Si no hay accidentes, lo certifica', $chequeo),
                        self::item('j2_investigacion', 'Investiga los accidentes y genera acciones sobre las causas básicas', $chequeo),
                        self::item('j2_emergencias', 'Mantiene equipos de emergencia (extintores, gabinetes, botiquín) y libres de obstáculos', $chequeo),
                        self::item('j2_acata', 'En emergencia acata las orientaciones del funcionario de seguridad y la señalización', $chequeo),
                        self::item('j2_capacitacion', 'Capacita y entrena a sus trabajadores y subcontratistas para prevenir accidentes y enfermedades laborales', $chequeo),
                    ]),
                ],
            ],

            'SST_NATURAL' => [
                'nombre' => 'Requisitos SG-SST — persona natural (RE-SST-16)',
                'fuente' => '2.10.1 Lista de chequeo contratistas persona natural',
                'uso' => 'requisitos_sst',
                'escala' => 'chequeo',
                'secciones' => [
                    self::seccion('Al ser seleccionado', [
                        self::item('n1_afiliacion', 'Entrega certificado de afiliación vigente a salud y pensión', $chequeo),
                        self::item('n1_arl', 'Entrega carta de intención de afiliarse o no a la ARL', $chequeo),
                        self::item('n1_subcontratistas', 'Si tiene subcontratistas, les exige los mismos documentos', $chequeo),
                    ]),
                    self::seccion('Durante la ejecución', [
                        self::item('n2_planillas', 'Copia de las planillas de pago a EPS, AFP y ARL', $chequeo),
                        self::item('n2_induccion', 'Acredita inducción en temas SST de la empresa (y la exige a sus subcontratistas)', $chequeo),
                        self::item('n2_alcohol', 'Conoce y cumple la política de prevención del consumo de alcohol, tabaco y sustancias psicoactivas', $chequeo),
                        self::item('n2_vacunas', 'Certificado de vacunación para zonas endémicas a las que viaje por el contrato', $chequeo),
                        self::item('n2_epp', 'Usa ropa adecuada y EPP acordes a la actividad y los riesgos', $chequeo),
                        self::item('n2_normas_epp', 'Los EPP cumplen las especificaciones técnicas de la ley colombiana', $chequeo),
                        self::item('n2_reporta', 'Reporta los accidentes de trabajo a su ARL', $chequeo),
                        self::item('n2_emergencias', 'Mantiene equipos de emergencia (botiquín, extintores) y libres de obstáculos', $chequeo),
                        self::item('n2_acata', 'En emergencia acata las orientaciones de la empresa y la señalización', $chequeo),
                    ]),
                ],
            ],

            'TERCERO_CONDUCTOR' => [
                'nombre' => 'Evaluación de terceros — conductor',
                'fuente' => 'PR-TERCEROS 5.3.1',
                'uso' => 'evaluacion',
                'escala' => 'puntos',
                'secciones' => [
                    self::seccion('Criterios', array_map(fn ($t, $i) => self::item("c{$i}", $t, $siNo1), [
                        'Cumplimiento de la programación',
                        'Legaliza oportunamente los anticipos',
                        'Entrega mensualmente la planilla de pagos de seguridad social',
                        'No registra pérdida de guías',
                        'Tiene curso de manejo defensivo vigente',
                        'Tiene curso de manejo de sustancias peligrosas vigente',
                        'Reporta oportunamente accidentes y casi accidentes',
                        'No registra accidentes de trabajo',
                        'Usa y mantiene en buen estado sus EPP',
                        'Porta las tarjetas de emergencia de los productos transportados',
                        'Mantiene en buen estado el kit de derrames del vehículo',
                        'Participa en las capacitaciones de la empresa',
                        'Reporta faltantes de los viajes realizados',
                        'Para en puntos autorizados durante sus recorridos',
                        'Mantiene vigentes sus documentos personales',
                        'Solicita autorización para el tránsito nocturno',
                        'Lleva oportunamente el vehículo a la inspección HSE trimestral',
                        'No tiene reportes por vencimiento de guías sin justa causa',
                        'No tiene reportes por incumplir normas de seguridad o procedimientos',
                        'No tiene reportes por cambio de ruta sin autorización',
                    ], range(1, 20))),
                ],
            ],

            'TERCERO_VEHICULO' => [
                'nombre' => 'Evaluación de terceros — vehículo',
                'fuente' => 'PR-TERCEROS 5.3.2',
                'uso' => 'evaluacion',
                'escala' => 'puntos',
                'secciones' => [
                    self::seccion('Criterios', array_map(fn ($t, $i) => self::item("v{$i}", $t, $siNo1), [
                        'Mantiene vigentes y actualizados los documentos',
                        'Mantiene vigente la prueba de luz negra de quinta rueda y/o king pin',
                        'Registra la inspección HSE periódicamente',
                        'Dispone de satelital en buen estado y funcionando',
                        'Dispone del kit de contingencias completo y en buen estado',
                        'Dispone del botiquín de primeros auxilios completo y en buen estado',
                        'Se le hacen los ajustes o mantenimientos sugeridos en las inspecciones',
                        'No tiene reportes de los puestos de control por mantenimiento',
                        'No tiene reportes por varadas en carretera',
                    ], range(1, 9))),
                ],
            ],
        ];
    }

    /**
     * Califica unas respuestas contra la estructura del formulario.
     * Cada respuesta es el índice de la opción elegida o 'na'.
     *
     * @param  array<string, mixed>  $formato
     * @param  array<string, array{opcion: int|string, observacion?: ?string}>  $respuestas
     * @return array{puntaje: float, porcentaje: float, resultado: string, incumplimientos: int}
     */
    public static function calificar(array $formato, array $respuestas): array
    {
        $obtenido = 0.0;
        $maximo = 0.0;
        $incumplimientos = 0;

        foreach ($formato['secciones'] as $s) {
            foreach ($s['items'] as $item) {
                $puntos = array_column($item['opciones'], 'puntos');
                $max = max($puntos);
                $peso = $item['peso'] ?? 1.0;
                $opcion = $respuestas[$item['key']]['opcion'] ?? null;

                // N/A = máxima calificación (regla de CMK). Sin respuesta, lo
                // mismo no pasa: el controlador exige responderlo todo.
                $valor = $opcion === 'na' ? $max : (float) ($item['opciones'][(int) $opcion]['puntos'] ?? 0);
                if ($opcion !== 'na' && $valor < $max && $formato['escala'] === 'chequeo') {
                    $incumplimientos++;
                }

                $obtenido += $valor * $peso;
                $maximo += $max * $peso;
            }
        }

        $porcentaje = $maximo > 0 ? round($obtenido / $maximo * 100, 1) : 0.0;

        return match ($formato['escala']) {
            // Los pesos suman 1: el total ya está en la escala 1-5 de la hoja.
            'ponderada' => [
                'puntaje' => round($obtenido, 2),
                'porcentaje' => $porcentaje,
                'resultado' => round($obtenido, 2) >= self::UMBRAL_SELECCION ? 'apto' : 'no_apto',
                'incumplimientos' => 0,
            ],
            'puntos' => [
                'puntaje' => round($obtenido, 2),
                'porcentaje' => $porcentaje,
                'resultado' => self::clasificar($porcentaje),
                'incumplimientos' => 0,
            ],
            default => [
                'puntaje' => round($obtenido, 2),
                'porcentaje' => $porcentaje,
                'resultado' => $incumplimientos === 0 ? 'cumple' : 'no_cumple',
                'incumplimientos' => $incumplimientos,
            ],
        };
    }

    public static function clasificar(float $porcentaje): string
    {
        return $porcentaje >= self::CONFIABLE ? 'confiable' : ($porcentaje >= self::REGULAR ? 'regular' : 'no_confiable');
    }

    /** Claves de todos los ítems, para validar que se respondió todo. */
    public static function claves(array $formato): array
    {
        return collect($formato['secciones'])->flatMap(fn ($s) => array_column($s['items'], 'key'))->all();
    }

    private static function seccion(string $titulo, array $items): array
    {
        return ['titulo' => $titulo, 'items' => $items];
    }

    private static function item(string $key, string $texto, array $opciones): array
    {
        return ['key' => $key, 'texto' => $texto, 'opciones' => $opciones];
    }

    /**
     * Criterio ponderado: tres niveles 5 / 3 / 1. Cuando la hoja solo trae
     * dos (sí o no hace aportes), el nivel intermedio es null y se omite.
     */
    private static function ponderado(string $key, string $texto, float $peso, array $niveles): array
    {
        $opciones = [];
        foreach ([5, 3, 1] as $i => $puntos) {
            if ($niveles[$i] !== null) {
                $opciones[] = ['label' => $niveles[$i], 'puntos' => $puntos];
            }
        }

        return ['key' => $key, 'texto' => $texto, 'peso' => $peso, 'opciones' => $opciones];
    }
}
