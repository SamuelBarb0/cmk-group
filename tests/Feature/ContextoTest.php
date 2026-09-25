<?php

namespace Tests\Feature;

use App\Models\ContextIssue;
use App\Models\ContextProfile;
use App\Models\ControlledDocument;
use App\Models\InterestedParty;
use App\Models\Process;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M02 — Contexto de la organización: DOFA/PESTEL, partes interesadas, alcance
 * con cambio climático y caracterización de procesos, y su paso al control
 * documental como borrador de los documentos del catálogo.
 */
class ContextoTest extends TestCase
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

    private function cuestion(array $extra = [])
    {
        return $this->comoConsultor()->post('/contexto/cuestiones', array_merge([
            'origen' => 'externo', 'dofa' => 'amenaza', 'pestel' => 'legal',
            'descripcion' => 'Endurecimiento de la normativa de seguridad vial', 'impacto' => 'alto',
            'tratamiento' => 'Actualizar el PESV', 'sistemas' => ['pesv', 'iso45001'],
        ], $extra));
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/contexto')->assertOk()
            ->assertInertia(fn ($p) => $p->component('contexto/index')->where('needsClient', false)->has('documentos.dofa'));
    }

    public function test_lo_interno_es_fortaleza_o_debilidad_y_no_lleva_pestel(): void
    {
        $this->cuestion(['origen' => 'interno', 'dofa' => 'oportunidad'])->assertSessionHasErrors('dofa');

        $this->cuestion(['origen' => 'interno', 'dofa' => 'debilidad', 'pestel' => 'legal'])->assertSessionHasNoErrors();
        $this->assertNull(ContextIssue::withoutTenantScope()->firstOrFail()->pestel);
    }

    public function test_la_base_de_partes_interesadas_se_carga_una_sola_vez(): void
    {
        $this->comoConsultor()->post('/contexto/partes/base')->assertSessionHasNoErrors();
        $total = count(InterestedParty::BASE);
        $this->assertSame($total, InterestedParty::withoutTenantScope()->count());

        $this->comoConsultor()->post('/contexto/partes/base')->assertSessionHasErrors('partes');
        $this->assertSame($total, InterestedParty::withoutTenantScope()->count());
    }

    public function test_la_estrategia_sale_de_influencia_e_interes(): void
    {
        $p = new InterestedParty(['influencia' => 'alta', 'interes' => 'alto']);
        $this->assertSame('Gestionar de cerca', $p->estrategia());
        $p->interes = 'bajo';
        $this->assertSame('Mantener satisfecha', $p->estrategia());
        $p->influencia = 'baja';
        $this->assertSame('Monitorear', $p->estrategia());
    }

    public function test_exclusiones_y_cambio_climatico_exigen_justificacion(): void
    {
        $this->comoConsultor()->put('/contexto/perfil', [
            'alcance' => 'Diseño e instalación de redes eléctricas.',
            'exclusiones' => [['requisito' => 'ISO 9001 8.3 Diseño', 'justificacion' => '']],
            'cambio_climatico' => false,
        ])->assertSessionHasErrors(['exclusiones.0.justificacion', 'cambio_climatico_justificacion']);

        $this->comoConsultor()->put('/contexto/perfil', [
            'alcance' => 'Instalación de redes eléctricas.',
            'exclusiones' => [['requisito' => 'ISO 9001 8.3 Diseño', 'justificacion' => 'La empresa instala diseños del cliente.']],
            'cambio_climatico' => true,
            'cambio_climatico_justificacion' => 'Las olas de calor afectan a los trabajadores en campo.',
        ])->assertSessionHasNoErrors();

        $perfil = ContextProfile::withoutTenantScope()->firstOrFail();
        $this->assertTrue($perfil->cambio_climatico);
        $this->assertSame('ISO 9001 8.3 Diseño', $perfil->exclusiones[0]['requisito']);
    }

    public function test_la_caracterizacion_guarda_solo_campos_conocidos(): void
    {
        Process::asegurarBase($this->empresa->id);
        $ope = Process::withoutTenantScope()->where('tenant_id', $this->empresa->id)->where('sigla', 'OPE')->firstOrFail();

        $this->comoConsultor()->put("/contexto/procesos/{$ope->id}", [
            'objetivo' => 'Prestar el servicio según los requisitos del cliente.',
            'lider' => 'Director de operaciones',
            'caracterizacion' => ['entradas' => 'Órdenes de servicio', 'hacer' => 'Ejecutar el servicio', 'salidas' => 'Servicio entregado', 'inventado' => 'x'],
        ])->assertSessionHasNoErrors();

        $ope->refresh();
        $this->assertArrayNotHasKey('inventado', $ope->caracterizacion);
        $this->assertTrue($ope->caracterizado());
    }

    public function test_no_toca_datos_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '900000009-1']);
        $ajena = new ContextIssue(['origen' => 'interno', 'dofa' => 'fortaleza', 'descripcion' => 'x', 'impacto' => 'bajo', 'sistemas' => ['sst']]);
        $ajena->tenant_id = $otra->id;
        $ajena->save();

        $this->comoConsultor()->delete("/contexto/cuestiones/{$ajena->id}")->assertNotFound();
        $this->assertDatabaseHas('context_issues', ['id' => $ajena->id]);
    }

    public function test_enviar_la_dofa_crea_el_borrador_vinculado_a_su_requisito(): void
    {
        $this->cuestion()->assertSessionHasNoErrors();

        $this->comoConsultor()->post('/contexto/enviar', ['documento' => 'dofa'])->assertSessionHasNoErrors();

        $doc = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->firstOrFail();
        $this->assertStringStartsWith('MTZ-DIR-', $doc->codigo);
        $this->assertSame('borrador', $doc->estado);
        $this->assertStringContainsString('Endurecimiento de la normativa de seguridad vial', $doc->versions()->firstOrFail()->contenido);

        // Queda vinculado al requisito de contexto (4.1) en las tres ISO.
        $claves = $doc->requirements()->with('norm')->get();
        $this->assertCount(3, $claves);
        $this->assertTrue($claves->every(fn ($r) => $r->referencia === '4.1'));
    }

    public function test_reenviar_reemplaza_el_borrador_y_sobre_uno_vigente_abre_version_nueva(): void
    {
        $this->cuestion();
        $this->comoConsultor()->post('/contexto/enviar', ['documento' => 'dofa']);
        $this->cuestion(['descripcion' => 'Nueva cuestión del entorno']);
        $this->comoConsultor()->post('/contexto/enviar', ['documento' => 'dofa'])->assertSessionHasNoErrors();

        $doc = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->firstOrFail();
        $this->assertSame(1, $doc->versions()->count());
        $this->assertStringContainsString('Nueva cuestión del entorno', $doc->versions()->firstOrFail()->contenido);

        // Publicada: el siguiente envío abre la v2 en borrador.
        $doc->versions()->firstOrFail()->update(['estado' => 'vigente']);
        $doc->sincronizarEstado();
        $this->comoConsultor()->post('/contexto/enviar', ['documento' => 'dofa'])->assertSessionHasNoErrors();
        $this->assertSame(2, $doc->versions()->count());
        $this->assertSame('borrador', $doc->versions()->where('version', 2)->firstOrFail()->estado);

        // En revisión: no se toca lo que alguien está revisando.
        $doc->versions()->where('version', 2)->firstOrFail()->update(['estado' => 'en_revision']);
        $this->comoConsultor()->post('/contexto/enviar', ['documento' => 'dofa'])->assertSessionHasErrors('estado');
    }

    public function test_el_alcance_lleva_mapa_de_procesos_y_cambio_climatico(): void
    {
        Process::asegurarBase($this->empresa->id);
        $this->comoConsultor()->put('/contexto/perfil', [
            'alcance' => 'Transporte de carga terrestre en Colombia.',
            'cambio_climatico' => false,
            'cambio_climatico_justificacion' => 'Evaluado en el análisis de contexto: sin efecto material.',
        ]);

        $this->comoConsultor()->post('/contexto/enviar', ['documento' => 'alcance'])->assertSessionHasNoErrors();

        $doc = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->firstOrFail();
        $contenido = $doc->versions()->firstOrFail()->contenido;
        $this->assertStringStartsWith('MAN-DIR-', $doc->codigo);
        $this->assertStringContainsString('Transporte de carga terrestre', $contenido);
        $this->assertStringContainsString('no es una cuestión pertinente', $contenido);
        $this->assertStringContainsString('| Estratégico | DIR |', $contenido);
        // Alcance (4.3) y mapa de procesos (4.4): dos requisitos, en las normas del documento.
        $this->assertSame(['SIG-06', 'SIG-07'], $doc->requirements()->pluck('clave_comun')->unique()->sort()->values()->all());
    }

    public function test_el_informe_de_gestion_trae_la_seccion(): void
    {
        $this->cuestion();
        $this->comoConsultor()->post('/contexto/revisado')->assertSessionHasNoErrors();

        $informe = app(InformeGestion::class);
        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
        $datos = $informe->generar($this->consultor, Periodo::desde('2026-01-01', '2026-12-31'), ['contexto']);

        $cifras = collect($datos['secciones'][0]['cifras'])->pluck('valor', 'etiqueta');
        $this->assertSame('1', $cifras['Amenazas de impacto alto']);
        $this->assertSame('Sin decidir', $cifras['¿Cambio climático pertinente?']);
        $this->assertSame(now()->toDateString(), $cifras['Última revisión del contexto']);
    }
}
