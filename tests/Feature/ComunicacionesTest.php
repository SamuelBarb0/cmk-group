<?php

namespace Tests\Feature;

use App\Models\CommunicationLog;
use App\Models\CommunicationPlanItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M19 — Comunicaciones: matriz de comunicaciones y registro de lo enviado y
 * recibido, con respuestas pendientes y vencidas.
 */
class ComunicacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function registro(array $extra = [])
    {
        return $this->comoConsultor()->post('/comunicaciones/registro', array_merge([
            'fecha' => '2026-09-01', 'tipo' => 'externa', 'direccion' => 'entrante',
            'parte_interesada' => 'Ministerio del Trabajo', 'asunto' => 'Requerimiento de información del SG-SST',
        ], $extra));
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/comunicaciones')->assertOk()
            ->assertInertia(fn ($p) => $p->component('comunicaciones/index')->where('needsClient', false));
    }

    public function test_la_matriz_base_se_carga_una_sola_vez(): void
    {
        $this->comoConsultor()->post('/comunicaciones/matriz/base')->assertSessionHasNoErrors();
        $total = count(CommunicationPlanItem::BASE);
        $this->assertSame($total, CommunicationPlanItem::withoutTenantScope()->count());

        // Sobre una matriz que ya existe no se carga: pisaría la de la empresa.
        $this->comoConsultor()->post('/comunicaciones/matriz/base')->assertSessionHasErrors('matriz');
        $this->assertSame($total, CommunicationPlanItem::withoutTenantScope()->count());
    }

    public function test_una_fila_de_la_matriz_exige_que_cuando_quien_como(): void
    {
        $this->comoConsultor()->post('/comunicaciones/matriz', ['tipo' => 'interna', 'que' => 'Política', 'sistemas' => ['sst']])
            ->assertSessionHasErrors(['cuando', 'a_quien', 'como', 'responsable']);

        $this->comoConsultor()->post('/comunicaciones/matriz', [
            'tipo' => 'interna', 'que' => 'Política', 'cuando' => 'Anual', 'a_quien' => 'Todos',
            'como' => 'Cartelera', 'responsable' => 'SST', 'sistemas' => ['sst'],
        ])->assertSessionHasNoErrors();
    }

    public function test_pendiente_y_vencida_salen_de_la_respuesta(): void
    {
        $this->registro(['requiere_respuesta' => true, 'fecha_limite_respuesta' => '2026-09-10'])->assertSessionHasNoErrors();
        $c = CommunicationLog::withoutTenantScope()->firstOrFail();

        $this->assertTrue($c->pendiente);
        $this->assertTrue($c->respuesta_vencida);

        $this->comoConsultor()->put("/comunicaciones/registro/{$c->id}", [
            'fecha' => '2026-09-01', 'tipo' => 'externa', 'direccion' => 'entrante',
            'parte_interesada' => 'Ministerio del Trabajo', 'asunto' => 'Requerimiento',
            'requiere_respuesta' => true, 'fecha_limite_respuesta' => '2026-09-10', 'fecha_respuesta' => '2026-09-08', 'respuesta' => 'Oficio enviado',
        ])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertFalse($c->pendiente);
        $this->assertFalse($c->respuesta_vencida);
    }

    public function test_sin_respuesta_pedida_no_guarda_plazo_ni_respuesta(): void
    {
        $this->registro(['requiere_respuesta' => false, 'fecha_limite_respuesta' => '2026-09-10', 'respuesta' => 'x'])->assertSessionHasNoErrors();

        $c = CommunicationLog::withoutTenantScope()->firstOrFail();
        $this->assertNull($c->fecha_limite_respuesta);
        $this->assertNull($c->respuesta);
    }

    public function test_la_respuesta_no_puede_ser_anterior_a_la_comunicacion(): void
    {
        $this->registro(['requiere_respuesta' => true, 'fecha_respuesta' => '2026-08-01'])->assertSessionHasErrors('fecha_respuesta');
    }

    public function test_no_enlaza_la_matriz_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '900000009-1']);
        $ajena = new CommunicationPlanItem(['tipo' => 'interna', 'que' => 'x', 'cuando' => 'x', 'a_quien' => 'x', 'como' => 'x', 'responsable' => 'x', 'sistemas' => ['sst']]);
        $ajena->tenant_id = $otra->id;
        $ajena->save();

        $this->registro(['communication_plan_id' => $ajena->id])->assertSessionHasErrors('communication_plan_id');
    }

    public function test_el_informe_de_gestion_trae_la_seccion(): void
    {
        $this->registro(['requiere_respuesta' => true, 'fecha_limite_respuesta' => '2026-09-10']);

        $this->comoConsultor()->get('/reportes?desde=2026-01-01&hasta=2026-12-31&secciones[]=comunicaciones')->assertOk();

        $informe = app(InformeGestion::class);
        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
        $datos = $informe->generar($this->consultor, Periodo::desde('2026-01-01', '2026-12-31'), ['comunicaciones']);

        $cifras = collect($datos['secciones'][0]['cifras'])->pluck('valor', 'etiqueta');
        $this->assertSame('1', $cifras['Esperando respuesta hoy']);
        $this->assertSame('1', $cifras['Respuestas vencidas']);
    }
}
