<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PesvPlan;
use App\Models\PesvStep;
use App\Models\PesvVehicle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PesvFeed;
use App\Support\TenantContext;
use Database\Seeders\PesvStepsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PESV (Res. 40595 de 2022).
 *
 * Lo que se protege aquí no es el CRUD, sino las decisiones que no se ven:
 * que "no aplica" salga del denominador del avance, que la caracterización de
 * una empresa no se filtre a otra, que la misma placa pueda existir en dos
 * clientes distintos, y que el panel de insumos diga la verdad sobre lo que ya
 * hay cargado.
 */
class PesvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PesvStepsSeeder::class);
    }

    private function permisos(): void
    {
        foreach (['pesv.view', 'pesv.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        Role::findOrCreate('consultor_admin', 'web')->givePermissionTo(['pesv.view', 'pesv.manage']);
        // Un rol que solo mira: sirve para comprobar que no puede escribir.
        Role::findOrCreate('auditor', 'web')->givePermissionTo(['pesv.view']);
    }

    private function tenant(string $nombre = 'Empresa Demo'): Tenant
    {
        return Tenant::create(['name' => $nombre, 'nit' => '900'.random_int(100000, 999999).'-1']);
    }

    private function consultor(string $rol = 'consultor_admin'): User
    {
        $this->permisos();

        return tap(User::factory()->create())->assignRole($rol);
    }

    /** Deja el tenant activo, como hace el botón «Trabajar» de Clientes. */
    private function activar(Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant);
    }

    public function test_el_catalogo_tiene_los_24_pasos_en_sus_4_fases(): void
    {
        $this->assertSame(24, PesvStep::count());

        $porFase = PesvStep::selectRaw('fase, count(*) as total')->groupBy('fase')->pluck('total', 'fase');

        $this->assertSame(8, (int) $porFase[1], 'Fase 1 Planificación va del paso 1 al 8.');
        $this->assertSame(11, (int) $porFase[2], 'Fase 2 Implementación va del 9 al 19.');
        $this->assertSame(3, (int) $porFase[3], 'Fase 3 Seguimiento va del 20 al 22.');
        $this->assertSame(2, (int) $porFase[4], 'Fase 4 Mejora continua va del 23 al 24.');
    }

    public function test_los_pasos_marcados_no_aplica_no_castigan_el_avance(): void
    {
        $tenant = $this->tenant();
        $this->activar($tenant);

        $plan = PesvPlan::create(['tenant_id' => $tenant->id, 'nivel' => 'basico']);

        // 2 pasos cumplen, 2 no aplican, el resto queda pendiente.
        foreach ([1, 2] as $numero) {
            $plan->pasos()->create([
                'pesv_step_id' => PesvStep::where('numero', $numero)->value('id'),
                'estado' => 'cumple',
            ]);
        }

        foreach ([3, 4] as $numero) {
            $plan->pasos()->create([
                'pesv_step_id' => PesvStep::where('numero', $numero)->value('id'),
                'estado' => 'no_aplica',
            ]);
        }

        $plan->recalcular();

        // 2 de 2 aplicables => 100%, no 2 de 4 (50%).
        $this->assertSame('100.00', (string) $plan->fresh()->avance);
    }

    public function test_guardar_un_paso_recalcula_el_avance(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();

        $respuesta = $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/pesv/paso/1', ['estado' => 'cumple']);

        $respuesta->assertRedirect();

        $plan = PesvPlan::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($plan, 'Entrar al PESV crea el plan de la empresa.');
        $this->assertSame('cumple', $plan->pasos()->first()->estado);
        $this->assertGreaterThan(0, (float) $plan->avance);
    }

    public function test_quien_solo_puede_ver_no_puede_guardar_un_paso(): void
    {
        $tenant = $this->tenant();
        $auditor = $this->consultor('auditor');

        $this->actingAs($auditor)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/pesv/paso/1', ['estado' => 'cumple'])
            ->assertForbidden();
    }

    public function test_la_caracterizacion_de_una_empresa_no_se_ve_desde_otra(): void
    {
        $unaEmpresa = $this->tenant('Transportes A');
        $otraEmpresa = $this->tenant('Transportes B');

        $this->activar($unaEmpresa);
        PesvVehicle::create(['placa' => 'ABC123', 'tipo' => 'camion', 'propiedad' => 'propio']);

        $this->assertSame(1, PesvVehicle::count());

        $this->activar($otraEmpresa);
        $this->assertSame(0, PesvVehicle::count(), 'La flota de un cliente no puede aparecer en otro.');
    }

    public function test_la_misma_placa_puede_existir_en_dos_empresas_distintas(): void
    {
        // Un vehículo tercerizado puede prestar servicio a dos clientes de CMK
        // a la vez, así que la placa es única por empresa y no globalmente.
        $unaEmpresa = $this->tenant('Transportes A');
        $otraEmpresa = $this->tenant('Transportes B');
        $user = $this->consultor();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $unaEmpresa->id])
            ->post('/pesv/vehiculos', ['placa' => 'XYZ789', 'tipo' => 'camioneta', 'propiedad' => 'contratista'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $otraEmpresa->id])
            ->post('/pesv/vehiculos', ['placa' => 'XYZ789', 'tipo' => 'camioneta', 'propiedad' => 'contratista'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PesvVehicle::withoutTenantScope()->where('placa', 'XYZ789')->count());
    }

    public function test_la_placa_no_se_puede_repetir_dentro_de_la_misma_empresa(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/pesv/vehiculos', ['placa' => 'DUP001', 'tipo' => 'bus', 'propiedad' => 'propio']);

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/pesv/vehiculos', ['placa' => 'DUP001', 'tipo' => 'bus', 'propiedad' => 'propio'])
            ->assertSessionHasErrors('placa');
    }

    public function test_la_ficha_de_conductor_va_sobre_el_empleado_ya_cargado(): void
    {
        $tenant = $this->tenant();
        $this->activar($tenant);

        $empleado = Employee::create([
            'nombres' => 'Ana', 'apellidos' => 'Gómez',
            'tipo_documento' => 'CC', 'numero_documento' => '52123456',
            'cargo' => 'Mensajera', 'is_active' => true,
        ]);

        $user = $this->consultor();

        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->put("/pesv/colaboradores/{$empleado->id}", [
                'es_conductor' => true,
                'licencia_numero' => 'L-9988',
                'licencia_categoria' => 'B1',
            ])
            ->assertSessionHasNoErrors();

        $empleado->refresh();

        $this->assertTrue($empleado->es_conductor);
        $this->assertSame('L-9988', $empleado->licencia_numero);
        // Lo importante: NO se creó una persona nueva, se enriqueció la que había.
        $this->assertSame(1, Employee::count());
    }

    public function test_el_panel_de_insumos_distingue_lo_que_hay_de_lo_que_falta(): void
    {
        $tenant = $this->tenant();
        $this->activar($tenant);

        $feed = new PesvFeed($tenant);

        // Paso 5 (Diagnóstico) sin nada cargado: todo debe salir como "falta".
        $vacio = collect($feed->paraPaso(5));
        $this->assertTrue($vacio->every(fn ($i) => $i['estado'] === 'falta'));

        Employee::create([
            'nombres' => 'Luis', 'apellidos' => 'Pérez',
            'tipo_documento' => 'CC', 'numero_documento' => '79111222',
            'is_active' => true,
        ]);
        PesvVehicle::create(['placa' => 'FEED01', 'tipo' => 'automovil', 'propiedad' => 'propio']);

        $conDatos = collect($feed->paraPaso(5))->keyBy('etiqueta');

        // Hay empleados pero ninguno marcado como conductor: ni ok ni falta.
        $this->assertSame('parcial', $conDatos['Colaboradores y conductores']['estado']);
        $this->assertSame('ok', $conDatos['Vehículos']['estado']);
        $this->assertSame('falta', $conDatos['Rutas']['estado']);
    }

    public function test_el_nivel_sugerido_no_se_impone_al_consultor(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();

        // Se guarda "avanzado" aunque no haya un solo vehículo cargado: la
        // norma fija el nivel por la misionalidad, que el código no conoce.
        $this->actingAs($user)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->put('/pesv', ['nivel' => 'avanzado'])
            ->assertSessionHasNoErrors();

        $plan = PesvPlan::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

        $this->assertSame('avanzado', $plan->nivel);
    }
}
