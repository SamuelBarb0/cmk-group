<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\NormRequirement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditorías contra la tabla de requisitos (informe, sección 8.3): la lista
 * sale de las normas del alcance, se evalúa una vez por requisito común, los
 * hallazgos se vinculan al requisito y el informe se desglosa por norma.
 */
class AuditoriaRequisitosTest extends TestCase
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

    private function auditoria(array $sistemas, array $extra = []): Audit
    {
        $this->comoConsultor()->post('/auditoria', array_merge([
            'tipo' => 'interna', 'objetivo' => 'Auditoría interna 2026', 'fecha_programada' => '2026-10-01',
            'estado' => 'programada', 'sistemas' => $sistemas,
        ], $extra))->assertSessionHasNoErrors();

        return Audit::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function clave(string $titulo): string
    {
        return NormRequirement::where('titulo', 'like', $titulo.'%')->value('clave_comun');
    }

    public function test_la_lista_sale_solo_de_las_normas_del_alcance(): void
    {
        $pesv = $this->auditoria(['pesv']);
        $esperadas = NormRequirement::whereHas('norm', fn ($q) => $q->where('clave', 'pesv'))->distinct()->count('clave_comun');

        $this->comoConsultor()->get("/auditoria/{$pesv->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('auditoria/show')
                ->has('lista', $esperadas)
                ->has('cumplimiento', 1)
                ->where('cumplimiento.0.clave', 'pesv'));

        $integrada = $this->auditoria(['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001']);
        $this->comoConsultor()->get("/auditoria/{$integrada->id}")
            ->assertInertia(fn ($p) => $p->has('lista', 62)->has('cumplimiento', 5));
    }

    public function test_un_requisito_comun_se_evalua_una_vez_y_cuenta_en_cada_norma(): void
    {
        $a = $this->auditoria(['sst', 'iso9001']);
        $politica = $this->clave('Política integrada');
        $legal = $this->clave('Matriz de requisitos legales');

        $this->comoConsultor()->put("/auditoria/{$a->id}/verificacion", ['respuestas' => [
            ['clave_comun' => $politica, 'resultado' => 'conforme', 'evidencia' => 'PLT-GSI-001 v1'],
            ['clave_comun' => $legal, 'resultado' => 'no_conforme'],
        ]])->assertSessionHasNoErrors();

        $informe = collect($a->fresh()->cumplimientoPorNorma())->keyBy('clave');
        foreach (['sst', 'iso9001'] as $norma) {
            $this->assertSame(1, $informe[$norma]['conformes']);
            $this->assertSame(1, $informe[$norma]['no_conformes']);
            $this->assertSame(50, $informe[$norma]['cumplimiento']);
        }
        $this->assertSame(2, $a->checks()->count());
    }

    public function test_no_aplica_sale_del_calculo_y_la_observacion_cumple(): void
    {
        $a = $this->auditoria(['sst']);

        $this->comoConsultor()->put("/auditoria/{$a->id}/verificacion", ['respuestas' => [
            ['clave_comun' => $this->clave('Política integrada'), 'resultado' => 'observacion'],
            ['clave_comun' => $this->clave('Comité de Convivencia'), 'resultado' => 'no_aplica'],
        ]]);

        $sst = $a->fresh()->cumplimientoPorNorma()[0];
        $this->assertSame(100, $sst['cumplimiento']);
        $this->assertSame(1, $sst['no_aplica']);
    }

    public function test_no_acepta_requisitos_fuera_del_alcance(): void
    {
        $a = $this->auditoria(['pesv']);

        // Los programas ambientales solo existen en la ISO 14001.
        $this->comoConsultor()->put("/auditoria/{$a->id}/verificacion", ['respuestas' => [
            ['clave_comun' => $this->clave('Programas ambientales'), 'resultado' => 'conforme'],
        ]])->assertSessionHasErrors();

        $this->assertSame(0, $a->checks()->count());
    }

    public function test_quitar_la_respuesta_la_devuelve_a_pendiente(): void
    {
        $a = $this->auditoria(['sst']);
        $clave = $this->clave('Política integrada');

        $this->comoConsultor()->put("/auditoria/{$a->id}/verificacion", ['respuestas' => [['clave_comun' => $clave, 'resultado' => 'conforme']]]);
        $this->comoConsultor()->put("/auditoria/{$a->id}/verificacion", ['respuestas' => [['clave_comun' => $clave, 'resultado' => null]]]);

        $this->assertSame(0, $a->checks()->count());
    }

    public function test_un_hallazgo_se_vincula_a_su_requisito_en_cada_norma(): void
    {
        $a = $this->auditoria(['sst', 'iso45001', 'iso9001']);
        $control = $this->clave('Procedimiento de control de información documentada');

        $this->comoConsultor()->post("/auditoria/{$a->id}/hallazgos", [
            'clave_comun' => $control, 'tipo' => 'no_conformidad_menor', 'descripcion' => 'Documentos sin control de versiones',
        ])->assertSessionHasNoErrors();

        $hallazgo = $a->findings()->firstOrFail();
        // Un hallazgo, tres filas: 2.2.4.6.12 del Dec. 1072, 7.5 de 45001 y 7.5 de 9001.
        $this->assertSame(3, $hallazgo->requirements()->count());

        $informe = collect($a->fresh()->cumplimientoPorNorma())->keyBy('clave');
        $this->assertSame(1, $informe['sst']['hallazgos']);
        $this->assertSame(1, $informe['iso9001']['hallazgos']);
    }

    public function test_el_formulario_conserva_los_requisitos_de_los_hallazgos(): void
    {
        $control = $this->clave('Procedimiento de control de información documentada');
        $a = $this->auditoria(['sst', 'iso9001'], ['findings' => [[
            'tipo' => 'no_conformidad_menor', 'descripcion' => 'Sin control de versiones', 'claves' => [$control],
        ]]]);

        $this->assertSame(2, $a->findings()->first()->requirements()->count());

        $this->comoConsultor()->get('/auditoria')
            ->assertInertia(fn ($p) => $p->where('auditorias.0.findings.0.claves', [$control])->has('requisitos', 62));

        // Guardar de nuevo (el formulario reemplaza los hallazgos en bloque)
        // no pierde el vínculo.
        $this->comoConsultor()->put("/auditoria/{$a->id}", [
            'tipo' => 'interna', 'objetivo' => 'Auditoría interna 2026', 'fecha_programada' => '2026-10-01',
            'estado' => 'en_curso', 'sistemas' => ['sst', 'iso9001'],
            'findings' => [['tipo' => 'no_conformidad_menor', 'descripcion' => 'Sin control de versiones', 'claves' => [$control]]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $a->findings()->first()->requirements()->count());
    }

    public function test_una_auditoria_sin_normas_sigue_funcionando(): void
    {
        $this->comoConsultor()->post('/auditoria', [
            'tipo' => 'interna', 'objetivo' => 'Auditoría antigua', 'fecha_programada' => '2026-10-01', 'estado' => 'programada',
        ])->assertSessionHasNoErrors();
        $a = Audit::withoutTenantScope()->latest('id')->firstOrFail();

        $this->assertSame([], $a->cumplimientoPorNorma());
        $this->comoConsultor()->get("/auditoria/{$a->id}")->assertOk()->assertInertia(fn ($p) => $p->has('lista', 0));
    }
}
