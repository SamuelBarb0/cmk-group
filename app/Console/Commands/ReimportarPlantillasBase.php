<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Services\PlantillaImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Vuelve a leer los .docx modelo de CMK y regenera el contenido base.
 *
 * POR QUE EXISTE: los `.txt` de `plantillas-base` se extrajeron a mano, antes de
 * que existiera PlantillaImporter. Son texto plano: sin `#` de encabezado y sin
 * tablas Markdown. Al exportar, ese texto se reconstruye como UN SOLO parrafo
 * corrido —un documento de 29 tablas salia como un muro de letras—. Ademas 15 de
 * las 24 plantillas nunca guardaron su .docx, asi que el export no tenia de donde
 * sacar el formato de CMK.
 *
 * Este comando arregla las dos cosas de una vez:
 *  1. copia el .docx original a `plantillas-base/{CODIGO}.docx` (si falta), para
 *     que DocxExporter pueda exportar sobre el Word real; y
 *  2. regenera `plantillas-base/{CODIGO}.txt` pasandolo por PlantillaImporter,
 *     que si emite encabezados y tablas Markdown.
 *
 * Es idempotente: se puede correr las veces que haga falta.
 */
class ReimportarPlantillasBase extends Command
{
    protected $signature = 'plantillas:reimportar
                            {--originales= : Carpeta con los .docx modelo de CMK (para las plantillas a las que les falta el suyo)}
                            {--solo= : Reimportar solo estos codigos, separados por coma}
                            {--dry-run : Muestra lo que haria sin escribir nada}';

    protected $description = 'Regenera el contenido base de las plantillas desde sus .docx modelo';

    /**
     * Plantillas que se quedaron sin .docx, y el nombre del original en la
     * CARPETA MODELO SGI de CMK. Los nombres son los de los archivos reales
     * (tildes y dobles espacios incluidos): se buscan tal cual.
     *
     * @var array<string,string>
     */
    private const ORIGINALES = [
        'FT-ACTA-REV-DIR' => '6.1.3 ACTA DE REVISIÓN POR LA ALTA DIRECCIÓN AL SISTEMA DE GESTIÓN DE SEGURIDAD Y SALUD EN EL TRABAJO.docx',
        'FT-AUTOGESTION-PESV' => 'PASO 20 REPORTE DE AUTOGESTIÓN PESV.docx',
        'FT-RECOM-MED' => '3.1.6 CARTAA DE RECOMENDACIONES MEDICAS.docx',
        'FT-RENDICION' => '2.6.1 INFORME DE RENDICIÓN DE CUENTAS.docx',
        'MAN-CONTROL-DOC' => '2.5.1 - PASO 19 MANUAL DE CONTROL DE DOCUMENTOS Y CONTROL DE CAMBIOS.docx',
        'MAN-FUNCIONES' => '1.1.1 - PASO 1 MANUAL DE FUNCIONES - COMPETENCIAS.docx',
        'PL-EMERGENCIAS' => '5.1.1 PLAN DE EMERGENCIAS.docx',
        'PR-EMO' => '3.1.4 - PASO 19 PROCEDIMIENTO PARA REALIZAR EXÁMENES MÉDICOS OCUPACIONALES.docx',
        'PR-GESTION-CAMBIO' => 'PASO 18 PROCEDIMIENTO DE GESTIÓN DEL CAMBIO.docx',
        'PR-IDONEIDAD' => 'PASO 11 PROCEDIMIENTO PARA APLICACIÓN DE PRUEBAS IDONEIDAD.docx',
        'PR-INFRACTORES' => 'PASO 11 PROCEDIMIENTO DE SEGUIMIENTO Y CONTROL A INFRACTORES DE TRANSITO.docx',
        'PR-INV-SINIESTROS' => 'PASO 13 PROCEDIMIENTO DE INVESTIGACIÓN DE SINISTROS VIALES.docx',
        'PR-PARTICIPACION' => 'PASO 24 -  ESTANDAR 2.8.1 PROCEDIMIENTO PARTICIPACIÓN, CONSULTA  Y COMUNICACIÓN.docx',
        'PR-PLAN-VIAJES' => 'PASO 15 PROCEDIMIENTO DE PLANIFICACIÓN DE VIAJES.docx',
        'PR-REV-DIRECCION' => '6.1.3 PROCEDIMIENTO DE REVISIÓN POR LA DIRECCIÓN.docx',
    ];

