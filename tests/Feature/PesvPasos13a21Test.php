<?php

namespace Tests\Feature;

use App\Http\Controllers\PesvEstadisticaController;
use App\Models\AcpmAction;
use App\Models\PesvInternalRoad;
use App\Models\PesvKmPeriodo;
use App\Models\PesvRoute;
use App\Models\PesvSiniestro;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PesvFeed;
use App\Support\Pesv\PlanDesplazamiento;
use App\Support\TenantContext;
use Database\Seeders\PesvCriteriaSeeder;
use Database\Seeders\PesvStepsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PESV · pasos 13 (investigación de siniestros), 14 (vías internas),
 * 15 (planificación de desplazamientos) y 21 (análisis estadístico: TSV y $SV).
 */
class PesvPasos13a21Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-15 10:00');
        $this->seed([RolesAndPermissionsSeeder::class, PesvStepsSeeder::class, PesvCriteriaSeeder::class]);
        $this->empresa = Tenant::create(['name' => 'Transportes Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function web()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    /** Crea datos bajo el tenant indicado y suelta el contexto. */
    private function en(Tenant $tenant, callable $fn): mixed
    {
        app(TenantContext::class)->set($tenant);
        $r = $fn();
        app()->forgetInstance(TenantContext::class);

        return $r;
    }

    private function siniestro(array $extra = []): PesvSiniestro
    {
        return PesvSiniestro::create(array_merge([
            'fecha' => '2026-05-10', 'tipo' => 'choque', 'gravedad' => 'solo_danos', 'descripcion' => 'Choque en parqueadero',
        ], $extra));
    }

    public function test_el_nivel_de_perdida_sale_de_las_consecuencias_si_no_se_fija(): void
    {
        $this->en($this->empresa, function () {
            $this->assertSame(1, $this->siniestro()->nivel());
            $this->assertSame(2, $this->siniestro(['gravedad' => 'con_heridos', 'lesionados' => 1, 'dias_incapacidad' => 5])->nivel());
            $this->assertSame(3, $this->siniestro(['gravedad' => 'con_heridos', 'dias_incapacidad' => 45])->nivel());
            $this->assertSame(4, $this->siniestro(['gravedad' => 'fatal', 'fallecidos' => 1])->nivel());
            // El responsable puede corregir el nivel sugerido.
            $this->assertSame(3, $this->siniestro(['nivel_perdida' => 3])->nivel());
            $this->assertSame(1500000.0, $this->siniestro(['costo_directo' => 1000000, 'costo_indirecto' => 500000])->costoTotal());
        });
    }

    public function test_registra_la_investigacion_y_crea_la_accion_en_acpm(): void
    {
        $this->web()->post('/pesv/siniestros', [
            'fecha' => '2026-06-01', 'tipo' => 'atropello', 'gravedad' => 'con_heridos', 'descripcion' => 'Atropello a peatón',
            'lesionados' => 1, 'dias_incapacidad' => 40, 'tipo_desplazamiento' => 'in_itinere',
            'investigado' => true, 'fecha_investigacion' => '2026-06-05', 'equipo_investigador' => 'Líder PESV, COPASST',
            'causas_inmediatas' => 'Exceso de velocidad', 'causas_basicas' => 'Sin control de velocidad en la ruta',
            'leccion_aprendida' => 'Controlar velocidad con GPS', 'leccion_divulgada' => true,
            'costo_directo' => 2000000, 'costo_indirecto' => 800000,
        ])->assertSessionHasNoErrors();

        $s = $this->en($this->empresa, fn () => PesvSiniestro::firstOrFail());
        $this->assertSame('in_itinere', $s->tipo_desplazamiento);
        $this->assertSame(3, $s->nivel());
        $this->assertTrue($s->leccion_divulgada);

        $this->web()->post("/pesv/siniestros/{$s->id}/acpm", [
            'accion' => 'Instalar GPS con alertas de velocidad', 'responsable' => 'Jefe de flota', 'fecha_limite' => '2026-08-30',
        ])->assertSessionHasNoErrors();

        $acpm = $this->en($this->empresa, fn () => AcpmAction::firstOrFail());
        $this->assertSame('siniestro_vial', $acpm->origen_tipo);
        $this->assertSame($s->id, $acpm->origen_id);
        $this->assertSame('Sin control de velocidad en la ruta', $acpm->causa);

        // El paso 13 ve la investigación y la lección divulgada.
        $insumos = $this->en($this->empresa, fn () => collect((new PesvFeed($this->empresa))->paraPaso(13))->keyBy('etiqueta'));
        $this->assertSame('ok', $insumos['Siniestros pendientes de investigar']['estado']);
        $this->assertSame('ok', $insumos['Lecciones aprendidas divulgadas']['estado']);
    }

    public function test_un_siniestro_sin_desplazamiento_queda_como_laboral(): void
    {
        $this->web()->post('/pesv/siniestros', [
            'fecha' => '2026-06-01', 'tipo' => 'choque', 'gravedad' => 'solo_danos', 'descripcion' => 'Roce',
        ])->assertSessionHasNoErrors();

        $this->assertSame('laboral', $this->en($this->empresa, fn () => PesvSiniestro::firstOrFail()->tipo_desplazamiento));
    }

    public function test_tsv_y_costos_por_trimestre_con_los_kilometros_de_la_flota(): void
    {
        $this->en($this->empresa, function () {
            $this->siniestro(['fecha' => '2026-02-01', 'costo_directo' => 300000]);
            $this->siniestro(['fecha' => '2026-03-01']);
            $this->siniestro(['fecha' => '2026-05-01', 'gravedad' => 'fatal', 'fallecidos' => 1, 'costo_directo' => 150000000]);
            $this->siniestro(['fecha' => '2025-04-01']);   // año anterior: línea base
        });

        $this->web()->put('/pesv/estadistica/km', ['anio' => 2026, 'km' => [1 => 500000, 2 => '', 3 => null]])->assertSessionHasNoErrors();

        $this->web()->get('/pesv/estadistica?anio=2026')->assertOk()->assertInertia(fn ($p) => $p
            ->component('pesv/estadistica')
            ->where('piramide.1.laboral', 2)
            ->where('piramide.4.laboral', 1)
            ->where('lineaBase.1.laboral', 1)
            // T1: 2 siniestros nivel 1 en 500.000 km -> 2 × 1.000.000 / 500.000 = 4
            ->where('trimestres.0.km', 500000)
            ->where('trimestres.0.niveles.1.siniestros', 2)
            ->where('trimestres.0.niveles.1.tsv', 4)
            ->where('trimestres.0.niveles.1.costo', 300000)
            // T2 sin kilómetros: hay siniestro pero no hay tasa.
            ->where('trimestres.1.km', null)
            ->where('trimestres.1.niveles.4.siniestros', 1)
            ->where('trimestres.1.niveles.4.tsv', null)
            ->where('porMes.actual.1', 1)
        );

        $km = $this->en($this->empresa, fn () => PesvKmPeriodo::where('anio', 2026)->pluck('km', 'trimestre')->all());
        $this->assertSame([1], array_keys($km));

        $insumos = $this->en($this->empresa, fn () => collect((new PesvFeed($this->empresa))->paraPaso(21))->keyBy('etiqueta'));
        // Julio: T1 y T2 ya cerraron y solo T1 tiene kilómetros.
        $this->assertSame('parcial', $insumos['Kilómetros de la flota por trimestre']['estado']);
    }

    public function test_la_tsv_usa_la_constante_de_un_millon_de_kilometros(): void
    {
        $siniestros = collect([new PesvSiniestro(['fecha' => '2026-01-10', 'gravedad' => 'solo_danos'])]);
        $t = PesvEstadisticaController::trimestres($siniestros, collect([1 => 2000000.0]));

        $this->assertSame(0.5, (float) $t[0]['niveles'][1]['tsv']);
    }

    public function test_vias_internas_con_cronograma_y_cumplimiento(): void
    {
        $this->web()->post('/pesv/vias-internas', [
            'nombre' => 'Vía principal planta', 'km' => 1.2, 'frecuente' => true, 'activa' => true, 'anio_cronograma' => 2026,
            'cronograma' => [
                ['actividad' => 'Señalización vertical', 'costo' => 500000, 'programados' => [3, 6, 9, 12], 'ejecutados' => [3, 6]],
                ['actividad' => 'Demarcación', 'programados' => [5, 5], 'ejecutados' => []],
            ],
        ])->assertSessionHasNoErrors();

        $via = $this->en($this->empresa, fn () => PesvInternalRoad::firstOrFail());
        $this->assertSame([5], $via->cronograma[1]['programados']);   // meses repetidos no cuentan dos veces
        // Hasta julio: programados 3, 6 y 5 -> ejecutados 3 y 6.
        $this->assertSame(['programados' => 3, 'ejecutados' => 2, 'porcentaje' => 66.7], $via->cumplimiento(7));

        $this->web()->post('/pesv/vias-internas', [
            'nombre' => 'Mala', 'cronograma' => [['actividad' => 'X', 'programados' => [13]]],
        ])->assertSessionHasErrors('cronograma.0.programados.0');

        $insumo = $this->en($this->empresa, fn () => (new PesvFeed($this->empresa))->paraPaso(14)[0]);
        $this->assertSame('parcial', $insumo['estado']);
        $this->assertSame(1, $insumo['cantidad']);
    }

    public function test_plan_de_desplazamiento_se_sanea_y_marca_el_paso_15(): void
    {
        $ruta = $this->en($this->empresa, fn () => PesvRoute::create(['nombre' => 'Bogotá - Tunja', 'is_active' => true]));

        $this->web()->get("/pesv/rutas/{$ruta->id}/plan")->assertOk()
            ->assertInertia(fn ($p) => $p->component('pesv/ruta-plan')->where('completo', false)->has('plan.tablas.velocidades', 7));

        $this->web()->put("/pesv/rutas/{$ruta->id}/plan", [
            'campos' => ['hora_salida' => '05:30', 'basura' => 'x'],
            'tablas' => [
                'velocidades' => [
                    ['zona' => 'Vía pavimentada', 'cargado' => '60', 'descargado' => '80'],
                    ['zona' => 'Inventada', 'cargado' => '200'],
                ],
                'directorio' => [['entidad' => 'Policía de Carreteras', 'telefono' => '#767'], ['entidad' => '', 'telefono' => '']],
                'otra' => [['x' => 'y']],
            ],
        ])->assertSessionHasNoErrors();

        $plan = $this->en($this->empresa, fn () => PesvRoute::findOrFail($ruta->id)->plan);
        $this->assertSame('05:30', $plan['campos']['hora_salida']);
        $this->assertArrayNotHasKey('basura', $plan['campos']);
        $this->assertArrayNotHasKey('otra', $plan['tablas']);
        $this->assertCount(7, $plan['tablas']['velocidades']);   // solo las zonas de la norma
        $this->assertSame(['zona' => 'Vía pavimentada', 'cargado' => '60', 'descargado' => '80', 'requisito' => ''], $plan['tablas']['velocidades'][2]);
        $this->assertNotContains('Inventada', array_column($plan['tablas']['velocidades'], 'zona'));
        $this->assertCount(1, $plan['tablas']['directorio']);      // la fila vacía se descarta
        $this->assertTrue(PlanDesplazamiento::completo($plan));

        $insumos = $this->en($this->empresa, fn () => collect((new PesvFeed($this->empresa))->paraPaso(15))->keyBy('etiqueta'));
        $this->assertSame('ok', $insumos['Planes de desplazamiento']['estado']);
    }

    public function test_no_se_tocan_datos_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra SAS', 'nit' => '800000000-1']);
        [$siniestro, $via, $ruta] = $this->en($otra, fn () => [
            $this->siniestro(),
            PesvInternalRoad::create(['nombre' => 'Ajena']),
            PesvRoute::create(['nombre' => 'Ajena', 'is_active' => true]),
        ]);
        $this->en($otra, fn () => PesvKmPeriodo::create(['anio' => 2026, 'trimestre' => 1, 'km' => 1000]));

        $this->web()->post("/pesv/siniestros/{$siniestro->id}/acpm", [
            'accion' => 'X', 'responsable' => 'Y', 'fecha_limite' => '2026-08-30',
        ])->assertNotFound();
        $this->web()->put("/pesv/vias-internas/{$via->id}", ['nombre' => 'Hackeada'])->assertNotFound();
        $this->web()->delete("/pesv/vias-internas/{$via->id}")->assertNotFound();
        $this->web()->get("/pesv/rutas/{$ruta->id}/plan")->assertNotFound();
        $this->web()->put("/pesv/rutas/{$ruta->id}/plan", ['campos' => ['hora_salida' => '01:00']])->assertNotFound();

        $this->web()->get('/pesv/estadistica?anio=2026')->assertOk()
            ->assertInertia(fn ($p) => $p->where('trimestres.0.km', null)->where('piramide.1.laboral', 0));

        $this->assertSame('Ajena', PesvInternalRoad::withoutGlobalScopes()->findOrFail($via->id)->nombre);
        $this->assertSame(0, AcpmAction::withoutGlobalScopes()->count());
    }
}
