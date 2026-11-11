<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\GeneratedDocument;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El dashboard con indicadores y alertas: los mínimos de la Res. 0312, la
 * tabla de indicadores medidos, las cifras de cumplimiento y el centro de
 * alertas.
 *
 * La fecha se fija en junio: «el mes pasado» es mayo y el resultado no
 * depende del día en que se corra la prueba.
 */
class DashboardIndicadoresTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SigCatalogSeeder::class);
        $this->seed(IndicatorsSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enEmpresa(callable $fn): mixed
    {
        $ctx = app(TenantContext::class);
        $ctx->set($this->empresa);
        try {
            return $fn();
        } finally {
            $ctx->set(null);
            $this->app->forgetInstance(TenantContext::class);
        }
    }

    /** @param  list<int>  $meses */
    private function lecturas(string $codigo, array $meses, float $numerador, float $denominador, int $anio = 2026): void
    {
        $id = Indicator::whereNull('tenant_id')->where('codigo', $codigo)->value('id');
        $this->enEmpresa(function () use ($id, $meses, $numerador, $denominador, $anio) {
            foreach ($meses as $mes) {
                IndicatorReading::create(['indicator_id' => $id, 'anio' => $anio, 'mes' => $mes, 'numerador' => $numerador, 'denominador' => $denominador]);
            }
        });
    }

    private function dashboard(?Tenant $tenant = null)
    {
        $this->app->forgetInstance(TenantContext::class);
        $peticion = $this->actingAs($this->consultor);
        if ($tenant !== null) {
            $peticion = $peticion->withSession(['active_tenant_id' => $tenant->id]);
        }

        return $peticion->get('/dashboard')->assertOk();
    }

    /**
     * IF-AT (legal) por fuera de la meta y sin reportar mayo; AUS-CM (legal)
     * en meta y al día; CUMP-SIM (propio de CMK, no legal) por fuera de meta.
     */
    private function escenario(): void
    {
        $this->lecturas('IF-AT', [1, 2, 3, 4], 10, 1);
        $this->lecturas('AUS-CM', [1, 2, 3, 4, 5], 0, 100);
        $this->lecturas('CUMP-SIM', [1, 2, 3, 4, 5], 1, 2);
    }

    public function test_los_minimos_0312_salen_en_su_orden_midan_o_no(): void
    {
        $this->escenario();

        $this->dashboard($this->empresa)->assertInertia(fn (Assert $p) => $p->where('view', 'client')
            ->has('kpis', 6)
            ->where('kpis.0.codigo', 'IF-AT')->where('kpis.0.cumple', false)
            ->where('kpis.1.codigo', 'IS-AT')->where('kpis.1.valor', null)->where('kpis.1.cumple', null)
            ->where('kpis.5.codigo', 'AUS-CM')->where('kpis.5.cumple', true)
            ->has('kpis.0.serie', 12)
            // Mayo sin lectura es null, no cero: la gráfica corta la línea.
            ->where('kpis.0.serie.4', null));
    }

    public function test_el_dashboard_y_la_pantalla_de_indicadores_dan_el_mismo_resultado(): void
    {
        $this->escenario();

        $panel = null;
        $this->dashboard($this->empresa)->assertInertia(function (Assert $p) use (&$panel) {
            $panel = collect($p->toArray()['props']['kpis'])->firstWhere('codigo', 'IF-AT');
        });

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id])
            ->get('/indicadores')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('indicadores/index')
                ->where('indicators', fn ($lista) => collect($lista)->firstWhere('codigo', 'IF-AT')['promedio'] === $panel['valor']
                    && collect($lista)->firstWhere('codigo', 'IF-AT')['cumple'] === $panel['cumple']));
    }

    public function test_la_tabla_trae_solo_los_medidos_y_los_que_no_cumplen_primero(): void
    {
        $this->escenario();

        $this->dashboard($this->empresa)->assertInertia(fn (Assert $p) => $p
            ->has('indicadores.filas', 3)
            ->where('indicadores.filas.0.cumple', false)
            ->where('indicadores.filas.1.cumple', false)
            ->where('indicadores.filas.2.codigo', 'AUS-CM')
            ->where('indicadores.sin_medir', fn ($n) => $n > 0)
            ->where('indicadores.total', fn ($n) => $n === 3 + $this->toArrayInt($p, 'indicadores.sin_medir')));
    }

    private function toArrayInt(Assert $p, string $clave): int
    {
        return (int) data_get($p->toArray()['props'], $clave);
    }

    public function test_el_centro_de_alertas_pone_lo_legal_primero_y_desglosa_los_indicadores(): void
    {
        $this->escenario();
        $this->enEmpresa(fn () => GeneratedDocument::create([
            'titulo' => 'Política SST', 'contenido' => 'Texto', 'estado' => 'en_revision', 'version' => 1,
        ]));

        $this->dashboard($this->empresa)->assertInertia(function (Assert $p) {
            $alertas = collect($p->toArray()['props']['alertas']);

            $this->assertSame('alta', $alertas[0]['nivel']);
            $this->assertSame('IF-AT fuera de meta', $alertas[0]['titulo']);
            $this->assertSame(['IF-AT fuera de meta'], $alertas->where('nivel', 'alta')->pluck('titulo')->all(), 'Solo lo legal es alta.');

            $this->assertTrue($alertas->contains(fn ($a) => $a['titulo'] === 'CUMP-SIM fuera de meta' && $a['nivel'] === 'media'));

            $mayo = $alertas->firstWhere('titulo', 'Sin reportar mayo');
            $this->assertNotNull($mayo, 'Falta la alerta del mes pasado sin reportar.');
            $this->assertStringContainsString('IF-AT', $mayo['texto']);
            $this->assertStringNotContainsString('AUS-CM', $mayo['texto'], 'AUS-CM sí reportó mayo.');
            $this->assertStringNotContainsString('IS-AT', $mayo['texto'], 'Lo que nunca se ha medido no «falta» por mes.');

            $minimos = $alertas->firstWhere('titulo', 'Mínimos de la Res. 0312 sin medir');
            $this->assertStringContainsString('4 de 6', $minimos['texto']);

            $this->assertNotNull($alertas->firstWhere('titulo', 'Documentos por aprobar'));

            // La alerta genérica de la sección Indicadores no se repite: ya va desglosada.
            $this->assertFalse($alertas->contains(fn ($a) => str_contains($a['texto'], 'por fuera de la meta:')));
        });
    }

    public function test_en_enero_no_reclama_diciembre(): void
    {
        Carbon::setTestNow('2027-01-20 10:00:00');
        $this->lecturas('IF-AT', [1], 10, 1, 2027);

        $this->dashboard($this->empresa)->assertInertia(fn (Assert $p) => $p->where('alertas', fn ($alertas) => ! collect($alertas)->contains(fn ($a) => str_starts_with($a['titulo'], 'Sin reportar'))));
    }

    public function test_sin_el_modulo_de_indicadores_no_hay_kpis_ni_alertas_de_indicadores(): void
    {
        $this->escenario();
        $this->empresa->update(['modulos' => ['acpm']]);

        $this->dashboard($this->empresa)->assertInertia(fn (Assert $p) => $p
            ->has('kpis', 0)
            ->where('indicadores.total', 0)
            ->where('alertas', fn ($alertas) => ! collect($alertas)->contains('modulo', 'indicadores')));
    }

    public function test_el_cumplimiento_usa_el_semaforo_del_informe(): void
    {
        $this->enEmpresa(fn () => AcpmAction::create([
            'tipo' => 'correctiva', 'origen_tipo' => 'inspeccion', 'hallazgo' => 'Extintor sin señalizar',
            'accion' => 'Señalizar', 'responsable' => 'Jefe de planta', 'fecha_deteccion' => '2026-05-01',
            'fecha_limite' => '2026-06-01', 'estado' => 'abierta',
        ]));

        $this->dashboard($this->empresa)->assertInertia(function (Assert $p) {
            $c = collect($p->toArray()['props']['cumplimiento'])->keyBy('clave');

            $this->assertSame(['diagnostico', 'plan-trabajo', 'capacitaciones', 'acpm'], $c->keys()->all());
            $this->assertSame('mal', $c['acpm']['estado'], 'Una ACPM vencida pone el semáforo en rojo.');
            $this->assertNotNull($c['acpm']['detalle']);
            $this->assertSame('sin_dato', $c['diagnostico']['estado'], 'Sin autoevaluación no hay puntaje que pintar.');
        });
    }

    public function test_la_vista_consolidada_cuenta_los_indicadores_fuera_de_meta_por_cliente(): void
    {
        $this->escenario();
        $sinIndicadores = Tenant::create(['name' => 'Zeta Sin Indicadores', 'modulos' => ['acpm']]);

        $this->dashboard()->assertInertia(function (Assert $p) use ($sinIndicadores) {
            $clientes = collect($p->toArray()['props']['clientes'])->keyBy('id');

            $this->assertSame(2, $clientes[$this->empresa->id]['fuera_meta']);
            $this->assertNull($clientes[$this->empresa->id]['estandares']);
            $this->assertNull($clientes[$sinIndicadores->id]['fuera_meta'], 'Sin el módulo es «no aplica», no cero.');
        });

        $this->assertNull(session('active_tenant_id'));
    }
}
