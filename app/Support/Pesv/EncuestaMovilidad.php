<?php

namespace App\Support\Pesv;

use Illuminate\Support\Collection;

/**
 * Encuesta de movilidad del PESV (paso 5, diagnóstico): RE-SST-36
 * «Caracterización de movilidad (Encuesta de riesgos viales)» de CMK, con sus
 * preguntas y opciones literales. La tabulación es la RE-SST-37.
 *
 * Tipos: texto, numero, fecha, unica (una opción), multiple (varias).
 * `si` = [pregunta, valor]: la pregunta solo aplica si la otra tiene ese valor
 * (o lo incluye, si es múltiple).
 */
final class EncuestaMovilidad
{
    private const SI_NO = ['Sí', 'No'];

    /** @return list<array{titulo: string, preguntas: list<array<string, mixed>>}> */
    public static function secciones(): array
    {
        return [
            ['titulo' => 'Datos generales', 'preguntas' => [
                ['clave' => 'nombre', 'texto' => 'Nombres y apellidos', 'tipo' => 'texto', 'requerida' => true],
                ['clave' => 'documento', 'texto' => 'Número de identificación', 'tipo' => 'texto', 'requerida' => true],
                ['clave' => 'ciudad', 'texto' => 'Ciudad', 'tipo' => 'texto'],
                ['clave' => 'cargo', 'texto' => 'Cargo', 'tipo' => 'texto'],
                ['clave' => 'edad', 'texto' => 'Edad', 'tipo' => 'numero', 'min' => 15, 'max' => 90],
                ['clave' => 'genero', 'texto' => 'Género', 'tipo' => 'unica', 'opciones' => ['Masculino', 'Femenino', 'Otro']],
                ['clave' => 'grupo', 'texto' => 'Grupo de trabajo al que pertenece', 'tipo' => 'unica', 'opciones' => ['Administrativo', 'Comercial', 'Técnico', 'Operativo', 'Otro']],
                ['clave' => 'horario', 'texto' => 'Horario de trabajo', 'tipo' => 'unica', 'opciones' => ['Turnos', 'Tiempo completo', 'Jornada continua', 'No tiene horario', 'Otro']],
                ['clave' => 'mas_8_horas', 'texto' => '¿Trabaja más de ocho horas?', 'tipo' => 'unica', 'opciones' => ['Sí', 'No', 'Algunas veces']],
                ['clave' => 'contrato', 'texto' => 'Tipo de contrato', 'tipo' => 'unica', 'opciones' => ['Indefinido', 'Fijo', 'Contratista', 'Prestación de servicios', 'Otro']],
                ['clave' => 'requiere_desplazarse', 'texto' => '¿Requiere desplazarse durante su jornada laboral?', 'tipo' => 'unica', 'opciones' => self::SI_NO, 'requerida' => true],
            ]],
            ['titulo' => 'Conducción', 'preguntas' => [
                ['clave' => 'licencia', 'texto' => '¿Tiene licencia de conducción?', 'tipo' => 'unica', 'opciones' => self::SI_NO, 'requerida' => true],
                ['clave' => 'licencia_categoria', 'texto' => 'Categoría de la licencia', 'tipo' => 'unica', 'opciones' => ['A1', 'A2', 'B1', 'B2', 'B3', 'C1', 'C2', 'C3'], 'si' => ['licencia', 'Sí']],
                ['clave' => 'licencia_vigencia', 'texto' => 'Fecha de vigencia de la licencia', 'tipo' => 'fecha', 'si' => ['licencia', 'Sí']],
                ['clave' => 'experiencia', 'texto' => 'Experiencia en conducción (años)', 'tipo' => 'numero', 'min' => 0, 'max' => 70, 'si' => ['licencia', 'Sí']],
                ['clave' => 'vehiculo_propio', 'texto' => '¿Cuenta con vehículo propio?', 'tipo' => 'unica', 'opciones' => self::SI_NO],
                ['clave' => 'tipo_vehiculo', 'texto' => 'Tipo de vehículo', 'tipo' => 'multiple', 'opciones' => ['Motocicleta', 'Automóvil', 'Camioneta', 'Bicicleta', 'Otro'], 'si' => ['vehiculo_propio', 'Sí']],
            ]],
            ['titulo' => 'Accidentes e incidentes', 'preguntas' => [
                ['clave' => 'accidentes', 'texto' => '¿Ha tenido en los últimos cinco años algún accidente de tránsito?', 'tipo' => 'unica', 'opciones' => self::SI_NO],
                ['clave' => 'accidente_circunstancias', 'texto' => 'Las circunstancias están relacionadas con', 'tipo' => 'multiple', 'opciones' => ['Choque simple', 'Volcamiento', 'Caída', 'Atropello', 'Otro'], 'si' => ['accidentes', 'Sí']],
                ['clave' => 'accidente_descripcion', 'texto' => 'Describa brevemente las circunstancias', 'tipo' => 'texto', 'largo' => true, 'si' => ['accidentes', 'Sí']],
                ['clave' => 'incidentes', 'texto' => '¿Ha tenido en los últimos cinco años algún incidente de tránsito con daños materiales, pero no personales?', 'tipo' => 'unica', 'opciones' => self::SI_NO],
            ]],
            ['titulo' => 'Desplazamientos en misión', 'preguntas' => [
                ['clave' => 'mision_frecuencia', 'texto' => '¿Con qué frecuencia realiza desplazamientos en misión?', 'tipo' => 'unica', 'opciones' => ['A diario', 'Alguna vez a la semana', 'Uno o dos veces al mes', 'Varias veces al año', 'No realizo desplazamientos en misión']],
                ['clave' => 'mision_medio', 'texto' => 'Medio de desplazamiento que utiliza para los trayectos en misión', 'tipo' => 'multiple', 'opciones' => ['A pie', 'En bicicleta', 'Transporte público', 'Moto o ciclomotor', 'Vehículo de la empresa', 'Vehículo propio', 'Bicicleta eléctrica', 'Otro']],
                ['clave' => 'mision_planifica', 'texto' => 'Mis desplazamientos en misión son, en general, planificados por', 'tipo' => 'unica', 'opciones' => ['Mí mismo', 'La empresa']],
                ['clave' => 'mision_antelacion', 'texto' => '¿Con cuánto tiempo de antelación se suelen prever sus misiones?', 'tipo' => 'unica', 'opciones' => ['El mismo día', 'Con un día de anticipación', 'Con una semana de anticipación', 'Con dos semanas de anticipación', 'Con un mes de anticipación']],
                ['clave' => 'mision_km_mes', 'texto' => 'Kilómetros mensuales recorridos en la labor profesional', 'tipo' => 'numero', 'min' => 0, 'max' => 100000],
            ]],
            ['titulo' => 'Trayectos in itinere (casa - trabajo)', 'preguntas' => [
                ['clave' => 'itinere_medio', 'texto' => 'Medios de desplazamiento para los trayectos casa - trabajo', 'tipo' => 'multiple', 'opciones' => ['A pie', 'En bicicleta', 'Transporte público', 'Moto o ciclomotor', 'Transporte colectivo de la empresa', 'Vehículo propio', 'Bicicleta eléctrica', 'Otro'], 'requerida' => true],
                ['clave' => 'itinere_km', 'texto' => 'Kilómetros diarios entre el trabajo y el domicilio (ida y vuelta)', 'tipo' => 'numero', 'min' => 0, 'max' => 1000],
                ['clave' => 'itinere_horas', 'texto' => 'Tiempo medio diario de desplazamiento entre el trabajo y el domicilio (ida y vuelta, horas)', 'tipo' => 'numero', 'min' => 0, 'max' => 24],
            ]],
            ['titulo' => 'Factores de riesgo', 'preguntas' => [
                ['clave' => 'factor_humano', 'texto' => 'Factores de riesgo motivados por el factor humano', 'tipo' => 'multiple', 'opciones' => ['Exceso de velocidad', 'Exceso de confianza', 'Fatiga', 'Desconocimiento de las normas de tránsito', 'Distracción', 'Estrés', 'Sueño', 'Impericia de conductores', 'Imprudencia de peatones y/o pasajeros', 'Riesgo público']],
                ['clave' => 'factor_vehiculo', 'texto' => 'Factores de riesgo motivados por el vehículo', 'tipo' => 'multiple', 'opciones' => ['Falta de mantenimiento', 'Fallas mecánicas', 'Ergonomía', 'Inadecuada capacidad de carga y/o pasajeros']],
                ['clave' => 'factor_via', 'texto' => 'Factores de riesgo motivados por la vía', 'tipo' => 'multiple', 'opciones' => ['Clima', 'Estado de la vía', 'Falta de iluminación', 'Falta de señalización']],
                ['clave' => 'causas', 'texto' => 'Causas que motivan el riesgo', 'tipo' => 'multiple', 'opciones' => ['Intensidad del tráfico', 'Condiciones climatológicas', 'Tipo de vehículo o sus características, estado del vehículo', 'Organización del trabajo (agenda, reuniones, tiempos de entrega, etc.)', 'Su propia conducción', 'Su estado psicofísico (cansancio, estrés, sueño, etc.)', 'Otros conductores', 'Estado de la infraestructura / vía', 'Falta de información o formación en seguridad vial', 'Otras']],
                ['clave' => 'riesgo_percibido', 'texto' => 'Concrete el riesgo que percibe', 'tipo' => 'texto', 'largo' => true],
                ['clave' => 'propuestas', 'texto' => 'Sus propuestas para reducir el riesgo de accidente', 'tipo' => 'texto', 'largo' => true],
            ]],
            ['titulo' => 'Riesgos viales por rol', 'preguntas' => [
                ['clave' => 'rol', 'texto' => '¿Con qué rol en la vía se identifica más?', 'tipo' => 'multiple', 'opciones' => ['Peatón', 'Pasajero', 'Ciclista', 'Conductor', 'Acompañante', 'Motociclista'], 'requerida' => true],
                ['clave' => 'conductas_peaton', 'texto' => 'Como peatón, conductas en las que incurre', 'tipo' => 'multiple', 'si' => ['rol', 'Peatón'], 'opciones' => ['Atravesar el tráfico vehicular en lugares donde existen pasos peatonales', 'Usar el celular y/o audífonos mientras camina', 'No respetar las señales de tránsito', 'Cruzar la calle cuando el semáforo está en amarillo o rojo']],
                ['clave' => 'conductas_pasajero', 'texto' => 'Como pasajero, conductas en las que incurre', 'tipo' => 'multiple', 'si' => ['rol', 'Pasajero'], 'opciones' => ['Bajarse del transporte público en movimiento', 'No usar los paraderos para bajarse y subirse del bus', 'Viajar colgado en el vehículo']],
                ['clave' => 'conductas_ciclista', 'texto' => 'Como ciclista, conductas en las que incurre', 'tipo' => 'multiple', 'si' => ['rol', 'Ciclista'], 'opciones' => ['Usar el celular y/o audífonos', 'No usar elementos de protección personal (casco, rodilleras, reflectores, gafas, etc.)', 'Movilizarse en el centro de la vía y por entre los vehículos', 'Mantenimiento inadecuado de la bicicleta', 'No respetar las señales de tránsito', 'Conducir en estado de embriaguez o después de consumir sustancias alucinógenas']],
                ['clave' => 'conductas_motociclista', 'texto' => 'Como motociclista, conductas en las que incurre', 'tipo' => 'multiple', 'si' => ['rol', 'Motociclista'], 'opciones' => ['Usar el celular y/o audífonos', 'Transitar usando varios carriles o en medio de los vehículos', 'No respetar las señales de tránsito', 'Conducir en estado de embriaguez o después de consumir sustancias alucinógenas', 'Exceso de velocidad', 'Llevar carga excesiva', 'Llevar el casco del parrillero colgado en la mano', 'Tomar medicamentos que puedan producir sueño antes de conducir']],
                ['clave' => 'conductas_conductor', 'texto' => 'Como conductor, conductas en las que incurre', 'tipo' => 'multiple', 'si' => ['rol', 'Conductor'], 'opciones' => ['Usar el celular, audífonos, etc. mientras conduce', 'No respetar las señales de tránsito', 'Conducir en estado de embriaguez o después de consumir sustancias alucinógenas', 'Exceso de velocidad', 'Tomar medicamentos que puedan producir sueño antes de conducir', 'No usar el cinturón de seguridad', 'No asegurar adecuadamente la carga en el vehículo', 'Transitar ocupando varios carriles']],
            ]],
        ];
    }

