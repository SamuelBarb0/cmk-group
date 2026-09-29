<?php

namespace Tests\Feature;

use App\Models\EquipmentCalibration;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\MeasuringEquipment;
use App\Models\Tenant;
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
        $this->como()->post('/indicadores', $this->indicadorPropio())->assertSessionHasNoErrors();
        $ind = Indicator::where('codigo', 'IND-01')->firstOrFail();

        $this->como()->put("/indicadores/{$ind->id}", $this->indicadorPropio(['nombre' => 'Rotación voluntaria', 'meta' => 3]))
            ->assertSessionHasNoErrors();

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
        $this->como($otra)->post('/indicadores', $this->indicadorPropio(['codigo' => 'OTRA-1']))->assertSessionHasNoErrors();
        $ajeno = Indicator::where('codigo', 'OTRA-1')->firstOrFail();

        $this->como()->put("/indicadores/{$ajeno->id}", $this->indicadorPropio(['nombre' => 'Cambiado']))->assertSessionHasErrors('indicator');
        $this->assertSame('Rotación de personal', $ajeno->fresh()->nombre);
    }

    public function test_un_mes_vaciado_borra_la_lectura_y_un_cero_escrito_se_conserva(): void
    {
        $this->como()->post('/indicadores', $this->indicadorPropio())->assertSessionHasNoErrors();
        $ind = Indicator::where('codigo', 'IND-01')->firstOrFail();
        $guardar = fn (array $lecturas) => $this->como()->post('/indicadores/lecturas', [
            'indicator_id' => $ind->id, 'anio' => 2026, 'lecturas' => $lecturas,
        ])->assertSessionHasNoErrors();

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
        ])->assertSessionHasNoErrors();

        return MeasuringEquipment::withoutTenantScope()->where('codigo', $codigo)->firstOrFail();
    }

    /** @return array{0: MeasuringEquipment, 1: EquipmentCalibration} */
    private function equipoCalibrado(): array
    {
        Storage::fake('local');
        $equipo = $this->equipo();
        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones", $this->calibracion([
            'archivo' => UploadedFile::fake()->create('cert-viejo.pdf', 20, 'application/pdf'),
        ]))->assertSessionHasNoErrors();

        return [$equipo, EquipmentCalibration::withoutTenantScope()->where('measuring_equipment_id', $equipo->id)->firstOrFail()];
    }

    public function test_una_calibracion_se_corrige_y_conserva_su_certificado(): void
    {
        [$equipo, $cal] = $this->equipoCalibrado();
        $archivo = $cal->archivo;

        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones/{$cal->id}", $this->calibracion([
            'realizado_por' => 'Metrolab S.A.S.', 'certificado' => 'CC-2026-114',
        ]))->assertSessionHasNoErrors();

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
        ]))->assertSessionHasNoErrors();

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
        ]))->assertSessionHasNoErrors();

        $this->assertSame('fuera_servicio', $equipo->fresh()->estado);
    }

    public function test_corregir_una_calibracion_vieja_no_cambia_el_estado(): void
    {
        [$equipo, $vieja] = $this->equipoCalibrado();
        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones", $this->calibracion(['fecha' => now()->subDay()->toDateString()]))
            ->assertSessionHasNoErrors();

        $this->como()->post("/equipos-medicion/{$equipo->id}/calibraciones/{$vieja->id}", $this->calibracion([
            'resultado' => 'no_conforme', 'impacto_mediciones' => 'Histórico.',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('en_uso', $equipo->fresh()->estado);
    }

    public function test_no_se_corrige_una_calibracion_por_la_ruta_de_otro_equipo(): void
    {
        [, $cal] = $this->equipoCalibrado();
        $otro = $this->equipo('EQ-002');

        $this->como()->post("/equipos-medicion/{$otro->id}/calibraciones/{$cal->id}", $this->calibracion())->assertNotFound();
    }
}
