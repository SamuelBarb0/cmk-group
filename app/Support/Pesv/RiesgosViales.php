<?php

namespace App\Support\Pesv;

/**
 * Matriz de riesgos viales del PESV (paso 6): RE-SST-45 «Matriz para la
 * identificación de riesgos viales y medidas de control» de CMK, con la
 * metodología del anexo de la Res. 40595 (tablas 3, 4 y 5): nivel de
 * exposición × nivel de probabilidad, de 1 a 3 cada uno.
 */
final class RiesgosViales
{
    /** Factores de desempeño, con los factores que CMK trae precargados. */
    public const FACTORES = [
        'humano' => ['Factor humano', [
            'No cumplir con las normas de tránsito', 'Distancia de seguridad', 'Exceso de velocidad', 'Adelantamiento incorrecto',
            'Cansancio / fatiga', 'Giros prohibidos', 'No respetar la prelación', 'Transitar en contravía', 'Hablar por celular',
            'No usar el cinturón de seguridad', 'Desconcentración', 'Ingerir alcohol y drogas',
        ]],
        'vehiculo' => ['Factor vehículo', ['Seguridad activa', 'Seguridad pasiva', 'Estabilidad', 'Modelo del vehículo']],
        'via' => ['Factor vía', ['Diseño de la vía', 'Mal estado de la vía', 'Falta de señalización']],
        'ambiente' => ['Factor ambiente', ['Condiciones climáticas adversas', 'Visibilidad reducida', 'Sismos', 'Temblores', 'Inundaciones']],
    ];

    public const ROLES = ['conductor' => 'Conductor', 'motociclista' => 'Motociclista', 'ciclista' => 'Ciclista', 'peaton' => 'Peatón', 'pasajero' => 'Pasajero'];

    /** Tabla 3 del anexo. */
    public const EXPOSICION = [
        3 => 'Frecuente: más de 6 horas al día',
        2 => 'Ocasional: entre 3 y 6 horas al día',
        1 => 'Esporádica: menos de 3 horas al día',
    ];

    /** Tabla 4 del anexo. */
    public const PROBABILIDAD = [
        3 => 'Muy probable: sin controles o no son eficaces',
        2 => 'Poco probable: controles parciales',
        1 => 'No es probable: controles eficaces',
    ];

    public const ACCIONES = [
        'evitar' => 'Evitarlo',
        'aceptar' => 'Aceptarlo',
        'eliminar' => 'Eliminar la fuente de riesgo',
        'modificar' => 'Modificar los factores de exposición y probabilidad',
    ];

    public const CONTROLES = [
        'ingenieria' => 'Controles de ingeniería', 'capacitacion' => 'Capacitación', 'senalizacion' => 'Señalización',
        'epp' => 'EPP', 'inspecciones' => 'Inspecciones', 'otros' => 'Otros',
    ];

    /** Las cinco líneas de acción del PESV a las que apunta el control. */
    public const LINEAS = [
        'gestion_institucional' => 'Gestión institucional', 'comportamiento_seguro' => 'Comportamiento seguro',
        'vehiculo_seguro' => 'Vehículo seguro', 'infraestructura' => 'Infraestructura segura', 'atencion_victimas' => 'Atención a víctimas',
    ];

    /** Mapa de calor (tabla 5): 6 y 9 crítico, 3 y 4 moderado, 1 y 2 bajo. */
    public static function nivel(int $valor): string
    {
        return match (true) {
            $valor >= 6 => 'critico',
            $valor >= 3 => 'moderado',
            default => 'bajo',
        };
    }
}
