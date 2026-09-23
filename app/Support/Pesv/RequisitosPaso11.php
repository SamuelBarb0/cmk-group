<?php

namespace App\Support\Pesv;

/**
 * Listas de requisitos del PASO 11 del PESV (responsabilidad y comportamiento
 * seguro), tal como las usa CMK:
 *  - Operador: RE-SST-51 «Lista de chequeo de requisitos del operador».
 *  - Vehículo: RE-SST-50 «Lista de verificación de requisitos del vehículo».
 *
 * `diferible`: el requisito se puede cumplir hasta un mes después del
 * ingreso (nota (*) de la hoja del operador). `cuando_aplique`: solo para
 * cierto tipo de vehículo (cisterna, tractocamión…), nota (*) del vehículo.
 */
final class RequisitosPaso11
{
    public const RESPUESTAS = ['cumple', 'no_cumple', 'no_aplica'];

    /** @var array<string, array<string, array{0: string, 1?: bool}>> grupo => [clave => [texto, diferible]] */
    public const OPERADOR = [
        'General' => [
            'hoja_vida' => ['Hoja de vida actualizada'],
            'cedula' => ['Cédula'],
            'antecedentes_judiciales' => ['Antecedentes judiciales'],
            'antecedentes_disciplinarios' => ['Antecedentes disciplinarios'],
            'licencia' => ['Licencia de conducción'],
            'simit' => ['SIMIT (consulta de comparendos)'],
            'runt' => ['Carta pantalla MinTransporte (RUNT)'],
        ],
        'Medicina preventiva' => [
            'examen_ocupacional' => ['Certificación médico ocupacional (ingreso / periódico / retiro)'],
            'electrocardiograma' => ['Electrocardiograma'],
            'vacunacion' => ['Esquema de vacunación (fiebre amarilla / tétanos)'],
            'crc' => ['Certificado de reconocimiento de conductores (CRC)'],
            'psicosensometrica' => ['Prueba psicosensométrica'],
            'alcohol_drogas' => ['Examen de alcohol y drogas'],
            'seguridad_social' => ['Seguridad social (EPS, ARL, AFP)'],
            'epp_dotacion' => ['Entrega de EPP y dotación'],
        ],
        'Formación y entrenamiento' => [
            'induccion' => ['Registro de inducción / reinducción'],
            'manejo_defensivo' => ['Manejo defensivo'],
            'mercancias_peligrosas' => ['Mercancías peligrosas'],
            'mecanica_basica' => ['Mecánica básica', true],
            'prueba_practica' => ['Prueba práctica de conducción', true],
            'alturas' => ['Trabajo en alturas'],
            'control_incendios' => ['Control de incendios'],
            'primeros_auxilios' => ['Primeros auxilios'],
        ],
        'Experiencia' => [
            'certificaciones_laborales' => ['Certificaciones laborales según el perfil'],
        ],
    ];

    /** @var array<string, array{0: string, 1?: bool}> clave => [texto, cuando_aplique] */
    public const VEHICULO = [
        'licencia_transito' => ['Licencia de tránsito'],
        'licencia_transito_tanque' => ['Licencia de tránsito del tanque', true],
        'rtm' => ['Certificado de revisión técnico-mecánica y de emisión de gases'],
        'soat' => ['SOAT'],
        'poliza_rc_cabezote' => ['Póliza de responsabilidad civil del cabezote', true],
        'poliza_rc_tanque' => ['Póliza de responsabilidad civil del tanque', true],
        'poliza_hidrocarburos' => ['Póliza de hidrocarburos', true],
        'prueba_hidrostatica' => ['Prueba hidrostática', true],
        'quinta_rueda' => ['Prueba de luz negra de quinta rueda y/o king pin', true],
        'gps' => ['GPS (usuario y clave de monitoreo)'],
        'lineas_vida' => ['Inspección de líneas de vida', true],
        'inspeccion_vehiculo' => ['Inspección del vehículo'],
        'kit_contingencia' => ['Kit de contingencia', true],
        'herramienta_basica' => ['Herramienta básica'],
        'preoperacional' => ['Inspección preoperacional'],
        'plan_mantenimiento' => ['Documentación del plan de mantenimiento'],
        'botiquin' => ['Botiquín'],
    ];

    /** @return array<string, string> clave => texto, del operador */
    public static function clavesOperador(): array
    {
        return collect(self::OPERADOR)->flatMap(fn ($items) => collect($items)->map(fn ($i) => $i[0]))->all();
    }

    /**
     * Resultado de una lista: con alguna «no cumple» no pasa; con alguna sin
     * responder queda pendiente; si no, pasa. «No aplica» no cuenta.
     *
     * @param  array<string, string>  $claves  clave => texto (el catálogo)
     * @param  array<string, array{estado?: string}>  $respuestas
     */
    public static function resultado(array $claves, array $respuestas): string
    {
        $estados = collect(array_keys($claves))->map(fn ($k) => $respuestas[$k]['estado'] ?? null);

        return match (true) {
            $estados->contains('no_cumple') => 'no_cumple',
            $estados->contains(null) => 'pendiente',
            default => 'cumple',
        };
    }

    /**
     * Limpia lo que llega del formulario: solo claves del catálogo, estados
     * válidos y observación corta.
     *
     * @param  array<string, string>  $claves
     * @return array<string, array{estado: string, obs: ?string}>
     */
    public static function sanear(array $claves, array $entrada): array
    {
        $limpio = [];
        foreach ($claves as $k => $texto) {
            $estado = $entrada[$k]['estado'] ?? null;
            if (in_array($estado, self::RESPUESTAS, true)) {
                $obs = trim((string) ($entrada[$k]['obs'] ?? ''));
                $limpio[$k] = ['estado' => $estado, 'obs' => $obs === '' ? null : mb_substr($obs, 0, 500)];
            }
        }

        return $limpio;
    }
}
