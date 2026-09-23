<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PesvCriterion;
use App\Models\PesvEvidence;
use App\Models\PesvPlan;
use App\Models\PesvStep;
use App\Models\PesvVehicle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PesvFeed;
use App\Support\TenantContext;
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\PesvCriteriaSeeder;
use Database\Seeders\PesvStepsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        $this->seed([PesvStepsSeeder::class, PesvCriteriaSeeder::class]);
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

    /** Responde preguntas de la lista de verificación por código («1.1»). */
    private function responder(PesvPlan $plan, array $codigos, string $estado): void
    {
        foreach ($codigos as $codigo) {
            $plan->criterios()->updateOrCreate(['pesv_criterion_id' => PesvCriterion::where('codigo', $codigo)->value('id')], ['estado' => $estado]);
        }
    }

    /**
     * El avance es la proporción de preguntas de la Tabla 16 en «cumple»
     * sobre las que exige el nivel, sin las «no aplica». El denominador es el
     * catálogo, no lo respondido: con una sola respuesta no puede dar 100 %.
     */
    public function test_el_avance_se_mide_sobre_las_preguntas_que_exige_el_nivel(): void
    {
        $tenant = $this->tenant();
        $this->activar($tenant);
        $plan = PesvPlan::create(['tenant_id' => $tenant->id, 'nivel' => 'basico']);

        // Básico: 40 de las 60 preguntas (sin las de los pasos 2, 11, 13, 18, 19 y 21).
        $this->responder($plan, ['1.1'], 'cumple');
        $plan->recalcular();
        $this->assertSame('2.50', (string) $plan->fresh()->avance, '1 de 40.');

        // Una pregunta que básico no exige no suma; «no aplica» sale del denominador.
        $this->responder($plan, ['2.1'], 'cumple');
        $this->responder($plan, ['3.1', '3.2'], 'no_aplica');
        $plan->recalcular();
        $this->assertSame('2.63', (string) $plan->fresh()->avance, '1 de 38.');

        // Avanzado: las 60; la 2.1 ya suma.
        $plan->update(['nivel' => 'avanzado']);
        $plan->recalcular();
        $this->assertSame('3.45', (string) $plan->fresh()->avance, '2 de 58.');
    }

    public function test_la_lista_de_verificacion_es_la_tabla_16(): void
    {
        $this->assertSame(60, PesvCriterion::count());
        $this->assertSame(24, PesvCriterion::distinct()->count('pesv_step_id'), 'Todo paso tiene al menos una pregunta.');
        $this->assertSame(40, PesvCriterion::all()->filter(fn ($c) => $c->aplicaA('basico'))->count());
        $this->assertSame(53, PesvCriterion::all()->filter(fn ($c) => $c->aplicaA('estandar'))->count());
        $this->assertSame(['avanzado'], PesvCriterion::where('codigo', '21.3')->value('niveles'));
        $this->assertSame(3, PesvCriterion::where('codigo', 'like', '19.%')->count(), 'La fuente repite 19.2; la tercera es la 19.3.');
    }

    public function test_el_estado_del_paso_sale_de_sus_preguntas(): void
    {
        $this->assertSame('pendiente', PesvPlan::estadoDelPaso(['no_verificado', 'no_verificado']));
        $this->assertSame('en_proceso', PesvPlan::estadoDelPaso(['cumple', 'no_verificado']));
        $this->assertSame('no_cumple', PesvPlan::estadoDelPaso(['cumple', 'no_cumple']));
        $this->assertSame('cumple', PesvPlan::estadoDelPaso(['cumple', 'no_aplica']));
        $this->assertSame('no_aplica', PesvPlan::estadoDelPaso(['no_aplica', 'no_aplica']));
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

        $c21 = PesvCriterion::where('codigo', '2.1')->value('id');
        $web()->post("/pesv/criterio/{$c21}", ['estado' => 'cumple'])->assertSessionHasNoErrors();
        $plan = PesvPlan::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('0.00', (string) $plan->avance, 'La 2.1 no se exige en básico.');

        $web()->put('/pesv', ['nivel' => 'estandar', 'misionalidad' => 2])->assertSessionHasNoErrors();
        $this->assertSame('1.89', (string) $plan->fresh()->avance, '1 de 53 en estándar.');

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

    public function test_responder_una_pregunta_actualiza_el_paso_y_el_avance(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();
        $web = fn () => $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
        $id = fn (string $codigo) => PesvCriterion::where('codigo', $codigo)->value('id');

        $web()->post('/pesv/criterio/'.$id('1.1'), ['estado' => 'cumple', 'observaciones' => 'Acta de designación firmada'])->assertSessionHasNoErrors();
        $plan = PesvPlan::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $paso1 = $plan->pasos()->where('pesv_step_id', PesvStep::where('numero', 1)->value('id'))->first();
        $this->assertSame('en_proceso', $paso1->estado, 'Falta la 1.2.');
        $respuesta = $plan->criterios()->first();
        $this->assertSame('Acta de designación firmada', $respuesta->observaciones);
        $this->assertSame($user->name, $respuesta->verificado_por);

        $web()->post('/pesv/criterio/'.$id('1.2'), ['estado' => 'cumple'])->assertSessionHasNoErrors();
        $this->assertSame('cumple', $paso1->fresh()->estado);
        $this->assertSame('5.00', (string) $plan->fresh()->avance, '2 de 40.');

        // El estado del paso ya no se elige a mano: el formulario del paso lo ignora.
        $web()->post('/pesv/paso/1', ['estado' => 'no_cumple', 'responsable' => 'Líder PESV'])->assertSessionHasNoErrors();
        $this->assertSame('cumple', $paso1->fresh()->estado);
        $this->assertSame('Líder PESV', $paso1->fresh()->responsable);

        $web()->post('/pesv/criterio/'.$id('1.2'), ['estado' => 'quizas'])->assertSessionHasErrors('estado');
    }

    /** Al llegar la lista de verificación, lo que ya estaba marcado por paso no se pierde. */
    public function test_la_migracion_trae_el_estado_que_tenian_los_pasos(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_01_000001_create_pesv_verificacion_tables.php'])->assertSuccessful();

        $tenant = $this->tenant();
        $this->activar($tenant);
        $plan = PesvPlan::create(['tenant_id' => $tenant->id, 'nivel' => 'basico']);
        $plan->pasos()->create(['pesv_step_id' => PesvStep::where('numero', 1)->value('id'), 'estado' => 'cumple']);
        $plan->pasos()->create(['pesv_step_id' => PesvStep::where('numero', 3)->value('id'), 'estado' => 'no_aplica']);
        $plan->pasos()->create(['pesv_step_id' => PesvStep::where('numero', 4)->value('id'), 'estado' => 'en_proceso']);

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_01_000001_create_pesv_verificacion_tables.php'])->assertSuccessful();

        $estados = $plan->criterios()->with('criterio')->get()->mapWithKeys(fn ($r) => [$r->criterio->codigo => $r->estado]);
        $this->assertSame(['1.1' => 'cumple', '1.2' => 'cumple', '3.1' => 'no_aplica', '3.2' => 'no_aplica'], $estados->sortKeys()->all());
        // «En proceso» no dice qué preguntas faltan: no se inventa ninguna respuesta.
        $this->assertSame('en_proceso', $plan->pasos()->where('pesv_step_id', PesvStep::where('numero', 4)->value('id'))->value('estado'));
        $this->assertSame('5.26', (string) $plan->fresh()->avance, '2 de 38 (40 menos las 2 no aplica).');
    }

    public function test_quien_solo_puede_ver_no_puede_responder(): void
    {
        $tenant = $this->tenant();
        $auditor = $this->consultor('auditor');
        $id = PesvCriterion::where('codigo', '1.1')->value('id');

        $this->actingAs($auditor)->withSession(['active_tenant_id' => $tenant->id])
            ->post("/pesv/criterio/{$id}", ['estado' => 'cumple'])->assertForbidden();
    }

    public function test_las_evidencias_se_suben_bajan_y_no_cruzan_empresas(): void
    {
        Storage::fake('local');
        $mia = $this->tenant('Mía');
        $otra = $this->tenant('Otra');
        $user = $this->consultor();
        $id = PesvCriterion::where('codigo', '3.1')->value('id');

        $this->actingAs($user)->withSession(['active_tenant_id' => $mia->id])
            ->post("/pesv/criterio/{$id}/evidencias", ['archivo' => UploadedFile::fake()->create('politica-firmada.pdf', 120)])
            ->assertSessionHasNoErrors();
        $ev = PesvEvidence::withoutTenantScope()->firstOrFail();
        $this->assertSame($mia->id, $ev->tenant_id);
        $this->assertSame('politica-firmada.pdf', $ev->nombre);
        Storage::disk('local')->assertExists($ev->archivo);

        $this->actingAs($user)->withSession(['active_tenant_id' => $mia->id])
            ->get("/pesv/evidencias/{$ev->id}")->assertOk()->assertDownload('politica-firmada.pdf');
        // Desde otra empresa, la evidencia no existe.
        $this->actingAs($user)->withSession(['active_tenant_id' => $otra->id])->get("/pesv/evidencias/{$ev->id}")->assertNotFound();
        $this->actingAs($user)->withSession(['active_tenant_id' => $otra->id])->delete("/pesv/evidencias/{$ev->id}")->assertNotFound();

        $this->actingAs($user)->withSession(['active_tenant_id' => $mia->id])
            ->post("/pesv/criterio/{$id}/evidencias", ['archivo' => UploadedFile::fake()->create('script.exe', 5)])
            ->assertSessionHasErrors('archivo');

        $this->actingAs($user)->withSession(['active_tenant_id' => $mia->id])->delete("/pesv/evidencias/{$ev->id}")->assertRedirect();
        $this->assertSame(0, PesvEvidence::withoutTenantScope()->count());
        Storage::disk('local')->assertMissing($ev->archivo);
    }

    public function test_la_pantalla_del_paso_trae_sus_preguntas(): void
    {
        $tenant = $this->tenant();
        $user = $this->consultor();

        $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id])->get('/pesv/paso/18')->assertOk()
            ->assertInertia(fn ($p) => $p->component('pesv/paso')
                ->has('criterios', 5)
                ->where('criterios.0.codigo', '18.1')
                ->where('criterios.0.aplica', false)   // básico: el paso 18 no se exige
                ->where('criterios.0.estado', 'no_verificado'));
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
