<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocxExporter;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Logo y firmas del cliente: se suben en Organización y se estampan en los
 * documentos que se exportan a Word.
 *
 * Los .docx modelo reales de CMK se copian al disco falso para que la prueba
 * mida lo que pasa con ESOS documentos (su «LOGO» y su «Firma del
 * representante legal» tal como los dejó Word), no con uno hecho a medida.
 */
class MarcaClienteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $modelos = [];
        foreach (['PR-FATIGA', 'POL-SGI'] as $codigo) {
            $real = storage_path("app/private/plantillas-base/{$codigo}.docx");
            if (is_file($real)) {
                $modelos[$codigo] = file_get_contents($real);
            }
        }
        Storage::fake('local');
        foreach ($modelos as $codigo => $contenido) {
            Storage::disk('local')->put("plantillas-base/{$codigo}.docx", $contenido);
        }

        $this->empresa = Tenant::create([
            'name' => 'Transportes del Norte S.A.S.',
            'nit' => '900123456-7',
            'representante_legal' => 'Ana Gómez',
            'representante_cc' => '52123456',
            'responsable_sgsst' => 'Luis Pérez',
            'licencia_sgsst' => 'LIC-2024-889',
        ]);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function como(User $user, ?Tenant $empresa = null)
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($user)->withSession(['active_tenant_id' => ($empresa ?? $this->empresa)->id]);
    }

    private function subir(string $tipo, ?UploadedFile $archivo = null)
    {
        return $this->como($this->consultor)->post("/organizacion/marca/{$tipo}", [
            'archivo' => $archivo ?? UploadedFile::fake()->image("{$tipo}.png", 300, 120),
        ]);
    }

    // --- Subida y acceso -------------------------------------------------

    public function test_sube_reemplaza_y_quita_el_logo(): void
    {
        $this->subir('logo')->assertSessionHasNoErrors();
        $primera = $this->empresa->fresh()->logo_path;
        Storage::disk('local')->assertExists($primera);
        $this->assertStringStartsWith("tenants/{$this->empresa->id}/marca/", $primera);

        // Reemplazar no deja el archivo viejo huérfano en el disco.
        $this->subir('logo')->assertSessionHasNoErrors();
        $segunda = $this->empresa->fresh()->logo_path;
        $this->assertNotSame($primera, $segunda);
        Storage::disk('local')->assertMissing($primera);

        $this->como($this->consultor)->delete('/organizacion/marca/logo')->assertSessionHasNoErrors();
        $this->assertNull($this->empresa->fresh()->logo_path);
        Storage::disk('local')->assertMissing($segunda);
    }

    public function test_solo_acepta_png_o_jpg_que_es_lo_que_word_puede_incrustar(): void
    {
        $this->subir('firma_rep', UploadedFile::fake()->create('firma.svg', 10, 'image/svg+xml'))->assertSessionHasErrors('archivo');
        $this->subir('firma_rep', UploadedFile::fake()->create('firma.pdf', 10, 'application/pdf'))->assertSessionHasErrors('archivo');
        $this->subir('firma_rep', UploadedFile::fake()->image('firma.png')->size(3000))->assertSessionHasErrors('archivo');
        $this->assertNull($this->empresa->fresh()->firma_rep_path);

        $this->subir('firma_rep', UploadedFile::fake()->image('firma.jpg'))->assertSessionHasNoErrors();
        $this->assertNotNull($this->empresa->fresh()->firma_rep_path);
    }

    public function test_quien_solo_consulta_ve_las_imagenes_pero_no_las_cambia(): void
    {
        $this->subir('logo');
        $lector = tap(User::factory()->create(['tenant_id' => $this->empresa->id]))->assignRole('cliente_usuario');

        $this->como($lector)->get('/organizacion/marca/logo')->assertOk();
        $this->como($lector)->post('/organizacion/marca/logo', ['archivo' => UploadedFile::fake()->image('otro.png')])->assertForbidden();
        $this->como($lector)->delete('/organizacion/marca/logo')->assertForbidden();
        $this->assertNotNull($this->empresa->fresh()->logo_path);
    }

    public function test_cada_empresa_solo_ve_su_propio_logo(): void
    {
        $this->subir('logo');
        $otra = Tenant::create(['name' => 'Otra S.A.S.']);

        // La ruta sirve el logo de la empresa ACTIVA: con otra activa no hay
        // nada que servir. Un solo GET por prueba: el router guarda la
        // instancia del controlador (y su TenantContext) entre peticiones del
        // MISMO proceso, así que un segundo GET reusaría el contexto del
        // primero. En producción cada petición es un proceso nuevo.
        $this->como($this->consultor, $otra)->get('/organizacion/marca/logo')->assertNotFound();
    }

    public function test_la_pantalla_entrega_la_url_de_lo_subido_y_null_de_lo_que_falta(): void
    {
        $this->subir('firma_resp');

        $this->como($this->consultor)->get('/organizacion')->assertOk()
            ->assertInertia(fn ($p) => $p->component('organizacion/index')
                ->where('marca.logo', null)
                ->where('marca.firma_rep', null)
                ->where('marca.firma_resp', fn ($url) => str_contains((string) $url, '/organizacion/marca/firma_resp')));
    }

    // --- Exportación a Word ----------------------------------------------

    private function plantilla(string $codigo, ?string $base = "# Documento\n\nContenido base."): DocumentTemplate
    {
        if ($base !== null && ! Storage::disk('local')->exists("plantillas-base/{$codigo}.docx")) {
            $this->markTestSkipped("No esta el .docx modelo {$codigo}; corre plantillas:reimportar.");
        }

        return DocumentTemplate::create([
            'codigo' => $codigo,
            'nombre' => $codigo,
            'tipo' => 'Procedimiento',
            'categoria' => 'SST',
            'normas' => ['Dec. 1072'],
            'contenido_base' => $base,
            'archivo' => $base !== null ? "plantillas-base/{$codigo}.docx" : null,
            'prompt' => 'Redacta el documento.',
            'orden' => 1,
        ]);
    }

    private function exportar(?DocumentTemplate $plantilla, string $contenido = "# Documento\n\nTexto.", int $version = 1): string
    {
        $doc = new GeneratedDocument([
            'document_template_id' => $plantilla?->id,
            'titulo' => 'Documento de prueba',
            'contenido' => $contenido,
            'estado' => 'borrador',
            'version' => $version,
            'generado_por' => 'pruebas',
        ]);
        $doc->tenant_id = $this->empresa->id;
        $doc->save();

        return app(DocxExporter::class)->export($doc->fresh());
    }

    /**
     * Texto e imágenes COLOCADAS (dibujos) del .docx. Se cuentan dibujos y no
     * archivos de word/media: PhpWord guarda una sola vez la imagen que se
     * repite y la referencia desde cada sitio.
     *
     * @return array{texto:string, imagenes:int}
     */
    private function leer(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $texto = '';
        $imagenes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $nombre)) {
                $xml = (string) $zip->getFromIndex($i);
                $imagenes += substr_count($xml, '<w:drawing>') + substr_count($xml, '<w:pict>');
                $texto .= ' '.strip_tags(str_replace('</w:p>', "\n", $xml));
            }
        }
        $zip->close();

        return ['texto' => $texto, 'imagenes' => $imagenes];
    }

    public function test_sin_logo_el_modelo_sale_como_siempre(): void
    {
        $plantilla = $this->plantilla('PR-FATIGA');
        $original = $this->leer(Storage::disk('local')->path('plantillas-base/PR-FATIGA.docx'));
        $exportado = $this->leer($this->exportar($plantilla));

        $this->assertMatchesRegularExpression('/\bLOGO\b/', $exportado['texto']);
        $this->assertStringNotContainsString('${LOGO}', $exportado['texto']);
        $this->assertSame($original['imagenes'], $exportado['imagenes']);
    }

    public function test_el_logo_reemplaza_el_literal_logo_del_modelo(): void
    {
        $plantilla = $this->plantilla('PR-FATIGA');
        $sin = $this->leer($this->exportar($plantilla));

        $this->subir('logo');
        $con = $this->leer($this->exportar($plantilla));

        $this->assertDoesNotMatchRegularExpression('/\bLOGO\b/', $con['texto']);
        $this->assertStringNotContainsString('${LOGO}', $con['texto']);
        $this->assertSame($sin['imagenes'] + 1, $con['imagenes']);
        // Los datos del cliente se siguen rellenando igual.
        $this->assertStringContainsString('Transportes del Norte S.A.S.', $con['texto']);
    }

    public function test_la_firma_del_representante_va_bajo_su_rotulo_en_la_politica(): void
    {
        $plantilla = $this->plantilla('POL-SGI');
        $sin = $this->leer($this->exportar($plantilla));
        $rotulos = preg_match_all('/Firma del representante legal/i', $sin['texto']);
        $this->assertGreaterThan(0, $rotulos);

        $this->subir('firma_rep');
        $con = $this->leer($this->exportar($plantilla));

        $this->assertSame($sin['imagenes'] + $rotulos, $con['imagenes'], 'Una firma por cada rótulo de firma del representante.');
        $this->assertStringNotContainsString('${FIRMA_REP}', $con['texto']);
        $this->assertSame($rotulos, preg_match_all('/Firma del representante legal/i', $con['texto']), 'El rótulo se conserva.');
    }

    public function test_lo_redactado_por_la_ia_lleva_logo_y_bloque_de_firmas(): void
    {
        $this->subir('logo');
        $this->subir('firma_rep');
        $this->subir('firma_resp');

        $docx = $this->leer($this->exportar($this->plantilla('PG-IA', null)));

        $this->assertSame(3, $docx['imagenes']);
        $this->assertStringContainsString('Firmas', $docx['texto']);
        $this->assertStringContainsString('Ana Gómez', $docx['texto']);
        $this->assertStringContainsString('C.C. 52123456', $docx['texto']);
        $this->assertStringContainsString('Luis Pérez', $docx['texto']);
        $this->assertStringContainsString('Licencia SST N.° LIC-2024-889', $docx['texto']);
        $this->assertStringContainsString('Acompañamiento:', $docx['texto']);
    }

    public function test_sin_imagenes_el_bloque_de_firmas_deja_espacio_para_firmar_a_mano(): void
    {
        $this->empresa->update(['responsable_sgsst' => null]);

        $docx = $this->leer($this->exportar(null));

        $this->assertSame(0, $docx['imagenes']);
        $this->assertStringContainsString('______________________________', $docx['texto']);
        $this->assertStringContainsString('Ana Gómez', $docx['texto']);
        $this->assertStringContainsString('[PENDIENTE]', $docx['texto']);
    }

    public function test_no_duplica_firmas_si_el_documento_ya_trae_su_seccion_o_es_un_modelo(): void
    {
        // Documento IA viejo que ya escribió su propia sección de firmas.
        $viejo = $this->leer($this->exportar(null, "# Política\n\nTexto.\n\n## Firmas\n\nAna Gómez"));
        $this->assertStringNotContainsString('______________________________', $viejo['texto']);

        // Modelo de CMK editado (v2, se reconstruye desde el texto): sus firmas ya van en el texto.
        $modelo = $this->leer($this->exportar($this->plantilla('PR-FATIGA'), "# Procedimiento\n\nTexto editado.", 2));
        $this->assertStringNotContainsString('Licencia SST N.°', $modelo['texto']);
    }
}
