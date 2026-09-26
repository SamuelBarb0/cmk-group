<?php

namespace Tests\Feature;

use App\Models\ChemicalProduct;
use App\Models\ControlledDocument;
use App\Models\ResourceReading;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WasteRecord;
use App\Services\Ambiental\DocumentosAmbiental;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M18 — Gestión ambiental: residuos y categoría RESPEL, certificados,
 * consumos mensuales, productos químicos con su compatibilidad, documentos e
 * informe.
 */
class AmbientalTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SigCatalogSeeder::class);
        Storage::fake('local');

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function residuo(array $extra = [])
    {
        return $this->comoConsultor()->post('/ambiental/residuos', array_merge([
            'fecha' => now()->subDays(5)->toDateString(), 'tipo' => 'aprovechable', 'corriente' => 'Cartón', 'cantidad_kg' => 40,
            'gestor' => 'Recicladora del Norte', 'disposicion' => 'aprovechamiento',
        ], $extra));
    }

    private function quimico(string $nombre, array $peligros, array $extra = [])
    {
        return $this->comoConsultor()->post('/ambiental/quimicos', array_merge([
            'nombre' => $nombre, 'peligros' => $peligros, 'hds_fecha' => now()->subYear()->toDateString(), 'activo' => true,
        ], $extra));
    }

    private function enEmpresa(): void
    {
        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/ambiental')->assertOk()
            ->assertInertia(fn ($p) => $p->component('ambiental/index')->where('needsClient', false)->has('documentos', 6)
                ->where('respel.categoria', 'no_obligado'));
    }

    public function test_la_categoria_respel_sale_de_la_media_movil_de_seis_meses(): void
    {
        Carbon::setTestNow('2026-09-20');
        // 300 kg en seis meses = 50 kg/mes: pequeño generador.
        foreach (['2026-04-10', '2026-06-10', '2026-09-01'] as $f) {
            $this->residuo(['fecha' => $f, 'tipo' => 'peligroso', 'corriente' => 'Aceite usado', 'cantidad_kg' => 100])->assertSessionHasNoErrors();
        }
        // Fuera de la ventana (marzo) y no peligroso: no cuentan.
        $this->residuo(['fecha' => '2026-03-31', 'tipo' => 'peligroso', 'corriente' => 'Aceite usado', 'cantidad_kg' => 5000]);
        $this->residuo(['fecha' => '2026-09-02', 'cantidad_kg' => 9000]);

        $this->enEmpresa();
        $cat = WasteRecord::categoriaRespel();
        $this->assertSame(['2026-04-01', '2026-09-30'], [$cat['desde'], $cat['hasta']]);
        $this->assertSame(50.0, $cat['media_kg']);
        $this->assertSame('pequeno', $cat['categoria']);
        $this->assertTrue($cat['registro_obligatorio']);
    }

    public function test_el_peligroso_exige_nombre_y_queda_pendiente_sin_certificado(): void
    {
        $this->residuo(['tipo' => 'peligroso', 'corriente' => ''])->assertSessionHasErrors('corriente');
        $this->residuo(['tipo' => 'peligroso', 'corriente' => 'Luminarias'])->assertSessionHasNoErrors();
        $r = WasteRecord::withoutTenantScope()->firstOrFail();
        $this->assertTrue($r->sinCertificado());

        // Se edita por POST (lleva archivo) y el certificado lo resuelve.
        $this->comoConsultor()->post("/ambiental/residuos/{$r->id}", [
            'fecha' => $r->fecha->toDateString(), 'tipo' => 'peligroso', 'corriente' => 'Luminarias', 'cantidad_kg' => 12,
            'archivo' => UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertFalse($r->sinCertificado());
        Storage::disk('local')->assertExists($r->archivo);
        $this->comoConsultor()->get("/ambiental/residuos/{$r->id}/certificado")->assertOk()->assertDownload('cert.pdf');

        $this->comoConsultor()->delete("/ambiental/residuos/{$r->id}");
        Storage::disk('local')->assertMissing($r->archivo);
    }

    public function test_una_lectura_por_mes_y_recurso(): void
    {
        $mes = now()->subMonth()->format('Y-m');
        $this->comoConsultor()->post('/ambiental/lecturas', ['mes' => $mes, 'recurso' => 'agua', 'cantidad' => 30, 'trabajadores' => 10])->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/ambiental/lecturas', ['mes' => $mes, 'recurso' => 'agua', 'cantidad' => 32, 'trabajadores' => 10])->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/ambiental/lecturas', ['mes' => now()->addMonth()->format('Y-m'), 'recurso' => 'agua', 'cantidad' => 1])
            ->assertSessionHasErrors('mes');

        $lecturas = ResourceReading::withoutTenantScope()->get();
        $this->assertCount(1, $lecturas);
        $this->assertSame(32.0, $lecturas[0]->cantidad);
        $this->assertSame(3.2, $lecturas[0]->por_persona);
    }

    public function test_la_compatibilidad_sale_de_los_pictogramas(): void
    {
        $this->quimico('Thinner', ['GHS02', 'GHS07'])->assertSessionHasNoErrors();
        $this->quimico('Hipoclorito', ['GHS05', 'GHS09']);
        $this->quimico('Peróxido', ['GHS03', 'GHS05']);
        $this->quimico('Jabón', []);

        $p = ChemicalProduct::withoutTenantScope()->get()->keyBy('nombre');
        $this->assertSame('incompatible', ChemicalProduct::compatibilidad($p['Thinner'], $p['Peróxido']));
        $this->assertSame('separar', ChemicalProduct::compatibilidad($p['Thinner'], $p['Hipoclorito']));
        $this->assertSame('compatible', ChemicalProduct::compatibilidad($p['Jabón'], $p['Thinner']));
        $this->assertCount(6, ChemicalProduct::matriz($p->values()));
    }

    public function test_el_estado_de_la_hds(): void
    {
        $this->quimico('Sin hoja', ['GHS07'], ['hds_fecha' => null]);
        $this->quimico('Vieja', ['GHS07'], ['hds_fecha' => now()->subYears(6)->toDateString()]);
        $this->quimico('Nueva', ['GHS07']);

        $estados = ChemicalProduct::withoutTenantScope()->get()->pluck('estado_hds', 'nombre')->all();
        $this->assertSame(['Sin hoja' => 'sin_hds', 'Vieja' => 'desactualizada', 'Nueva' => 'vigente'], $estados);
    }

    public function test_los_documentos_van_al_control_documental(): void
    {
        $this->residuo(['tipo' => 'peligroso', 'corriente' => 'Aceite usado', 'cantidad_kg' => 25, 'disposicion' => 'tratamiento']);
        $this->comoConsultor()->post('/ambiental/lecturas', ['mes' => now()->subMonth()->format('Y-m'), 'recurso' => 'energia', 'cantidad' => 1200]);
        $this->quimico('Thinner', ['GHS02']);
        $this->quimico('Peróxido', ['GHS03']);

        foreach (array_keys(DocumentosAmbiental::DOCUMENTOS) as $d) {
            $this->comoConsultor()->post('/ambiental/enviar', ['documento' => $d])->assertSessionHasNoErrors();
        }

        $docs = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->get()->keyBy('titulo');
        $this->assertCount(6, $docs);
        $contenido = fn (string $t) => $docs[$t]->versions()->firstOrFail()->contenido;
        $this->assertStringContainsString('Aceite usado', $contenido('Plan de gestión integral de residuos peligrosos (RESPEL)'));
        $this->assertStringContainsString('**Pendiente**', $contenido('Registro de certificados de disposición final'));
        $this->assertStringContainsString('1.200,00', $contenido('Registro de consumos de agua y energía'));
        $this->assertStringContainsString('**X**', $contenido('Matriz de compatibilidad química (SGA)'));
        $this->assertSame(['8.1'], $docs['Registro de generación de residuos']->requirements()->pluck('referencia')->all());
    }

    public function test_el_informe_trae_la_seccion(): void
    {
        $this->residuo(['fecha' => '2026-08-10', 'cantidad_kg' => 60]);
        $this->residuo(['fecha' => '2026-08-11', 'tipo' => 'ordinario', 'corriente' => 'Barrido', 'cantidad_kg' => 40, 'disposicion' => 'relleno']);
        $this->residuo(['fecha' => '2026-08-12', 'tipo' => 'raee', 'corriente' => 'Monitores', 'cantidad_kg' => 20, 'disposicion' => 'posconsumo']);
        $this->quimico('Sin hoja', ['GHS07'], ['hds_fecha' => null]);
        foreach (['2025-08' => 100, '2026-08' => 80] as $mes => $m3) {
            $this->comoConsultor()->post('/ambiental/lecturas', ['mes' => $mes, 'recurso' => 'agua', 'cantidad' => $m3]);
        }

        $this->enEmpresa();
        $datos = app(InformeGestion::class)->generar($this->consultor, Periodo::desde('2026-08-01', '2026-08-31'), ['ambiental']);
        $s = $datos['secciones'][0];
        $cifras = collect($s['cifras'])->pluck('valor', 'etiqueta');

        $this->assertSame('120,0', $cifras['Residuos generados (kg)']);
        $this->assertSame('67 %', $cifras['Aprovechados']);
        $this->assertSame('1', $cifras['Entregas sin certificado de disposición']);
        $this->assertSame('1', $cifras['Productos químicos sin HDS vigente']);
        $agua = collect($s['tablas'][0]['filas'])->first();
        $this->assertSame(['Agua (m³)', '80,0', '100,0', '-20 %'], $agua);
    }
}
