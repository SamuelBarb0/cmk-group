<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use App\Services\DocxExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * De donde sale el .docx que se descarga.
 *
 * El fallo que motiva estas pruebas: un formato de 29 tablas se descargaba como
 * cuatro parrafos de texto corrido. La plantilla no tenia guardado su .docx
 * modelo, asi que el exportador reconstruia el documento desde el texto — y el
 * texto era plano, sin un solo marcador de Markdown.
 *
 * Se protegen las dos mitades del arreglo: que con modelo se exporte SOBRE el
 * Word original (formato de CMK intacto), y que en cuanto el consultor edita se
 * vuelva a reconstruir, porque rellenar el modelo descartaria sus cambios.
 */
class DocxExporterModeloTest extends TestCase
{
    use RefreshDatabase;

    private const MODELO = 'plantillas-base/FT-AUTOGESTION-PESV.docx';

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Transportes del Norte S.A.S.',
            'nit' => '900123456-7',
        ]);
    }

    /** Plantilla apuntando al .docx modelo real de CMK (29 tablas y membrete). */
    private function plantillaConModelo(): DocumentTemplate
    {
        if (! is_file(storage_path('app/private/'.self::MODELO))) {
            $this->markTestSkipped('No esta el .docx modelo de referencia; corre plantillas:reimportar.');
        }

        return DocumentTemplate::create([
            'codigo' => 'FT-AUTOGESTION-PESV',
            'nombre' => 'Reporte de autogestion PESV',
            'tipo' => 'Formato',
            'categoria' => 'PESV',
            'normas' => ['Res. 40595'],
            'contenido_base' => "# Reporte\n\nContenido base.",
            'archivo' => self::MODELO,
            'prompt' => 'Completa el formato.',
            'orden' => 1,
        ]);
    }

    private function documento(Tenant $tenant, ?DocumentTemplate $plantilla, string $contenido, int $version = 1): GeneratedDocument
    {
        $doc = new GeneratedDocument([
            'document_template_id' => $plantilla?->id,
            'titulo' => 'Reporte de autogestion PESV',
            'contenido' => $contenido,
            'estado' => 'borrador',
            'version' => $version,
            'generado_por' => 'pruebas',
        ]);

        $doc->tenant_id = $tenant->id;
        $doc->save();

        return $doc;
    }

    /** @return array{tablas:int, saltos:int, texto:string} */
    private function radiografia(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, "No se pudo abrir el .docx exportado: {$ruta}");
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        // Los saltos de linea de Word son etiquetas vacias: si se quitan sin
        // dejar rastro, un documento bien partido en lineas parece un bloque
        // corrido y la prueba mediria lo contrario de lo que quiere medir.
        $plano = (string) preg_replace('/<w:(?:br|p)\b[^>]*>/', "\n", $xml);

        return [
            'tablas' => substr_count($xml, '<w:tbl>'),
            'saltos' => substr_count($xml, '<w:br/>') + substr_count($xml, '<w:br '),
            'texto' => strip_tags($plano),
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/temp/reporte-de-autogestion-pesv-v*.docx')) ?: [] as $f) {
            @unlink($f);
        }

        parent::tearDown();
    }

    public function test_un_documento_sin_editar_se_exporta_sobre_el_word_original(): void
    {
        $tenant = $this->tenant();
        $plantilla = $this->plantillaConModelo();
        $doc = $this->documento($tenant, $plantilla, 'Contenido en pantalla.', version: 1);

        $radio = $this->radiografia(app(DocxExporter::class)->export($doc));

        // El formato real trae 29 tablas: si se hubiera reconstruido desde el
        // texto de esta prueba («Contenido en pantalla.») no habria ninguna.
        $this->assertGreaterThan(20, $radio['tablas'], 'Se perdieron las tablas del formato original.');
    }

    public function test_un_documento_editado_se_reconstruye_para_no_perder_las_ediciones(): void
    {
        $tenant = $this->tenant();
        $plantilla = $this->plantillaConModelo();

        // Editar sube la version (AiDocumentController::update).
        $doc = $this->documento(
            $tenant,
            $plantilla,
            "## Conclusiones\n\nLo que el consultor escribio a mano.",
            version: 2,
        );

        $radio = $this->radiografia(app(DocxExporter::class)->export($doc));

        $this->assertStringContainsString('Lo que el consultor escribio a mano', $radio['texto']);
    }

    public function test_el_texto_plano_sin_modelo_no_sale_como_un_bloque_corrido(): void
    {
        $tenant = $this->tenant();

        // Tal cual venian los contenidos base extraidos a .txt: saltos simples,
        // sin encabezados ni lineas en blanco.
        $doc = $this->documento(
            $tenant,
            null,
            "REPORTE DE AUTOGESTION PESV\nPLAN ESTRATEGICO DE SEGURIDAD VIAL\nRESOLUCION 40595 DEL 2022",
            version: 1,
        );

        $radio = $this->radiografia(app(DocxExporter::class)->export($doc));

        $this->assertGreaterThanOrEqual(2, $radio['saltos'], 'Las lineas se fundieron en un solo parrafo.');
        $this->assertStringNotContainsString('PESVPLAN', $radio['texto']);
    }
}
