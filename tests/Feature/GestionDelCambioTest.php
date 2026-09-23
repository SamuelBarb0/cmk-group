<?php

namespace Tests\Feature;

use App\Models\ChangeRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestión del cambio. Lo que se comprueba son las reglas del procedimiento de
 * CMK («PASO 18»): sin la aprobación de la Gerencia Y del encargado del SG-SST
 * no hay cierre, y si el cambio exige actualizar la IPERC tampoco.
 */
class GestionDelCambioTest extends TestCase
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
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function cambio(array $extra = []): array
    {
        return array_merge([
            'fecha_solicitud' => '2026-09-01',
            'solicitante' => 'Carlos Rodríguez',
            'area' => 'Operaciones',
            'tipo' => 'proceso',
            'condicion' => 'fijo',
            'descripcion' => 'Se cambia el montacargas por uno eléctrico',
            'requiere_actualizar_iperc' => false,
            'requiere_capacitacion' => false,
            'actions' => [],
        ], $extra);
    }

    private function aprobado(): array
    {
        return [
            'aprobacion_gerencia_nombre' => 'María Gómez', 'aprobacion_gerencia_fecha' => '2026-09-02',
            'aprobacion_sst_nombre' => 'Carlos Rodríguez', 'aprobacion_sst_fecha' => '2026-09-03',
        ];
    }

    private function cierre(array $extra = []): array
    {
        return array_merge([
            'cierre_fecha' => '2026-09-20',
            'cierre_implementado' => true,
            'cierre_a_tiempo' => true,
            'cierre_eficaz' => true,
        ], $extra);
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/gestion-cambio')->assertOk()
            ->assertInertia(fn ($p) => $p->component('gestion-cambio/index')->where('needsClient', false));
    }

    public function test_el_estado_sale_de_las_aprobaciones(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio())->assertSessionHasNoErrors();
        $c = ChangeRequest::withoutTenantScope()->sole();
        $this->assertSame('pendiente_aprobacion', $c->estado);

        // Con UNA sola aprobación sigue pendiente.
        $this->comoConsultor()->put('/gestion-cambio/'.$c->id, $this->cambio([
            'aprobacion_gerencia_nombre' => 'María Gómez', 'aprobacion_gerencia_fecha' => '2026-09-02',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('pendiente_aprobacion', $c->fresh()->estado);

        $this->comoConsultor()->put('/gestion-cambio/'.$c->id, $this->cambio($this->aprobado()))->assertSessionHasNoErrors();
        $this->assertSame('aprobado', $c->fresh()->estado);
    }

    public function test_no_se_cierra_sin_las_dos_aprobaciones(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio([
            'aprobacion_gerencia_nombre' => 'María Gómez', 'aprobacion_gerencia_fecha' => '2026-09-02',
        ] + $this->cierre()))->assertSessionHasErrors('cierre_fecha');

        $this->assertSame(0, ChangeRequest::withoutTenantScope()->count());
    }

    public function test_no_se_cierra_sin_actualizar_la_iperc_si_el_cambio_lo_exige(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(
            ['requiere_actualizar_iperc' => true] + $this->aprobado() + $this->cierre()
        ))->assertSessionHasErrors('iperc_actualizada_at');

        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(
            ['requiere_actualizar_iperc' => true, 'iperc_actualizada_at' => '2026-09-10'] + $this->aprobado() + $this->cierre()
        ))->assertSessionHasNoErrors();

        $this->assertSame('cerrado', ChangeRequest::withoutTenantScope()->sole()->estado);
    }

    public function test_un_no_en_el_cierre_exige_justificacion(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(
            $this->aprobado() + $this->cierre(['cierre_eficaz' => false])
        ))->assertSessionHasErrors('cierre_justificacion');

        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(
            $this->aprobado() + $this->cierre(['cierre_eficaz' => false, 'cierre_justificacion' => 'El proveedor no entregó a tiempo'])
        ))->assertSessionHasNoErrors();
    }

    public function test_un_rechazado_pide_motivo_y_no_se_cierra(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(['rechazado_at' => '2026-09-02']))
            ->assertSessionHasErrors('motivo_rechazo');

        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(
            ['rechazado_at' => '2026-09-02', 'motivo_rechazo' => 'Sin presupuesto'] + $this->aprobado() + $this->cierre()
        ))->assertSessionHasErrors('cierre_fecha');
    }

    public function test_una_aprobacion_necesita_nombre_y_fecha(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(['aprobacion_sst_fecha' => '2026-09-03']))
            ->assertSessionHasErrors('aprobacion_sst_nombre');
    }

    public function test_plan_de_accion_y_analisis_se_guardan_limpios(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio([
            'analisis' => [
                'mano_obra' => ['actividad' => 'Capacitar a 3 operarios', 'costo' => '450000', 'observaciones' => ''],
                'tecnologico' => ['actividad' => '', 'costo' => '', 'observaciones' => ''],
                'inventado' => ['actividad' => 'no debe guardarse'],
            ],
            'actions' => [
                ['descripcion' => 'Comprar el montacargas', 'ejecutada' => true],
                ['descripcion' => 'Actualizar el procedimiento', 'ejecutada' => false],
            ],
        ]))->assertSessionHasNoErrors();

        $c = ChangeRequest::withoutTenantScope()->sole();
        $this->assertSame(['mano_obra'], array_keys($c->analisis));
        $this->assertEquals(450000, $c->analisis['mano_obra']['costo']);
        $this->assertSame(2, $c->actions()->count());
    }

    public function test_una_actividad_sin_descripcion_no_deja_el_cambio_a_medias(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio([
            'actions' => [['descripcion' => '', 'ejecutada' => false]],
        ]))->assertSessionHasErrors('actions.0.descripcion');

        $this->assertSame(0, ChangeRequest::withoutTenantScope()->count());
    }

    public function test_vencido_y_estadisticas(): void
    {
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(['fecha_limite' => '2026-09-05'] + $this->aprobado()));
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio($this->aprobado() + $this->cierre()));
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio(
            $this->aprobado() + $this->cierre(['cierre_eficaz' => false, 'cierre_justificacion' => 'No redujo el riesgo'])
        ));
        $this->comoConsultor()->post('/gestion-cambio', $this->cambio());

        $this->comoConsultor()->get('/gestion-cambio')->assertInertia(fn ($p) => $p
            ->where('stats.pendientes', 1)
            ->where('stats.en_curso', 1)
            ->where('stats.vencidos', 1)
            ->where('stats.cerrados', 2)
            ->where('stats.eficacia', 50));
    }

    public function test_no_se_toca_un_cambio_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = new ChangeRequest($this->cambio());
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->app->forgetInstance(TenantContext::class);
        $this->comoConsultor()->delete('/gestion-cambio/'.$ajeno->id)->assertNotFound();
        $this->assertTrue(ChangeRequest::withoutTenantScope()->whereKey($ajeno->id)->exists());
    }
}
