<?php

namespace Tests\Feature;

use App\Models\BrigadeMember;
use App\Models\EmergencyContact;
use App\Models\EmergencyDrill;
use App\Models\EmergencyEquipment;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan de emergencias: brigada, simulacros, equipos y directorio. Lo que se
 * comprueba es lo que un auditor miraría: que los números del programa salgan
 * bien y que no se cuele nada de otra empresa.
 */
class EmergenciasTest extends TestCase
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

    private function empleado(?Tenant $de = null): Employee
    {
        $e = new Employee([
            'nombres' => 'Ana', 'apellidos' => 'Perez', 'tipo_documento' => 'CC',
            'numero_documento' => (string) random_int(10000000, 99999999), 'cargo' => 'Operario',
            'grupo_sanguineo' => 'O+', 'eps' => 'Sura',
        ]);
        $e->tenant_id = ($de ?? $this->empresa)->id;
        $e->save();

        return $e;
    }

    private function simulacro(array $extra = []): array
    {
        return array_merge([
            'fecha' => now()->subDays(3)->toDateString(),
            'tipo' => 'interno',
            'escenario' => 'Sismo',
            'estado' => 'realizado',
            'hora_inicio' => '09:00',
            'hora_fin' => '09:20',
            'tiempo_evacuacion_segundos' => 245,
            'evacuados' => 30,
            'convocados' => 40,
            'participantes' => 30,
            'recommendations' => [],
        ], $extra);
    }

    public function test_la_pantalla_carga_con_y_sin_cliente(): void
    {
        $this->comoConsultor()->get('/emergencias')->assertOk()
            ->assertInertia(fn ($p) => $p->component('emergencias/index')->where('needsClient', false));

        // La sesión arrastra el cliente elegido en la petición anterior.
        $this->flushSession();
        $this->actingAs($this->consultor)->get('/emergencias')->assertOk()
            ->assertInertia(fn ($p) => $p->where('needsClient', true));
    }

    public function test_inscribe_un_brigadista_de_la_nomina(): void
    {
        $emp = $this->empleado();

        $this->comoConsultor()->post('/emergencias/brigada', [
            'employee_id' => $emp->id,
            'nombres' => 'Ana Perez',
            'rol' => 'jefe_brigada',
            'especialidad' => 'primeros_auxilios',
            'grupo_sanguineo' => 'O+',
            'curso_primer_respondiente' => true,
            'fecha_curso' => now()->subMonth()->toDateString(),
            'usa_anteojos' => false,
            'activo' => true,
        ])->assertSessionHasNoErrors();

        $b = BrigadeMember::withoutTenantScope()->sole();
        $this->assertSame($this->empresa->id, $b->tenant_id);
        $this->assertSame('jefe_brigada', $b->rol);
    }

    public function test_la_misma_persona_no_se_inscribe_dos_veces(): void
    {
        $emp = $this->empleado();
        $datos = ['employee_id' => $emp->id, 'nombres' => 'Ana Perez', 'rol' => 'brigadista'];

        $this->comoConsultor()->post('/emergencias/brigada', $datos)->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/emergencias/brigada', $datos)->assertSessionHasErrors('employee_id');

        $this->assertSame(1, BrigadeMember::withoutTenantScope()->count());
    }

    public function test_no_acepta_un_trabajador_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = $this->empleado($otra);

        $this->comoConsultor()->post('/emergencias/brigada', [
            'employee_id' => $ajeno->id, 'nombres' => 'Intruso', 'rol' => 'brigadista',
        ])->assertSessionHasErrors('employee_id');

        $this->assertSame(0, BrigadeMember::withoutTenantScope()->count());
    }

    public function test_avisa_los_cargos_clave_sin_asignar_y_el_curso_pendiente(): void
    {
        $this->comoConsultor()->post('/emergencias/brigada', [
            'nombres' => 'Luis Gomez', 'rol' => 'coordinador_emergencias', 'curso_primer_respondiente' => false,
        ])->assertSessionHasNoErrors();

        $this->comoConsultor()->get('/emergencias')->assertInertia(fn ($p) => $p
            ->where('stats.brigadistas', 1)
            ->where('stats.sin_primer_respondiente', 1)
            ->where('stats.roles_vacantes', [
                'jefe_brigada', 'lider_primeros_auxilios', 'lider_incendios', 'lider_evacuacion',
            ]));
    }

    public function test_los_indicadores_del_programa_salen_bien(): void
    {
        // Realizado interno: 30 de 40, dos recomendaciones y una implementada.
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'recommendations' => [
                ['descripcion' => 'Despejar la ruta del segundo piso', 'implementada' => true],
                ['descripcion' => 'Comprar chalecos para brigadistas', 'implementada' => false],
            ],
        ]))->assertSessionHasNoErrors();

        // Realizado externo: 10 de 10.
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'tipo' => 'externo', 'escenario' => 'Incendio', 'convocados' => 10, 'participantes' => 10,
        ]))->assertSessionHasNoErrors();

        // Programado: cuenta en el denominador y en nada más.
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'estado' => 'programado', 'fecha' => now()->addMonth()->toDateString(),
            'convocados' => 100, 'participantes' => 0,
        ]))->assertSessionHasNoErrors();

        $anio = now()->year;
        // Un simulacro del año pasado no debe contar en el de este año.
        $viejo = new EmergencyDrill($this->simulacro(['fecha' => now()->subYear()->toDateString()]));
        $viejo->tenant_id = $this->empresa->id;
        $viejo->save();

        // Si «dentro de un mes» cae en el año siguiente, el programado sale del año.
        $programadosEsperados = now()->addMonth()->year === $anio ? 3 : 2;

        $this->comoConsultor()->get('/emergencias?anio='.$anio)->assertInertia(fn ($p) => $p
            ->where('stats.simulacros_programados', $programadosEsperados)
            ->where('stats.simulacros_realizados', 2)
            ->where('stats.simulacros_externos', 1)
            ->where('stats.recomendaciones_total', 2)
            ->where('stats.recomendaciones_implementadas', 1)
            // (30 + 10) / (40 + 10): el programado no entra aunque tenga convocados.
            ->where('stats.participacion', 80));
    }

    public function test_una_recomendacion_sin_descripcion_no_deja_el_simulacro_a_medias(): void
    {
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'recommendations' => [['descripcion' => '', 'implementada' => false]],
        ]))->assertSessionHasErrors('recommendations.0.descripcion');

        $this->assertSame(0, EmergencyDrill::withoutTenantScope()->count());
    }

    public function test_valida_fechas_horas_y_participantes(): void
    {
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'fecha' => now()->addWeek()->toDateString(),
        ]))->assertSessionHasErrors('fecha');

        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'hora_inicio' => '10:00', 'hora_fin' => '09:00',
        ]))->assertSessionHasErrors('hora_fin');

        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'convocados' => 10, 'participantes' => 11,
        ]))->assertSessionHasErrors('participantes');

        // Programado a futuro sí es válido.
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'estado' => 'programado', 'fecha' => now()->addWeek()->toDateString(),
        ]))->assertSessionHasNoErrors();
    }

    public function test_la_hora_vuelve_en_el_formato_del_input(): void
    {
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro())->assertSessionHasNoErrors();

        $this->comoConsultor()->get('/emergencias')->assertInertia(fn ($p) => $p
            ->where('simulacros.0.hora_inicio', '09:00')
            ->where('simulacros.0.fecha', now()->subDays(3)->toDateString()));
    }

    public function test_editar_reemplaza_las_recomendaciones(): void
    {
        $this->comoConsultor()->post('/emergencias/simulacros', $this->simulacro([
            'recommendations' => [['descripcion' => 'Una', 'implementada' => false]],
        ]));
        $s = EmergencyDrill::withoutTenantScope()->sole();

        $this->comoConsultor()->put('/emergencias/simulacros/'.$s->id, $this->simulacro([
            'recommendations' => [
                ['descripcion' => 'Una', 'implementada' => true],
                ['descripcion' => 'Dos', 'implementada' => false],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, $s->recommendations()->count());
        $this->assertSame(1, $s->recommendations()->where('implementada', true)->count());
    }

    public function test_marca_los_equipos_vencidos_y_por_vencer(): void
    {
        $base = ['ubicacion' => 'Bodega', 'cantidad' => 1, 'tipo' => 'contra_incendios', 'estado' => 'bueno'];

        $this->comoConsultor()->post('/emergencias/equipos', $base + [
            'elemento' => 'Extintor vencido', 'fecha_vencimiento' => now()->subDay()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/emergencias/equipos', $base + [
            'elemento' => 'Extintor por vencer', 'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/emergencias/equipos', ['estado' => 'malo'] + $base + [
            'elemento' => 'Camilla rota',
        ])->assertSessionHasNoErrors();

        $this->comoConsultor()->get('/emergencias')->assertInertia(fn ($p) => $p
            ->where('stats.equipos_vencidos', 1)
            ->where('stats.equipos_por_vencer', 1)
            ->where('stats.equipos_malos', 1));

        $this->assertSame(3, EmergencyEquipment::withoutTenantScope()->count());
    }

    public function test_cargar_lineas_nacionales_no_duplica(): void
    {
        $this->comoConsultor()->post('/emergencias/directorio', [
            'tipo' => 'entidad_apoyo', 'nombre' => 'Bomberos', 'telefono' => '119',
        ])->assertSessionHasNoErrors();

        $this->comoConsultor()->post('/emergencias/directorio/nacionales')->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/emergencias/directorio/nacionales')->assertSessionHasNoErrors();

        $this->assertSame(
            count(EmergencyContact::LINEAS_NACIONALES),
            EmergencyContact::withoutTenantScope()->count(),
        );
    }

    public function test_no_se_toca_un_registro_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = new EmergencyEquipment([
            'ubicacion' => 'X', 'elemento' => 'Botiquín', 'cantidad' => 1, 'tipo' => 'primeros_auxilios', 'estado' => 'bueno',
        ]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();

        // Singleton fresco: ver la cabecera de AislamientoEntreEmpresasTest.
        $this->app->forgetInstance(TenantContext::class);
        $this->comoConsultor()->delete('/emergencias/equipos/'.$ajeno->id)->assertNotFound();
        $this->assertTrue(EmergencyEquipment::withoutTenantScope()->whereKey($ajeno->id)->exists());
    }
}
