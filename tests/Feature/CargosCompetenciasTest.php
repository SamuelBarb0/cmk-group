<?php

namespace Tests\Feature;

use App\Models\CompetencyAssessment;
use App\Models\ControlledDocument;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\JobPositionRequirement;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Services\Talento\MatrizCompetencias;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M07 — Perfiles de cargo y matriz de competencias: cargos traídos de la
 * nómina, cumplimiento por capacitación o por evaluación, brechas que se
 * programan como capacitación, documentos e informe.
 */
class CargosCompetenciasTest extends TestCase
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

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function deLaEmpresa(string $clase, array $datos, ?Tenant $tenant = null)
    {
        $m = new $clase($datos);
        $m->tenant_id = ($tenant ?? $this->empresa)->id;
        $m->save();

        return $m;
    }

    private function empleado(string $nombre, string $cargo, bool $activo = true): Employee
    {
        return $this->deLaEmpresa(Employee::class, [
            'nombres' => $nombre, 'apellidos' => 'Prueba', 'numero_documento' => (string) random_int(10000000, 99999999),
            'cargo' => $cargo, 'is_active' => $activo,
        ]);
    }

    /** Una capacitación realizada del tema, con el trabajador y su nota. */
    private function capacitado(Employee $e, TrainingTopic $tema, string $fecha, ?int $vigencia = null, ?float $nota = null): void
    {
        $t = $this->deLaEmpresa(Training::class, [
            'training_topic_id' => $tema->id, 'titulo' => $tema->titulo, 'categoria' => 'SST', 'fecha' => $fecha,
            'estado' => 'realizada', 'evalua_eficacia' => $nota !== null, 'nota_minima' => 70, 'vigencia_meses' => $vigencia,
        ]);
        $t->attendees()->create(['employee_id' => $e->id, 'nombres' => $e->nombres, 'asistio' => true, 'nota' => $nota]);
    }

    private function cargoConRequisitos(): array
    {
        $cargo = $this->deLaEmpresa(JobPosition::class, ['nombre' => 'Técnico de alturas']);
        $tema = TrainingTopic::create(['codigo' => 'ALT', 'titulo' => 'Trabajo seguro en alturas', 'categoria' => 'SST']);
        $formacion = $this->deLaEmpresa(JobPositionRequirement::class, [
            'job_position_id' => $cargo->id, 'tipo' => 'formacion', 'descripcion' => 'Curso de alturas avanzado', 'training_topic_id' => $tema->id,
        ]);
        $educacion = $this->deLaEmpresa(JobPositionRequirement::class, [
            'job_position_id' => $cargo->id, 'tipo' => 'educacion', 'descripcion' => 'Técnico electricista',
        ]);

        return [$cargo, $tema, $formacion, $educacion];
    }

    private function matriz(JobPosition $cargo): array
    {
        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);

        return app(MatrizCompetencias::class)->deCargo($cargo)['celdas'];
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/cargos')->assertOk()
            ->assertInertia(fn ($p) => $p->component('cargos/index')->where('needsClient', false)->has('documentos', 3));
    }

    public function test_trae_los_cargos_de_la_nomina_sin_duplicar_por_mayusculas_o_espacios(): void
    {
        $this->empleado('Ana', 'Auxiliar de bodega');
        $this->empleado('Beto', '  auxiliar   DE bodega ');
        $this->empleado('Caro', 'Conductor');
        $this->empleado('Dani', 'Gerente', activo: false);

        $this->comoConsultor()->post('/cargos/desde-nomina')->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/cargos/desde-nomina');

        $this->assertSame(['Auxiliar de bodega', 'Conductor'], JobPosition::withoutTenantScope()->orderBy('nombre')->pluck('nombre')->all());
        $cargo = JobPosition::withoutTenantScope()->where('nombre', 'Auxiliar de bodega')->firstOrFail();
        $this->comoConsultor()->get("/cargos?cargo={$cargo->id}")
            ->assertInertia(fn ($p) => $p->has('cargo.empleados', 2)->where('resumen.sin_cargo', 0));
    }

    public function test_no_admite_dos_cargos_con_el_mismo_nombre(): void
    {
        $this->comoConsultor()->post('/cargos', ['nombre' => 'Conductor'])->assertSessionHasNoErrors();
        $this->comoConsultor()->post('/cargos', ['nombre' => ' CONDUCTOR '])->assertSessionHasErrors('nombre');
        $this->assertSame(1, JobPosition::withoutTenantScope()->count());
    }

    public function test_la_formacion_se_cumple_con_una_capacitacion_eficaz_y_vigente(): void
    {
        [$cargo, $tema, $formacion] = $this->cargoConRequisitos();
        $vigente = $this->empleado('Vigente', 'Técnico de alturas');
        $vencida = $this->empleado('Vencida', 'Técnico de alturas');
        $reprobo = $this->empleado('Reprobó', 'Técnico de alturas');
        $nunca = $this->empleado('Nunca', 'Técnico de alturas');

        $this->capacitado($vigente, $tema, now()->subMonths(2)->toDateString(), vigencia: 12, nota: 90);
        $this->capacitado($vencida, $tema, now()->subMonths(14)->toDateString(), vigencia: 12);
        $this->capacitado($reprobo, $tema, now()->subMonth()->toDateString(), nota: 40);

        $c = $this->matriz($cargo);
        $this->assertSame('cumple', $c["{$vigente->id}-{$formacion->id}"]['estado']);
        $this->assertSame('vencido', $c["{$vencida->id}-{$formacion->id}"]['estado']);
        $this->assertSame('falta', $c["{$reprobo->id}-{$formacion->id}"]['estado']);
        $this->assertSame('falta', $c["{$nunca->id}-{$formacion->id}"]['estado']);
    }

    public function test_la_capacitacion_mas_reciente_reemplaza_a_la_vencida(): void
    {
        [$cargo, $tema, $formacion] = $this->cargoConRequisitos();
        $e = $this->empleado('Ana', 'Técnico de alturas');
        $this->capacitado($e, $tema, now()->subMonths(30)->toDateString(), vigencia: 12);
        $this->capacitado($e, $tema, now()->subMonth()->toDateString(), vigencia: 12);

        $this->assertSame('cumple', $this->matriz($cargo)["{$e->id}-{$formacion->id}"]['estado']);
    }

    public function test_lo_demas_se_evalua_a_mano_y_se_puede_borrar(): void
    {
        [$cargo, , , $educacion] = $this->cargoConRequisitos();
        $e = $this->empleado('Ana', 'técnico de ALTURAS');
        $this->assertSame('sin_evaluar', $this->matriz($cargo)["{$e->id}-{$educacion->id}"]['estado']);

        $datos = ['employee_id' => $e->id, 'job_position_requirement_id' => $educacion->id];
        $this->comoConsultor()->post("/cargos/{$cargo->id}/evaluar", $datos + ['cumple' => true, 'evidencia' => 'Diploma SENA'])->assertSessionHasNoErrors();
        $celda = $this->matriz($cargo)["{$e->id}-{$educacion->id}"];
        $this->assertSame('cumple', $celda['estado']);
        $this->assertSame('Diploma SENA', $celda['evidencia']);

        $this->comoConsultor()->post("/cargos/{$cargo->id}/evaluar", $datos + ['cumple' => null]);
        $this->assertSame(0, CompetencyAssessment::withoutTenantScope()->count());
    }

    public function test_no_evalua_a_quien_no_tiene_el_cargo(): void
    {
        [$cargo, , , $educacion] = $this->cargoConRequisitos();
        $otro = $this->empleado('Otro', 'Conductor');

        $this->comoConsultor()->post("/cargos/{$cargo->id}/evaluar", [
            'employee_id' => $otro->id, 'job_position_requirement_id' => $educacion->id, 'cumple' => true,
        ])->assertSessionHasErrors('employee_id');
        $this->assertSame(0, CompetencyAssessment::withoutTenantScope()->count());
    }

    public function test_solo_la_formacion_guarda_tema_de_capacitacion(): void
    {
        [$cargo, $tema] = $this->cargoConRequisitos();
        $this->comoConsultor()->post("/cargos/{$cargo->id}/requisitos", [
            'tipo' => 'experiencia', 'descripcion' => '2 años', 'training_topic_id' => $tema->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(JobPositionRequirement::withoutTenantScope()->where('tipo', 'experiencia')->firstOrFail()->training_topic_id);
    }

    public function test_la_brecha_se_programa_como_capacitacion_con_las_personas(): void
    {
        [$cargo, $tema, $formacion] = $this->cargoConRequisitos();
        $ok = $this->empleado('Ok', 'Técnico de alturas');
        $falta = $this->empleado('Falta', 'Técnico de alturas');
        $this->capacitado($ok, $tema, now()->subMonth()->toDateString());

        $this->comoConsultor()->post("/cargos/{$cargo->id}/requisitos/{$formacion->id}/programar")->assertRedirect();

        $programada = Training::withoutTenantScope()->where('estado', 'programada')->firstOrFail();
        $this->assertSame($tema->id, $programada->training_topic_id);
        $this->assertSame([$falta->id], $programada->attendees()->pluck('employee_id')->all());
        $this->assertFalse($programada->attendees()->firstOrFail()->asistio);
    }

    public function test_no_toca_cargos_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800111222-3']);
        $ajeno = $this->deLaEmpresa(JobPosition::class, ['nombre' => 'Ajeno'], $otra);

        $this->comoConsultor()->put("/cargos/{$ajeno->id}", ['nombre' => 'Hackeado'])->assertNotFound();
        $this->comoConsultor()->post("/cargos/{$ajeno->id}/requisitos", ['tipo' => 'habilidad', 'descripcion' => 'X'])->assertNotFound();
        $this->assertSame('Ajeno', $ajeno->refresh()->nombre);
    }

    public function test_los_documentos_van_al_control_documental_con_sus_requisitos(): void
    {
        [$cargo, $tema] = $this->cargoConRequisitos();
        $cargo->update(['funciones' => "Instalar redes\n- Mantener tableros", 'responsabilidades_sig' => 'Reportar condiciones inseguras']);
        $this->empleado('Ana', 'Técnico de alturas');

        foreach (['perfiles', 'competencias', 'responsabilidades'] as $d) {
            $this->comoConsultor()->post('/cargos/enviar', ['documento' => $d])->assertSessionHasNoErrors();
        }

        $docs = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->get()->keyBy('titulo');
        $this->assertCount(3, $docs);

        $perfiles = $docs['Manual de funciones y perfiles de cargo'];
        $contenido = $perfiles->versions()->firstOrFail()->contenido;
        $this->assertStringContainsString('- Mantener tableros', $contenido);
        $this->assertStringContainsString('tema de capacitación: Trabajo seguro en alturas', $contenido);
        $referencias = $perfiles->requirements()->pluck('referencia')->all();
        $this->assertContains('5.3', $referencias);
        $this->assertContains('7.2', $referencias);

        $matriz = $docs['Matriz de competencias por cargo']->versions()->firstOrFail()->contenido;
        $this->assertStringContainsString('| Ana Prueba | Falta | Sin evaluar |', $matriz);
        $this->assertStringContainsString('## Necesidades de formación', $matriz);

        $this->assertStringContainsString('Reportar condiciones inseguras', $docs['Manual de responsabilidades en SST']->versions()->firstOrFail()->contenido);
    }

    public function test_el_informe_trae_la_seccion(): void
    {
        [$cargo] = $this->cargoConRequisitos();
        $this->empleado('Ana', 'Técnico de alturas');
        $this->empleado('Sin perfil', 'Mensajero');

        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
        $datos = app(InformeGestion::class)->generar($this->consultor, Periodo::desde('2026-01-01', '2026-12-31'), ['cargos']);

        $cifras = collect($datos['secciones'][0]['cifras'])->pluck('valor', 'etiqueta');
        $this->assertSame('1', $cifras['Cargos con perfil']);
        $this->assertSame('1', $cifras['Perfiles sin funciones o responsabilidades']);
        $this->assertSame('1', $cifras['Trabajadores con cargo sin perfil']);
        $this->assertSame('0 %', $cifras['Cumplimiento de la matriz']);
        $this->assertSame('1', $cifras['Personas con brechas de formación']);
    }
}
