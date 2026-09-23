<?php

namespace App\Support\Importacion;

use Illuminate\Support\Facades\Validator;

/**
 * Aplica un mapeo a las filas de una hoja: transforma cada celda según el
 * tipo del campo, valida con las reglas del módulo y marca duplicados.
 *
 * Es DETERMINISTA y es lo mismo que se ve en la vista previa y lo que se
 * guarda al confirmar: la IA solo propuso el mapeo, no tocó ningún dato.
 *
 * Estructura del mapeo:
 *   fila_inicio      índice (0-based) de la primera fila de datos
 *   columnas         campo => índice de columna | null
 *   nombre_completo  {columna, orden}: una sola columna con nombres y apellidos
 *   valores          campo => [valor original normalizado => valor destino]
 *   fijos            campo => valor, cuando la celda está vacía o no hay columna
 */
final class Aplicador
{
    /**
     * @param  list<list<string|null>>  $filas
     * @param  list<string>  $existentes  claves que ya están en la base (p. ej. documentos)
     * @return array{filas: list<array<string, mixed>>, resumen: array<string, int>}
     */
    public static function aplicar(array $destino, array $filas, array $mapeo, int $tenantId, array $existentes = []): array
    {
        $campos = $destino['campos'];
        $reglas = ($destino['reglas'])($tenantId);
        $clave = $destino['clave'];
        $existentes = array_flip(array_map([self::class, 'normalizar'], $existentes));
        $vistas = [];
        $salida = [];

        $inicio = max(0, (int) ($mapeo['fila_inicio'] ?? 1));
        $usadas = array_filter(array_merge(
            array_values($mapeo['columnas'] ?? []),
            [$mapeo['nombre_completo']['columna'] ?? null],
        ), fn ($c) => $c !== null);

        foreach (array_slice($filas, $inicio, null, true) as $i => $fila) {
            // Una fila sin nada en las columnas usadas no es un registro: es
            // relleno del Excel (bordes, totales vacíos). No se lista.
            if (array_filter(array_map(fn ($c) => $fila[$c] ?? null, $usadas), fn ($v) => $v !== null) === []) {
                continue;
            }

            [$datos, $errores] = self::fila($fila, $campos, $mapeo);
            // Fijos del módulo (is_active, aplica). No con «+=»: todos los campos
            // ya existen en $datos, aunque sea en null, y += no pisa una clave existente.
            foreach ($destino['fijos'] as $k => $v) {
                $datos[$k] ??= $v;
            }

            $k = ($clave !== null && ($datos[$clave] ?? null) !== null) ? self::normalizar((string) $datos[$clave]) : null;

            // Ya está en la base: es un duplicado, no un error. Se mira ANTES de
            // validar, porque la regla unique del formulario lo daría por error.
            if ($k !== null && isset($existentes[$k])) {
                $salida[] = ['fila' => $i + 1, 'estado' => 'duplicada', 'datos' => $datos, 'errores' => [$clave => 'Ya existe en la empresa: no se importa.']];

                continue;
            }

            // Con los nombres del módulo: «Número de documento», no «numero documento».
            $v = Validator::make($datos, $reglas, [], array_map(fn ($c) => mb_strtolower($c['label']), $campos));
            foreach ($v->errors()->messages() as $campo => $mensajes) {
                $errores[$campo] ??= $mensajes[0];
            }

            $estado = $errores === [] ? 'valida' : 'error';
            // Repetido dentro del archivo: se compara solo con filas válidas, para
            // no descartar una buena por una copia anterior que tenía errores.
            if ($estado === 'valida' && $k !== null) {
                if (isset($vistas[$k])) {
                    $estado = 'duplicada';
                    $errores[$clave] = 'Repetido en el archivo (fila '.$vistas[$k].').';
                } else {
                    $vistas[$k] = $i + 1;
                }
            }

            $salida[] = ['fila' => $i + 1, 'estado' => $estado, 'datos' => $datos, 'errores' => $errores];
        }

        $conteo = array_count_values(array_column($salida, 'estado'));

        return [
            'filas' => $salida,
            'resumen' => [
                'total' => count($salida),
                'validas' => $conteo['valida'] ?? 0,
                'errores' => $conteo['error'] ?? 0,
                'duplicadas' => $conteo['duplicada'] ?? 0,
            ],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function fila(array $fila, array $campos, array $mapeo): array
    {
        $datos = [];
        $errores = [];

        foreach ($campos as $campo => $def) {
            $col = $mapeo['columnas'][$campo] ?? null;
            $crudo = $col === null ? null : ($fila[$col] ?? null);

            if ($crudo === null || $crudo === '') {
                $fijo = $mapeo['fijos'][$campo] ?? null;
                $datos[$campo] = ($fijo === null || $fijo === '') ? null : self::transformar($fijo, $def, $mapeo['valores'][$campo] ?? [], $campo, $errores);

                continue;
            }

            $datos[$campo] = self::transformar($crudo, $def, $mapeo['valores'][$campo] ?? [], $campo, $errores);
        }

        // Una sola columna con el nombre completo -> nombres y apellidos.
        $nc = $mapeo['nombre_completo'] ?? null;
        if (($nc['columna'] ?? null) !== null && ($datos['nombres'] ?? null) === null && ($datos['apellidos'] ?? null) === null) {
            [$datos['nombres'], $datos['apellidos']] = self::partirNombre((string) ($fila[$nc['columna']] ?? ''), $nc['orden'] ?? 'nombres_apellidos');
        }

        return [$datos, $errores];
    }

    private static function transformar(string $crudo, array $def, array $valores, string $campo, array &$errores): mixed
    {
        $v = trim($crudo);

        switch ($def['tipo']) {
            case 'fecha':
                $f = self::fecha($v);
                if ($f === null) {
                    $errores[$campo] = "«{$v}» no es una fecha reconocible.";
                }

                return $f ?? $v;

            case 'numero':
            case 'entero':
                $n = self::numero($v);
                if ($n === null) {
                    $errores[$campo] = "«{$v}» no es un número.";

                    return $v;
                }

                return $def['tipo'] === 'entero' ? (int) round($n) : $n;

            case 'documento':
                // «1.098.765.432», «1098765432.0» o con espacios -> 1098765432.
                return preg_replace('/\.0+$|[.\s]/', '', $v);

            case 'booleano':
                $traducido = $valores[self::normalizar($v)] ?? null;
                if ($traducido !== null) {
                    return in_array(self::normalizar((string) $traducido), ['si', 'true', '1'], true);
                }
                $b = self::booleano($v);
                if ($b === null) {
                    $errores[$campo] = "«{$v}» no se entiende como sí o no.";
                }

                return $b;

            case 'lista':
                $opciones = $def['opciones'];
                $destino = $valores[self::normalizar($v)] ?? null;
                if ($destino === null) {
                    // El valor ya viene bien escrito (sin tildes ni mayúsculas que importen).
                    foreach ($opciones as $o) {
                        if (self::normalizar($o) === self::normalizar($v)) {
                            $destino = $o;
                        }
                    }
                }
                if ($destino === null || ! in_array((string) $destino, array_map('strval', $opciones), true)) {
                    $errores[$campo] = "«{$v}» no corresponde a ninguna opción (".implode(', ', $opciones).').';

                    return $v;
                }

                // Listas numéricas (ND, NE, NC de la GTC 45) viajan como número.
                return is_numeric($destino) ? (int) $destino : $destino;

            default:
                return $v;
        }
    }

    /** Día primero, como se escribe en Colombia. Acepta también el serial de Excel. */
    public static function fecha(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/', $v, $m)) {
            $anio = strlen($m[3]) === 2 ? 2000 + (int) $m[3] - ((int) $m[3] > 50 ? 100 : 0) : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $anio) ? sprintf('%04d-%02d-%02d', $anio, $m[2], $m[1]) : null;
        }
        // Serial de Excel que llegó como número (celda sin formato de fecha).
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 10000 && (float) $v < 80000) {
            return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
        }

