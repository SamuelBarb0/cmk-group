<?php

namespace App\Services\Ai;

use App\Services\AiService;
use App\Support\Importacion\Aplicador;
use App\Support\Importacion\LectorTabular;

/**
 * Le pide a Claude el MAPEO de una hoja de Excel a un módulo: en qué fila
 * empiezan los datos, qué columna va a qué campo y cómo se traducen los
 * valores de las listas cerradas. No le pide los datos: esos los transforma
 * el Aplicador, de forma determinista, sobre todas las filas.
 *
 * Así una nómina de 800 filas cuesta lo mismo que una de 20, y la IA no
 * puede inventar un registro: solo puede equivocarse de columna, y eso se ve
 * y se corrige en la vista previa.
 */
class MapeadorImportacion
{
    /** Filas que ve la IA para entender la estructura. */
    public const FILAS_MUESTRA = 25;

    /** Una columna con más valores distintos que esto no es una lista. */
    private const MAX_DISTINTOS = 40;

    public function __construct(private readonly AiService $ai) {}

    /**
     * @param  array<string, mixed>  $destino
     * @param  list<list<string|null>>  $filas
     * @return array<string, mixed> mapeo listo para el Aplicador
     */
    public function mapear(array $destino, array $filas, string $hoja): array
    {
        $propuesta = $this->ai->herramienta(
            $this->system($destino),
            $this->prompt($destino, $filas, $hoja),
            $this->herramienta($destino),
        );

        return self::sanear($propuesta, $destino, $filas);
    }

    private function system(array $destino): string
    {
        return <<<TXT
        Eres un analista de datos que ayuda a un consultor de seguridad y salud en el trabajo en Colombia
        a cargar un Excel de su cliente en el módulo «{$destino['nombre']}» de la plataforma.

        Tu única tarea es proponer el MAPEO con la herramienta `proponer_mapeo`. No copias ni corriges datos.

        Reglas:
        - Las filas y columnas se numeran desde 0, tal como aparecen en la muestra.
        - Los Excel de consultoría suelen tener filas de título, logos y encabezados partidos en dos filas.
          `fila_inicio_datos` es la primera fila que ya es un registro, no un encabezado.
        - Asigna una columna a un campo solo si estás razonablemente seguro por el encabezado y los datos.
          Si no hay columna para un campo, usa -1. Nunca uses la misma columna para dos campos.
        - Si los nombres y apellidos vienen juntos en una sola columna, NO la asignes a `nombres` ni a
          `apellidos`: usa `nombre_completo` e indica el orden según los ejemplos.
        - `valores`: para los campos de lista y de sí/no, traduce cada valor distinto que aparece en esa
          columna a una de las opciones permitidas. Si un valor no corresponde a ninguna, no lo traduzcas.
        - `fijos`: valor para un campo obligatorio que no tiene columna o viene vacío, SOLO si es evidente
          (p. ej. tipo de documento CC en una nómina colombiana sin esa columna). Si no es evidente, no lo inventes.
        - `advertencias`: en español, breve, lo que el consultor debe revisar (columnas dudosas, campos
          obligatorios sin columna, datos que parecen de otra cosa). Si todo está claro, lista vacía.
        TXT;
    }

    private function prompt(array $destino, array $filas, string $hoja): string
    {
        $campos = collect($destino['campos'])->map(function ($d, $k) {
            $linea = "- {$k}: {$d['label']} [{$d['tipo']}".(! empty($d['requerido']) ? ', obligatorio' : '').']';
            if (! empty($d['opciones'])) {
                $linea .= ' opciones: '.implode(' | ', $d['opciones']);
            }
            if (! empty($d['ayuda'])) {
                $linea .= " — {$d['ayuda']}";
            }

            return $linea;
        })->implode("\n");

        $muestra = collect(array_slice($filas, 0, self::FILAS_MUESTRA, true))->map(function ($fila, $i) {
            if ($fila === []) {
                return "fila {$i}: (vacía)";
            }
            $celdas = [];
            foreach ($fila as $c => $v) {
                if ($v !== null) {
                    $celdas[] = "[{$c}] ".mb_substr($v, 0, 80);
                }
            }

            return "fila {$i}: ".implode(' | ', $celdas);
        })->implode("\n");

        $distintos = collect(self::distintos($filas))->map(
            fn ($vals, $c) => "columna {$c} (".LectorTabular::letra($c).'): '.implode(' | ', array_map(fn ($v) => "«{$v}»", $vals))
        )->implode("\n");

        return "Hoja: «{$hoja}» (".count($filas)." filas en total).\n\n"
            ."CAMPOS DEL MÓDULO:\n{$campos}\n\n"
            .'MUESTRA (primeras '.self::FILAS_MUESTRA." filas; [n] es el número de columna):\n{$muestra}\n\n"
            ."VALORES DISTINTOS de las columnas con pocos valores (para traducir listas y sí/no):\n"
            .($distintos !== '' ? $distintos : '(ninguna)');
    }

