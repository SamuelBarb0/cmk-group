<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\ControlledDocument;
use App\Models\CustomerRequest;
use App\Models\NonconformingOutput;
use App\Models\SatisfactionSurvey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M17 — Calidad (ISO 9001): PQRS con plazo y respuesta, salidas no conformes
 * con su tratamiento, satisfacción del cliente, acciones en ACPM, documentos
 * e informe.
 */
class CalidadTest extends TestCase
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

    private function pqrs(array $extra = [])
    {
        return $this->comoConsultor()->post('/calidad/pqrs', array_merge([
            'fecha' => '2026-09-04', 'tipo' => 'reclamo', 'cliente' => 'Constructora Andina', 'canal' => 'correo',
            'descripcion' => 'El pedido llegó incompleto.', 'estado' => 'abierta',
        ], $extra));
    }

    private function salida(array $extra = [])
    {
        return $this->comoConsultor()->post('/calidad/salidas', array_merge([
            'fecha' => '2026-09-10', 'producto' => 'Lote 245 de tableros', 'detectado_en' => 'final',
            'descripcion' => 'Pintura con burbujas.', 'tratamiento' => 'reproceso', 'estado' => 'abierta',
        ], $extra));
    }

    private function encuesta(array $notas, string $fecha = '2026-09-15')
    {
        return $this->comoConsultor()->post('/calidad/encuestas', ['fecha' => $fecha, 'cliente' => 'Cliente '.uniqid()] + $notas);
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/calidad')->assertOk()
            ->assertInertia(fn ($p) => $p->component('calidad/index')->where('needsClient', false)->has('documentos', 5));
    }

    public function test_la_pqrs_se_radica_con_consecutivo_y_plazo_de_15_dias_habiles(): void
    {
        $this->pqrs()->assertSessionHasNoErrors();
        $this->pqrs(['tipo' => 'peticion'])->assertSessionHasNoErrors();

        $r = CustomerRequest::withoutTenantScope()->orderBy('id')->get();
        $anio = now()->year;
        $this->assertSame(["PQRS-{$anio}-001", "PQRS-{$anio}-002"], $r->pluck('radicado')->all());
        // Viernes 4 de sept. + 15 días hábiles (sin fines de semana) = viernes 25.
        $this->assertSame('2026-09-25', $r[0]->fecha_limite->toDateString());
        $this->assertSame('2026-09-25', CustomerRequest::plazo('2026-09-04')->toDateString());
    }

    public function test_no_se_cierra_una_pqrs_sin_respuesta(): void
    {
        $this->pqrs(['estado' => 'cerrada'])->assertSessionHasErrors('respuesta');
        $this->pqrs(['estado' => 'cerrada', 'respuesta' => 'Enviamos lo faltante.', 'fecha_respuesta' => '2026-09-08'])->assertSessionHasNoErrors();

        $r = CustomerRequest::withoutTenantScope()->firstOrFail();
        $this->assertTrue($r->a_tiempo);
        $this->assertSame(4, $r->dias_respuesta);
    }

    public function test_la_pqrs_sin_respuesta_pasado_el_plazo_queda_vencida(): void
    {
        Carbon::setTestNow('2026-10-01');
        $this->pqrs()->assertSessionHasNoErrors();
        $this->assertTrue(CustomerRequest::withoutTenantScope()->firstOrFail()->vencida);
        Carbon::setTestNow();
    }

    public function test_procede_solo_aplica_a_quejas_y_reclamos(): void
    {
        $this->pqrs(['tipo' => 'sugerencia', 'procede' => true])->assertSessionHasNoErrors();
        $this->assertNull(CustomerRequest::withoutTenantScope()->firstOrFail()->procede);
    }

    public function test_el_reclamo_que_procede_crea_su_accion_correctiva(): void
    {
        $datos = ['accion' => 'Revisar el alistamiento', 'responsable' => 'Jefe de bodega', 'fecha_limite' => now()->addMonth()->toDateString()];
        $this->pqrs(['procede' => false]);
        $r = CustomerRequest::withoutTenantScope()->firstOrFail();
        $this->comoConsultor()->post("/calidad/pqrs/{$r->id}/accion", $datos)->assertSessionHasErrors('acpm');

        $r->update(['procede' => true]);
        $this->comoConsultor()->post("/calidad/pqrs/{$r->id}/accion", $datos)->assertSessionHasNoErrors();

        $acpm = AcpmAction::withoutTenantScope()->firstOrFail();
        $this->assertSame(['correctiva', 'pqrs'], [$acpm->tipo, $acpm->origen_tipo]);
        $this->assertStringContainsString($r->radicado, $acpm->hallazgo);
        $this->assertSame($acpm->id, $r->refresh()->acpm_action_id);
    }

    public function test_la_concesion_exige_quien_la_autorizo(): void
    {
        $this->salida(['tratamiento' => 'concesion'])->assertSessionHasErrors('autorizado_por');
        $this->salida(['tratamiento' => 'concesion', 'autorizado_por' => 'Gerente y cliente por correo'])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('SNC-', NonconformingOutput::withoutTenantScope()->firstOrFail()->codigo);
    }

    public function test_un_reproceso_no_se_cierra_sin_verificar(): void
    {
        $this->salida(['estado' => 'cerrada'])->assertSessionHasErrors('verificado_por');
        $this->salida(['estado' => 'cerrada', 'verificado_por' => 'Inspector de calidad', 'fecha_verificacion' => '2026-09-11'])->assertSessionHasNoErrors();
        // Un desecho no se verifica: se cierra de una vez.
        $this->salida(['tratamiento' => 'desecho', 'estado' => 'cerrada'])->assertSessionHasNoErrors();
    }

    public function test_el_indice_de_satisfaccion_es_la_suma_sobre_el_maximo(): void
    {
        $cinco = array_fill_keys(array_keys(SatisfactionSurvey::CRITERIOS), 5);
        $tres = array_fill_keys(array_keys(SatisfactionSurvey::CRITERIOS), 3);
        $this->encuesta($cinco)->assertSessionHasNoErrors();
        $this->encuesta($tres)->assertSessionHasNoErrors();
        $this->encuesta(['calidad' => 6] + $tres)->assertSessionHasErrors('calidad');

        $todas = SatisfactionSurvey::withoutTenantScope()->get();
        $this->assertSame([100.0, 60.0], $todas->pluck('indice')->all());
        $this->assertSame(80.0, SatisfactionSurvey::resumen($todas)['indice']);
    }

    public function test_los_documentos_van_al_control_documental_con_su_requisito(): void
    {
        $this->pqrs(['fecha' => now()->subDays(3)->toDateString()]);
        $this->salida(['fecha' => now()->subDays(2)->toDateString()]);
        $this->encuesta(array_fill_keys(array_keys(SatisfactionSurvey::CRITERIOS), 4), now()->subDay()->toDateString());

        foreach (['pqrs_prc', 'pqrs_ft', 'snc_prc', 'snc_ft', 'encuesta'] as $d) {
            $this->comoConsultor()->post('/calidad/enviar', ['documento' => $d])->assertSessionHasNoErrors();
        }

        $docs = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->get()->keyBy('titulo');
        $this->assertCount(5, $docs);
        $this->assertStringContainsString('Constructora Andina', $docs['Registro y respuesta de PQRS']->versions()->firstOrFail()->contenido);
        $this->assertStringContainsString('Lote 245', $docs['Registro de salidas no conformes']->versions()->firstOrFail()->contenido);
        $this->assertStringContainsString('**80 %**', $docs['Encuesta de satisfacción del cliente']->versions()->firstOrFail()->contenido);
        $this->assertSame(['8.6–8.7'], $docs['Procedimiento de control de salidas no conformes']->requirements()->pluck('referencia')->all());
        $this->assertSame(['9.1.2'], $docs['Encuesta de satisfacción del cliente']->requirements()->pluck('referencia')->all());
    }

    public function test_el_informe_trae_la_seccion(): void
    {
        $this->pqrs(['fecha' => '2026-08-03', 'estado' => 'cerrada', 'respuesta' => 'Listo.', 'fecha_respuesta' => '2026-09-01']);
        $this->salida(['detectado_en' => 'cliente']);
        $this->encuesta(array_fill_keys(array_keys(SatisfactionSurvey::CRITERIOS), 3));

        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
        $datos = app(InformeGestion::class)->generar($this->consultor, Periodo::desde('2026-07-01', '2026-09-30'), ['calidad']);

        $cifras = collect($datos['secciones'][0]['cifras'])->pluck('valor', 'etiqueta');
        $this->assertSame('1', $cifras['PQRS recibidas']);
        $this->assertSame('0 %', $cifras['Respondidas a tiempo']);
        $this->assertSame('1', $cifras['Detectadas por el cliente']);
        $this->assertSame('60 %', $cifras['Índice de satisfacción']);
        $this->assertNotEmpty(collect($datos['atencion'])->where('seccion', 'Calidad: clientes y salidas no conformes'));
    }
}
