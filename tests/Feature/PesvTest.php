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
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\PesvStepsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
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

    private function marcar(PesvPlan $plan, array $numeros, string $estado): void
    {
        foreach ($numeros as $numero) {
            $plan->pasos()->updateOrCreate(['pesv_step_id' => PesvStep::where('numero', $numero)->value('id')], ['estado' => $estado]);
        }
    }

    /**
     * El bug que había: se dividía por las filas GUARDADAS, así que un plan
     * nuevo con un solo paso en «cumple» marcaba 100 %. El denominador son los
     * pasos que la norma exige al nivel del plan, menos los «no aplica».
     */
    public function test_el_avance_se_mide_sobre_los_pasos_que_exige_el_nivel(): void
    {
        $tenant = $this->tenant();
        $this->activar($tenant);
        $plan = PesvPlan::create(['tenant_id' => $tenant->id, 'nivel' => 'basico']);

        // Básico exige 18 pasos (sin 2, 11, 13, 18, 19 ni 21).
        $this->marcar($plan, [1], 'cumple');
        $plan->recalcular();
        $this->assertSame('5.56', (string) $plan->fresh()->avance, '1 de 18, no 1 de 1.');

        // Cumplir un paso que básico no exige no suma; «no aplica» sí sale del denominador.
        $this->marcar($plan, [2], 'cumple');
        $this->marcar($plan, [3, 4], 'no_aplica');
        $plan->recalcular();
        $this->assertSame('6.25', (string) $plan->fresh()->avance, '1 de 16.');

        // En avanzado cuentan los 24: el paso 2 ya suma.
        $plan->update(['nivel' => 'avanzado']);
        $plan->recalcular();
        $this->assertSame('9.09', (string) $plan->fresh()->avance, '2 de 22.');
    }

    public function test_los_pasos_que_aplican_por_nivel_son_los_del_anexo(): void
    {
        $noEnBasico = PesvStep::all()->reject(fn ($s) => $s->aplicaA('basico'))->pluck('numero')->sort()->values()->all();
        $noEnEstandar = PesvStep::all()->reject(fn ($s) => $s->aplicaA('estandar'))->pluck('numero')->sort()->values()->all();

        $this->assertSame([2, 11, 13, 18, 19, 21], $noEnBasico);
        $this->assertSame([11, 21], $noEnEstandar);
        $this->assertSame(24, PesvStep::all()->filter(fn ($s) => $s->aplicaA('avanzado'))->count());
    }

    /** Tabla 1 del anexo: misionalidad + flota o conductores, gana el nivel más alto. */
    public function test_el_nivel_por_norma_sigue_la_tabla_1(): void
    {
        $casos = [
            // [misionalidad, vehículos, conductores, nivel esperado]
            [2, 10, 1, null],        // por debajo del umbral: no obligada
            [2, 11, 0, 'basico'],
            [2, 49, 49, 'basico'],
            [2, 50, 0, 'estandar'],
            [2, 100, 0, 'estandar'],
            [2, 0, 101, 'avanzado'],
            [1, 19, 0, 'basico'],
            [1, 20, 0, 'estandar'],
            [1, 0, 2, 'basico'],
            [1, 51, 0, 'avanzado'],
            [1, 12, 60, 'avanzado'],  // flota básico, conductores avanzado → el más alto
        ];
        foreach ($casos as [$m, $v, $c, $esperado]) {
            $this->assertSame($esperado, PesvPlan::nivelPorNorma($m, $v, $c), "misionalidad {$m}, {$v} vehículos, {$c} conductores");
        }
    }

    public function test_cambiar_el_nivel_o_la_misionalidad_recalcula_y_sugiere(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();
        $web = fn () => $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

        $web()->post('/pesv/paso/2', ['estado' => 'cumple'])->assertRedirect();
        $plan = PesvPlan::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('0.00', (string) $plan->avance, 'Paso 2 no se exige en básico.');

        $web()->put('/pesv', ['nivel' => 'estandar', 'misionalidad' => 2])->assertSessionHasNoErrors();
        $this->assertSame('4.55', (string) $plan->fresh()->avance, '1 de 22 en estándar.');

        app()->forgetInstance(TenantContext::class);
        $this->activar($tenant);
        foreach (range(1, 12) as $i) {
            PesvVehicle::create(['placa' => "AAA{$i}", 'tipo' => 'automovil', 'propiedad' => 'propio']);
        }
        app()->forgetInstance(TenantContext::class);

        $web()->get('/pesv')->assertInertia(fn ($p) => $p->where('nivelSugerido.nivel', 'basico')->where('nivelSugerido.flota', 12));
        $web()->put('/pesv', ['nivel' => 'estandar', 'misionalidad' => 9])->assertSessionHasErrors('misionalidad');
    }

    public function test_no_se_enlazan_empleados_ni_vehiculos_de_otra_empresa(): void
    {
        $mia = $this->tenant('Mía');
        $ajena = $this->tenant('Ajena');
        $user = $this->consultor();

        $this->activar($ajena);
        $empleadoAjeno = Employee::create(['nombres' => 'Otro', 'apellidos' => 'X', 'numero_documento' => '999', 'is_active' => true]);
        $vehiculoAjeno = PesvVehicle::create(['placa' => 'ZZZ999', 'tipo' => 'automovil', 'propiedad' => 'propio']);
        app()->forgetInstance(TenantContext::class);

        $web = fn () => $this->actingAs($user)->withSession(['active_tenant_id' => $mia->id]);

        $web()->post('/pesv/comite', ['employee_id' => $empleadoAjeno->id, 'nombre' => 'Otro X', 'rol_comite' => 'integrante'])
            ->assertSessionHasErrors('employee_id');
        $web()->post('/pesv/siniestros', ['fecha' => '2026-03-01', 'tipo' => 'choque', 'gravedad' => 'solo_danos',
            'pesv_vehicle_id' => $vehiculoAjeno->id, 'employee_id' => $empleadoAjeno->id])
            ->assertSessionHasErrors(['pesv_vehicle_id', 'employee_id']);
    }

    public function test_el_comite_se_arma_con_empleados_y_se_edita(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();
        $this->activar($tenant);
        $ana = Employee::create(['nombres' => 'Ana', 'apellidos' => 'Pérez', 'numero_documento' => '101', 'cargo' => 'Gerente', 'is_active' => true]);
        app()->forgetInstance(TenantContext::class);
        $web = fn () => $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

        $web()->post('/pesv/comite', ['employee_id' => $ana->id, 'nombre' => 'Ana Pérez', 'cargo' => 'Gerente', 'rol_comite' => 'integrante'])
            ->assertSessionHasNoErrors();
        $plan = PesvPlan::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $miembro = $plan->comite()->first();
        $this->assertSame($ana->id, $miembro->employee_id);

        $web()->put("/pesv/comite/{$miembro->id}", ['employee_id' => $ana->id, 'nombre' => 'Ana Pérez', 'rol_comite' => 'presidente', 'es_representante_direccion' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('presidente', $miembro->fresh()->rol_comite);
        $this->assertTrue($miembro->fresh()->es_representante_direccion);

        // El integrante de otra empresa no se puede editar por id.
        $otra = $this->tenant('Otra');
        $this->actingAs($user)->withSession(['active_tenant_id' => $otra->id])
            ->put("/pesv/comite/{$miembro->id}", ['nombre' => 'X', 'rol_comite' => 'integrante'])->assertNotFound();
    }

    public function test_los_indicadores_minimos_del_pesv_solo_los_ve_quien_tiene_el_modulo(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, IndicatorsSeeder::class]);
        $user = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
        $conPesv = $this->tenant('Con PESV');
        $sinPesv = $this->tenant('Sin PESV');
        $sinPesv->update(['modulos' => ['indicadores']]);

        $codigos = fn (Tenant $t) => collect($this->actingAs($user)->withSession(['active_tenant_id' => $t->id])
            ->get('/indicadores')->assertOk()->viewData('page')['props']['indicators'])->pluck('codigo');

        $this->assertContains('PESV-TSV-FAT', $codigos($conPesv));
        $this->assertSame(14, $codigos($conPesv)->filter(fn ($c) => str_starts_with($c, 'PESV-'))->count());
        // Sin forgetInstance: el controlador queda cacheado en la ruta con SU
        // TenantContext; el middleware lo actualiza en cada petición.
        $this->assertNotContains('PESV-TSV-FAT', $codigos($sinPesv));
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
