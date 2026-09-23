<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PesvMobilitySurvey;
use App\Models\PesvPlan;
use App\Models\PesvRoadRisk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PesvFeed;
use App\Support\Pesv\EncuestaMovilidad;
use App\Support\TenantContext;
use Database\Seeders\PesvCriteriaSeeder;
use Database\Seeders\PesvStepsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PESV · pasos 5 y 6: encuesta de movilidad (con enlace público, sin cuenta)
 * y matriz de riesgos viales con los indicadores RSVI y GRV.
 */
class PesvPasos56Test extends TestCase
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
        app(TenantContext::class)->set($this->empresa);
        Employee::create(['nombres' => 'Ana', 'apellidos' => 'Pérez', 'numero_documento' => '1020304050', 'is_active' => true]);
        Employee::create(['nombres' => 'Luis', 'apellidos' => 'Gómez', 'numero_documento' => '99', 'is_active' => true]);
        app()->forgetInstance(TenantContext::class);
    }

    private function web()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function respuesta(array $extra = []): array
    {
        return ['respuestas' => array_merge([
            'nombre' => 'Ana Pérez', 'documento' => '1.020.304.050', 'requiere_desplazarse' => 'Sí', 'licencia' => 'No',
            'licencia_categoria' => 'B1',              // no aplica sin licencia: se descarta
            'itinere_medio' => ['Transporte público', 'Inventado'],
            'rol' => ['Peatón', 'Pasajero'], 'conductas_peaton' => ['No respetar las señales de tránsito'],
            'conductas_conductor' => ['Exceso de velocidad'],   // no eligió «Conductor»: se descarta
            'experiencia' => 12,
        ], $extra)];
    }

    public function test_la_encuesta_publica_se_responde_sin_cuenta(): void
    {
        $this->web()->post('/pesv/encuesta/enlace')->assertSessionHasNoErrors();
        $token = PesvPlan::withoutTenantScope()->where('tenant_id', $this->empresa->id)->value('encuesta_token');
        $this->assertSame(40, strlen($token));
        auth()->logout();

        $this->get("/encuesta-movilidad/{$token}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('encuesta/movilidad')->where('empresa', 'Transportes Demo')->where('abierta', true));
        $this->get('/encuesta-movilidad/'.str_repeat('x', 40))->assertNotFound();

        $this->post("/encuesta-movilidad/{$token}", ['respuestas' => ['nombre' => 'Ana']])
            ->assertSessionHasErrors(['respuestas.documento', 'respuestas.requiere_desplazarse', 'respuestas.itinere_medio', 'respuestas.rol']);

        $this->post("/encuesta-movilidad/{$token}", $this->respuesta())->assertRedirect("/encuesta-movilidad/{$token}");
        $r = PesvMobilitySurvey::withoutTenantScope()->firstOrFail();
        $this->assertSame($this->empresa->id, $r->tenant_id);
        $this->assertSame('1020304050', $r->documento, 'El documento se guarda solo con dígitos.');
        $this->assertNotNull($r->employee_id, 'Se enlaza con el empleado por su documento.');
        $this->assertSame('enlace', $r->origen);
        $this->assertArrayNotHasKey('licencia_categoria', $r->respuestas);
        $this->assertArrayNotHasKey('conductas_conductor', $r->respuestas);
        $this->assertSame(['Transporte público'], $r->respuestas['itinere_medio']);

        // La misma persona vuelve a responder en el año: se reemplaza.
        $this->post("/encuesta-movilidad/{$token}", $this->respuesta(['requiere_desplazarse' => 'No']));
        $this->assertSame(1, PesvMobilitySurvey::withoutTenantScope()->count());
        $this->assertSame('No', PesvMobilitySurvey::withoutTenantScope()->first()->respuestas['requiere_desplazarse']);

        // Cerrada: no recibe; renovada: el enlace viejo deja de servir.
        $this->web()->patch('/pesv/encuesta/enlace');
        auth()->logout();
        $this->post("/encuesta-movilidad/{$token}", $this->respuesta())->assertForbidden();
        $this->web()->post('/pesv/encuesta/enlace');
        auth()->logout();
        $this->get("/encuesta-movilidad/{$token}")->assertNotFound();
    }

    public function test_la_tabulacion_cuenta_por_opcion(): void
    {
        $respuestas = collect([
            ['rol' => ['Peatón', 'Conductor'], 'experiencia' => 2, 'genero' => 'Femenino'],
            ['rol' => ['Conductor'], 'experiencia' => 20, 'genero' => 'Masculino'],
            ['rol' => ['Ciclista'], 'genero' => 'Femenino'],
        ]);
        $t = collect(EncuestaMovilidad::tabular($respuestas))->keyBy('clave');

        $rol = collect($t['rol']['opciones'])->keyBy('opcion');
        $this->assertSame(3, $t['rol']['respondieron']);
        $this->assertSame(2, $rol['Conductor']['cantidad']);
        $this->assertSame(66.7, $rol['Conductor']['porcentaje']);
        $exp = collect($t['experiencia']['opciones'])->keyBy('opcion');
        $this->assertSame(1, $exp['1-3 años']['cantidad']);
        $this->assertSame(1, $exp['Más de 15 años']['cantidad']);
        $this->assertEquals(11, $t['experiencia']['promedio']);
    }

    public function test_el_consultor_registra_y_ve_la_cobertura(): void
    {
        $this->web()->post('/pesv/encuesta/respuestas', $this->respuesta())->assertSessionHasNoErrors();
        $this->web()->get('/pesv/encuesta')->assertOk()->assertInertia(fn ($p) => $p
            ->where('cobertura.respuestas', 1)->where('cobertura.colaboradores', 2)->where('respuestas.0.origen', 'consultor'));
    }

    public function test_la_matriz_calcula_el_nivel_y_los_indicadores(): void
    {
        $crear = fn (array $d) => $this->web()->post('/pesv/riesgos-viales', array_merge([
            'desempeno' => 'humano', 'factor' => 'Exceso de velocidad', 'exposicion' => 3, 'probabilidad' => 3,
            'fecha_identificacion' => '2025-06-01', 'controles' => ['capacitacion' => 'Taller de velocidad segura', 'inventado' => 'x'],
            'lineas' => ['comportamiento_seguro'],
        ], $d))->assertSessionHasNoErrors();

        $crear(['fecha_cierre' => '2026-05-10']);                                   // A: crítico 2025, cerrado en mayo
        $crear(['exposicion' => 2, 'probabilidad' => 2]);                           // B: moderado 2025, abierto
        $crear(['fecha_identificacion' => '2026-03-01']);                           // C: crítico 2026, abierto
        $crear(['fecha_identificacion' => '2026-04-01', 'exposicion' => 1, 'probabilidad' => 2]);   // D: bajo 2026

        $niveles = PesvRoadRisk::withoutTenantScope()->orderBy('id')->get(['valor', 'nivel'])->map(fn ($r) => "{$r->valor}:{$r->nivel}")->all();
        $this->assertSame(['9:critico', '4:moderado', '9:critico', '2:bajo'], $niveles);
        $this->assertSame(['capacitacion' => 'Taller de velocidad segura'], PesvRoadRisk::withoutTenantScope()->first()->controles);

        // Inicio de 2026: A y B (1 crítico). Hoy: B, C y D (1 crítico).
        $this->web()->get('/pesv/riesgos-viales?anio=2026')->assertOk()->assertInertia(fn ($p) => $p
            ->where('indicadores.ri_inicio', 2)->where('indicadores.ri_fin', 3)->where('indicadores.rsvi', 1)
            ->where('indicadores.rva_inicio', 1)->where('indicadores.rva_fin', 1)->where('indicadores.grv', 0)
            ->where('indicadores.criticos_abiertos', 1));

        $this->web()->post('/pesv/riesgos-viales', ['desempeno' => 'humano', 'factor' => 'X', 'exposicion' => 4, 'probabilidad' => 1,
            'fecha_identificacion' => '2026-01-01'])->assertSessionHasErrors('exposicion');
    }

    public function test_los_pasos_5_y_6_muestran_encuesta_y_matriz(): void
    {
        app(TenantContext::class)->set($this->empresa);
        PesvMobilitySurvey::create(['fecha' => '2026-07-01', 'nombre' => 'Ana', 'documento' => '1', 'respuestas' => []]);
        PesvRoadRisk::create(['desempeno' => 'via', 'factor' => 'Mal estado de la vía', 'exposicion' => 3, 'probabilidad' => 2, 'fecha_identificacion' => '2026-01-10']);

        $feed = new PesvFeed($this->empresa);
        $encuesta = collect($feed->paraPaso(5))->firstWhere('etiqueta', 'Encuesta de movilidad 2026');
        $this->assertSame('parcial', $encuesta['estado'], '1 de 2 colaboradores: 50 %.');
        $matriz = collect($feed->paraPaso(6))->firstWhere('etiqueta', 'Matriz de riesgos viales');
        $this->assertSame('parcial', $matriz['estado'], 'Un riesgo crítico abierto.');
    }
}
