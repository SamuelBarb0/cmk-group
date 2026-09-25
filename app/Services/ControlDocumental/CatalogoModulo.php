<?php

namespace App\Services\ControlDocumental;

use App\Models\DocumentCatalogEntry;
use App\Models\NormRequirement;

/**
 * Para los módulos que mandan sus matrices al control documental: encuentra
 * el documento del catálogo del SIG que les corresponde y los requisitos que
 * evidencia. Por nombre y no por número, porque el código lo genera cada
 * empresa y la numeración SIG-xx depende del orden del catálogo.
 */
class CatalogoModulo
{
    public static function entrada(string $modulo, string $tipo, string $fragmentoNombre): ?DocumentCatalogEntry
    {
        return DocumentCatalogEntry::query()
            ->where('modulo', $modulo)
            ->where('tipo', $tipo)
            ->where('nombre', 'like', "%{$fragmentoNombre}%")
            ->first();
    }

    /**
     * @param  list<string>  $fragmentosTitulo
     * @return list<string> claves comunes (SIG-xx)
     */
    public static function claves(string $modulo, array $fragmentosTitulo): array
    {
        return NormRequirement::query()
            ->where('modulo', $modulo)
            ->where(function ($q) use ($fragmentosTitulo) {
                foreach ($fragmentosTitulo as $t) {
                    $q->orWhere('titulo', 'like', "%{$t}%");
                }
            })
            ->distinct()
            ->pluck('clave_comun')
            ->values()
            ->all();
    }

    /** Un texto dentro de una celda de tabla markdown: sin saltos de línea ni barras. */
    public static function celda(?string $texto): string
    {
        $t = trim((string) $texto);

        return $t === '' ? '—' : str_replace(['|', "\r\n", "\n", "\r"], ['/', ' ', ' ', ' '], $t);
    }
}
