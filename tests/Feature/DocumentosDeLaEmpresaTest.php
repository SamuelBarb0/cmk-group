<?php

namespace Tests\Feature;

use App\Jobs\ActualizarDocumentoConIaJob;
use App\Models\ControlledDocument;
use App\Models\ControlledDocumentVersion;
use App\Models\DocumentCatalogEntry;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Process;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiService;
use App\Services\ControlDocumental\CicloDocumental;
use App\Services\PesvFeed;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Los documentos que la empresa YA tiene:
 *
 * - cuentan en «Lo que ya tiene la plataforma» del PESV cuando están en el
 *   control documental, sin pasar por Documentos IA;
 * - la IA los pone al día con los datos de la plataforma (Word o PDF);
 * - y en Documentos IA la empresa puede usar su propia plantilla en lugar
 *   de la de CMK, solo para ella.
 *
 * La IA se simula: nada sale a la API.
 */
class DocumentosDeLaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private const MODELO = 'plantillas-base/PR-FATIGA.docx';

    private Tenant $empresa;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SigCatalogSeeder::class);

        $real = storage_path('app/private/'.self::MODELO);
        $modelo = is_file($real) ? file_get_contents($real) : null;
        Storage::fake('local');
        if ($modelo !== null) {
            Storage::disk('local')->put(self::MODELO, $modelo);
        }

        $this->empresa = Tenant::create(['name' => 'Transportes del Norte S.A.S.', 'nit' => '900123456-7', 'representante_legal' => 'Ana Gómez']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function como(?Tenant $empresa = null)
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => ($empresa ?? $this->empresa)->id]);
    }

    private function enEmpresa(callable $fn): mixed
    {
        $ctx = app(TenantContext::class);
        $ctx->set($this->empresa);
        try {
            return $fn();
        } finally {
            $ctx->set(null);
            $this->app->forgetInstance(TenantContext::class);
        }
    }

    private function plantillaCmk(string $codigo = 'PR-FATIGA', ?int $catalogo = null): DocumentTemplate
    {
        $t = new DocumentTemplate([
            'codigo' => $codigo, 'nombre' => 'Procedimiento de gestión de la fatiga', 'tipo' => 'Procedimiento',
            'categoria' => 'PESV', 'normas' => ['Res. 40595'], 'contenido_base' => "# Fatiga\n\nNOMBRE DE LA EMPRESA", 'prompt' => 'Redacta.', 'orden' => 7,
        ]);
        $t->document_catalog_id = $catalogo;
        $t->archivo = self::MODELO;
        $t->save();

        return $t;
    }

    /** Documento del listado maestro creado por la pantalla, con su borrador v1. */
    private function documento(array $extra = []): ControlledDocument
    {
        Process::asegurarBase($this->empresa->id);
        $proceso = Process::withoutTenantScope()->where('tenant_id', $this->empresa->id)->where('sigla', 'SVL')->first()
            ?? Process::withoutTenantScope()->where('tenant_id', $this->empresa->id)->firstOrFail();

        $this->como()->post('/control-documental', array_merge([
            'tipo' => 'PRC', 'process_id' => $proceso->id, 'titulo' => 'Gestión de la fatiga de conductores', 'sistemas' => ['pesv'],
        ], $extra))->assertSessionHasNoErrors();

        return ControlledDocument::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function borrador(ControlledDocument $doc): ControlledDocumentVersion
    {
        return $doc->versions()->where('estado', 'borrador')->firstOrFail();
    }

    private function publicar(ControlledDocument $doc): void
    {
        $this->enEmpresa(function () use ($doc) {
            $ciclo = app(CicloDocumental::class);
            $v = $this->borrador($doc);
            $v->update(['contenido' => '# Fatiga', 'descripcion_cambio' => 'Creación del documento.']);
            $ciclo->enviarARevision($v, $this->consultor);
            $ciclo->aprobarRevision($v->fresh(), $this->consultor);
            $ciclo->aprobar($v->fresh(), $this->consultor);
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function insumosDelPaso(int $paso): array
    {
        return $this->enEmpresa(fn () => collect((new PesvFeed($this->empresa->fresh()))->paraPaso($paso))->keyBy('etiqueta')->all());
    }

    // --- PESV: «Lo que ya tiene la plataforma» -------------------------------

    public function test_un_documento_marcado_en_el_control_documental_cuenta_en_el_paso_del_pesv(): void
    {
        $this->plantillaCmk();
        $this->assertSame('falta', $this->insumosDelPaso(8)['Documento PR-FATIGA']['estado']);

        $doc = $this->documento(['codigo_pesv' => 'PR-FATIGA']);
        $insumo = $this->insumosDelPaso(8)['Documento PR-FATIGA'];
        $this->assertSame('parcial', $insumo['estado'], 'En borrador cuenta, pero a medias.');
        $this->assertStringContainsString($doc->codigo, $insumo['detalle']);
        $this->assertStringContainsString("/control-documental/{$doc->id}", $insumo['url']);

        $this->publicar($doc);
        $this->assertSame('ok', $this->insumosDelPaso(8)['Documento PR-FATIGA']['estado'], 'Vigente: el paso queda respaldado.');
    }

    public function test_tambien_lo_reconoce_por_el_documento_del_catalogo_que_redacta_la_plantilla(): void
    {
        $entrada = DocumentCatalogEntry::query()->firstOrFail();
        $this->plantillaCmk('PR-PLAN-VIAJES', $entrada->id);

        $this->enEmpresa(function () use ($entrada) {
            Process::asegurarBase($this->empresa->id);
            app(CicloDocumental::class)->recibirDeModulo($this->empresa->id, $entrada, '# Plan', 'Desde el módulo.', $this->consultor);
        });

        $this->assertSame('parcial', $this->insumosDelPaso(15)['Documento PR-PLAN-VIAJES']['estado']);
    }

    public function test_el_codigo_pesv_solo_acepta_documentos_que_piden_los_pasos(): void
    {
        Process::asegurarBase($this->empresa->id);
        $proceso = Process::withoutTenantScope()->where('tenant_id', $this->empresa->id)->firstOrFail();

        $this->como()->post('/control-documental', [
            'tipo' => 'PRC', 'process_id' => $proceso->id, 'titulo' => 'Otro', 'sistemas' => ['pesv'], 'codigo_pesv' => 'NO-EXISTE',
        ])->assertSessionHasErrors('codigo_pesv');
    }

    // --- Actualizar con IA ----------------------------------------------------

    private function adjuntar(ControlledDocumentVersion $v, string $nombre, string $bytes): void
    {
        $ruta = "tenants/{$this->empresa->id}/control-documental/{$v->controlled_document_id}/{$nombre}";
        Storage::disk('local')->put($ruta, $bytes);
        $v->update(['archivo' => $ruta, 'archivo_nombre' => $nombre]);
    }

    public function test_la_ia_actualiza_el_word_de_la_empresa_en_el_borrador(): void
    {
        if (! Storage::disk('local')->exists(self::MODELO)) {
            $this->markTestSkipped('No esta el .docx modelo de referencia.');
        }
        $doc = $this->documento();
        $v = $this->borrador($doc);
        $this->adjuntar($v, 'fatiga.docx', Storage::disk('local')->get(self::MODELO));

        $pedido = null;
        $this->mock(AiService::class, function ($m) use (&$pedido) {
            $m->shouldReceive('complete')->once()->andReturnUsing(function ($prompt) use (&$pedido) {
                $pedido = $prompt;

                return "# Procedimiento de fatiga\n\nTransportes del Norte S.A.S.";
            });
        });

        $this->como()->post("/control-documental/{$doc->id}/actualizar-ia")->assertSessionHasNoErrors();

        $this->assertIsString($pedido, 'Un Word se manda como texto.');
        $this->assertStringContainsString('Documento actual de la empresa', $pedido);
        $this->assertStringContainsString('Transportes del Norte S.A.S.', $pedido, 'Lleva los datos de la empresa.');

        $v->refresh();
        $this->assertSame("# Procedimiento de fatiga\n\nTransportes del Norte S.A.S.", $v->contenido);
        $this->assertNull($v->ia_estado);
        $this->assertSame('fatiga.docx', $v->archivo_nombre, 'El original queda adjunto para comparar.');
        $this->assertSame('borrador', $v->estado, 'No se salta el ciclo de aprobación.');
    }

    public function test_un_pdf_se_le_manda_a_la_ia_como_documento(): void
    {
        $doc = $this->documento();
        $this->adjuntar($this->borrador($doc), 'fatiga.pdf', "%PDF-1.4\nfalso");

        $pedido = null;
        $this->mock(AiService::class, function ($m) use (&$pedido) {
            $m->shouldReceive('complete')->once()->andReturnUsing(function ($prompt) use (&$pedido) {
                $pedido = $prompt;

                return '# Actualizado';
            });
        });

        $this->como()->post("/control-documental/{$doc->id}/actualizar-ia")->assertSessionHasNoErrors();

        $this->assertIsArray($pedido);
        $this->assertSame('document', $pedido[0]['type']);
        $this->assertSame('application/pdf', $pedido[0]['source']['media_type']);
        $this->assertSame(base64_encode("%PDF-1.4\nfalso"), $pedido[0]['source']['data']);
        $this->assertSame('text', $pedido[1]['type'], 'El PDF va antes de la instrucción.');
    }

    public function test_sobre_un_vigente_abre_la_version_siguiente_que_hereda_el_archivo(): void
    {
        $doc = $this->documento();
        $this->adjuntar($this->borrador($doc), 'fatiga.pdf', "%PDF-1.4\nfalso");
        $this->publicar($doc);
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('complete')->once()->andReturn('# v2'));

        $this->como()->post("/control-documental/{$doc->id}/actualizar-ia")->assertSessionHasNoErrors();

        $v2 = $doc->versions()->where('version', 2)->firstOrFail();
        $this->assertSame('borrador', $v2->estado);
        $this->assertSame('# v2', $v2->contenido);
        $this->assertSame('fatiga.pdf', $v2->archivo_nombre);
        $this->assertSame('vigente', $doc->versions()->where('version', 1)->value('estado'), 'La vigente sigue en uso.');
    }

    public function test_sin_word_pdf_ni_texto_no_hay_que_actualizar(): void
    {
        $doc = $this->documento();
        $this->mock(AiService::class, fn ($m) => $m->shouldNotReceive('complete'));

        $this->como()->post("/control-documental/{$doc->id}/actualizar-ia")->assertSessionHasErrors('ia');
        $this->assertNull($this->borrador($doc)->ia_estado);
    }

    public function test_si_la_ia_falla_el_borrador_lo_dice_y_no_se_pierde_nada(): void
    {
        $doc = $this->documento();
        $v = $this->borrador($doc);
        $v->update(['contenido' => '# Texto de la empresa']);
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('complete')->andThrow(new RuntimeException('Claude está saturado (529).')));

        try {
            $this->como()->post("/control-documental/{$doc->id}/actualizar-ia");
        } catch (RuntimeException) {
            // Con la cola síncrona de las pruebas el error sube; en producción lo atrapa el worker.
        }
        // Lo que haría el worker al fallar el job.
        (new ActualizarDocumentoConIaJob($v->id, $this->consultor->id))->failed(new RuntimeException('Claude está saturado (529).'));

        $v->refresh();
        $this->assertSame('error', $v->ia_estado);
        $this->assertStringContainsString('529', $v->ia_error);
        $this->assertSame('# Texto de la empresa', $v->contenido);
    }

    public function test_mientras_la_ia_trabaja_el_borrador_no_se_edita_ni_se_envia(): void
    {
        $doc = $this->documento();
        $v = $this->borrador($doc);
        $v->update(['contenido' => '# x', 'ia_estado' => 'generando']);

        $this->como()->post("/control-documental/{$doc->id}/versiones/{$v->id}", ['contenido' => '# y'])->assertSessionHasErrors('estado');
        $this->como()->post("/control-documental/{$doc->id}/versiones/{$v->id}/transicion", ['accion' => 'enviar'])->assertSessionHasErrors('estado');
        $this->como()->post("/control-documental/{$doc->id}/actualizar-ia")->assertSessionHasErrors('ia');
    }

    public function test_el_texto_de_una_version_se_descarga_en_word(): void
    {
        $doc = $this->documento();
        $v = $this->borrador($doc);
        $v->update(['contenido' => "# Fatiga\n\nControl de jornadas."]);

        $respuesta = $this->como()->get("/control-documental/{$doc->id}/versiones/{$v->id}/word");
        $respuesta->assertOk();
        $this->assertStringContainsString('.docx', (string) $respuesta->headers->get('content-disposition'));

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($respuesta->getFile()->getPathname()) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('Control de jornadas', $xml);
        $this->assertStringContainsString('Transportes del Norte', $xml);
    }

    // --- Plantilla de la empresa en Documentos IA -------------------------------

    private function visiblesPara(Tenant $t): array
    {
        return DocumentTemplate::visibles($t->id)->where('codigo', 'PR-FATIGA')->pluck('tenant_id')->all();
    }

    public function test_la_plantilla_de_la_empresa_reemplaza_la_de_cmk_solo_para_ella(): void
    {
        if (! Storage::disk('local')->exists(self::MODELO)) {
            $this->markTestSkipped('No esta el .docx modelo de referencia.');
        }
        $entrada = DocumentCatalogEntry::query()->firstOrFail();
        $cmk = $this->plantillaCmk('PR-FATIGA', $entrada->id);
        $otra = Tenant::create(['name' => 'Otra S.A.S.']);

        $archivo = UploadedFile::fake()->createWithContent('fatiga-empresa.docx', Storage::disk('local')->get(self::MODELO));
        $this->como()->post("/documentos-ia/plantillas/{$cmk->id}/sustituir", ['archivo' => $archivo])->assertSessionHasNoErrors();

        $propia = DocumentTemplate::where('tenant_id', $this->empresa->id)->firstOrFail();
        $this->assertSame($cmk->id, $propia->reemplaza_id);
        $this->assertSame('PR-FATIGA', $propia->codigo);
        $this->assertSame($entrada->id, $propia->document_catalog_id, 'Sigue contando en el catálogo y el PESV.');
        $this->assertNotEmpty($propia->contenido_base);
        $this->assertTrue($propia->estaLista());

        $this->assertSame([$this->empresa->id], $this->visiblesPara($this->empresa), 'La empresa ve solo la suya.');
        $this->assertSame([null], $this->visiblesPara($otra), 'Las demás siguen con la de CMK.');

        // Volver a la de CMK: se borra la propia y reaparece la global.
        $this->como()->delete("/documentos-ia/plantillas/{$propia->id}")->assertSessionHasNoErrors();
        $this->assertSame([null], $this->visiblesPara($this->empresa));
        $this->assertNotNull($cmk->fresh(), 'La de CMK nunca se toca.');
    }

    public function test_subir_otra_vez_reemplaza_la_propia_en_vez_de_duplicarla(): void
    {
        $cmk = $this->plantillaCmk();
        foreach (['uno', 'dos'] as $texto) {
            $this->como()->post("/documentos-ia/plantillas/{$cmk->id}/sustituir", [
                'archivo' => UploadedFile::fake()->createWithContent('fatiga.md', "# Fatiga {$texto}"),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(1, DocumentTemplate::where('tenant_id', $this->empresa->id)->count());
        $this->assertStringContainsString('dos', DocumentTemplate::where('tenant_id', $this->empresa->id)->value('contenido_base'));
    }

    public function test_un_pdf_propio_se_pasa_a_texto_y_mientras_tanto_no_se_genera(): void
    {
        $cmk = $this->plantillaCmk();
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('complete')->once()->andReturn("# Fatiga de la empresa\n\nNOMBRE DE LA EMPRESA"));

        $this->como()->post("/documentos-ia/plantillas/{$cmk->id}/sustituir", [
            'archivo' => UploadedFile::fake()->createWithContent('fatiga.pdf', "%PDF-1.4\nfalso"),
        ])->assertSessionHasNoErrors();

        $propia = DocumentTemplate::where('tenant_id', $this->empresa->id)->firstOrFail();
        $this->assertTrue($propia->estaLista(), 'Con la cola síncrona el PDF ya quedó transcrito.');
        $this->assertStringContainsString('Fatiga de la empresa', $propia->contenido_base);
        $this->assertNull($propia->archivo, 'Un PDF no sirve de modelo Word para exportar.');

        // Mientras se procesa, generar se niega en vez de redactar desde cero.
        $propia->update(['estado_proceso' => 'procesando']);
        $this->como()->post('/documentos-ia/generar', ['document_template_id' => $propia->id])->assertSessionHasErrors('plantilla');
        $this->assertSame(0, GeneratedDocument::withoutTenantScope()->count());
    }

    public function test_solo_se_sustituye_una_plantilla_de_cmk(): void
    {
        $propiaSuelta = DocumentTemplate::create([
            'tenant_id' => $this->empresa->id, 'codigo' => 'FT-PROPIO', 'nombre' => 'Formato propio', 'tipo' => 'Formato',
            'categoria' => 'SST', 'normas' => [], 'prompt' => 'x', 'orden' => 900,
        ]);

        $this->como()->post("/documentos-ia/plantillas/{$propiaSuelta->id}/sustituir", [
            'archivo' => UploadedFile::fake()->createWithContent('x.md', '# x'),
        ])->assertNotFound();
    }
}
