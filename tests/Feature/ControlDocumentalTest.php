<?php

namespace Tests\Feature;

use App\Models\ControlledDocument;
use App\Models\DocumentCatalogEntry;
use App\Models\Norm;
use App\Models\NormRequirement;
use App\Models\Process;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M01 — Control documental. Lo que se comprueba son las reglas del informe de
 * estructura documental: el código lo genera el sistema y no se reutiliza, el
 * ciclo de vida no se salta pasos, publicar vuelve obsoleta la versión
 * anterior y lo publicado no se borra.
 */
class ControlDocumentalTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SigCatalogSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function proceso(string $sigla = 'SST'): Process
    {
        Process::asegurarBase($this->empresa->id);

        return Process::withoutTenantScope()->where('tenant_id', $this->empresa->id)->where('sigla', $sigla)->firstOrFail();
    }

    private function crear(array $extra = []): ControlledDocument
    {
        $this->comoConsultor()->post('/control-documental', array_merge([
            'tipo' => 'PRC',
            'process_id' => $this->proceso()->id,
            'titulo' => 'Procedimiento de exámenes médicos ocupacionales',
            'sistemas' => ['sst', 'iso45001'],
        ], $extra))->assertSessionHasNoErrors();

        return ControlledDocument::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function transicion(ControlledDocument $doc, string $accion, array $extra = [])
    {
        $version = $doc->versions()->first();

        return $this->comoConsultor()->post(
            "/control-documental/{$doc->id}/versiones/{$version->id}/transicion",
            ['accion' => $accion] + $extra,
        );
    }

    /** Lleva el documento hasta vigente por el camino completo. */
    private function publicar(ControlledDocument $doc): void
    {
        $version = $doc->versions()->first();
        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$version->id}", [
            'contenido' => '# Objetivo',
            'descripcion_cambio' => $version->descripcion_cambio ?? 'Creación del documento.',
        ])->assertSessionHasNoErrors();

        foreach (['enviar', 'revisar', 'aprobar'] as $accion) {
            $this->transicion($doc, $accion)->assertSessionHasNoErrors();
        }
    }

    public function test_el_catalogo_trae_los_numeros_del_informe(): void
    {
        $this->assertSame(5, Norm::count());
        $this->assertSame(62, NormRequirement::distinct('clave_comun')->count('clave_comun'));
        $this->assertSame(180, NormRequirement::count());
        $this->assertSame(227, DocumentCatalogEntry::count());
        $this->assertSame(124, DocumentCatalogEntry::where('tipo', 'FT')->count());

        // Idempotente: correrlo dos veces no duplica nada.
        $this->seed(SigCatalogSeeder::class);
        $this->assertSame(180, NormRequirement::count());
        $this->assertSame(227, DocumentCatalogEntry::count());
    }

    public function test_la_pantalla_carga_y_siembra_el_mapa_de_procesos(): void
    {
        $this->comoConsultor()->get('/control-documental')->assertOk()
            ->assertInertia(fn ($p) => $p->component('control-documental/index')->where('needsClient', false)->has('procesos', 9));
    }

    public function test_el_codigo_lo_genera_el_sistema_con_el_formato_del_informe(): void
    {
        $a = $this->crear();
        $b = $this->crear(['titulo' => 'Procedimiento de elección del COPASST']);
        $c = $this->crear(['tipo' => 'FT', 'titulo' => 'Registro de visitantes', 'process_id' => $this->proceso('GSI')->id]);

        $this->assertSame('PRC-SST-001', $a->codigo);
        $this->assertSame('PRC-SST-002', $b->codigo);
        $this->assertSame('FT-GSI-001', $c->codigo);
        foreach ([$a, $b, $c] as $doc) {
            $this->assertMatchesRegularExpression(ControlledDocument::CODIGO_REGEX, $doc->codigo);
        }

        // Nivel, frecuencia y retención salen del tipo y de las normas.
        $this->assertSame(2, $a->nivel);
        $this->assertSame(24, $a->frecuencia_revision_meses);
        $this->assertSame(20, $a->retencion_anios);
        $this->assertSame(4, $c->nivel);
    }

    public function test_un_codigo_no_se_reutiliza_aunque_se_borre_el_documento(): void
    {
        $a = $this->crear();
        $this->comoConsultor()->delete("/control-documental/{$a->id}")->assertSessionHasNoErrors();

        $b = $this->crear(['titulo' => 'Procedimiento de elección del COPASST']);

        $this->assertSame('PRC-SST-002', $b->codigo);
    }

    public function test_el_titulo_no_puede_llevar_un_codigo(): void
    {
        $this->comoConsultor()->post('/control-documental', [
            'tipo' => 'FT',
            'process_id' => $this->proceso()->id,
            'titulo' => 'FT-SST-068 Formato Mantenimiento de equipos',
            'sistemas' => ['sst'],
        ])->assertSessionHasErrors('titulo');
    }

    public function test_avisa_de_un_documento_parecido_y_deja_crearlo_si_se_confirma(): void
    {
        $this->crear(['titulo' => 'Entrega de EPP y dotación', 'tipo' => 'FT']);

        $this->comoConsultor()->post('/control-documental', [
            'tipo' => 'FT', 'process_id' => $this->proceso()->id,
            'titulo' => 'Entrega de EPP y dotacion', 'sistemas' => ['sst'],
        ])->assertSessionHasErrors('duplicado');

        $this->comoConsultor()->post('/control-documental', [
            'tipo' => 'FT', 'process_id' => $this->proceso()->id,
            'titulo' => 'Entrega de EPP y dotacion', 'sistemas' => ['sst'], 'confirmar_duplicado' => true,
        ])->assertSessionHasNoErrors();
    }

    public function test_el_ciclo_no_se_salta_pasos(): void
    {
        $doc = $this->crear();

        // Sin contenido no hay nada que revisar.
        $this->transicion($doc, 'enviar')->assertSessionHasErrors('contenido');

        // Desde borrador no se aprueba directamente.
        $this->transicion($doc, 'aprobar')->assertSessionHasErrors('estado');

        $this->publicar($doc);
        $doc->refresh();

        $this->assertSame('vigente', $doc->estado);
        $this->assertSame(1, $doc->version_vigente);
        $this->assertSame(now()->addMonths(24)->toDateString(), $doc->proxima_revision->toDateString());

        $v = $doc->versions()->first();
        $this->assertSame($this->consultor->name, $v->reviso_nombre);
        $this->assertSame($this->consultor->name, $v->aprobo_nombre);
        $this->assertNotNull($v->aprobado_at);
    }

    public function test_devolver_exige_observaciones_y_regresa_a_borrador(): void
    {
        $doc = $this->crear();
        $version = $doc->versions()->first();
        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$version->id}", [
            'contenido' => 'Texto', 'descripcion_cambio' => 'Creación del documento.',
        ]);
        $this->transicion($doc, 'enviar')->assertSessionHasNoErrors();

        $this->transicion($doc, 'devolver')->assertSessionHasErrors('observaciones');
        $this->transicion($doc, 'devolver', ['observaciones' => 'Falta el alcance'])->assertSessionHasNoErrors();

        $version->refresh();
        $this->assertSame('borrador', $version->estado);
        $this->assertSame('Falta el alcance', $version->observaciones);
    }

    public function test_en_revision_el_autor_no_puede_editar(): void
    {
        $doc = $this->crear();
        $version = $doc->versions()->first();
        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$version->id}", ['contenido' => 'Texto', 'descripcion_cambio' => 'Creación.']);
        $this->transicion($doc, 'enviar');

        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$version->id}", ['contenido' => 'Otro texto'])
            ->assertSessionHasErrors('estado');

        $this->assertSame('Texto', $version->fresh()->contenido);
    }

    public function test_publicar_una_version_nueva_vuelve_obsoleta_la_anterior(): void
    {
        $doc = $this->crear();
        $this->publicar($doc);

        // Versión nueva sin descripción del cambio: no.
        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones", ['descripcion_cambio' => ''])
            ->assertSessionHasErrors('descripcion_cambio');

        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones", ['descripcion_cambio' => 'Se agrega el examen post-incapacidad'])
            ->assertSessionHasNoErrors();

        // Una sola versión en curso a la vez.
        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones", ['descripcion_cambio' => 'Otra'])
            ->assertSessionHasErrors('version');

        // Mientras la v2 está en borrador, el documento sigue vigente en v1.
        $this->assertSame('vigente', $doc->fresh()->estado);
        $this->assertSame(1, $doc->fresh()->version_vigente);

        $this->publicar($doc->fresh());

        $versiones = $doc->versions()->get()->keyBy('version');
        $this->assertSame('vigente', $versiones[2]->estado);
        $this->assertSame('obsoleta', $versiones[1]->estado);
        $this->assertSame('# Objetivo', $versiones[2]->contenido);
        $this->assertSame(2, $doc->fresh()->version_vigente);
        $this->assertSame(1, $doc->versions()->where('estado', 'vigente')->count());
    }

    public function test_lo_publicado_no_se_borra_se_retira(): void
    {
        $doc = $this->crear();
        $this->publicar($doc);

        $this->comoConsultor()->delete("/control-documental/{$doc->id}")->assertSessionHasErrors('estado');
        $this->assertNotNull($doc->fresh());

        $this->comoConsultor()->post("/control-documental/{$doc->id}/retirar", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->comoConsultor()->post("/control-documental/{$doc->id}/retirar", ['motivo' => 'Se integra al PRC-SST-002'])->assertSessionHasNoErrors();

        $doc->refresh();
        $this->assertSame('obsoleto', $doc->estado);
        $this->assertNull($doc->version_vigente);
        $this->assertSame('obsoleta', $doc->versions()->first()->estado);
    }

    public function test_la_lectura_confirmada_se_registra_una_vez(): void
    {
        $doc = $this->crear();
        $this->comoConsultor()->post("/control-documental/{$doc->id}/leido")->assertSessionHasErrors('estado');

        $this->publicar($doc);
        $this->comoConsultor()->post("/control-documental/{$doc->id}/leido")->assertSessionHasNoErrors();
        $this->comoConsultor()->post("/control-documental/{$doc->id}/leido")->assertSessionHasNoErrors();

        $this->assertSame(1, $doc->versions()->first()->reads()->count());
    }

    public function test_un_requisito_comun_se_vincula_una_vez_y_cuenta_en_cada_norma(): void
    {
        $doc = $this->crear(['tipo' => 'PLT', 'titulo' => 'Política integrada del SIG', 'sistemas' => ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']]);

        $politica = NormRequirement::where('titulo', 'like', 'Política integrada%')->value('clave_comun');
        $this->comoConsultor()->put("/control-documental/{$doc->id}/requisitos", ['claves' => [$politica]])->assertSessionHasNoErrors();

        // Una clave común = una fila por norma (SG-SST, PESV y las tres ISO).
        $this->assertSame(5, $doc->requirements()->count());

        // Mientras no esté vigente, está «en proceso», no cubierto.
        $this->comoConsultor()->get('/control-documental/requisitos')->assertOk()
            ->assertInertia(fn ($p) => $p->component('control-documental/requisitos')
                ->where('normas.0.clave', 'sst')->where('normas.0.cubiertos', 0)->where('normas.0.en_proceso', 1));

        $this->publicar($doc);

        $this->comoConsultor()->get('/control-documental/requisitos')
            ->assertInertia(fn ($p) => $p->where('normas.0.cubiertos', 1)->where('normas.3.clave', 'iso9001')->where('normas.3.cubiertos', 1));

        // Quitar una norma de la ficha suelta sus vínculos.
        $this->comoConsultor()->put("/control-documental/{$doc->id}", [
            'titulo' => $doc->titulo, 'sistemas' => ['sst', 'pesv', 'iso45001'],
            'frecuencia_revision_meses' => 12, 'retencion_anios' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $doc->requirements()->count());
    }

    public function test_el_contenido_se_guarda_con_saltos_lf(): void
    {
        $doc = $this->crear();
        $version = $doc->versions()->first();

        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$version->id}", [
            'contenido' => "# Objetivo\r\n\r\n- Uno\r\n- Dos",
        ])->assertSessionHasNoErrors();

        $this->assertSame("# Objetivo\n\n- Uno\n- Dos", $version->fresh()->contenido);
    }

    public function test_un_borrador_nuevo_cuenta_una_sola_vez_en_elaboracion(): void
    {
        $this->crear();
        $publicado = $this->crear(['titulo' => 'Procedimiento de elección del COPASST']);
        $this->publicar($publicado);
        $this->comoConsultor()->post("/control-documental/{$publicado->id}/versiones", ['descripcion_cambio' => 'Ajuste']);

        // Uno nunca publicado + uno vigente con la v2 en curso = 2, no 3.
        $this->comoConsultor()->get('/control-documental')
            ->assertInertia(fn ($p) => $p->where('stats.en_flujo', 2)->where('stats.vigentes', 1));
    }

    public function test_el_sgsst_no_admite_menos_de_20_anos_de_retencion(): void
    {
        $doc = $this->crear();

        $this->comoConsultor()->put("/control-documental/{$doc->id}", [
            'titulo' => $doc->titulo, 'sistemas' => ['sst'], 'frecuencia_revision_meses' => 24, 'retencion_anios' => 5,
        ])->assertSessionHasErrors('retencion_anios');
    }

    public function test_inicializar_desde_el_catalogo_no_duplica(): void
    {
        $this->comoConsultor()->post('/control-documental/catalogo', ['modulos' => ['M01', 'M14']])->assertSessionHasNoErrors();

        // M01 tiene 8 documentos y M14 7, ninguno condicional.
        $this->assertSame(15, ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->count());

        $politica = ControlledDocument::withoutTenantScope()->where('titulo', 'Política integrada del SIG')->first();
        $this->assertSame('PLT-GSI-001', $politica->codigo);
        $this->assertSame('PLT-SST-001', $politica->codigo_historico);

        $this->comoConsultor()->post('/control-documental/catalogo', ['modulos' => ['M01', 'M14']]);
        $this->assertSame(15, ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->count());
    }

    public function test_los_condicionales_solo_entran_si_se_piden(): void
    {
        // M08 tiene 25 documentos; el acta del vigía es «si aplica».
        $this->comoConsultor()->post('/control-documental/catalogo', ['modulos' => ['M08']]);
        $this->assertSame(24, ControlledDocument::withoutTenantScope()->count());

        $this->comoConsultor()->post('/control-documental/catalogo', ['modulos' => ['M08'], 'incluir_condicionales' => true]);
        $this->assertSame(25, ControlledDocument::withoutTenantScope()->count());
    }

    public function test_la_sigla_de_un_proceso_con_documentos_no_cambia(): void
    {
        $doc = $this->crear();
        $proceso = $doc->process;

        $this->comoConsultor()->put("/control-documental/procesos/{$proceso->id}", [
            'sigla' => 'SSO', 'nombre' => $proceso->nombre, 'tipo' => $proceso->tipo,
        ])->assertSessionHasErrors('sigla');

        $this->comoConsultor()->delete("/control-documental/procesos/{$proceso->id}")->assertSessionHasErrors('proceso');

        $this->comoConsultor()->post('/control-documental/procesos', ['sigla' => 'tic', 'nombre' => 'Tecnología', 'tipo' => 'apoyo'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Process::withoutTenantScope()->where('sigla', 'TIC')->exists());
    }

    public function test_el_borrador_acepta_un_archivo_y_se_descarga(): void
    {
        Storage::fake('local');
        $doc = $this->crear();
        $version = $doc->versions()->first();

        $this->comoConsultor()->post("/control-documental/{$doc->id}/versiones/{$version->id}", [
            'descripcion_cambio' => 'Creación del documento.',
            'archivo' => UploadedFile::fake()->create('procedimiento.docx', 40),
        ])->assertSessionHasNoErrors();

        $this->transicion($doc, 'enviar')->assertSessionHasNoErrors();

        $this->comoConsultor()->get("/control-documental/{$doc->id}/versiones/{$version->id}/archivo")
            ->assertOk()->assertDownload('procedimiento.docx');
    }

    public function test_otra_empresa_no_ve_ni_toca_el_documento(): void
    {
        $doc = $this->crear();
        $version = $doc->versions()->first();

        $otra = Tenant::create(['name' => 'Otra', 'nit' => '900000009-1']);
        $admin = tap(User::factory()->create(['tenant_id' => $otra->id]))->assignRole('cliente_admin');

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($admin)->get("/control-documental/{$doc->id}")->assertNotFound();

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($admin)->post("/control-documental/{$doc->id}/versiones/{$version->id}", ['contenido' => 'x'])->assertNotFound();

        $this->assertNull($version->fresh()->contenido);
    }
}
