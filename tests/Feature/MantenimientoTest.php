<?php

namespace Tests\Feature;

use App\Models\MaintenanceAsset;
use App\Models\MaintenancePlanItem;
use App\Models\MaintenanceRecord;
use App\Models\PesvVehicle;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mantenimiento de activos: el cálculo del próximo mantenimiento (por días y
 * por uso), el enlace con los vehículos del PESV y el aislamiento entre
 * empresas, que aquí tiene dos puertas extra: el activo y el ítem del plan
 * llegan como ids en el cuerpo, no por la URL.
 */
class MantenimientoTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comoConsultor()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function vehiculo(?Tenant $de = null, array $extra = []): PesvVehicle
    {
        $v = new PesvVehicle(array_merge(['placa' => 'ABC123', 'tipo' => 'camioneta', 'marca' => 'Toyota', 'linea' => 'Hilux', 'kilometraje' => 20000], $extra));
        $v->tenant_id = ($de ?? $this->empresa)->id;
        $v->save();

        return $v;
    }

    private function activo(array $extra = [], array $plan = []): MaintenanceAsset
    {
        $this->comoConsultor()->post('/mantenimiento/activos', array_merge([
            'tipo' => 'maquina', 'nombre' => 'Compresor', 'activo' => true, 'plan' => $plan,
        ], $extra))->assertSessionHasNoErrors();

        return MaintenanceAsset::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function registrar(MaintenanceAsset $a, array $extra = [])
    {
        return $this->comoConsultor()->post('/mantenimiento/registros', array_merge([
            'maintenance_asset_id' => $a->id, 'fecha' => now()->toDateString(), 'tipo' => 'preventivo',
            'descripcion' => 'Hecho', 'estado' => 'cerrada',
        ], $extra));
    }

    public function test_la_pantalla_carga_con_y_sin_cliente(): void
    {
        $this->comoConsultor()->get('/mantenimiento')->assertOk()
            ->assertInertia(fn ($p) => $p->component('mantenimiento/index')->where('needsClient', false));

        $this->flushSession();
        $this->actingAs($this->consultor)->get('/mantenimiento')->assertOk()
            ->assertInertia(fn ($p) => $p->where('needsClient', true));
    }

    public function test_el_vencimiento_por_dias_avisa_en_el_ultimo_diez_por_ciento(): void
    {
        $item = new MaintenancePlanItem(['actividad' => 'Lubricar', 'frecuencia_valor' => 100, 'frecuencia_unidad' => 'dias']);
        $ultimo = new MaintenanceRecord(['fecha' => '2026-01-01']);
        $en = fn (string $hoy) => $item->vencimiento($ultimo, null, Carbon::parse($hoy));

        // Próximo: 11 de abril (1 de enero + 100 días). Margen: 10 días.
        $this->assertSame('al_dia', $en('2026-03-31')['estado']);
        $this->assertSame('2026-04-11', $en('2026-03-31')['proxima_fecha']);
        $this->assertSame('por_vencer', $en('2026-04-01')['estado']);   // faltan 10
        $this->assertSame('por_vencer', $en('2026-04-11')['estado']);   // es hoy
        $this->assertSame('vencido', $en('2026-04-12')['estado']);
        $this->assertSame(-1, $en('2026-04-12')['restante']);

        $this->assertSame('sin_registro', $item->vencimiento(null, null)['estado']);
        $aDemanda = new MaintenancePlanItem(['actividad' => 'Latonería']);
        $this->assertSame('sin_frecuencia', $aDemanda->vencimiento($ultimo, null)['estado']);
    }

    public function test_el_vencimiento_por_km_usa_la_lectura_del_ultimo_y_la_actual(): void
    {
        $item = new MaintenancePlanItem(['actividad' => 'Aceite', 'frecuencia_valor' => 5000, 'frecuencia_unidad' => 'km']);
        $ultimo = new MaintenanceRecord(['fecha' => '2026-01-01', 'lectura' => 20000]);

        $this->assertSame(['estado' => 'al_dia', 'proxima_fecha' => null, 'proxima_lectura' => 25000, 'restante' => 1000], $item->vencimiento($ultimo, 24000));
        $this->assertSame('por_vencer', $item->vencimiento($ultimo, 24500)['estado']);   // faltan 500 = 10 %
        $this->assertSame('vencido', $item->vencimiento($ultimo, 25000)['estado']);
        // Sin la lectura del último no hay cómo saber cuándo toca.
        $this->assertSame('sin_lectura', $item->vencimiento(new MaintenanceRecord(['fecha' => '2026-01-01']), 24000)['estado']);
    }

    public function test_registrar_sube_la_lectura_pero_nunca_la_baja(): void
    {
        $a = $this->activo(['tipo' => 'vehiculo', 'unidad_lectura' => 'km', 'lectura_actual' => 30000], [
            ['actividad' => 'Aceite', 'frecuencia_valor' => 5000, 'frecuencia_unidad' => 'km'],
        ]);
        $item = $a->planItems()->first();

        $this->registrar($a, ['maintenance_plan_item_id' => $item->id, 'lectura' => 32000])->assertSessionHasNoErrors();
        $this->assertSame(32000, $a->fresh()->lectura_actual);

        // Un registro viejo cargado tarde no mueve el odómetro hacia atrás.
        $this->registrar($a, ['fecha' => now()->subMonths(6)->toDateString(), 'lectura' => 15000])->assertSessionHasNoErrors();
        $this->assertSame(32000, $a->fresh()->lectura_actual);

        // El próximo sale del último registro DE ESE ÍTEM: 32.000 + 5.000.
        $this->comoConsultor()->get('/mantenimiento')->assertInertia(fn ($p) => $p
            ->where('activos.0.plan.0.proxima_lectura', 37000)
            ->where('activos.0.plan.0.estado', 'al_dia'));
    }

    public function test_traer_vehiculos_del_pesv_crea_el_activo_con_el_plan_del_paso_17_una_sola_vez(): void
    {
        $v = $this->vehiculo();
        $this->vehiculo(null, ['placa' => 'XYZ999', 'is_active' => false]);   // fuera de uso: no entra

        $this->comoConsultor()->post('/mantenimiento/activos/importar-vehiculos')->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/mantenimiento/activos/importar-vehiculos');

        $activos = MaintenanceAsset::withoutTenantScope()->get();
        $this->assertCount(1, $activos);
        $a = $activos->first();
        $this->assertSame($v->id, $a->pesv_vehicle_id);
        $this->assertSame('ABC123', $a->codigo);
        $this->assertSame(20000, $a->lectura_actual);
        $this->assertSame(15, $a->planItems()->count());
        $this->assertSame(5000, $a->planItems()->first()->frecuencia_valor);
    }

    public function test_el_vehiculo_del_pesv_recibe_el_resumen_del_mantenimiento(): void
    {
        $v = $this->vehiculo();
        $a = $this->activo(['tipo' => 'vehiculo', 'pesv_vehicle_id' => $v->id, 'unidad_lectura' => 'km', 'lectura_actual' => 20000], [
            ['actividad' => 'Revisión general', 'frecuencia_valor' => 90, 'frecuencia_unidad' => 'dias'],
        ]);

        $this->registrar($a, ['maintenance_plan_item_id' => $a->planItems()->first()->id, 'fecha' => '2026-09-01', 'lectura' => 21500])
            ->assertSessionHasNoErrors();

        $v->refresh();
        $this->assertSame('2026-09-01', $v->ultimo_mantenimiento->toDateString());
        $this->assertSame('2026-11-30', $v->proximo_mantenimiento->toDateString());   // + 90 días
        $this->assertSame(21500, $v->kilometraje);

        // Borrar el único registro deja al vehículo sin mantenimiento.
        $this->comoConsultor()->delete('/mantenimiento/registros/'.MaintenanceRecord::withoutTenantScope()->first()->id);
        $this->assertNull($v->fresh()->ultimo_mantenimiento);
    }

    public function test_editar_el_plan_conserva_los_ids_y_el_historial(): void
    {
        $a = $this->activo([], [['actividad' => 'Lubricar', 'frecuencia_valor' => 30, 'frecuencia_unidad' => 'dias']]);
        $item = $a->planItems()->first();
        $this->registrar($a, ['maintenance_plan_item_id' => $item->id])->assertSessionHasNoErrors();

        $this->comoConsultor()->put("/mantenimiento/activos/{$a->id}", [
            'tipo' => 'maquina', 'nombre' => 'Compresor', 'activo' => true,
            'plan' => [
                ['id' => $item->id, 'actividad' => 'Lubricar y limpiar', 'frecuencia_valor' => 45, 'frecuencia_unidad' => 'dias'],
                ['actividad' => 'Cambiar filtro', 'frecuencia_valor' => 180, 'frecuencia_unidad' => 'dias'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Lubricar y limpiar', $item->fresh()->actividad);
        $this->assertSame($item->id, MaintenanceRecord::withoutTenantScope()->first()->maintenance_plan_item_id);
        $this->assertSame(2, $a->planItems()->count());
    }

    public function test_valida_el_plan_la_unidad_y_la_fecha(): void
    {
        // Ítem por km en un activo sin lectura en km: nunca podría vencer.
        $this->comoConsultor()->post('/mantenimiento/activos', [
            'tipo' => 'vehiculo', 'nombre' => 'Camión', 'activo' => true,
            'plan' => [['actividad' => 'Aceite', 'frecuencia_valor' => 5000, 'frecuencia_unidad' => 'km']],
        ])->assertSessionHasErrors('unidad_lectura');

        // Frecuencia sin unidad.
        $this->comoConsultor()->post('/mantenimiento/activos', [
            'tipo' => 'maquina', 'nombre' => 'Torno', 'activo' => true,
            'plan' => [['actividad' => 'Engrase', 'frecuencia_valor' => 30]],
        ])->assertSessionHasErrors('plan.0.frecuencia_unidad');
        $this->assertSame(0, MaintenanceAsset::withoutTenantScope()->count());

        $a = $this->activo();
        $this->registrar($a, ['fecha' => now()->addDay()->toDateString()])->assertSessionHasErrors('fecha');
    }

    public function test_no_se_cruzan_activos_items_ni_vehiculos_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = new MaintenanceAsset(['tipo' => 'maquina', 'nombre' => 'Ajeno']);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();
        $itemAjeno = $ajeno->planItems()->create(['actividad' => 'X', 'frecuencia_valor' => 10, 'frecuencia_unidad' => 'dias']);
        $vehiculoAjeno = $this->vehiculo($otra, ['placa' => 'OTR111']);

        // Por la URL: 404.
        $this->app->forgetInstance(TenantContext::class);
        $this->comoConsultor()->delete("/mantenimiento/activos/{$ajeno->id}")->assertNotFound();

        // Por el cuerpo: el activo, el ítem y el vehículo llegan como ids.
        $this->app->forgetInstance(TenantContext::class);
        $propio = $this->activo();
        $this->registrar($ajeno)->assertSessionHasErrors('maintenance_asset_id');
        $this->registrar($propio, ['maintenance_plan_item_id' => $itemAjeno->id])->assertSessionHasErrors('maintenance_plan_item_id');
        $this->comoConsultor()->post('/mantenimiento/activos', [
            'tipo' => 'vehiculo', 'nombre' => 'Robado', 'activo' => true, 'pesv_vehicle_id' => $vehiculoAjeno->id, 'plan' => [],
        ])->assertSessionHasErrors('pesv_vehicle_id');

        $this->assertSame(0, MaintenanceRecord::withoutTenantScope()->count());
        $this->assertTrue(MaintenanceAsset::withoutTenantScope()->whereKey($ajeno->id)->exists());
    }
}
