<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PesvDriverCheck;
use App\Models\PesvDriverTest;
use App\Models\PesvInfraction;
use App\Models\PesvVehicle;
use App\Models\PesvVehicleCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PesvFeed;
use App\Support\Pesv\RequisitosPaso11;
use App\Support\Pesv\SemaforoDocumentos;
use App\Support\TenantContext;
use Database\Seeders\PesvCriteriaSeeder;
use Database\Seeders\PesvStepsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PESV · paso 11: requisitos del operador y del vehículo (listas de CMK),
 * pruebas de idoneidad, comparendos y semáforo de documentos.
 */
class PesvPaso11Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $consultor;

    private Employee $conductor;

    private PesvVehicle $vehiculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-15 10:00');
        $this->seed([RolesAndPermissionsSeeder::class, PesvStepsSeeder::class, PesvCriteriaSeeder::class]);
        $this->empresa = Tenant::create(['name' => 'Transportes Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');

        app(TenantContext::class)->set($this->empresa);
        $this->conductor = Employee::create(['nombres' => 'Ana', 'apellidos' => 'Pérez', 'numero_documento' => '101', 'is_active' => true,
            'es_conductor' => true, 'licencia_vence' => '2026-07-20', 'examen_psicosensometrico_vence' => '2027-01-01']);
        $this->vehiculo = PesvVehicle::create(['placa' => 'ABC123', 'tipo' => 'camion', 'propiedad' => 'propio', 'soat_vence' => '2026-08-01']);
        app()->forgetInstance(TenantContext::class);
    }

    private function web(?Tenant $t = null)
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => ($t ?? $this->empresa)->id]);
    }

    /** Todas las claves del operador con un estado, salvo las indicadas. */
    private function respuestas(string $estado, array $otras = []): array
    {
        return collect(RequisitosPaso11::clavesOperador())->map(fn () => ['estado' => $estado])->merge($otras)->all();
    }

    public function test_los_requisitos_del_operador_dan_su_resultado(): void
    {
        $url = "/pesv/conductores/{$this->conductor->id}/requisitos";

        $this->web()->put($url, ['fecha' => '2026-07-10', 'respuestas' => ['cedula' => ['estado' => 'cumple']]])->assertSessionHasNoErrors();
        $this->assertSame('pendiente', PesvDriverCheck::withoutTenantScope()->first()->resultado, 'Faltan requisitos por verificar.');

        $this->web()->put($url, ['fecha' => '2026-07-10', 'placa_asignada' => 'ABC123',
            'respuestas' => $this->respuestas('cumple', ['simit' => ['estado' => 'no_cumple', 'obs' => 'Comparendo C29 pendiente'], 'inventada' => ['estado' => 'cumple']])])
            ->assertSessionHasNoErrors();
        $check = PesvDriverCheck::withoutTenantScope()->first();
        $this->assertSame('no_cumple', $check->resultado);
        $this->assertSame('Comparendo C29 pendiente', $check->respuestas['simit']['obs']);
        $this->assertArrayNotHasKey('inventada', $check->respuestas, 'Solo se guardan claves del catálogo.');
        $this->assertSame(24, count($check->respuestas));

        $this->web()->put($url, ['fecha' => '2026-07-10', 'respuestas' => $this->respuestas('cumple', ['mercancias_peligrosas' => ['estado' => 'no_aplica']])]);
        $this->assertSame('cumple', $check->fresh()->resultado, '«No aplica» no impide cumplir.');
        $this->assertSame($this->consultor->name, $check->fresh()->verificado_por);

        $this->web()->put($url, ['fecha' => '2026-12-01'])->assertSessionHasErrors('fecha');
    }

    public function test_los_requisitos_del_vehiculo(): void
    {
        $claves = collect(RequisitosPaso11::VEHICULO)->map(fn () => ['estado' => 'cumple'])->all();
        $this->web()->put("/pesv/vehiculos/{$this->vehiculo->id}/requisitos", ['fecha' => '2026-07-10', 'respuestas' => $claves])
            ->assertSessionHasNoErrors();
        $this->assertSame('cumple', PesvVehicleCheck::withoutTenantScope()->first()->resultado);
        $this->web()->get("/pesv/vehiculos/{$this->vehiculo->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('pesv/vehiculo')->where('requisitos.resultado', 'cumple'));
    }

    public function test_las_pruebas_de_idoneidad(): void
    {
        $url = "/pesv/conductores/{$this->conductor->id}/pruebas";

        $this->web()->post($url, ['tipo' => 'practica', 'fecha' => '2026-07-01', 'puntaje' => 60])->assertSessionHasNoErrors();
        $this->web()->post($url, ['tipo' => 'teorica', 'fecha' => '2026-07-01', 'puntaje' => 60.5])->assertSessionHasNoErrors();
        $resultados = PesvDriverTest::withoutTenantScope()->orderBy('id')->pluck('resultado')->all();
        $this->assertSame(['no_apto', 'apto'], $resultados, 'RE-SST-48: 60 % o menos es no apto.');

        $this->web()->post($url, ['tipo' => 'teorica', 'fecha' => '2026-07-01'])->assertSessionHasErrors('puntaje');
        $this->web()->post($url, ['tipo' => 'psicosensometrica', 'fecha' => '2026-07-01'])->assertSessionHasErrors('resultado');

        // La psicosensométrica apta actualiza el vencimiento en la ficha del conductor.
        $this->web()->post($url, ['tipo' => 'psicosensometrica', 'fecha' => '2026-07-05', 'resultado' => 'apto', 'vigente_hasta' => '2027-07-05'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-07-05', $this->conductor->fresh()->examen_psicosensometrico_vence->toDateString());
    }

    public function test_las_infracciones_se_cuentan_por_codigo_y_no_cruzan_empresas(): void
    {
        // El conductor de otra empresa se crea ANTES de cualquier petición: el
        // controlador queda cacheado en la ruta con el TenantContext de la
        // primera, y cambiarlo después lo contaminaría (artefacto de pruebas).
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        app(TenantContext::class)->set($otra);
        $ajeno = Employee::create(['nombres' => 'X', 'apellidos' => 'Y', 'numero_documento' => '999', 'is_active' => true, 'es_conductor' => true]);
        app()->forgetInstance(TenantContext::class);

        $this->web()->post('/pesv/infracciones', ['employee_id' => $this->conductor->id, 'fecha' => '2026-05-10', 'codigo' => ' c29 ',
            'descripcion' => 'Exceso de velocidad', 'valor' => 604100, 'estado' => 'pendiente'])->assertSessionHasNoErrors();
        $this->web()->post('/pesv/infracciones', ['employee_id' => $this->conductor->id, 'fecha' => '2026-06-02', 'codigo' => 'C29',
            'estado' => 'pagada'])->assertSessionHasNoErrors();
        $this->assertSame(['C29', 'C29'], PesvInfraction::withoutTenantScope()->pluck('codigo')->all(), 'El código se normaliza.');

        $this->web()->get('/pesv/infracciones?anio=2026')->assertOk()->assertInertia(fn ($p) => $p
            ->where('stats.total', 2)->where('stats.abiertas', 1)->where('porCodigo.0.codigo', 'C29')->where('porCodigo.0.cantidad', 2));

        $this->web()->post('/pesv/infracciones', ['employee_id' => $this->conductor->id, 'fecha' => '2026-06-02', 'codigo' => '', 'estado' => 'pagada'])
            ->assertSessionHasErrors('codigo');

        // Otra empresa: su conductor no se puede usar aquí y la ficha ajena no se ve.
        $this->web()->post('/pesv/infracciones', ['employee_id' => $ajeno->id, 'fecha' => '2026-06-02', 'codigo' => 'C02', 'estado' => 'pendiente'])
            ->assertSessionHasErrors('employee_id');
        $this->web()->get("/pesv/conductores/{$ajeno->id}")->assertNotFound();
    }

    public function test_el_semaforo_sigue_la_regla_de_cmk(): void
    {
        $hoy = Carbon::parse('2026-07-15');
        $this->assertSame('E', SemaforoDocumentos::estado(Carbon::parse('2026-07-10'), $hoy), 'Vencido.');
        $this->assertSame('E', SemaforoDocumentos::estado(Carbon::parse('2026-07-22'), $hoy), '7 días: menos de 8 ya no cumple.');
        $this->assertSame('P', SemaforoDocumentos::estado(Carbon::parse('2026-07-23'), $hoy), '8 días: por vencer.');
        $this->assertSame('P', SemaforoDocumentos::estado(Carbon::parse('2026-08-14'), $hoy), '30 días.');
        $this->assertSame('V', SemaforoDocumentos::estado(Carbon::parse('2026-08-15'), $hoy), '31 días.');
        $this->assertSame('sin_dato', SemaforoDocumentos::estado(null, $hoy));

        // Conductor: licencia a 5 días (E) y psico vigente (V). Vehículo: SOAT a 17 días (P), el resto sin fecha.
        $this->web()->get('/pesv/documentos')->assertOk()->assertInertia(fn ($p) => $p
            ->where('resumen.E', 1)->where('resumen.P', 1)->where('resumen.V', 1)->where('resumen.sin_dato', 3)
            ->where('resumen.cumplimiento', 66.7));
    }

    public function test_el_paso_11_muestra_lo_registrado(): void
    {
        app(TenantContext::class)->set($this->empresa);
        PesvDriverCheck::create(['employee_id' => $this->conductor->id, 'fecha' => '2026-07-10', 'respuestas' => [], 'resultado' => 'cumple']);
        PesvInfraction::create(['employee_id' => $this->conductor->id, 'fecha' => '2026-07-01', 'codigo' => 'C02', 'estado' => 'pendiente']);

        $insumos = collect((new PesvFeed($this->empresa))->paraPaso(11))->keyBy('etiqueta');
        $this->assertSame('ok', $insumos['Requisitos del operador']['estado']);
        $this->assertSame('falta', $insumos['Pruebas de idoneidad']['estado']);
        $this->assertSame('parcial', $insumos['Infracciones de tránsito sin cerrar']['estado']);
        $this->assertSame('parcial', $insumos['Semáforo de documentos']['estado']);
    }
}
