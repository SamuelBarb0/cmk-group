<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * documentos:regenerar-base: arregla SOLO los documentos guardados como texto
 * plano y nunca toca lo editado, lo aprobado ni lo redactado por la IA.
 */
class RegenerarDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private DocumentTemplate $conBase;

    // Nowdoc: con comillas dobles, ${REPRESENTANTE} se leería como variable.
    private const BASE = <<<'MD'
        # POLÍTICA DE SST

        NOMBRE DE LA EMPRESA se compromete a:

        - Cumplir la ley.
        - Prevenir accidentes.

        | Firma | Fecha |
        | --- | --- |
        | ${REPRESENTANTE} | ${FECHA} |
        MD;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1', 'representante_legal' => 'María Gómez']);
        $this->conBase = DocumentTemplate::create(['codigo' => 'POL-SST', 'nombre' => 'Política', 'tipo' => 'politica', 'categoria' => 'SST', 'normas' => [], 'prompt' => '-', 'contenido_base' => self::BASE]);
    }

    private function doc(string $contenido, array $extra = [], ?DocumentTemplate $t = null): GeneratedDocument
    {
        $d = new GeneratedDocument(array_merge([
            'document_template_id' => ($t ?? $this->conBase)->id, 'titulo' => 'Política', 'contenido' => $contenido,
            'estado' => 'borrador', 'version' => 1,
        ], $extra));
        $d->tenant_id = $this->empresa->id;
        $d->created_at = Carbon::parse('2026-07-16 10:00');
        $d->save();

        return $d;
    }

    public function test_solo_regenera_el_texto_plano_y_conserva_la_fecha(): void
    {
        $plano = $this->doc("POLÍTICA DE SST\nEmpresa Demo se compromete a:\nCumplir la ley.\nPrevenir accidentes.");
        $conFormato = $this->doc("# Política redactada\n\n- Un punto propio");
        $editado = $this->doc('texto plano editado', ['version' => 2]);
        $aprobado = $this->doc('texto plano aprobado', ['estado' => 'aprobado']);
        $sinBase = DocumentTemplate::create(['codigo' => 'POL-PESV', 'nombre' => 'PESV', 'tipo' => 'politica', 'categoria' => 'PESV', 'normas' => [], 'prompt' => '-']);
        $ia = $this->doc('texto plano de la IA', [], $sinBase);

        // Sin --aplicar no escribe nada.
        $this->artisan('documentos:regenerar-base')->assertSuccessful();
        $this->assertStringStartsWith('POLÍTICA DE SST', $plano->fresh()->contenido);

        $this->artisan('documentos:regenerar-base --aplicar')->assertSuccessful();

        $nuevo = $plano->fresh();
        $this->assertStringContainsString('# POLÍTICA DE SST', $nuevo->contenido);
        $this->assertStringContainsString('- Prevenir accidentes.', $nuevo->contenido);
        $this->assertStringContainsString('Empresa Demo se compromete', $nuevo->contenido);
        // La fecha de emisión es la de creación, no la de hoy.
        $this->assertStringContainsString('16 de julio de 2026', $nuevo->contenido);
        $this->assertSame(1, $nuevo->version);

        // Lo demás, intacto.
        $this->assertSame("# Política redactada\n\n- Un punto propio", $conFormato->fresh()->contenido);
        $this->assertSame('texto plano editado', $editado->fresh()->contenido);
        $this->assertSame('texto plano aprobado', $aprobado->fresh()->contenido);
        $this->assertSame('texto plano de la IA', $ia->fresh()->contenido);

        // Respaldo del contenido anterior, y una segunda pasada no hace nada.
        $respaldo = Storage::disk('local')->files('respaldos');
        $this->assertCount(1, $respaldo);
        $this->assertSame([$plano->id => "POLÍTICA DE SST\nEmpresa Demo se compromete a:\nCumplir la ley.\nPrevenir accidentes."], json_decode(Storage::disk('local')->get($respaldo[0]), true));
        $this->artisan('documentos:regenerar-base --aplicar')->expectsOutputToContain('No hay documentos que regenerar')->assertSuccessful();
    }
}