    private const CARPETA = 'plantillas-base';

    public function handle(PlantillaImporter $importer): int
    {
        $seco = (bool) $this->option('dry-run');
        $originales = $this->carpetaOriginales();
        $filtro = $this->filtro();

        $disco = Storage::disk('local');
        $plantillas = DocumentTemplate::whereNull('tenant_id')
            ->when($filtro !== null, fn ($q) => $q->whereIn('codigo', $filtro))
            ->orderBy('codigo')
            ->get();

        if ($plantillas->isEmpty()) {
            $this->warn('No hay plantillas del catalogo global que coincidan.');

            return self::SUCCESS;
        }

        $hechas = 0;
        $saltadas = 0;

        foreach ($plantillas as $plantilla) {
            $codigo = $plantilla->codigo;
            $destino = self::CARPETA."/{$codigo}.docx";
            $lectura = $disco->path($destino);

            // 1) Traer el .docx original si la plantilla no lo tiene todavia.
            if (! $disco->exists($destino)) {
                $fuente = $this->origen($codigo, $originales);

                if ($fuente === null) {
                    $this->line("  <fg=gray>—</> {$codigo}: sin .docx modelo, se deja como esta");
                    $saltadas++;

                    continue;
                }

                if ($seco) {
                    // En seco no se copia nada, asi que el texto se lee del
                    // original: el informe tiene que ser el mismo que en real.
                    $lectura = $fuente;
                } else {
                    $disco->put($destino, (string) file_get_contents($fuente));
                }

                $this->line("  <fg=green>+</> {$codigo}: copiado ".basename($fuente));
            }

            // 2) Regenerar el texto base desde ese .docx, ahora si como Markdown.
            try {
                $texto = $importer->extraerDe($lectura, 'docx');
            } catch (Throwable $e) {
                $this->error("  x {$codigo}: no se pudo leer el .docx — {$e->getMessage()}");
                $saltadas++;

                continue;
            }

            $antes = mb_strlen((string) $plantilla->contenido_base);

            if (! $seco) {
                $disco->put(self::CARPETA."/{$codigo}.txt", $texto);

                $plantilla->update([
                    'contenido_base' => $texto,
                    'archivo' => $destino,
                ]);
            }

            $this->line(sprintf(
                '  <fg=green>✓</> %-22s %s  (%d → %d chars, %d encabezados, %d tablas)',
                $codigo,
                $seco ? '[dry-run]' : 'actualizada',
                $antes,
                mb_strlen($texto),
                preg_match_all('/^#{1,6} /m', $texto),
                preg_match_all('/^\|(?: --- \|)+$/m', $texto),
            ));

            $hechas++;
        }

        $this->newLine();
        $this->info("Listo: {$hechas} plantillas reimportadas, {$saltadas} sin tocar.");

        if ($seco) {
            $this->comment('Era un dry-run: no se escribio nada.');
        }

        return self::SUCCESS;
    }

    /** Ruta del .docx original de una plantilla, o null si no se encuentra. */
    private function origen(string $codigo, ?string $carpeta): ?string
    {
        if ($carpeta === null || ! isset(self::ORIGINALES[$codigo])) {
            return null;
        }

        $ruta = rtrim($carpeta, '/\\').DIRECTORY_SEPARATOR.self::ORIGINALES[$codigo];

        return is_file($ruta) ? $ruta : null;
    }

    private function carpetaOriginales(): ?string
    {
        $carpeta = $this->option('originales');

        if ($carpeta === null) {
            return null;
        }

        if (! is_dir($carpeta)) {
            $this->warn("La carpeta de originales no existe: {$carpeta}");

            return null;
        }

        return $carpeta;
    }

    /** @return array<int,string>|null */
    private function filtro(): ?array
    {
        $solo = $this->option('solo');

        if (blank($solo)) {
            return null;
        }

        return collect(explode(',', $solo))
            ->map(fn (string $c) => strtoupper(trim($c)))
            ->filter()
            ->values()
            ->all();
    }
}
