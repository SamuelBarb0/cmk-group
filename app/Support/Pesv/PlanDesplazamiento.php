<?php

namespace App\Support\Pesv;

/**
 * Planificación de desplazamientos laborales por ruta (paso 15): RE-SST-69
 * «Planificación de desplazamientos laborales» de CMK. Los campos y tablas
 * son los de la hoja; el plan se guarda en `pesv_routes.plan`.
 */
final class PlanDesplazamiento
{
    /** @var array<string, array{0: string, 1: string}> clave => [etiqueta, tipo] */
    public const CAMPOS = [
        'hora_salida' => ['Hora de salida', 'hora'],
        'hora_llegada' => ['Hora de llegada', 'hora'],
        'km_pavimentados' => ['Km pavimentados', 'numero'],
        'km_destapados' => ['Km destapados', 'numero'],
        'municipios' => ['Municipios o veredas que atraviesa', 'texto'],
        'rutas_alternas' => ['Rutas alternas', 'texto'],
        'rutas_bloqueadas' => ['Rutas bloqueadas o restringidas', 'texto'],
        'tipo_vehiculo' => ['Tipo de vehículo', 'linea'],
        'producto' => ['Producto o carga que transporta', 'linea'],
        'preoperacional' => ['Exige inspección preoperacional antes de salir', 'si_no'],
        'documentos' => ['Exige verificar documentos del vehículo y del conductor', 'si_no'],
        'recomendaciones' => ['Recomendaciones por tipo de tramo (destapado, urbano, curvas, intersecciones, puentes, rectas)', 'texto'],
    ];

    /** @var array<string, array{titulo: string, columnas: array<string, string>, filas_fijas?: list<string>}> */
    public const TABLAS = [
        'velocidades' => [
            'titulo' => 'Límites de velocidad (km/h)',
            'columnas' => ['zona' => 'Zona', 'cargado' => 'Cargado', 'descargado' => 'Descargado', 'requisito' => 'Requisito'],
            'filas_fijas' => ['Locaciones', 'Vía destapada', 'Vía pavimentada', 'Áreas urbanas', 'Zona escolar', 'Descensos peligrosos', 'Otro'],
        ],
        'paradas' => [
            'titulo' => 'Sitios autorizados para detenerse y pernoctar',
            'columnas' => ['km' => 'Km', 'nombre' => 'Nombre', 'municipio' => 'Municipio', 'servicios' => 'Servicios'],
        ],
        'puestos_control' => [
            'titulo' => 'Puestos de control',
            'columnas' => ['km' => 'Km', 'nombre' => 'Nombre', 'municipio' => 'Municipio', 'servicios' => 'Servicios'],
        ],
        'puntos_criticos' => [
            'titulo' => 'Puntos críticos de la ruta',
            'columnas' => ['km_desde' => 'Km desde', 'km_hasta' => 'Km hasta', 'sentido' => 'Sentido', 'descripcion' => 'Descripción'],
        ],
        'apoyo' => [
            'titulo' => 'Puntos de apoyo para emergencias',
            'columnas' => ['entidad' => 'Entidad', 'ubicacion' => 'Ubicación', 'telefono' => 'Teléfono', 'equipos' => 'Equipos disponibles', 'contacto' => 'Responsable / contacto'],
        ],
        'directorio' => [
            'titulo' => 'Directorio de emergencia',
            'columnas' => ['entidad' => 'Entidad', 'area' => 'Área', 'telefono' => 'Teléfono'],
        ],
    ];

    /** Plan vacío con las filas fijas (zonas de velocidad) ya puestas. */
    public static function vacio(): array
    {
        return [
            'campos' => (object) [],
            'tablas' => collect(self::TABLAS)->map(fn ($t) => collect($t['filas_fijas'] ?? [])->map(fn ($z) => ['zona' => $z])->all())->all(),
        ];
    }

    /** Solo claves conocidas, textos cortos, sin filas vacías y con tope de filas. */
    public static function sanear(array $entrada): array
    {
        $campos = [];
        foreach (self::CAMPOS as $k => [$etq, $tipo]) {
            $v = $entrada['campos'][$k] ?? null;
            $campos[$k] = match ($tipo) {
                'si_no' => filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                'numero' => is_numeric($v) && $v >= 0 ? $v + 0 : null,
                'hora' => is_string($v) && preg_match('/^\d{2}:\d{2}$/', $v) ? $v : null,
                default => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $tipo === 'texto' ? 3000 : 255) : null,
            };
        }

        $tablas = [];
        foreach (self::TABLAS as $clave => $def) {
            $entradas = array_values(array_filter((array) ($entrada['tablas'][$clave] ?? []), 'is_array'));
            $limpiar = fn (array $fila) => collect($def['columnas'])->keys()
                ->mapWithKeys(fn ($c) => [$c => is_scalar($fila[$c] ?? null) ? mb_substr(trim((string) $fila[$c]), 0, 255) : ''])->all();

            if (isset($def['filas_fijas'])) {
                // Las zonas las pone la norma: se buscan por nombre y no se aceptan filas nuevas.
                $porZona = collect($entradas)->keyBy(fn ($f) => (string) ($f['zona'] ?? ''));
                $tablas[$clave] = array_map(fn ($zona) => ['zona' => $zona] + $limpiar($porZona[$zona] ?? []), $def['filas_fijas']);

                continue;
            }

            $tablas[$clave] = collect(array_slice($entradas, 0, 50))->map($limpiar)
                ->filter(fn ($f) => collect($f)->contains(fn ($v) => $v !== ''))->values()->all();
        }

        return ['campos' => array_filter($campos, fn ($v) => $v !== null), 'tablas' => $tablas];
    }

    /** ¿El plan tiene lo mínimo: horario, velocidades y algún apoyo de emergencia? */
    public static function completo(?array $plan): bool
    {
        if (! $plan) {
            return false;
        }
        $velocidades = collect($plan['tablas']['velocidades'] ?? [])->filter(fn ($f) => ($f['cargado'] ?? '') !== '' || ($f['descargado'] ?? '') !== '');

        return ! empty($plan['campos']['hora_salida']) && $velocidades->isNotEmpty()
            && (! empty($plan['tablas']['apoyo']) || ! empty($plan['tablas']['directorio']));
    }
}