        return null;
    }

    /** «$ 1.500.000», «1.500.000,50», «1500000.5», «2,5» -> número. */
    public static function numero(string $v): ?float
    {
        $s = preg_replace('/[^\d.,\-]/', '', $v);
        if ($s === '' || $s === '-') {
            return null;
        }
        $puntos = substr_count($s, '.');
        $comas = substr_count($s, ',');

        if ($puntos && $comas) {
            // El último separador es el decimal.
            $dec = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $s = str_replace($dec === ',' ? '.' : ',', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($puntos > 1 || ($puntos === 1 && preg_match('/\.\d{3}$/', $s) && strlen($s) > 4)) {
            $s = str_replace('.', '', $s);       // separador de miles
        } elseif ($comas > 1 || ($comas === 1 && preg_match('/,\d{3}$/', $s) && strlen($s) > 4)) {
            $s = str_replace(',', '', $s);       // miles con coma
        } else {
            $s = str_replace(',', '.', $s);      // decimal con coma
        }

        return is_numeric($s) ? (float) $s : null;
    }

    public static function booleano(string $v): ?bool
    {
        $n = self::normalizar($v);

        return match (true) {
            in_array($n, ['si', 's', 'x', '1', 'true', 'verdadero', 'aplica', 'yes', 'y'], true) => true,
            in_array($n, ['no', 'n', '0', 'false', 'falso', 'no aplica', 'na', 'n/a'], true) => false,
            default => null,
        };
    }

    /**
     * Parte «Nombres Apellidos» (o al revés) en dos. En Colombia casi siempre
     * hay dos apellidos: con 3 palabras o más, los apellidos son 2 y el resto
     * son nombres; con 2, una y una. No es infalible (apellidos compuestos),
     * por eso el resultado se ve en la vista previa antes de importar.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function partirNombre(string $completo, string $orden): array
    {
        $p = preg_split('/\s+/u', trim($completo), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($p);
        if ($n === 0) {
            return [null, null];
        }
        if ($n === 1) {
            return $orden === 'apellidos_nombres' ? [null, $p[0]] : [$p[0], null];
        }
        $ape = $n >= 3 ? 2 : 1;

        return $orden === 'apellidos_nombres'
            ? [implode(' ', array_slice($p, $ape)), implode(' ', array_slice($p, 0, $ape))]
            : [implode(' ', array_slice($p, 0, $n - $ape)), implode(' ', array_slice($p, $n - $ape))];
    }

    /** Minúsculas, sin tildes ni espacios sobrantes: para comparar valores. */
    public static function normalizar(string $v): string
    {
        $v = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));

        // Igual que normalizar() en importar/show.tsx (NFD + quitar marcas): la
        // pantalla y el servidor tienen que llegar a la misma clave.
        if (class_exists(\Normalizer::class)) {
            return preg_replace('/\p{M}/u', '', \Normalizer::normalize($v, \Normalizer::FORM_D));
        }

        return strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ç' => 'c', 'ã' => 'a', 'õ' => 'o']);
    }
}
