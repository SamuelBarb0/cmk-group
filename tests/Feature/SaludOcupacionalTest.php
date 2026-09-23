<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MedicalExam;
use App\Models\OccupationalProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profesiograma y exámenes médicos ocupacionales: el estado de cada
 * trabajador, lo que el profesiograma exige y no se hizo, y las cartas.
 */
class SaludOcupacionalTest extends TestCase
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
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function empleado(string $cargo = 'Conductor', ?Tenant $de = null): Employee
    {
        $e = new Employee([
            'nombres' => 'Ana', 'apellidos' => 'Perez', 'tipo_documento' => 'CC',
            'numero_documento' => (string) random_int(10000000, 99999999), 'cargo' => $cargo,
        ]);
        $e->tenant_id = ($de ?? $this->empresa)->id;
        $e->save();

        return $e;
    }

    private function perfilConductor(int $meses = 12): OccupationalProfile
    {
        $p = new OccupationalProfile([
            'cargo' => 'Conductor',
            'periodicidad_meses' => $meses,
            'examenes' => [
                'osteomuscular' => ['ingreso' => true, 'periodico' => true, 'retiro' => true],
                'psicosensometrico' => ['ingreso' => true, 'periodico' => true, 'retiro' => false],
                'audiometria' => ['ingreso' => true, 'periodico' => false, 'retiro' => false],
            ],
        ]);
        $p->tenant_id = $this->empresa->id;
        $p->save();

        return $p;
    }

    private function examen(Employee $emp, array $extra = []): array
    {
        return array_merge([
            'employee_id' => $emp->id,
            'fecha' => now()->subMonth()->toDateString(),
            'tipo' => 'periodico',
            'concepto' => 'apto',
            'examenes_realizados' => ['osteomuscular', 'psicosensometrico'],
            'carta_entregada' => false,
        ], $extra);
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/salud-ocupacional')->assertOk()
            ->assertInertia(fn ($p) => $p->component('salud-ocupacional/index')->where('needsClient', false));
    }

    public function test_el_proximo_examen_sale_de_la_periodicidad_del_cargo(): void
    {
        $this->perfilConductor(6);
        $emp = $this->empleado(' conductor ');   // mismo cargo, escrito distinto

        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'fecha' => '2026-01-31',
        ]))->assertSessionHasNoErrors();

        // Seis meses sin desbordar: 31-ene + 6 = 31-jul.
        $this->assertSame('2026-07-31', MedicalExam::withoutTenantScope()->sole()->proximo_examen->toDateString());
    }

    public function test_sin_profesiograma_el_periodico_es_anual_y_el_retiro_no_genera_proximo(): void
    {
        $emp = $this->empleado('Cargo sin perfil');

        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, ['fecha' => '2026-03-10']));
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'fecha' => '2026-04-10', 'tipo' => 'retiro',
        ]));

        $examenes = MedicalExam::withoutTenantScope()->orderBy('fecha')->get();
        $this->assertSame('2027-03-10', $examenes[0]->proximo_examen->toDateString());
        $this->assertNull($examenes[1]->proximo_examen);
    }

    public function test_estado_por_trabajador(): void
    {
        $this->perfilConductor();
        $alDia = $this->empleado();
        $vencido = $this->empleado();
        $porVencer = $this->empleado();
        $this->empleado();   // sin examen

        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($alDia));
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($vencido, [
            'fecha' => now()->subMonths(14)->toDateString(),
        ]));
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($porVencer, [
            'proximo_examen' => now()->addDays(10)->toDateString(),
            'concepto' => 'apto_con_restricciones', 'restricciones' => 'No levantar más de 12 kg',
        ]));

        $this->comoConsultor()->get('/salud-ocupacional')->assertInertia(fn ($p) => $p
            ->where('stats.trabajadores', 4)
            ->where('stats.al_dia', 1)
            ->where('stats.vencidos', 1)
            ->where('stats.por_vencer', 1)
            ->where('stats.sin_examen', 1)
            ->where('stats.con_restricciones', 1));
    }

    public function test_un_retiro_no_deja_al_trabajador_al_dia(): void
    {
        $emp = $this->empleado();
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, ['tipo' => 'retiro']));

        $this->comoConsultor()->get('/salud-ocupacional')->assertInertia(fn ($p) => $p
            ->where('trabajadores.0.estado', 'sin_examen'));
    }

    public function test_marca_lo_que_el_profesiograma_pide_y_no_se_hizo(): void
    {
        $this->perfilConductor();
        $emp = $this->empleado();

        // Ingreso: pide osteomuscular, psicosensométrico y audiometría.
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'tipo' => 'ingreso', 'examenes_realizados' => ['osteomuscular'],
        ]))->assertSessionHasNoErrors();

        $this->comoConsultor()->get('/salud-ocupacional')->assertInertia(fn ($p) => $p
            ->where('examenes.0.faltantes', ['psicosensometrico', 'audiometria']));
    }

    public function test_cartas_pendientes_y_validaciones_del_concepto(): void
    {
        $emp = $this->empleado();

        // Con restricciones hay que decir cuáles.
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'concepto' => 'apto_con_restricciones',
        ]))->assertSessionHasErrors('restricciones');

        // Carta marcada como entregada sin fecha.
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'recomendaciones_sst' => 'Pausas activas', 'carta_entregada' => true,
        ]))->assertSessionHasErrors('fecha_carta');

        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'recomendaciones_sst' => 'Pausas activas',
        ]))->assertSessionHasNoErrors();

        // Examen con fecha futura.
        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($emp, [
            'fecha' => now()->addDay()->toDateString(),
        ]))->assertSessionHasErrors('fecha');

        $this->comoConsultor()->get('/salud-ocupacional')->assertInertia(fn ($p) => $p
            ->where('stats.cartas_pendientes', 1));
    }

    public function test_no_acepta_un_trabajador_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = $this->empleado('Conductor', $otra);

        $this->comoConsultor()->post('/salud-ocupacional/examenes', $this->examen($ajeno))
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, MedicalExam::withoutTenantScope()->count());
    }

    public function test_la_matriz_se_limpia_al_guardar(): void
    {
        $this->comoConsultor()->post('/salud-ocupacional/profesiograma', [
            'cargo' => 'Operario',
            'periodicidad_meses' => 12,
            'examenes' => [
                'audiometria' => ['ingreso' => true, 'periodico' => 'true', 'retiro' => false],
                'optometria' => ['ingreso' => false, 'periodico' => false, 'retiro' => false],
                'inventado' => ['ingreso' => true],
            ],
        ])->assertSessionHasNoErrors();

        $perfil = OccupationalProfile::withoutTenantScope()->sole();
        $this->assertSame(['audiometria' => ['ingreso' => true, 'periodico' => true, 'retiro' => false]], $perfil->examenes);
    }

    public function test_no_duplica_cargos_en_el_profesiograma(): void
    {
        $this->perfilConductor();

        $this->comoConsultor()->post('/salud-ocupacional/profesiograma', [
            'cargo' => 'Conductor', 'periodicidad_meses' => 12,
        ])->assertSessionHasErrors('cargo');
    }

    public function test_traer_cargos_de_la_nomina_es_idempotente(): void
    {
        $this->perfilConductor();
        $this->empleado('Conductor');
        $this->empleado('CONDUCTOR ');
        $this->empleado('Operario');
        $this->empleado('Auxiliar contable');

        $this->comoConsultor()->post('/salud-ocupacional/profesiograma/desde-nomina')->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/salud-ocupacional/profesiograma/desde-nomina')->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['Conductor', 'Operario', 'Auxiliar contable'],
            OccupationalProfile::withoutTenantScope()->pluck('cargo')->all(),
        );
    }

    public function test_no_se_toca_un_examen_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = new MedicalExam([
            'employee_id' => $this->empleado('X', $otra)->id, 'fecha' => '2026-01-01',
            'tipo' => 'ingreso', 'concepto' => 'apto',
        ]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        $this->app->forgetInstance(TenantContext::class);
        $this->comoConsultor()->delete('/salud-ocupacional/examenes/'.$ajeno->id)->assertNotFound();
        $this->assertTrue(MedicalExam::withoutTenantScope()->whereKey($ajeno->id)->exists());
    }
}
