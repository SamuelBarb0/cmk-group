<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkPlan;
use App\Models\WorkPlanActivity;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\WorkPlanActivitiesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Plan de Trabajo: cada empresa lleva DOS planes por año. El SST-PESV (hoja
 * «2.4.1 Plan de trabajo», Res. 0312) faltaba: solo existía el del SGI por
 * cláusulas ISO, y las actividades del SG-SST no aparecían en ningún lado.
 */
class PlanTrabajoTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28 10:00:00');
        $this->seed([RolesAndPermissionsSeeder::class, WorkPlanActivitiesSeeder::class]);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    public function test_el_catalogo_trae_los_dos_planes(): void
    {
        $this->assertSame(88, WorkPlanActivity::where('plan', 'sst')->count());
        $this->assertSame(28, WorkPlanActivity::where('plan', 'sgi')->count());

        // Emergencias (ISO 14001/45001 8.2) no puede perderse bajo el 8.2 de la 9001.
        $this->assertTrue(WorkPlanActivity::where('codigo', '8.2E')->where('plan', 'sgi')->exists());

        $sst = WorkPlanActivity::where('plan', 'sst')->orderBy('orden')->get();
        $this->assertSame(['I. Planear', 'II. Hacer', 'III. Verificar', 'IV. Actuar'], $sst->pluck('fase')->unique()->values()->all());
        $this->assertSame('SST-01', $sst->first()->codigo);
        $this->assertTrue($sst->contains(fn ($a) => str_contains($a->nombre, 'Reuniones COPASST')));
        $this->assertSame('Mensual', $sst->firstWhere('nombre', 'Reuniones COPASST y capacitaciones')->frecuencia);
    }

    public function test_por_defecto_abre_el_plan_sst_y_se_cambia_con_la_url(): void
    {
        $this->comoConsultor()->get('/plan-trabajo')->assertOk()
            ->assertInertia(fn ($p) => $p->component('plan-trabajo/index')
                ->where('tipo', 'sst')
                ->has('activities', 88)
                ->where('activities.0.codigo', 'SST-01'));

        $this->comoConsultor()->get('/plan-trabajo?plan=sgi')->assertOk()
            ->assertInertia(fn ($p) => $p->where('tipo', 'sgi')->has('activities', 28));

        // Un plan que no existe cae al SST.
        $this->comoConsultor()->get('/plan-trabajo?plan=otro')->assertOk()
            ->assertInertia(fn ($p) => $p->where('tipo', 'sst'));
    }

    public function test_cada_plan_guarda_su_programacion_y_su_cumplimiento(): void
    {
        $sst = WorkPlanActivity::where('plan', 'sst')->orderBy('orden')->first();
        $sgi = WorkPlanActivity::where('plan', 'sgi')->orderBy('orden')->first();
        $todasSst = WorkPlanActivity::where('plan', 'sst')->pluck('id')->all();

        $this->comoConsultor()->post('/plan-trabajo', [
            'plan' => 'sst',
            'seleccionadas' => $todasSst,
            'items' => [['activity_id' => $sst->id, 'programados' => [1, 2], 'ejecutados' => [1]]],
        ])->assertSessionHasNoErrors();

        $this->comoConsultor()->post('/plan-trabajo', [
            'plan' => 'sgi',
            'seleccionadas' => [$sgi->id],
            'items' => [['activity_id' => $sgi->id, 'programados' => [3], 'ejecutados' => [3]]],
        ])->assertSessionHasNoErrors();

        $planes = WorkPlan::withoutTenantScope()->where('anio', 2026)->get()->keyBy('tipo');
        $this->assertCount(2, $planes);
        $this->assertEquals(50.0, $planes['sst']->cumplimiento);
        $this->assertEquals(100.0, $planes['sgi']->cumplimiento);
        // Todas las del SST aplican (null = todas); del SGI solo la elegida.
        $this->assertNull($planes['sst']->actividades_seleccionadas);
        $this->assertSame([$sgi->id], $planes['sgi']->actividades_seleccionadas);

        $this->comoConsultor()->get('/plan-trabajo')->assertInertia(fn ($p) => $p
            ->where('resumen.sst', 50)
            ->where('resumen.sgi', 100));
    }

    public function test_no_se_mezclan_actividades_de_un_plan_en_el_otro(): void
    {
        $sgi = WorkPlanActivity::where('plan', 'sgi')->first();

        $this->comoConsultor()->post('/plan-trabajo', [
            'plan' => 'sst',
            'seleccionadas' => [$sgi->id],
            'items' => [['activity_id' => $sgi->id, 'programados' => [1]]],
        ])->assertSessionHasErrors(['seleccionadas.0', 'items.0.activity_id']);
    }

    public function test_las_firmas_son_de_cada_plan(): void
    {
        $this->comoConsultor()->post('/plan-trabajo/firmar', ['plan' => 'sst', 'rol' => 'representante', 'nombre' => 'María Gómez', 'cc' => '123'])
            ->assertSessionHasNoErrors();

        $planes = WorkPlan::withoutTenantScope()->get()->keyBy('tipo');
        $this->assertSame('María Gómez', $planes['sst']->firma_rep_nombre);
        $this->assertFalse(isset($planes['sgi']) && $planes['sgi']->firma_rep_nombre);
    }
}
