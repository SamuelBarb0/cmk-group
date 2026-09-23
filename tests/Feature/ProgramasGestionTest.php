<?php

namespace Tests\Feature;

use App\Models\ManagementProgram;
use App\Models\ProgramPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\ManagementProgramsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Programas de gestión: catálogo, adopción, cronograma e indicadores.
 * Lo que importa es la aritmética que un auditor recalcularía a mano y que
 * un programa de otra empresa no se pueda tocar.
 */
class ProgramasGestionTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ManagementProgramsSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function adoptar(string $codigo, int $anio = 2026): ProgramPlan
    {
        $id = ManagementProgram::where('codigo', $codigo)->value('id');
        $this->comoConsultor()->post('/programas', ['anio' => $anio, 'management_program_id' => $id])->assertRedirect();

        return ProgramPlan::withoutTenantScope()->where('codigo', $codigo)->where('anio', $anio)->firstOrFail();
    }

    /** El cuerpo del PUT tal como lo manda la pantalla, a partir de lo guardado. */
    private function cuerpo(ProgramPlan $plan, array $cambios = []): array
    {
        $plan->load(['activities', 'indicators']);

        return array_merge([
            'nombre' => $plan->nombre,
            'objetivo' => $plan->objetivo,
            'alcance' => $plan->alcance,
            'recursos' => $plan->recursos,
            'responsable' => $plan->responsable,
            'observaciones' => $plan->observaciones,
            'actividades' => $plan->activities->map(fn ($a) => [
                'fase' => $a->fase, 'nombre' => $a->nombre, 'responsable' => $a->responsable,
                'programados' => $a->meses_programados, 'ejecutados' => $a->meses_ejecutados,
            ])->all(),
            'indicadores' => $plan->indicators->map(fn ($i) => [
                'clave' => $i->clave, 'nombre' => $i->nombre, 'numerador_label' => $i->numerador_label,
                'denominador_label' => $i->denominador_label, 'meta' => $i->meta, 'sentido' => $i->sentido,
                'frecuencia' => $i->frecuencia, 'automatico' => $i->automatico, 'lecturas' => $i->lecturas ?? [],
            ])->all(),
        ], $cambios);
    }

    public function test_el_catalogo_sembrado_es_coherente(): void
    {
        $this->assertSame(8, ManagementProgram::count());

        foreach (ManagementProgram::all() as $p) {
            $this->assertNotEmpty($p->actividades, $p->codigo);
            foreach ($p->actividades as $a) {
                $this->assertContains($a['fase'], ProgramPlan::FASES, $p->codigo);
                $this->assertNotSame('', trim($a['nombre']), $p->codigo);
                foreach ($a['meses'] as $m) {
                    $this->assertTrue($m >= 1 && $m <= 12, $p->codigo);
                }
            }
            // Cada programa trae exactamente un indicador automático: el cumplimiento.
            $this->assertSame(1, collect($p->indicadores)->where('automatico', true)->count(), $p->codigo);
            foreach ($p->indicadores as $i) {
                $this->assertArrayHasKey($i['frecuencia'], ProgramPlan::FRECUENCIAS, $p->codigo);
                $this->assertContains($i['sentido'], ['asc', 'desc'], $p->codigo);
            }
        }

        // Idempotente: volver a sembrar no duplica.
        $this->seed(ManagementProgramsSeeder::class);
        $this->assertSame(8, ManagementProgram::count());
    }

    public function test_la_pantalla_carga_con_y_sin_cliente(): void
    {
        $this->comoConsultor()->get('/programas?anio=2026')->assertOk()
            ->assertInertia(fn ($p) => $p->component('programas/index')
                ->where('needsClient', false)
                ->has('catalogo', 8)
                ->has('programas', 0));

        $this->flushSession();
        $this->actingAs($this->consultor)->get('/programas')->assertOk()
            ->assertInertia(fn ($p) => $p->where('needsClient', true));
    }

    public function test_adoptar_copia_actividades_meses_e_indicadores(): void
    {
        $plan = $this->adoptar('PR-SST-15');
        $modelo = ManagementProgram::where('codigo', 'PR-SST-15')->first();

        $this->assertSame($this->empresa->id, $plan->tenant_id);
        $this->assertSame(count($modelo->actividades), $plan->activities()->count());
        $this->assertSame(count($modelo->indicadores), $plan->indicators()->count());
        $this->assertSame('FT-LCH-FATIGA', $plan->formato_codigo);

        // Las pausas activas del modelo van programadas los 12 meses.
        $pausas = $plan->activities()->where('nombre', 'Pausas activas.')->first();
        $this->assertSame(range(1, 12), $pausas->meses_programados);
        $this->assertSame([], $pausas->meses_ejecutados);

        // Ya adoptado: sale del catálogo pendiente y no se adopta dos veces el mismo año.
        $this->comoConsultor()->get('/programas?anio=2026')
            ->assertInertia(fn ($p) => $p->has('programas', 1)->where('catalogo.3.adoptado', true));
        $this->comoConsultor()->post('/programas', ['anio' => 2026, 'management_program_id' => $modelo->id])
            ->assertSessionHasErrors('codigo');
        $this->assertSame(1, ProgramPlan::withoutTenantScope()->count());
    }

    public function test_el_cumplimiento_solo_cuenta_lo_ejecutado_que_estaba_programado(): void
    {
        $plan = $this->adoptar('PR-SST-16');

        $this->comoConsultor()->put("/programas/{$plan->id}", $this->cuerpo($plan, [
            'actividades' => [
                ['fase' => 'planear', 'nombre' => 'A', 'programados' => [1, 2, 3, 7], 'ejecutados' => [1, 2]],
                // Ejecutada en un mes que no estaba programado: no suma.
                ['fase' => 'hacer', 'nombre' => 'B', 'programados' => [8], 'ejecutados' => [5]],
            ],
        ]))->assertSessionHasNoErrors();

        $plan->refresh()->load('activities');
        $this->assertSame(['programadas' => 5, 'ejecutadas' => 2], $plan->conteo());
        $this->assertSame(40.0, $plan->cumplimiento());

        // El indicador automático reparte por semestre: 2 de 3 en el primero, 0 de 2 en el segundo.
        $cump = $plan->indicators()->where('automatico', true)->first();
        $v = $cump->valores($plan);
        $this->assertSame(66.7, $v['periodos'][0]['valor']);
        $this->assertSame(0.0, $v['periodos'][1]['valor']);
        $this->assertSame(40.0, $v['anual']);
        $this->assertFalse($cump->cumpleMeta($v['anual']));   // meta 80
    }

    public function test_el_acumulado_suma_numeradores_y_no_promedia_porcentajes(): void
    {
        $plan = $this->adoptar('PR-SST-15');
        $cuerpo = $this->cuerpo($plan);
        foreach ($cuerpo['indicadores'] as &$i) {
            if ($i['clave'] === 'eficacia') {
                $i['lecturas'] = ['1' => ['numerador' => 2, 'denominador' => 2], '2' => ['numerador' => 1, 'denominador' => 10]];
            }
            if ($i['clave'] === 'casos') {
                $i['lecturas'] = ['1' => ['numerador' => 0, 'denominador' => 40]];
            }
        }
        $this->comoConsultor()->put("/programas/{$plan->id}", $cuerpo)->assertSessionHasNoErrors();

        $plan->refresh();
        $eficacia = $plan->indicators()->where('clave', 'eficacia')->first();
        $v = $eficacia->valores($plan);
        $this->assertSame(100.0, $v['periodos'][0]['valor']);
        $this->assertSame(10.0, $v['periodos'][1]['valor']);
        $this->assertSame(25.0, $v['anual']);                  // 3 de 12, no (100 + 10) / 2
        $this->assertFalse($eficacia->cumpleMeta($v['anual'])); // meta ≥ 90

        // «Menos es mejor»: cero casos con meta 0 cumple.
        $casos = $plan->indicators()->where('clave', 'casos')->first();
        $this->assertSame(0.0, $casos->valores($plan)['anual']);
        $this->assertTrue($casos->cumpleMeta(0.0));
        $this->assertFalse($casos->cumpleMeta(2.5));
    }

    public function test_el_automatico_no_guarda_lecturas_y_se_descartan_periodos_que_no_existen(): void
    {
        $plan = $this->adoptar('PR-SST-04');
        $cuerpo = $this->cuerpo($plan);
        foreach ($cuerpo['indicadores'] as &$i) {
            if ($i['automatico']) {
                $i['lecturas'] = ['1' => ['numerador' => 99, 'denominador' => 1]];
            }
            if ($i['clave'] === 'incidencia') {       // anual: solo existe el periodo 1
                $i['lecturas'] = ['1' => ['numerador' => 1, 'denominador' => 50], '2' => ['numerador' => 5, 'denominador' => 5]];
            }
        }
        $this->comoConsultor()->put("/programas/{$plan->id}", $cuerpo)->assertSessionHasNoErrors();

        $this->assertNull($plan->indicators()->where('automatico', true)->value('lecturas'));
        $incidencia = $plan->indicators()->where('clave', 'incidencia')->first();
        $this->assertSame(['1' => ['numerador' => 1, 'denominador' => 50]], $incidencia->lecturas);
        $this->assertSame(2.0, $incidencia->valores($plan)['anual']);
    }

    public function test_un_cambio_invalido_no_deja_el_programa_a_medias(): void
    {
        $plan = $this->adoptar('PR-SST-17');
        $antes = $plan->activities()->count();

        $this->comoConsultor()->put("/programas/{$plan->id}", $this->cuerpo($plan, [
            'nombre' => 'Renombrado',
            'actividades' => [['fase' => 'hacer', 'nombre' => '', 'programados' => [13]]],
        ]))->assertSessionHasErrors(['actividades.0.nombre', 'actividades.0.programados.0']);

        $this->assertSame($antes, $plan->activities()->count());
        $this->assertNotSame('Renombrado', $plan->fresh()->nombre);
    }

    public function test_programar_el_ano_siguiente_copia_el_cronograma_sin_la_ejecucion(): void
    {
        $plan = $this->adoptar('PR-SST-15');
        $cuerpo = $this->cuerpo($plan);
        $cuerpo['actividades'][0]['ejecutados'] = $cuerpo['actividades'][0]['programados'];
        foreach ($cuerpo['indicadores'] as &$i) {
            if (! $i['automatico']) {
                $i['lecturas'] = ['1' => ['numerador' => 1, 'denominador' => 2]];
            }
        }
        $this->comoConsultor()->put("/programas/{$plan->id}", $cuerpo)->assertSessionHasNoErrors();

        $this->comoConsultor()->post("/programas/{$plan->id}/renovar")->assertRedirect();
        $nuevo = ProgramPlan::withoutTenantScope()->where('codigo', 'PR-SST-15')->where('anio', 2027)->firstOrFail();

        $this->assertSame($plan->activities()->count(), $nuevo->activities()->count());
        $this->assertSame($plan->activities()->first()->meses_programados, $nuevo->activities()->first()->meses_programados);
        $this->assertSame(0, $nuevo->activities()->get()->sum(fn ($a) => count($a->meses_ejecutados)));
        $this->assertSame(0, $nuevo->indicators()->whereNotNull('lecturas')->count());

        // Una segunda vez choca con el que ya existe.
        $this->comoConsultor()->post("/programas/{$plan->id}/renovar")->assertSessionHasErrors('codigo');
    }

    public function test_un_programa_propio_arranca_con_el_indicador_de_cumplimiento(): void
    {
        $this->comoConsultor()->post('/programas', [
            'anio' => 2026, 'codigo' => 'pr-sst-20', 'nombre' => 'Programa de riesgo mecánico', 'categoria' => 'sst',
        ])->assertRedirect();

        $plan = ProgramPlan::withoutTenantScope()->where('codigo', 'PR-SST-20')->firstOrFail();
        $this->assertNull($plan->management_program_id);
        $this->assertSame(0, $plan->activities()->count());
        $this->assertSame(['cumplimiento'], $plan->indicators()->pluck('clave')->all());

        $this->comoConsultor()->post('/programas', ['anio' => 2026, 'categoria' => 'sst'])
            ->assertSessionHasErrors(['codigo', 'nombre']);
    }

    public function test_no_se_toca_un_programa_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = new ProgramPlan(['anio' => 2026, 'codigo' => 'PR-SST-15', 'nombre' => 'Fatiga', 'categoria' => 'pesv']);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        // Singleton fresco: ver la cabecera de AislamientoEntreEmpresasTest.
        foreach ([
            fn () => $this->comoConsultor()->get("/programas/{$ajeno->id}"),
            fn () => $this->comoConsultor()->put("/programas/{$ajeno->id}", ['nombre' => 'x', 'actividades' => [], 'indicadores' => []]),
            fn () => $this->comoConsultor()->post("/programas/{$ajeno->id}/renovar"),
            fn () => $this->comoConsultor()->delete("/programas/{$ajeno->id}"),
        ] as $peticion) {
            $this->app->forgetInstance(TenantContext::class);
            $peticion()->assertNotFound();
        }

        $this->assertSame('Fatiga', ProgramPlan::withoutTenantScope()->find($ajeno->id)->nombre);
        $this->assertSame(1, ProgramPlan::withoutTenantScope()->count());
    }
}
