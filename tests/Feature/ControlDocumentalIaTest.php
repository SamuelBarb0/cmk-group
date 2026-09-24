<?php

namespace Tests\Feature;

use App\Models\ControlledDocument;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DocumentTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documentos IA redacta; el control documental revisa, aprueba y publica. El
 * texto de IA tiene que caer en el documento correcto del listado maestro y
 * siempre como borrador.
 */
class ControlDocumentalIaTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(DocumentTemplatesSeeder::class);
        $this->seed(SigCatalogSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function generado(string $plantilla, string $contenido = '# Texto de IA', string $estado = 'borrador'): GeneratedDocument
    {
        $g = new GeneratedDocument([
            'document_template_id' => DocumentTemplate::whereNull('tenant_id')->where('codigo', $plantilla)->value('id'),
            'titulo' => 'Redacción de '.$plantilla,
            'contenido' => $contenido,
            'estado' => $estado,
            'version' => 1,
        ]);
        $g->tenant_id = $this->empresa->id;
        $g->save();

        return $g;
    }

    private function enviar(GeneratedDocument $g)
    {
        return $this->comoConsultor()->post("/control-documental/desde-ia/{$g->id}");
    }

    private function documentos()
    {
        return ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id);
    }

    public function test_las_plantillas_de_cmk_quedan_enlazadas_al_catalogo(): void
    {
        $this->assertSame('Política integrada del SIG', DocumentTemplate::where('codigo', 'POL-SGI')->first()->catalogEntry->nombre);
        // La política de seguridad vial por separado no está en el catálogo.
        $this->assertNull(DocumentTemplate::where('codigo', 'POL-PESV')->first()->document_catalog_id);
    }

    public function test_crea_el_documento_del_catalogo_con_su_codigo_del_sig(): void
    {
        $g = $this->generado('POL-SGI');
        $this->enviar($g)->assertSessionHasNoErrors();

        $doc = $this->documentos()->firstOrFail();
        $this->assertSame('PLT-GSI-001', $doc->codigo);
        $this->assertSame('Política integrada del SIG', $doc->titulo);
        $this->assertSame('borrador', $doc->estado);

        $v = $doc->versions()->first();
        $this->assertSame('# Texto de IA', $v->contenido);
        $this->assertSame($g->id, $v->generated_document_id);
    }

    public function test_usa_el_documento_que_ya_estaba_en_el_listado(): void
    {
        $this->comoConsultor()->post('/control-documental/catalogo', ['modulos' => ['M01']]);
        $antes = $this->documentos()->count();

        $this->enviar($this->generado('POL-SGI'))->assertSessionHasNoErrors();

        $this->assertSame($antes, $this->documentos()->count());
        $politica = $this->documentos()->where('codigo', 'PLT-GSI-001')->first();
        $this->assertSame('# Texto de IA', $politica->versions()->first()->contenido);
    }

    public function test_sobre_un_vigente_abre_la_version_siguiente(): void
    {
        $this->enviar($this->generado('POL-SGI', '# v1'));
        $doc = $this->documentos()->firstOrFail();
        $v1 = $doc->versions()->first();
        foreach (['enviar', 'revisar', 'aprobar'] as $accion) {
            $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$v1->id}/transicion", ['accion' => $accion])->assertSessionHasNoErrors();
        }

        $this->enviar($this->generado('POL-SGI', '# v2'))->assertSessionHasNoErrors();

        $doc->refresh();
        $this->assertSame('vigente', $doc->estado);
        $this->assertSame(1, $doc->version_vigente);
        $v2 = $doc->versions()->first();
        $this->assertSame(2, $v2->version);
        $this->assertSame('borrador', $v2->estado);
        $this->assertSame('# v2', $v2->contenido);
        $this->assertStringContainsString('Documentos IA', $v2->descripcion_cambio);
    }

    public function test_no_pisa_una_version_que_esta_en_revision(): void
    {
        $this->enviar($this->generado('POL-SGI', '# original'));
        $doc = $this->documentos()->firstOrFail();
        $v1 = $doc->versions()->first();
        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$v1->id}/transicion", ['accion' => 'enviar']);

        $this->enviar($this->generado('POL-SGI', '# otro'))->assertSessionHasErrors('estado');

        $this->assertSame('# original', $v1->fresh()->contenido);
    }

    public function test_plantilla_sin_catalogo_crea_un_documento_propio_y_lo_reutiliza(): void
    {
        $this->enviar($this->generado('POL-PESV'))->assertSessionHasNoErrors();

        $doc = $this->documentos()->firstOrFail();
        $this->assertSame('PLT-SVL-001', $doc->codigo);
        $this->assertSame('POL-PESV', $doc->codigo_historico);
        $this->assertContains('pesv', $doc->sistemas);
        // «ISO 39001» no es ISO 9001.
        $this->assertNotContains('iso9001', $doc->sistemas);

        // Un segundo texto de la misma plantilla va al mismo documento.
        $this->enviar($this->generado('POL-PESV', '# corregido'))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->documentos()->count());
        $this->assertSame('# corregido', $doc->versions()->first()->contenido);
    }

    public function test_un_texto_que_se_esta_generando_no_se_envia(): void
    {
        $this->enviar($this->generado('POL-SGI', '', 'generando'))->assertSessionHasErrors('estado');
        $this->assertSame(0, $this->documentos()->count());
    }

    public function test_documentos_ia_muestra_a_donde_se_envio(): void
    {
        $g = $this->generado('POL-SGI');
        $this->enviar($g);

        $this->comoConsultor()->get('/documentos-ia')->assertOk()
            ->assertInertia(fn ($p) => $p->where('documents.0.controlado.codigo', 'PLT-GSI-001'));
    }
}
