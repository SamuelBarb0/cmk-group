<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EquipmentCalibration;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\MeasuringEquipment;
use App\Models\PesvDriverTest;
use App\Models\PpeAssignment;
use App\Models\PpeItem;
use App\Models\ResourceReading;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lo que se veía en pantalla y no se podía corregir ni quitar: cada dato de
 * un cliente tiene que poder crearse, editarse y borrarse desde la aplicación.
 */
class CrudCompletoTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, SigCatalogSeeder::class, IndicatorsSeeder::class]);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function como(?Tenant $tenant = null)
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => ($tenant ?? $this->empresa)->id]);
    }

    // --- Indicadores ------------------------------------------------------------

    private function indicadorPropio(array $extra = []): array
    {
        return array_merge([
            'codigo' => 'IND-01', 'nombre' => 'Rotación de personal', 'categoria' => 'Proceso',
            'numerador_label' => 'Retiros', 'denominador_label' => 'Trabajadores', 'constante' => 100,
            'unidad' => '%', 'sentido' => 'desc', 'meta' => 5,
        ], $extra);
    }

    public function test_un_indicador_propio_se_edita(): void
    {
        $this->como()->post('/indicadores', $this->indicadorPropio())->assertSessionHasNoErrors()->assertRedirect();
        $ind = Indicator::where('codigo', 'IND-01')->firstOrFail();

        $this->como()->put("/indicadores/{$ind->id}", $this->indicadorPropio(['nombre' => 'Rotación voluntaria', 'meta' => 3]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $ind->refresh();
        $this->assertSame('Rotación voluntaria', $ind->nombre);
        $this->assertEquals(3, $ind->meta);
    }

    public function test_un_indicador_legal_o_de_otra_empresa_no_se_edita(): void
    {
        $legal = Indicator::whereNull('tenant_id')->firstOrFail();
        $this->como()->put("/indicadores/{$legal->id}", $this->indicadorPropio(['nombre' => 'Cambiado']))->assertSessionHasErrors('indicator');
        $this->assertNotSame('Cambiado', $legal->fresh()->nombre);

        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $this->como($otra)->post('/indicadores', $this->indicadorPropio(['codigo' => 'OTRA-1']))->assertSessionHasNoErrors()->assertRedirect();
        $ajeno = Indicator::where('codigo', 'OTRA-1')->firstOrFail();

        $this->como()->put("/indicadores/{$ajeno->id}", $this->indicadorPropio(['nombre' => 'Cambiado']))->assertSessionHasErrors('indicator');
        $this->assertSame('Rotación de personal', $ajeno->fresh()->nombre);
    }

    public function test_un_mes_vaciado_borra_la_lectura_y_un_cero_escrito_se_conserva(): void
    {
        $this->como()->post('/indicadores', $this->indicadorPropio())->assertSessionHasNoErrors()->assertRedirect();
        $ind = Indicator::where('codigo', 'IND-01')->firstOrFail();
        $guardar = fn (array $lecturas) => $this->como()->post('/indicadores/lecturas', [
            'indicator_id' => $ind->id, 'anio' => 2026, 'lecturas' => $lecturas,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $guardar([['mes' => 1, 'numerador' => 2, 'denominador' => 40], ['mes' => 2, 'numerador' => 0, 'denominador' => 40]]);
        $lecturas = fn () => IndicatorReading::withoutTenantScope()->where('indicator_id', $ind->id)->orderBy('mes')->get();
        $this->assertSame([1, 2], $lecturas()->pluck('mes')->all());

        // Enero se vacía (las dos casillas) → se borra. Febrero: 0 escrito → sigue.
        $guardar([['mes' => 1, 'numerador' => null, 'denominador' => null], ['mes' => 2, 'numerador' => 0, 'denominador' => 40]]);
        $this->assertSame([2], $lecturas()->pluck('mes')->all());
        $this->assertEquals(0, $lecturas()->first()->numerador);
    }

    // --- Calibraciones ----------------------------------------------------------

    private function calibracion(array $extra = []): array
    {
        return array_merge([
            'fecha' => now()->subMonth()->toDateString(), 'tipo' => 'calibracion', 'realizado_por' => 'Metrolab',
            'acreditado_onac' => true, 'resultado' => 'conforme',
        ], $extra);
    }

    private function equipo(string $codigo = 'EQ-001'): MeasuringEquipment
    {
        $this->como()->post('/equipos-medicion', [
            'codigo' => $codigo, 'nombre' => 'Sonómetro', 'magnitud' => 'Nivel sonoro', 'unidad' => 'dB',
            'control' => 'calibracion', 'frecuencia_meses' => 12, 'estado' => 'en_uso',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return MeasuringEquipment::withoutTenantScope()->where('codigo', $codigo)->firstOrFail();
    }

    /** @return array{0: MeasuringEquipment, 1: EquipmentCalibration} */
    private function equipoCalibrado(): array
    {
        Storage::fake('local');
        $equipo = $this->equipo();
        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones", $this->calibracion([
            'archivo' => UploadedFile::fake()->create('cert-viejo.pdf', 20, 'application/pdf'),
        ]))->assertSessionHasNoErrors()->assertRedirect();

        return [$equipo, EquipmentCalibration::withoutTenantScope()->where('measuring_equipment_id', $equipo->id)->firstOrFail()];
    }

    public function test_una_calibracion_se_corrige_y_conserva_su_certificado(): void
    {
        [$equipo, $cal] = $this->equipoCalibrado();
        $archivo = $cal->archivo;

        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones/{$cal->id}", $this->calibracion([
            'realizado_por' => 'Metrolab S.A.S.', 'certificado' => 'CC-2026-114',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $cal->refresh();
        $this->assertSame('Metrolab S.A.S.', $cal->realizado_por);
        $this->assertSame('CC-2026-114', $cal->certificado);
        $this->assertSame($archivo, $cal->archivo, 'Sin archivo nuevo, el certificado se conserva.');
        Storage::disk('local')->assertExists($archivo);
    }

    public function test_un_certificado_nuevo_reemplaza_y_borra_el_anterior(): void
    {
        [$equipo, $cal] = $this->equipoCalibrado();
        $viejo = $cal->archivo;

        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones/{$cal->id}", $this->calibracion([
            'archivo' => UploadedFile::fake()->create('cert-nuevo.pdf', 20, 'application/pdf'),
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $cal->refresh();
        $this->assertSame('cert-nuevo.pdf', $cal->archivo_nombre);
        Storage::disk('local')->assertMissing($viejo);
        Storage::disk('local')->assertExists($cal->archivo);
    }

    public function test_corregir_la_ultima_calibracion_a_no_conforme_saca_el_equipo_de_uso(): void
    {
        [$equipo, $cal] = $this->equipoCalibrado();

        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones/{$cal->id}", $this->calibracion([
            'resultado' => 'no_conforme', 'impacto_mediciones' => 'Se repiten las mediciones del último mes.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('fuera_servicio', $equipo->fresh()->estado);
    }

    public function test_corregir_una_calibracion_vieja_no_cambia_el_estado(): void
    {
        [$equipo, $vieja] = $this->equipoCalibrado();
        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones", $this->calibracion(['fecha' => now()->subDay()->toDateString()]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones/{$vieja->id}", $this->calibracion([
            'resultado' => 'no_conforme', 'impacto_mediciones' => 'Histórico.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('en_uso', $equipo->fresh()->estado);
    }

    // --- Matriz de EPP por cargo --------------------------------------------------

    public function test_una_asignacion_de_la_matriz_de_epp_se_edita(): void
    {
        $this->como()->post('/epp/items', ['nombre' => 'Casco', 'categoria' => 'cabeza', 'activo' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->como()->post('/epp/items', ['nombre' => 'Guantes', 'categoria' => 'manos', 'activo' => true])->assertSessionHasNoErrors()->assertRedirect();
        $casco = PpeItem::withoutTenantScope()->where('nombre', 'Casco')->firstOrFail();
        $guantes = PpeItem::withoutTenantScope()->where('nombre', 'Guantes')->firstOrFail();

        $asignar = fn (PpeItem $i) => ['ppe_item_id' => $i->id, 'cargo' => 'Soldador', 'area' => 'Taller', 'requerimiento' => 'requerido'];
        $this->como()->post('/epp/matriz', $asignar($casco))->assertSessionHasNoErrors()->assertRedirect();
        $this->como()->post('/epp/matriz', $asignar($guantes))->assertSessionHasNoErrors()->assertRedirect();
        $asignacion = PpeAssignment::withoutTenantScope()->where('ppe_item_id', $casco->id)->firstOrFail();

        // Guardar sin cambiar cargo ni elemento: la propia fila no es un duplicado.
        $this->como()->put("/epp/matriz/{$asignacion->id}", ['area' => 'Planta', 'requerimiento' => 'segun_necesidad', 'observaciones' => 'Solo en patio'] + $asignar($casco))
            ->assertSessionHasNoErrors()->assertRedirect();
        $asignacion->refresh();
        $this->assertSame('Planta', $asignacion->area);
        $this->assertSame('segun_necesidad', $asignacion->requerimiento);

        // Pero convertirla en otra que ya existe (guantes al soldador) sí se rechaza.
        $this->como()->put("/epp/matriz/{$asignacion->id}", $asignar($guantes))->assertSessionHasErrors('cargo');
    }

    // --- Pruebas del conductor (PESV) ---------------------------------------------

    private function conductor(): Employee
    {
        $ctx = app(TenantContext::class);
        $ctx->set($this->empresa);
        $e = Employee::create(['nombres' => 'Luis', 'apellidos' => 'Gómez', 'numero_documento' => '7788', 'es_conductor' => true, 'is_active' => true]);
        $ctx->set(null);

        return $e;
    }

    private function prueba(array $extra = []): array
    {
        return array_merge(['tipo' => 'teorica', 'fecha' => now()->subDays(5)->toDateString(), 'puntaje' => 90, 'evaluador' => 'CEA'], $extra);
    }

    public function test_corregir_el_puntaje_de_una_prueba_recalcula_si_es_apto(): void
    {
        $e = $this->conductor();
        $this->como()->post("/pesv/conductores/{$e->id}/pruebas", $this->prueba())->assertSessionHasNoErrors()->assertRedirect();
        $prueba = PesvDriverTest::withoutTenantScope()->where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('apto', $prueba->resultado);

        $this->como()->put("/pesv/pruebas/{$prueba->id}", $this->prueba(['puntaje' => 40]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('no_apto', $prueba->fresh()->resultado);
        $this->assertEquals(40, $prueba->fresh()->puntaje);
    }

    public function test_corregir_la_psicosensometrica_mas_reciente_mueve_el_vencimiento_y_una_vieja_no(): void
    {
        $e = $this->conductor();
        $psico = fn (string $fecha, string $vence) => $this->prueba([
            'tipo' => 'psicosensometrica', 'puntaje' => null, 'resultado' => 'apto', 'fecha' => $fecha, 'vigente_hasta' => $vence,
        ]);
        $this->como()->post("/pesv/conductores/{$e->id}/pruebas", $psico(now()->subYear()->toDateString(), now()->addDays(10)->toDateString()));
        $this->como()->post("/pesv/conductores/{$e->id}/pruebas", $psico(now()->subDays(3)->toDateString(), now()->addYear()->toDateString()));
        [$vieja, $nueva] = PesvDriverTest::withoutTenantScope()->where('employee_id', $e->id)->orderBy('fecha')->get()->all();

        // La vieja: su vigencia NO pisa la de la prueba vigente.
        $this->como()->put("/pesv/pruebas/{$vieja->id}", $psico($vieja->fecha->toDateString(), now()->addDays(20)->toDateString()))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(now()->addYear()->toDateString(), $e->fresh()->examen_psicosensometrico_vence->toDateString());

        // La más reciente: su vigencia corregida pasa a la ficha.
        $this->como()->put("/pesv/pruebas/{$nueva->id}", $psico($nueva->fecha->toDateString(), now()->addMonths(18)->toDateString()))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(now()->addMonths(18)->toDateString(), $e->fresh()->examen_psicosensometrico_vence->toDateString());
    }

    // --- Consumos ambientales -------------------------------------------------------

    public function test_una_lectura_de_consumo_se_corrige_sin_fusionarse_con_otra(): void
    {
        $mes = fn (int $atras) => now()->subMonths($atras)->format('Y-m');
        $lectura = fn (string $m, int $cantidad) => ['mes' => $m, 'recurso' => 'agua', 'cantidad' => $cantidad, 'trabajadores' => 20];
        $this->como()->post('/ambiental/lecturas', $lectura($mes(2), 30))->assertSessionHasNoErrors()->assertRedirect();
        $this->como()->post('/ambiental/lecturas', $lectura($mes(1), 35))->assertSessionHasNoErrors()->assertRedirect();
        $leer = fn () => ResourceReading::withoutTenantScope()->orderBy('periodo')->get();
        [$feb, $mar] = $leer()->all();

        // Corregir la cantidad.
        $this->como()->put("/ambiental/lecturas/{$feb->id}", $lectura($mes(2), 32))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(32, $feb->fresh()->cantidad);

        // Moverla a un mes que ya tiene OTRA lectura: se rechaza y nada cambia.
        $this->como()->put("/ambiental/lecturas/{$feb->id}", $lectura($mes(1), 99))->assertSessionHasErrors('mes');
        $this->assertSame(2, $leer()->count());
        $this->assertEquals(35, $mar->fresh()->cantidad);

        // Moverla a un mes libre sí.
        $this->como()->put("/ambiental/lecturas/{$feb->id}", $lectura($mes(3), 32))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($mes(3), $feb->fresh()->periodo->format('Y-m'));
    }

    // --- Documentos de la empresa ---------------------------------------------------

    public function test_un_documento_se_renombra_sin_tocar_el_archivo_y_no_se_toca_el_de_otra_empresa(): void
    {
        Storage::fake('local');
        $this->como()->post('/documentos', ['archivo' => UploadedFile::fake()->create('scan_0042.pdf', 30, 'application/pdf')])
            ->assertSessionHasNoErrors()->assertRedirect();
        $doc = TenantDocument::withoutTenantScope()->firstOrFail();
        $path = $doc->path;

        $this->como()->put("/documentos/{$doc->id}", ['nombre' => 'Acta COPASST marzo', 'categoria' => 'Actas'])->assertSessionHasNoErrors()->assertRedirect();
        $doc->refresh();
        $this->assertSame('Acta COPASST marzo', $doc->nombre);
        $this->assertSame('Actas', $doc->categoria);
        $this->assertSame($path, $doc->path);
        Storage::disk('local')->assertExists($path);

        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $this->como($otra)->put("/documentos/{$doc->id}", ['nombre' => 'Hackeado'])->assertNotFound();
        $this->assertSame('Acta COPASST marzo', $doc->fresh()->nombre);
    }

    public function test_no_se_corrige_una_calibracion_por_la_ruta_de_otro_equipo(): void
    {
        [, $cal] = $this->equipoCalibrado();
        $otro = $this->equipo('EQ-002');

        $this->como()->post("/equipos-medicion/{$otro->id}/calibraciones/{$cal->id}", $this->calibracion())->assertNotFound();
    }
}
