<?php

namespace Tests\Feature;

use App\Models\ControlledDocument;
use App\Models\DocumentCatalogEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clientes\AlcanceDocumental;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que CMK contrata para un cliente con el mapa documental del SIG: los
 * documentos del catálogo M01–M20 deciden qué pantallas y partes ve la
 * empresa y qué entra a su listado maestro.
 */
class AlcanceDocumentalTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SigCatalogSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    /** @return list<int> */
    private function ids(string $modulo, string ...$fragmentos): array
    {
        return DocumentCatalogEntry::query()->where('modulo', $modulo)->get()
            ->filter(fn ($d) => $fragmentos === [] || collect($fragmentos)->contains(fn ($f) => str_contains(mb_strtolower($d->nombre), mb_strtolower($f))))
            ->pluck('id')->values()->all();
    }

    private function guardar(array $documentos, array $herramientas = [])
    {
        return $this->actingAs($this->consultor)->put("/clientes/{$this->empresa->id}", [
            'name' => 'Empresa Demo', 'nit' => '900123456-1', 'is_active' => true,
            'documentos_sig' => $documentos, 'herramientas' => $herramientas,
        ]);
    }

    public function test_todo_documento_del_mapa_enciende_alguna_pantalla_y_toda_regla_tiene_documentos(): void
    {
        $reglas = app(AlcanceDocumental::class)->reglas();
        $cubiertos = collect($reglas['pantallas'])->flatten()->unique();

        $sinPantalla = DocumentCatalogEntry::query()->whereNotIn('id', $cubiertos)->get()->map(fn ($d) => "{$d->modulo} {$d->nombre}");
        $this->assertSame([], $sinPantalla->all(), 'Documentos del mapa que no encienden ninguna pantalla');

        foreach ($reglas['pantallas'] as $pantalla => $ids) {
            $this->assertNotEmpty($ids, "La pantalla {$pantalla} no tiene documentos en el mapa");
            $this->assertArrayHasKey($pantalla, config('cmk.modulos_contratables'));
        }
        foreach ($reglas['partes'] as $pantalla => $partes) {
            $this->assertSame(array_keys(config("cmk.submodulos.{$pantalla}")), array_keys($partes), "Partes de {$pantalla} distintas al catálogo");
            foreach ($partes as $parte => $ids) {
                $this->assertNotEmpty($ids, "La parte {$pantalla}.{$parte} no tiene documentos en el mapa");
            }
        }
        // Cada módulo contratable sale del mapa o es una herramienta.
        $this->assertEqualsCanonicalizing(
            array_keys(config('cmk.modulos_contratables')),
            array_merge(array_keys($reglas['pantallas']), config('cmk.herramientas')),
        );
    }

    public function test_los_documentos_elegidos_deciden_pantallas_y_partes(): void
    {
        $r = app(AlcanceDocumental::class)->deducir(
            array_merge($this->ids('M18', 'RESPEL', 'compatibilidad'), $this->ids('M17', 'PQRS')),
            ['documentos-ia'],
        );

        $this->assertEqualsCanonicalizing(['documentos-ia', 'ambiental', 'calidad'], $r['modulos']);
        $this->assertSame(['calidad' => ['pqrs'], 'ambiental' => ['residuos', 'quimicos']], $r['submodulos']);
        $this->assertCount(4, $r['documentos_sig']);
    }

    public function test_todo_el_mapa_con_todas_las_herramientas_queda_en_null(): void
    {
        $r = app(AlcanceDocumental::class)->deducir(DocumentCatalogEntry::query()->pluck('id')->all(), config('cmk.herramientas'));

        $this->assertSame(['documentos_sig' => null, 'modulos' => null, 'submodulos' => null], $r);
    }

    public function test_la_ficha_guarda_la_seleccion_y_bloquea_lo_no_contratado(): void
    {
        $this->guardar(array_merge($this->ids('M18', 'RESPEL'), $this->ids('M09', 'matriz de elementos')))->assertSessionHasNoErrors();

        $t = $this->empresa->refresh();
        $this->assertEqualsCanonicalizing(['ambiental', 'epp'], $t->modulos);
        $this->assertSame(['epp' => ['matriz'], 'ambiental' => ['residuos']], $t->submodulos);

        $this->app->forgetInstance(TenantContext::class);
        $sesion = $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $t->id]);
        $sesion->get('/ambiental')->assertOk();
        $sesion->post('/ambiental/quimicos', [])->assertForbidden();
        $sesion->get('/calidad')->assertForbidden();
    }

    public function test_la_ficha_exige_al_menos_un_documento(): void
    {
        $this->guardar([])->assertSessionHasErrors('documentos_sig');
    }

    public function test_el_listado_maestro_se_arma_solo_con_lo_contratado(): void
    {
        $elegidos = $this->ids('M03');   // los 4 de requisitos legales
        $this->guardar(array_merge($elegidos, $this->ids('M01', 'listado maestro')));

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id])
            ->post('/control-documental/catalogo', ['modulos' => ['M01', 'M03'], 'incluir_condicionales' => true])
            ->assertSessionHasNoErrors();

        $creados = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->pluck('document_catalog_id')->sort()->values()->all();
        $esperados = collect(array_merge($elegidos, $this->ids('M01', 'listado maestro')))->sort()->values()->all();
        $this->assertSame($esperados, $creados);
    }
}
