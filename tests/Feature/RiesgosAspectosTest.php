<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\ContextIssue;
use App\Models\ControlledDocument;
use App\Models\EnvironmentalAspect;
use App\Models\Process;
use App\Models\RiskOpportunity;
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
 * M04 — Riesgos y oportunidades de los procesos y aspectos e impactos
 * ambientales: valoración, paso desde la DOFA, acciones en ACPM, envío al
 * control documental e informe.
 */
class RiesgosAspectosTest extends TestCase
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

    private function riesgo(array $extra = [])
    {
        return $this->comoConsultor()->post('/riesgos-oportunidades', array_merge([
            'tipo' => 'riesgo', 'descripcion' => 'Pérdida del contrato principal', 'probabilidad' => 4, 'impacto' => 5,
            'tratamiento' => 'reducir', 'estado' => 'abierto', 'sistemas' => ['iso9001'],
        ], $extra));
    }

    private function deLaEmpresa(string $clase, array $datos)
    {
        $m = new $clase($datos);
        $m->tenant_id = $this->empresa->id;
        $m->save();

        return $m;
    }

    public function test_las_pantallas_cargan(): void
    {
        $this->comoConsultor()->get('/riesgos-oportunidades')->assertOk()
            ->assertInertia(fn ($p) => $p->component('riesgos-oportunidades/index')->where('needsClient', false));
        $this->comoConsultor()->get('/aspectos-ambientales')->assertOk()
            ->assertInertia(fn ($p) => $p->component('aspectos-ambientales/index')->where('needsClient', false));
    }

    public function test_el_nivel_sale_de_la_matriz_5x5(): void
    {
        $this->assertSame('bajo', RiskOpportunity::nivelDe(4));
        $this->assertSame('medio', RiskOpportunity::nivelDe(9));
        $this->assertSame('alto', RiskOpportunity::nivelDe(16));
        $this->assertSame('critico', RiskOpportunity::nivelDe(20));
    }

    public function test_el_tratamiento_tiene_que_corresponder_al_tipo(): void
    {
        $this->riesgo(['tratamiento' => 'aprovechar'])->assertSessionHasErrors('tratamiento');
        $this->riesgo(['tipo' => 'oportunidad', 'tratamiento' => 'aprovechar'])->assertSessionHasNoErrors();
    }

    public function test_un_riesgo_alto_sin_accion_ni_responsable_sale_sin_tratar(): void
    {
        $this->riesgo()->assertSessionHasNoErrors();
        $r = RiskOpportunity::withoutTenantScope()->firstOrFail();
        $this->assertSame('critico', $r->nivel);
        $this->assertTrue($r->sinTratar());

        $r->update(['acciones' => 'Diversificar clientes', 'responsable' => 'Gerente comercial']);
        $this->assertFalse($r->refresh()->sinTratar());
    }

    public function test_la_fecha_de_eficacia_solo_cambia_con_la_eficacia(): void
    {
        $this->riesgo(['eficacia' => 'Se firmaron dos contratos nuevos.'])->assertSessionHasNoErrors();
        $r = RiskOpportunity::withoutTenantScope()->firstOrFail();
        $r->forceFill(['evaluado_at' => '2026-01-15'])->saveQuietly();

        // Editar otra cosa no mueve la fecha de la evaluación.
        $this->comoConsultor()->put("/riesgos-oportunidades/{$r->id}", [
            'tipo' => 'riesgo', 'descripcion' => 'Pérdida del contrato principal (actualizado)', 'probabilidad' => 3, 'impacto' => 5,
            'tratamiento' => 'reducir', 'estado' => 'cerrado', 'eficacia' => 'Se firmaron dos contratos nuevos.', 'sistemas' => ['iso9001'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-01-15', $r->refresh()->evaluado_at->toDateString());
    }

    public function test_trae_la_dofa_una_sola_vez_y_con_el_tipo_correcto(): void
    {
        $this->deLaEmpresa(ContextIssue::class, ['origen' => 'externo', 'dofa' => 'amenaza', 'pestel' => 'legal', 'descripcion' => 'Nueva norma de alturas', 'impacto' => 'alto', 'tratamiento' => 'Capacitar', 'sistemas' => ['iso45001']]);
        $this->deLaEmpresa(ContextIssue::class, ['origen' => 'interno', 'dofa' => 'fortaleza', 'descripcion' => 'Equipo con experiencia', 'impacto' => 'medio', 'sistemas' => ['iso9001']]);

        $this->comoConsultor()->post('/riesgos-oportunidades/desde-dofa')->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/riesgos-oportunidades/desde-dofa')->assertSessionHasNoErrors();

        $filas = RiskOpportunity::withoutTenantScope()->orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertSame(['riesgo', 'oportunidad'], $filas->pluck('tipo')->all());
        $this->assertSame(4, $filas[0]->impacto);        // alto → 4
        $this->assertSame('Capacitar', $filas[0]->acciones);
    }

    public function test_crear_la_accion_en_acpm_la_vincula_y_pasa_a_tratamiento(): void
    {
        $this->riesgo();
        $r = RiskOpportunity::withoutTenantScope()->firstOrFail();

        $this->comoConsultor()->post("/riesgos-oportunidades/{$r->id}/accion", [
            'accion' => 'Buscar dos clientes nuevos', 'responsable' => 'Gerente comercial', 'fecha_limite' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $acpm = AcpmAction::withoutTenantScope()->firstOrFail();
        $this->assertSame('riesgo_oportunidad', $acpm->origen_tipo);
        $this->assertSame('preventiva', $acpm->tipo);
        $r->refresh();
        $this->assertSame($acpm->id, $r->acpm_action_id);
        $this->assertSame('en_tratamiento', $r->estado);

        // Una sola acción por fila.
        $this->comoConsultor()->post("/riesgos-oportunidades/{$r->id}/accion", [
            'accion' => 'Otra', 'responsable' => 'x', 'fecha_limite' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('acpm');
    }

    public function test_no_acepta_procesos_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '900000009-1']);
        Process::asegurarBase($otra->id);
        $ajeno = Process::withoutTenantScope()->where('tenant_id', $otra->id)->firstOrFail();

        $this->riesgo(['process_id' => $ajeno->id])->assertSessionHasErrors('process_id');
    }

    public function test_significancia_por_puntaje_o_por_requisito_legal(): void
    {
        $bajo = new EnvironmentalAspect(['tipo_impacto' => 'negativo', 'frecuencia' => 2, 'severidad' => 2, 'requisito_legal' => false, 'preocupa_partes' => false]);
        $this->assertFalse($bajo->significativo);
        $bajo->requisito_legal = true;
        $this->assertTrue($bajo->significativo);

        $alto = new EnvironmentalAspect(['tipo_impacto' => 'negativo', 'frecuencia' => 3, 'severidad' => 4, 'requisito_legal' => false, 'preocupa_partes' => false]);
        $this->assertTrue($alto->significativo);
        // Una emergencia grave es significativa aunque sea rara.
        $incendio = new EnvironmentalAspect(['tipo_impacto' => 'negativo', 'condicion' => 'emergencia', 'frecuencia' => 1, 'severidad' => 5, 'requisito_legal' => false, 'preocupa_partes' => false]);
        $this->assertTrue($incendio->significativo);

        // Un impacto positivo no es significativo por muy alto que puntúe.
        $alto->tipo_impacto = 'positivo';
        $this->assertFalse($alto->significativo);
    }

    public function test_la_base_de_aspectos_se_carga_una_sola_vez(): void
    {
        $this->comoConsultor()->post('/aspectos-ambientales/base')->assertSessionHasNoErrors();
        $this->assertSame(count(EnvironmentalAspect::BASE), EnvironmentalAspect::withoutTenantScope()->count());
        $this->comoConsultor()->post('/aspectos-ambientales/base')->assertSessionHasErrors('matriz');
    }

    public function test_un_aspecto_significativo_crea_su_accion_en_acpm(): void
    {
        $this->comoConsultor()->post('/aspectos-ambientales/base');
        $a = EnvironmentalAspect::withoutTenantScope()->get()->firstWhere('significativo', true);

        $this->comoConsultor()->post("/aspectos-ambientales/{$a->id}/accion", [
            'accion' => 'Contratar gestor de RESPEL', 'responsable' => 'Coordinador ambiental', 'fecha_limite' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $acpm = AcpmAction::withoutTenantScope()->firstOrFail();
        $this->assertSame('aspecto_ambiental', $acpm->origen_tipo);
        $this->assertSame($acpm->id, $a->refresh()->acpm_action_id);
    }

    public function test_las_matrices_van_al_control_documental_con_sus_requisitos(): void
    {
        $this->riesgo();
        $this->comoConsultor()->post('/aspectos-ambientales/base');

        $this->comoConsultor()->post('/riesgos-oportunidades/enviar')->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/aspectos-ambientales/enviar')->assertSessionHasNoErrors();

        $docs = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->get();
        $this->assertCount(2, $docs);
        $this->assertTrue($docs->every(fn ($d) => str_starts_with($d->codigo, 'MTZ-GSI-')));

        $riesgos = $docs->firstWhere('titulo', 'Matriz de riesgos y oportunidades por proceso');
        $this->assertStringContainsString('Pérdida del contrato principal', $riesgos->versions()->firstOrFail()->contenido);
        // SIG-21 en las tres ISO.
        $this->assertSame(['6.1', '6.1.1', '6.1.1'], $riesgos->requirements()->pluck('referencia')->sort()->values()->all());

        $aspectos = $docs->firstWhere('titulo', 'Matriz de aspectos e impactos ambientales');
        $this->assertSame(['6.1.2'], $aspectos->requirements()->pluck('referencia')->all());
        $this->assertStringContainsString('**Sí**', $aspectos->versions()->firstOrFail()->contenido);
    }

    public function test_el_informe_trae_las_dos_secciones(): void
    {
        $this->riesgo();
        $this->comoConsultor()->post('/aspectos-ambientales/base');

        $informe = app(InformeGestion::class);
        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
        $datos = $informe->generar($this->consultor, Periodo::desde('2026-01-01', '2026-12-31'), ['riesgos-oportunidades', 'aspectos-ambientales']);

        $secciones = collect($datos['secciones'])->keyBy('clave');
        $riesgos = collect($secciones['riesgos-oportunidades']['cifras'])->pluck('valor', 'etiqueta');
        $this->assertSame('1', $riesgos['Altos o críticos sin tratar']);

        $aspectos = collect($secciones['aspectos-ambientales']['cifras'])->pluck('valor', 'etiqueta');
        $esperados = collect(EnvironmentalAspect::BASE)->filter(fn ($b) => $b[5] * $b[6] >= EnvironmentalAspect::UMBRAL || $b[7]
            || ($b[3] === 'emergencia' && $b[6] >= EnvironmentalAspect::SEVERIDAD_EMERGENCIA))->count();
        $this->assertSame((string) $esperados, $aspectos['Significativos']);
    }
}