    /**
     * Valores distintos por columna, solo en columnas con pocos valores cortos
     * (las candidatas a lista). Se mira hasta la fila 600: suficiente para ver
     * todas las variantes sin mandar el archivo entero.
     *
     * @return array<int, list<string>>
     */
    public static function distintos(array $filas): array
    {
        $por = [];
        foreach (array_slice($filas, 0, 600) as $fila) {
            foreach ($fila as $c => $v) {
                if ($v !== null && mb_strlen($v) <= 60) {
                    $por[$c][$v] = true;
                }
            }
        }

        return collect($por)
            ->filter(fn ($vals) => count($vals) <= self::MAX_DISTINTOS)
            // strval: PHP convierte en entero una clave como «4», y la pantalla
            // espera texto (le hace .trim()). Un «4» del Excel sigue siendo «4».
            ->map(fn ($vals) => array_map('strval', array_keys($vals)))
            ->sortKeys()
            ->all();
    }

    private function herramienta(array $destino): array
    {
        $campos = array_keys($destino['campos']);
        $conValores = array_keys(array_filter($destino['campos'], fn ($d) => in_array($d['tipo'], ['lista', 'booleano'], true)));
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return [
            'name' => 'proponer_mapeo',
            'description' => 'Propone cómo cargar la hoja en el módulo: fila de inicio, columna de cada campo, traducción de valores, valores fijos y advertencias.',
            'strict' => true,
            'input_schema' => $obj([
                'fila_encabezado' => ['type' => 'integer', 'description' => 'Fila (desde 0) con los encabezados; si están en dos filas, la de abajo.'],
                'fila_inicio_datos' => ['type' => 'integer', 'description' => 'Primera fila (desde 0) que es un registro.'],
                'columnas' => ['type' => 'array', 'items' => $obj([
                    'campo' => ['type' => 'string', 'enum' => $campos],
                    'columna' => ['type' => 'integer', 'description' => 'Número de columna desde 0, o -1 si no hay.'],
                ])],
                'nombre_completo' => $obj([
                    'columna' => ['type' => 'integer', 'description' => '-1 si nombres y apellidos vienen separados o no aplica.'],
                    'orden' => ['type' => 'string', 'enum' => ['nombres_apellidos', 'apellidos_nombres']],
                ]),
                'valores' => ['type' => 'array', 'items' => $obj([
                    'campo' => ['type' => 'string', 'enum' => $conValores ?: ['ninguno']],
                    'original' => ['type' => 'string'],
                    'destino' => ['type' => 'string', 'description' => 'Una de las opciones del campo; para sí/no: «si» o «no».'],
                ])],
                'fijos' => ['type' => 'array', 'items' => $obj([
                    'campo' => ['type' => 'string', 'enum' => $campos],
                    'valor' => ['type' => 'string'],
                ])],
                'advertencias' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]),
        ];
    }

    /**
     * Lo que devuelve la IA no se usa tal cual: una columna fuera de rango, un
     * campo que no existe o una traducción a un valor que no está en la lista
     * se descartan aquí. El mapeo que sale es siempre utilizable.
     */
    public static function sanear(array $p, array $destino, array $filas): array
    {
        $campos = $destino['campos'];
        $anchura = collect($filas)->map(fn ($f) => count($f))->max() ?? 0;
        $valida = fn ($c) => is_int($c) && $c >= 0 && $c < $anchura;

        $columnas = array_fill_keys(array_keys($campos), null);
        $usadas = [];
        foreach ($p['columnas'] ?? [] as $c) {
            $campo = $c['campo'] ?? null;
            $col = $c['columna'] ?? -1;
            if (isset($campos[$campo]) && $valida($col) && ! in_array($col, $usadas, true)) {
                $columnas[$campo] = $col;
                $usadas[] = $col;
            }
        }

        $nc = $p['nombre_completo'] ?? [];
        $nombreCompleto = ! empty($destino['nombre_completo']) && $valida($nc['columna'] ?? -1)
            ? ['columna' => $nc['columna'], 'orden' => ($nc['orden'] ?? '') === 'apellidos_nombres' ? 'apellidos_nombres' : 'nombres_apellidos']
            : ['columna' => null, 'orden' => 'nombres_apellidos'];
        if ($nombreCompleto['columna'] !== null) {
            // Si hay nombre completo, nombres y apellidos salen de él.
            $columnas['nombres'] = $columnas['apellidos'] = null;
        }

        $valores = [];
        foreach ($p['valores'] ?? [] as $v) {
            $def = $campos[$v['campo'] ?? ''] ?? null;
            if (! $def || ! isset($v['original'], $v['destino'])) {
                continue;
            }
            $ok = $def['tipo'] === 'booleano'
                ? in_array(Aplicador::normalizar($v['destino']), ['si', 'no'], true)
                : in_array($v['destino'], array_map('strval', $def['opciones'] ?? []), true);
            if ($ok) {
                $valores[$v['campo']][Aplicador::normalizar($v['original'])] = $v['destino'];
            }
        }

        $fijos = [];
        foreach ($p['fijos'] ?? [] as $f) {
            if (isset($campos[$f['campo'] ?? '']) && trim((string) ($f['valor'] ?? '')) !== '') {
                $fijos[$f['campo']] = trim($f['valor']);
            }
        }

        $total = count($filas);
        $inicio = (int) ($p['fila_inicio_datos'] ?? 1);

        return [
            'fila_encabezado' => max(0, min((int) ($p['fila_encabezado'] ?? 0), $total - 1)),
            'fila_inicio' => max(0, min($inicio, max(0, $total - 1))),
            'columnas' => $columnas,
            'nombre_completo' => $nombreCompleto,
            'valores' => $valores,
            'fijos' => $fijos,
            'advertencias' => array_values(array_filter(array_map('strval', $p['advertencias'] ?? []))),
        ];
    }
}
