<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Relleno DETERMINISTA de documentos modelo con los datos del cliente.
 *
 * Para los documentos modelo del SGI (que solo cambian datos, no contenido
 * técnico), rellenar con la IA es lento, caro y arriesga alterar texto de
 * cumplimiento. Aquí se reemplazan los marcadores de forma exacta e instantánea.
 *
 * FUENTE ÚNICA DE VERDAD del mapa cliente→valores: la usan tanto la generación
 * en pantalla (AiDocumentController) como el export a Word (DocxExporter).
 *
 * Reemplaza SOLO marcadores inequívocos:
 *  - tokens `${TOKEN}` (docs ya tokenizados: POL-SGI, procedimientos, …);
 *  - el literal «NOMBRE DE LA EMPRESA» / «NOMBRE EMPRESA» (docs sin tokenizar).
 * NO toca "LA EMPRESA", "FECHA", "C.C." ni "XXXX" sueltos: son prosa, encabezados
 * de tabla o campos de formulario y reemplazarlos corrompería el documento.
 */
class DocumentFiller
{
    /** Mapa TOKEN => valor del cliente (fuente única de verdad). */
    public function tokens(Tenant $tenant): array
    {
        return [
            'EMPRESA' => $tenant->name,
            'ACTIVIDAD' => $tenant->actividad_economica,
            'NIT' => $tenant->nit,
            'REPRESENTANTE' => $tenant->representante_legal,
            'CIUDAD' => $tenant->city,
            'CC' => $tenant->representante_cc,
            'FECHA' => $this->fechaLarga(),
            'NIVEL_RIESGO' => $tenant->nivel_riesgo,
            'ARL' => $tenant->arl,
            'RESPONSABLE_SGSST' => $tenant->responsable_sgsst,
            'RESPONSABLE' => $tenant->responsable_sgsst,
        ];
    }

    /**
     * Rellena el contenido base del modelo con los datos del tenant y le
     * antepone un encabezado de identificación del cliente. Determinista.
     */
    public function fill(string $base, Tenant $tenant): string
    {
        $tokens = $this->tokens($tenant);

        // 1) Tokens ${TOKEN} -> valor (o [PENDIENTE] si falta el dato).
        $out = preg_replace_callback('/\$\{([A-Z_]+)\}/', function (array $m) use ($tokens) {
            return array_key_exists($m[1], $tokens) && filled($tokens[$m[1]])
                ? $tokens[$m[1]]
                : '[PENDIENTE]';
        }, $base);

        // 2) Literal «NOM(M)BRE (DE LA) EMPRESA» -> nombre del cliente.
        $nombre = filled($tenant->name) ? $tenant->name : '[PENDIENTE]';
        $out = preg_replace_callback('/NOM+BRE\s+(?:DE\s+LA\s+)?EMPRESA/iu', fn () => $nombre, $out);

        return $this->encabezado($tenant)."\n\n".$out;
    }

    /** Bloque de identificación del cliente al inicio del documento. */
    private function encabezado(Tenant $tenant): string
    {
        $v = fn ($x) => filled($x) ? $x : '[PENDIENTE]';

        return '> **Empresa:** '.$v($tenant->name)
            .'  ·  **NIT:** '.$v($tenant->nit)
            .'  ·  **Ciudad:** '.$v($tenant->city)."\n"
            .'> **Representante legal:** '.$v($tenant->representante_legal)
            .'  ·  **Responsable SG-SST:** '.$v($tenant->responsable_sgsst)
            .'  ·  **Fecha:** '.$this->fechaLarga();
    }

    private function fechaLarga(): string
    {
        return Carbon::now()->locale('es')->translatedFormat('d \d\e F \d\e Y');
    }
}
