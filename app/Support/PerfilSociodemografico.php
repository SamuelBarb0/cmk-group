<?php

namespace App\Support;

/**
 * Opciones de la encuesta de perfil sociodemográfico, tal como las escribe la
 * hoja «3.1.1 Encuesta Perfil Sociod» del libro de CMK.
 *
 * Viven aquí y no repartidas entre el controlador y el formulario de React
 * porque las usan los dos: la validación del backend y las listas
 * desplegables. Copiadas en dos sitios, basta con que alguien agregue una
 * opción en la pantalla para que el guardado empiece a fallar con un error de
 * validación que no dice nada.
 *
 * El texto va con las tildes y la redacción del Excel: es el que el consultor
 * reconoce y el que sale en la tabulación que se le entrega al cliente.
 */
final class PerfilSociodemografico
{
    public const ESTADO_CIVIL = [
        'Soltero (a)',
        'Casado (a) / unión libre',
        'Separado (a) / divorciado (a)',
        'Viudo (a)',
    ];

    public const PERSONAS_A_CARGO = [
        'Ninguna',
        '1 - 3 personas',
        '4 - 6 personas',
        'Más de 6 personas',
    ];

    public const ESCOLARIDAD = [
        'Primaria',
        'Secundaria',
        'Técnico / Tecnólogo',
        'Universitario',
        'Especialista / Maestría',
    ];

    public const TENENCIA_VIVIENDA = [
        'Propia',
        'Arrendada',
        'Familiar',
        'Compartida con otra(s) familia(s)',
    ];

    public const USO_TIEMPO_LIBRE = [
        'Otro trabajo',
        'Labores domésticas',
        'Recreación y deporte',
        'Estudio',
        'Ninguno',
    ];

    public const INGRESOS_SMLV = [
        'Mínimo legal (S.M.L.)',
        'Entre 1 a 3 S.M.L.',
        'Entre 4 a 5 S.M.L.',
        'Entre 5 y 6 S.M.L.',
        'Más de 7 S.M.L.',
    ];

    /** Se usa para la antigüedad en el cargo actual (pregunta 10). */
    public const ANTIGUEDAD = [
        'Menos de 1 año',
        'De 1 a 5 años',
        'De 5 a 10 años',
        'De 10 a 15 años',
        'Más de 15 años',
    ];

    /** Pregunta 12: selección múltiple. */
    public const ACTIVIDADES_SALUD = [
        'Cardiovasculares y visuales',
        'Salud oral',
        'Exámenes de laboratorio / otros',
        'Exámenes periódicos',
        'Gimnasia laboral (rumbaterapia, balonterapia, etc.)',
        'Capacitaciones en Seguridad y Salud en el Trabajo',
        'Ninguna',
    ];

    /** Frecuencia para alcohol y deporte (preguntas 13 y 16). */
    public const FRECUENCIA = [
        'Diario',
        'Semanal',
        'Quincenal',
        'Mensual',
        'Ocasional',
    ];

    /** Todo junto, para mandárselo de una vez a la pantalla. */
    public static function opciones(): array
    {
        return [
            'estado_civil' => self::ESTADO_CIVIL,
            'personas_a_cargo' => self::PERSONAS_A_CARGO,
            'escolaridad' => self::ESCOLARIDAD,
            'tenencia_vivienda' => self::TENENCIA_VIVIENDA,
            'uso_tiempo_libre' => self::USO_TIEMPO_LIBRE,
            'ingresos_smlv' => self::INGRESOS_SMLV,
            'antiguedad_cargo' => self::ANTIGUEDAD,
            'actividades_salud' => self::ACTIVIDADES_SALUD,
            'frecuencia' => self::FRECUENCIA,
        ];
    }

    /**
     * Reglas de validación del bloque sociodemográfico.
     *
     * Todo es opcional a propósito: la encuesta se diligencia cuando el
     * trabajador la responde, no cuando se crea su ficha, y exigirla al crear
     * bloquearía el alta de un empleado nuevo.
     */
    public static function reglas(): array
    {
        return [
            'estado_civil' => ['nullable', 'string', 'in:'.implode(',', self::ESTADO_CIVIL)],
            'personas_a_cargo' => ['nullable', 'string', 'in:'.implode(',', self::PERSONAS_A_CARGO)],
            'escolaridad' => ['nullable', 'string', 'in:'.implode(',', self::ESCOLARIDAD)],
            'tenencia_vivienda' => ['nullable', 'string', 'in:'.implode(',', self::TENENCIA_VIVIENDA)],
            'uso_tiempo_libre' => ['nullable', 'string', 'in:'.implode(',', self::USO_TIEMPO_LIBRE)],
            'ingresos_smlv' => ['nullable', 'string', 'in:'.implode(',', self::INGRESOS_SMLV)],
            'antiguedad_cargo' => ['nullable', 'string', 'in:'.implode(',', self::ANTIGUEDAD)],

            'actividades_salud' => ['nullable', 'array'],
            'actividades_salud.*' => ['string', 'in:'.implode(',', self::ACTIVIDADES_SALUD)],

            'consume_alcohol' => ['nullable', 'boolean'],
            'alcohol_frecuencia' => ['nullable', 'string', 'in:'.implode(',', self::FRECUENCIA)],
            'fuma' => ['nullable', 'boolean'],
            'fuma_promedio_dia' => ['nullable', 'string', 'max:30'],
            'practica_deporte' => ['nullable', 'boolean'],
            'deporte_frecuencia' => ['nullable', 'string', 'in:'.implode(',', self::FRECUENCIA)],

            'consentimiento_datos' => ['boolean'],
            'consentimiento_fecha' => ['nullable', 'date'],
        ];
    }
}
