<?php

namespace Tests\Feature;

use App\Services\DocxExporter;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PhpWord parsea el HTML con DOMDocument::loadXML(), que es XML estricto: una
 * etiqueta vacía de HTML5 sin cerrar aborta la exportación y la descarga del
 * documento falla con «Opening and ending tag mismatch». Como el contenido lo
 * redacta la IA y mete HTML suelto en el Markdown, el exportador tiene que
 * dejarlo bien formado antes de entregárselo a PhpWord.
 */
class DocxExporterHtmlTest extends TestCase
{
    private function htmlParaWord(string $markdown): string
    {
        $metodo = new ReflectionMethod(DocxExporter::class, 'htmlParaWord');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(DocxExporter::class), $markdown);
    }

    /** @return array<string,array{0:string}> */
    public static function markdownDeLaIa(): array
    {
        return [
            'br sin cerrar' => ['Primera línea<br>segunda línea.'],
            'hr sin cerrar' => ["Antes\n\n<hr>\n\nDespués."],
            'img sin cerrar' => ['Firma: <img src="firma.png" alt="firma">'],
            'etiqueta sin cerrar' => ['<p>Párrafo que la IA no cerró'],
            'anidado al reves' => ['<strong><em>texto</strong></em>'],
            'markdown normal' => ["## Objeto\n\nLa empresa **se compromete** a:\n\n- Uno\n- Dos"],
        ];
    }

    #[DataProvider('markdownDeLaIa')]
    public function test_el_html_queda_bien_formado_para_phpword(string $markdown): void
    {
        $html = $this->htmlParaWord($markdown);

        $dom = new \DOMDocument;

        // loadXML() es exactamente lo que corre PhpWord por dentro.
        $this->assertTrue(
            $dom->loadXML('<body>'.$html.'</body>'),
            'PhpWord no podría parsear este HTML: '.$html,
        );
    }

    #[DataProvider('markdownDeLaIa')]
    public function test_phpword_acepta_el_html_sin_reventar(string $markdown): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        Html::addHtml($section, $this->htmlParaWord($markdown), false, false);

        $this->assertNotEmpty($section->getElements());
    }

    /**
     * El contenido base de las plantillas se extrajo a .txt: texto plano, con
     * saltos de linea simples y sin un solo marcador de Markdown. Markdown trata
     * el salto simple como un espacio, asi que un documento entero salia como UN
     * parrafo corrido —«REPORTE DE AUTOGESTION PESVPLAN ESTRATEGICO...»—.
     */
    public function test_el_texto_plano_conserva_sus_lineas(): void
    {
        $html = $this->htmlParaWord("REPORTE DE AUTOGESTIÓN PESV\nPLAN ESTRATÉGICO DE SEGURIDAD VIAL\nRESOLUCIÓN 40595");

        $this->assertSame(2, substr_count($html, '<br'), 'Las líneas se fundieron entre sí.');
        $this->assertStringNotContainsString('PESVPLAN', strip_tags(str_replace('<br />', "\n", $html)));
    }

    /** Y el Markdown de verdad tiene que seguir dando estructura, no líneas sueltas. */
    public function test_el_markdown_real_sigue_dando_encabezados_y_tablas(): void
    {
        $html = $this->htmlParaWord("# Objeto\n\nTexto.\n\n| Cargo | Responsable |\n| --- | --- |\n| SST | Ana |\n");

        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_no_se_pierde_el_texto_por_el_camino(): void
    {
        $html = $this->htmlParaWord('La política **se revisa** cada año<br>y se comunica a todos.');

        $this->assertStringContainsString('se revisa', $html);
        $this->assertStringContainsString('se comunica a todos', $html);
    }
}
