<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lo que PHP deja subir de verdad: el menor entre upload_max_filesize y
 * post_max_size (el POST entero, con el formulario, tiene que caber).
 *
 * OJO: en los Hostinger la CLI y la web tienen límites distintos; este valor
 * es el de la petición que lo pregunta, así que en pantalla sale el correcto.
 */
final class LimiteSubida
{
    public static function bytes(): int
    {
        $limites = array_filter([
            self::aBytes((string) ini_get('upload_max_filesize')),
            self::aBytes((string) ini_get('post_max_size')),
        ]);

        // Un poco por debajo del POST: el formulario también ocupa.
        return (int) (($limites ? min($limites) : 8 * 1024 ** 2) * 0.98);
    }

    /** Para la regla `max:` de Laravel, que va en KB. */
    public static function kilobytes(): int
    {
        return intdiv(self::bytes(), 1024);
    }

    private static function aBytes(string $valor): int
    {
        $valor = trim($valor);
        $n = (int) $valor;

        return match (Str::lower(substr($valor, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
