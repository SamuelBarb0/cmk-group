<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as Ruta;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Partes de cada módulo contratadas por empresa (config('cmk.submodulos')):
 * se guardan desde la ficha del cliente y el middleware `module` bloquea las
 * que no se contrataron.
 */
class SubmodulosTest extends TestCase
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

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function guardarCliente(array $extra)
    {
        return $this->actingAs($this->consultor)->put("/clientes/{$this->empresa->id}", array_merge([
            'name' => 'Empresa Demo', 'nit' => '900123456-1', 'is_active' => true,
        ], $extra));
    }

    public function test_cada_parte_apunta_a_rutas_reales_de_su_modulo(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn (Ruta $r) => $r->getName() !== null);

        foreach (config('cmk.submodulos') as $modulo => $partes) {
            $this->assertArrayHasKey($modulo, config('cmk.modulos_contratables'), "{$modulo} no es un módulo contratable");
            foreach ($partes as $parte => $def) {
                foreach ($def['rutas'] as $patron) {
                    $suyas = $rutas->filter(fn (Ruta $r) => Str::is($patron, $r->getName()));
                    $this->assertNotEmpty($suyas, "{$modulo}.{$parte}: «{$patron}» no coincide con ninguna ruta");
                    foreach ($suyas as $r) {
                        // Si la ruta no pasa por el middleware del módulo, la parte no se bloquea.
                        $this->assertContains("module:{$modulo}", $r->gatherMiddleware(), "{$r->getName()} no pasa por module:{$modulo}");
                    }
                }
            }
        }
    }

    public function test_la_ficha_guarda_solo_los_modulos_con_partes_quitadas(): void
    {
        $this->guardarCliente([
            'modulos' => ['calidad', 'ambiental', 'emergencias'],
            'submodulos' => [
                'calidad' => ['pqrs', 'satisfaccion'],
                'ambiental' => ['residuos', 'consumos', 'quimicos'],   // todas: no se guarda
                'emergencias' => ['brigada'],
                'pesv' => ['vehiculos'],                               // módulo no contratado: se ignora
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['calidad' => ['pqrs', 'satisfaccion'], 'emergencias' => ['brigada']], $this->empresa->refresh()->submodulos);

        // Todo marcado otra vez = sin selección.
        $this->guardarCliente(['modulos' => ['calidad'], 'submodulos' => ['calidad' => ['pqrs', 'salidas', 'satisfaccion']]]);
        $this->assertNull($this->empresa->refresh()->submodulos);
    }

    public function test_la_ficha_rechaza_partes_invalidas_o_un_modulo_sin_partes(): void
    {
        $this->guardarCliente(['modulos' => ['calidad'], 'submodulos' => ['calidad' => []]])->assertSessionHasErrors('submodulos');
        $this->guardarCliente(['modulos' => ['calidad'], 'submodulos' => ['calidad' => ['inventada']]])->assertSessionHasErrors('submodulos');
        $this->guardarCliente(['modulos' => null, 'submodulos' => ['iperc' => ['x']]])->assertSessionHasErrors('submodulos');
    }

    public function test_el_middleware_bloquea_la_parte_no_contratada(): void
    {
        $this->empresa->update(['submodulos' => ['calidad' => ['pqrs']]]);

        $this->comoConsultor()->get('/calidad')->assertOk()
            ->assertInertia(fn ($p) => $p->where('partes_contratadas.calidad', ['pqrs'])
                ->where('partes_contratadas.ambiental', ['residuos', 'consumos', 'quimicos']));
        $this->comoConsultor()->post('/calidad/salidas', [])->assertForbidden();
        $this->comoConsultor()->post('/calidad/encuestas', [])->assertForbidden();
        // La parte contratada pasa el middleware (y cae en la validación).
        $this->comoConsultor()->post('/calidad/pqrs', [])->assertSessionHasErrors('cliente');
    }

    public function test_sin_seleccion_la_empresa_tiene_todas_las_partes(): void
    {
        $this->assertNull($this->empresa->submodulos);
        $this->assertTrue($this->empresa->submoduloHabilitado('calidad', 'salidas'));
        $this->comoConsultor()->post('/calidad/salidas', [])->assertSessionHasErrors('producto');
    }

    public function test_sin_el_modulo_no_hay_partes(): void
    {
        $this->empresa->update(['modulos' => ['iperc']]);
        $this->assertFalse($this->empresa->refresh()->submoduloHabilitado('calidad', 'pqrs'));
    }

    public function test_el_comite_no_contratado_no_se_ve_ni_se_crea(): void
    {
        $this->empresa->update(['submodulos' => ['comites' => ['copasst']]]);
        $ajeno = new Committee(['tipo' => 'cocolab', 'periodo' => 2026, 'fecha_conformacion' => '2026-01-10']);
        $ajeno->tenant_id = $this->empresa->id;
        $ajeno->save();

        $this->comoConsultor()->get('/comites')->assertOk()
            ->assertInertia(fn ($p) => $p->has('comites', 0)->where('catalogos.tipos', ['copasst']));
        $this->comoConsultor()->post('/comites', ['tipo' => 'cocolab', 'periodo' => 2027])->assertSessionHasErrors('tipo');
        $this->comoConsultor()->delete("/comites/{$ajeno->id}")->assertForbidden();
    }
}
