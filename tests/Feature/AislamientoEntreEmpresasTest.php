<?php

namespace Tests\Feature;

use App\Http\Middleware\SetCurrentTenant;
use App\Models\Employee;
use App\Models\IpercRow;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Hueco del 22-sep-2026: SetCurrentTenant corría DESPUÉS del route model
 * binding, así que `/iperc/{peligro}` resolvía el id sin el filtro del
 * TenantScope. El administrador de la empresa A borraba registros de la
 * empresa B con solo cambiar el número en la URL.
 *
 * OJO al escribir pruebas de esto: TenantContext es un singleton y dentro de
 * un mismo test SOBREVIVE entre peticiones. Una petición anterior deja el
 * tenant puesto y la siguiente sale filtrada «por casualidad»: así fue como
 * la primera sonda dio un 404 engañoso. Por eso se olvida la instancia antes
 * de cada petición.
 */
class AislamientoEntreEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->a = Tenant::create(['name' => 'Empresa A', 'nit' => '900000001-1']);
        $this->b = Tenant::create(['name' => 'Empresa B', 'nit' => '900000002-1']);
    }

    private function peticionNueva(User $user)
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($user);
    }

    /** Un registro de la empresa B, creado sin pasar por el contexto. */
    private function deB(string $clase, array $atributos)
    {
        $m = new $clase($atributos);
        $m->tenant_id = $this->b->id;
        $m->save();

        return $m;
    }

    /** Un peligro IPERC mínimo; el modelo calcula NP, NR y el nivel al guardar. */
    private function peligro(string $peligro): array
    {
        return [
            'proceso' => 'Operación', 'actividad' => 'Bodega', 'clasificacion' => 'Físico',
            'peligro' => $peligro, 'nd' => 6, 'ne' => 3, 'nc' => 60,
        ];
    }

    public function test_el_tenant_se_resuelve_antes_del_binding_en_todas_las_rutas(): void
    {
        // El kernel HTTP es quien le pasa los grupos y la prioridad al router, y
        // sin una petición previa todavía no se ha construido.
        $this->app->make(Kernel::class);
        $router = $this->app->make(Router::class);
        $revisadas = 0;

        foreach ($router->getRoutes() as $route) {
            if ($route->parameterNames() === []) {
                continue;
            }
            $mw = $router->gatherRouteMiddleware($route);
            $tenant = array_search(SetCurrentTenant::class, $mw, true);
            $binding = array_search(SubstituteBindings::class, $mw, true);
            if ($tenant === false || $binding === false) {
                continue;
            }

            $this->assertLessThan($binding, $tenant, "En {$route->uri()} el binding corre antes que el tenant.");
            $revisadas++;
        }

        // Si esto baja a cero, la prueba dejó de mirar algo.
        $this->assertGreaterThan(20, $revisadas);
    }

    public function test_el_admin_de_una_empresa_no_borra_registros_de_otra(): void
    {
        $admin = tap(User::factory()->create(['tenant_id' => $this->a->id]))->assignRole('cliente_admin');

        $item = $this->deB(IpercRow::class, $this->peligro('Ruido'));
        $empleado = $this->deB(Employee::class, [
            'nombres' => 'Ana', 'apellidos' => 'Perez', 'tipo_documento' => 'CC', 'numero_documento' => '123',
        ]);

        $this->peticionNueva($admin)->delete('/iperc/'.$item->id)->assertNotFound();
        $this->peticionNueva($admin)->put('/iperc/'.$item->id, ['peligro' => 'Pisado'])->assertNotFound();

        $this->assertTrue(IpercRow::withoutTenantScope()->whereKey($item->id)->where('peligro', 'Ruido')->exists());

        // Empleados no pasa por sst.manage sino por su propio permiso; basta
        // con que no lo borre, venga como venga el rechazo.
        $this->peticionNueva($admin)->delete('/empleados/'.$empleado->id);
        $this->assertTrue(Employee::withoutTenantScope()->whereKey($empleado->id)->exists());
    }

    public function test_el_consultor_trabajando_en_una_empresa_no_toca_otra(): void
    {
        $consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
        $item = $this->deB(IpercRow::class, $this->peligro('Caida'));

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($consultor)->withSession(['active_tenant_id' => $this->a->id])
            ->delete('/iperc/'.$item->id)->assertNotFound();

        $this->assertTrue(IpercRow::withoutTenantScope()->whereKey($item->id)->exists());
    }
}
