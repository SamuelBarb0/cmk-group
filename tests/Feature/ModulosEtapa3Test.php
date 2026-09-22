<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AcpmAction;
use App\Models\Employee;
use App\Models\LegalRequirement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkAccident;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los cinco módulos de la etapa 3: que las pantallas respondan, que el guardado
 * valide, y que un cliente NO vea los datos de otro.
 *
 * El cliente activo del consultor vive en la SESIÓN (`active_tenant_id`), no en
 * una columna del usuario: lo resuelve el middleware SetCurrentTenant. De ahí el
 * `withSession` del helper de abajo.
 */
class ModulosEtapa3Test extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = $this->tenant('Empresa Demo');
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function tenant(string $nombre): Tenant
    {
        return Tenant::create(['name' => $nombre, 'nit' => '900'.random_int(100000, 999999).'-1']);
    }

    /** Un consultor trabajando sobre su cliente activo. */
    private function comoConsultor()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function empleado(?Tenant $de = null): Employee
    {
        $e = new Employee([
            'nombres' => 'Ana',
            'apellidos' => 'Perez',
            'tipo_documento' => 'CC',
            'numero_documento' => (string) random_int(10000000, 99999999),
        ]);
        $e->tenant_id = ($de ?? $this->empresa)->id;
        $e->save();

        return $e;
    }

    public function test_las_cinco_pantallas_cargan(): void
    {
        foreach (['requisitos-legales', 'acpm', 'reportes-ac', 'ausentismo', 'accidentes'] as $ruta) {
            $this->comoConsultor()->get('/'.$ruta)->assertOk();
        }
    }

    public function test_sin_permiso_no_entra(): void
    {
        $pelado = User::factory()->create(['tenant_id' => $this->empresa->id]);

        $this->actingAs($pelado)->get('/acpm')->assertForbidden();
        $this->actingAs($pelado)->get('/accidentes')->assertForbidden();
    }

    public function test_el_acpm_asigna_codigo_consecutivo(): void
    {
        $base = [
            'tipo' => 'correctiva', 'origen_tipo' => 'manual', 'hallazgo' => 'H', 'accion' => 'A',
            'responsable' => 'Navi', 'fecha_deteccion' => '2026-09-01', 'fecha_limite' => '2026-09-30',
            'estado' => 'abierta',
        ];

        $this->comoConsultor()->post('/acpm', $base)->assertRedirect();
        $this->comoConsultor()->post('/acpm', $base)->assertRedirect();

        $anio = now()->year;
        $this->assertSame(
            ['ACPM-'.$anio.'-001', 'ACPM-'.$anio.'-002'],
            AcpmAction::withoutTenantScope()->orderBy('id')->pluck('codigo')->all(),
        );
    }

    public function test_la_fecha_limite_no_puede_ser_anterior_a_la_deteccion(): void
    {
        $this->comoConsultor()->post('/acpm', [
            'tipo' => 'correctiva', 'origen_tipo' => 'manual', 'hallazgo' => 'H', 'accion' => 'A',
            'responsable' => 'Navi', 'fecha_deteccion' => '2026-09-30', 'fecha_limite' => '2026-09-01',
            'estado' => 'abierta',
        ])->assertSessionHasErrors('fecha_limite');
    }

    public function test_un_requisito_que_no_aplica_exige_justificacion(): void
    {
        $this->comoConsultor()->post('/requisitos-legales', [
            'norma' => 'Res. 1409 de 2012', 'requisito' => 'Alturas',
            'aplica' => false, 'cumplimiento' => 'no_cumple',
        ])->assertSessionHasErrors('justificacion_no_aplica');
    }

    public function test_el_cumplimiento_legal_no_cuenta_los_parciales(): void
    {
        // 3 que aplican (uno cumple) + 1 que no aplica.
        foreach ([['cumple', true], ['parcial', true], ['no_cumple', true], ['cumple', false]] as [$c, $aplica]) {
            $r = new LegalRequirement(['norma' => 'N', 'requisito' => 'R', 'aplica' => $aplica, 'cumplimiento' => $c]);
            $r->tenant_id = $this->empresa->id;
            $r->save();
        }

        $this->comoConsultor()->get('/requisitos-legales')
            ->assertInertia(fn ($p) => $p
                ->where('stats.aplicables', 3)
                ->where('stats.cumplidos', 1)
                ->where('stats.porcentaje', 33)
                ->etc());
    }

    public function test_no_se_puede_enlazar_un_empleado_de_otra_empresa(): void
    {
        $ajeno = $this->empleado($this->tenant('Otra empresa'));

        $this->comoConsultor()->post('/ausentismo', [
            'employee_id' => $ajeno->id,
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-03',
            'tipo' => 'enfermedad_general',
        ])->assertSessionHasErrors('employee_id');
    }

    public function test_un_cliente_no_ve_los_accidentes_de_otro(): void
    {
        foreach ([$this->empresa, $this->tenant('Otra empresa')] as $t) {
            $at = new WorkAccident(['clase' => 'accidente', 'fecha' => now()->toDateString(), 'descripcion' => 'x']);
            $at->tenant_id = $t->id;
            $at->save();
        }

        $this->comoConsultor()->get('/accidentes')
            ->assertInertia(fn ($p) => $p->where('stats.total', 1)->etc());
    }

    public function test_un_evento_mortal_queda_marcado_como_grave(): void
    {
        $this->comoConsultor()->post('/accidentes', [
            'clase' => 'accidente', 'fecha' => now()->toDateString(), 'descripcion' => 'caida',
            'mortal' => true, 'grave' => false,
        ])->assertRedirect();

        $this->assertTrue(
            WorkAccident::withoutTenantScope()->first()->grave,
            'un evento mortal tiene que quedar marcado como grave',
        );
    }

    public function test_los_dias_de_ausencia_se_calculan_si_no_vienen(): void
    {
        $this->comoConsultor()->post('/ausentismo', [
            'employee_id' => $this->empleado()->id,
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-05',
            'tipo' => 'accidente_trabajo',
        ])->assertRedirect();

        $this->assertSame(5, Absence::withoutTenantScope()->first()->dias);
    }

    public function test_los_dias_perdidos_salen_de_la_ausencia_enlazada(): void
    {
        $emp = $this->empleado();

        $ausencia = new Absence([
            'employee_id' => $emp->id, 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-10',
            'tipo' => 'accidente_trabajo',
        ]);
        $ausencia->tenant_id = $this->empresa->id;
        $ausencia->save();

        $this->comoConsultor()->post('/accidentes', [
            'clase' => 'accidente', 'fecha' => '2026-09-01', 'descripcion' => 'caida',
            'employee_id' => $emp->id, 'absence_id' => $ausencia->id,
        ])->assertRedirect();

        $this->comoConsultor()->get('/accidentes?anio=2026')
            ->assertInertia(fn ($p) => $p->where('stats.dias_perdidos', 10)->etc());
    }

    public function test_el_reporte_intervenido_cuenta_en_el_indicador(): void
    {
        foreach (['reportado', 'intervenido', 'cerrado'] as $estado) {
            $this->comoConsultor()->post('/reportes-ac', [
                'fecha' => '2026-09-01', 'reportado_por' => 'Obrero', 'tipo' => 'condicion',
                'descripcion' => 'cable suelto', 'severidad' => 'alto', 'estado' => $estado,
            ])->assertRedirect();
        }

        // 2 de 3 intervenidos -> 67 %
        $this->comoConsultor()->get('/reportes-ac')
            ->assertInertia(fn ($p) => $p
                ->where('stats.intervenidos', 2)
                ->where('stats.porcentaje', 67)
                ->etc());
    }
}