    /** @return Collection<string, array<string, mixed>> clave => pregunta */
    public static function preguntas(): Collection
    {
        return collect(self::secciones())->flatMap(fn ($s) => $s['preguntas'])->keyBy('clave');
    }

    /** ¿La pregunta aplica con estas respuestas? */
    public static function aplica(array $pregunta, array $respuestas): bool
    {
        if (! isset($pregunta['si'])) {
            return true;
        }
        [$otra, $valor] = $pregunta['si'];
        $r = $respuestas[$otra] ?? null;

        return is_array($r) ? in_array($valor, $r, true) : $r === $valor;
    }

    /**
     * Valida y limpia las respuestas: solo claves y opciones del catálogo, y
     * nada de lo que no aplica. Devuelve [respuestas, errores].
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function sanear(array $entrada): array
    {
        $limpio = [];
        $errores = [];
        foreach (self::preguntas() as $clave => $p) {
            if (! self::aplica($p, $limpio)) {
                continue;
            }
            $v = $entrada[$clave] ?? null;
            $valor = match ($p['tipo']) {
                'unica' => in_array($v, $p['opciones'], true) ? $v : null,
                'multiple' => ($m = array_values(array_intersect($p['opciones'], (array) $v))) ? $m : null,
                'numero' => is_numeric($v) && $v >= ($p['min'] ?? 0) && $v <= ($p['max'] ?? PHP_INT_MAX) ? $v + 0 : null,
                'fecha' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null,
                default => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 2000) : null,
            };
            if ($valor !== null) {
                $limpio[$clave] = $valor;
            } elseif (! empty($p['requerida'])) {
                $errores["respuestas.{$clave}"] = "Responde «{$p['texto']}».";
            }
        }

        return [$limpio, $errores];
    }

    /**
     * Tabulación (RE-SST-37): por pregunta de opciones, cuántos y qué % la
     * eligieron sobre quienes la respondieron; por pregunta numérica, promedio
     * y, para la experiencia, los rangos de la hoja de CMK.
     *
     * @param  Collection<int, array<string, mixed>>  $respuestas
     * @return list<array<string, mixed>>
     */
    public static function tabular(Collection $respuestas): array
    {
        $bloques = [];
        foreach (self::preguntas() as $clave => $p) {
            $valores = $respuestas->pluck($clave)->filter(fn ($v) => $v !== null && $v !== []);
            if (in_array($p['tipo'], ['unica', 'multiple'], true)) {
                $n = $valores->count();
                $bloques[] = [
                    'clave' => $clave, 'texto' => $p['texto'], 'tipo' => $p['tipo'], 'respondieron' => $n,
                    'opciones' => collect($p['opciones'])->map(function ($o) use ($valores, $n) {
                        $c = $valores->filter(fn ($v) => is_array($v) ? in_array($o, $v, true) : $v === $o)->count();

                        return ['opcion' => $o, 'cantidad' => $c, 'porcentaje' => $n ? round($c * 100 / $n, 1) : 0];
                    })->all(),
                ];
            } elseif ($p['tipo'] === 'numero' && $valores->isNotEmpty()) {
                $bloque = ['clave' => $clave, 'texto' => $p['texto'], 'tipo' => 'numero', 'respondieron' => $valores->count(), 'promedio' => round($valores->avg(), 1)];
                if ($clave === 'experiencia') {
                    $rangos = ['1-3 años' => [0, 3], '4-6 años' => [4, 6], '7-9 años' => [7, 9], '10-15 años' => [10, 15], 'Más de 15 años' => [16, 999]];
                    $bloque['opciones'] = collect($rangos)->map(fn ($r, $etq) => [
                        'opcion' => $etq,
                        'cantidad' => $c = $valores->filter(fn ($v) => $v >= $r[0] && $v <= $r[1])->count(),
                        'porcentaje' => round($c * 100 / $valores->count(), 1),
                    ])->values()->all();
                }
                $bloques[] = $bloque;
            }
        }

        return $bloques;
    }
}
