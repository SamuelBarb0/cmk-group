<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
