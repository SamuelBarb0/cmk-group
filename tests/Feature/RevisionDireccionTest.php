<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\ManagementReview;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M16 — Revisión por la dirección: datos recopilados y congelados del
 * periodo, análisis por entrada, conclusiones obligatorias para cerrar,
 * decisiones con seguimiento que pasan a la siguiente revisión.
 */
class RevisionDireccionTest extends TestCase
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
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function revision(array $sistemas = ['sst', 'pesv'], string $desde = '2026-01-01', string $hasta = '2026-09-30'): ManagementReview
    {
        $this->comoConsultor()->post('/revision-direccion', [
            'periodo_desde' => $desde, 'periodo_hasta' => $hasta, 'sistemas' => $sistemas,
        ])->assertSessionHasNoErrors();

        return ManagementReview::withoutTenantScope()->latest('id')->firstOrFail();
    }

    /** Deja la revisión lista para cerrar: datos, reunión, análisis y conclusiones. */
    private function completar(ManagementReview $r): void
    {
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/recopilar")->assertSessionHasNoErrors();
        $this->comoConsultor()->put("/revision-direccion/{$r->id}", [
            'periodo_desde' => $r->periodo_desde->toDateString(),
            'periodo_hasta' => $r->periodo_hasta->toDateString(),
            'fecha_reunion' => '2026-10-05',
            'sistemas' => $r->sistemas,
            'participantes' => 'María Gómez (gerente), Carlos Rodríguez (SG-SST)',
            'analisis' => array_fill_keys(array_keys($r->fresh()->entradasAplicables()), 'Revisado.'),
            'conclusiones_sistema' => ['conveniente' => 'si', 'adecuado' => 'si', 'eficaz' => 'parcial'],
        ])->assertSessionHasNoErrors();
    }

    public function test_crea_la_revision_con_su_codigo(): void
    {
        $r = $this->revision();

        $this->assertMatchesRegularExpression('/^RXD-\d{4}-001$/', $r->codigo);
        $this->assertSame('borrador', $r->estado);
        $this->comoConsultor()->get("/revision-direccion/{$r->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('revision-direccion/show'));
    }

    public function test_las_entradas_dependen_de_las_normas(): void
    {
        $calidad = $this->revision(['iso9001']);
        $claves = array_keys($calidad->entradasAplicables());
        $this->assertNotContains('pesv', $claves);
        $this->assertNotContains('riesgos', $claves);
        $this->assertContains('acciones_previas', $claves);

        $vial = $this->revision(['pesv']);
        $this->assertContains('pesv', array_keys($vial->entradasAplicables()));
    }

    public function test_recopilar_congela_las_secciones_del_periodo(): void
    {
        $r = $this->revision();
        AcpmAction::create([
            'tipo' => 'correctiva', 'origen_tipo' => 'manual', 'hallazgo' => 'x', 'accion' => 'y', 'responsable' => 'Ana',
            'fecha_deteccion' => '2026-03-01', 'fecha_limite' => '2026-04-01', 'estado' => 'abierta', 'tenant_id' => $this->empresa->id,
        ]);

        $this->comoConsultor()->post("/revision-direccion/{$r->id}/recopilar")->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertNotNull($r->datos_at);
        $this->assertArrayHasKey('acpm', $r->datos['secciones']);
        $this->assertArrayHasKey('indicadores', $r->datos['secciones']);

        // La pantalla muestra la sección dentro de su entrada.
        $this->comoConsultor()->get("/revision-direccion/{$r->id}")
            ->assertInertia(fn ($p) => $p->where('entradas.4.clave', 'acpm')->where('entradas.4.secciones.0.clave', 'acpm'));
    }

    public function test_no_cierra_sin_lo_que_pide_la_norma(): void
    {
        $r = $this->revision();

        $this->comoConsultor()->post("/revision-direccion/{$r->id}/cerrar")->assertSessionHasErrors('cierre');
        $this->assertSame('borrador', $r->fresh()->estado);

        $this->completar($r);
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/cerrar")->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('cerrada', $r->estado);
        $this->assertSame($this->consultor->name, $r->cerrada_por);
    }

    public function test_cerrada_no_se_edita_ni_se_borra(): void
    {
        $r = $this->revision();
        $this->completar($r);
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/cerrar");

        $this->comoConsultor()->put("/revision-direccion/{$r->id}", [
            'periodo_desde' => '2026-01-01', 'periodo_hasta' => '2026-09-30', 'sistemas' => ['sst'], 'participantes' => 'Otro',
        ])->assertSessionHasErrors('estado');
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/recopilar")->assertSessionHasErrors('estado');
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/decisiones", ['tipo' => 'mejora', 'descripcion' => 'Tarde'])
            ->assertSessionHasErrors('estado');
        $this->comoConsultor()->delete("/revision-direccion/{$r->id}")->assertSessionHasErrors('estado');

        $this->assertStringContainsString('María Gómez', $r->fresh()->participantes);
    }

    public function test_el_seguimiento_de_las_decisiones_sigue_despues_de_cerrar(): void
    {
        $r = $this->revision();
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/decisiones", [
            'tipo' => 'recursos', 'descripcion' => 'Contratar un técnico SST', 'responsable' => 'Gerencia', 'fecha_limite' => '2026-12-31',
        ])->assertSessionHasNoErrors();
        $this->completar($r);
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/cerrar");

        $d = $r->decisions()->firstOrFail();
        $this->comoConsultor()->put("/revision-direccion/{$r->id}/decisiones/{$d->id}", [
            'estado' => 'cumplida', 'seguimiento' => 'Contratado en noviembre', 'descripcion' => 'Cambiada a escondidas', 'tipo' => 'otro',
        ])->assertSessionHasNoErrors();

        $d->refresh();
        $this->assertSame('cumplida', $d->estado);
        $this->assertSame('Contratado en noviembre', $d->seguimiento);
        // Lo que se decidió no cambia después de cerrar.
        $this->assertSame('Contratar un técnico SST', $d->descripcion);
        $this->assertSame('recursos', $d->tipo);
    }

    public function test_las_decisiones_anteriores_son_la_primera_entrada_de_la_siguiente(): void
    {
        $primera = $this->revision(['sst'], '2025-01-01', '2025-12-31');
        $this->comoConsultor()->post("/revision-direccion/{$primera->id}/decisiones", ['tipo' => 'mejora', 'descripcion' => 'Actualizar la matriz legal']);
        $this->completar($primera);
        $this->comoConsultor()->post("/revision-direccion/{$primera->id}/cerrar");

        $segunda = $this->revision(['sst'], '2026-01-01', '2026-09-30');
        $this->comoConsultor()->get("/revision-direccion/{$segunda->id}")
            ->assertInertia(fn ($p) => $p->where('previas.0.descripcion', 'Actualizar la matriz legal')->where('previas.0.estado', 'pendiente'));

        // Al cerrar, el estado de ese día queda congelado en el acta.
        $this->completar($segunda);
        $this->comoConsultor()->post("/revision-direccion/{$segunda->id}/cerrar");
        $primera->decisions()->update(['estado' => 'cumplida']);

        $this->comoConsultor()->get("/revision-direccion/{$segunda->id}")
            ->assertInertia(fn ($p) => $p->where('previas.0.estado', 'pendiente'));
    }

    public function test_una_decision_se_convierte_en_accion_de_mejora(): void
    {
        $r = $this->revision();
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/decisiones", [
            'tipo' => 'mejora', 'descripcion' => 'Programa de pausas activas', 'responsable' => 'Carlos', 'fecha_limite' => '2026-11-30',
        ]);
        $d = $r->decisions()->firstOrFail();

        $this->comoConsultor()->post("/revision-direccion/{$r->id}/decisiones/{$d->id}/acpm")->assertSessionHasNoErrors();
        $this->comoConsultor()->post("/revision-direccion/{$r->id}/decisiones/{$d->id}/acpm")->assertSessionHasErrors('acpm');

        $accion = AcpmAction::withoutTenantScope()->firstOrFail();
        $this->assertSame('revision_direccion', $accion->origen_tipo);
        $this->assertSame('mejora', $accion->tipo);
        $this->assertSame('Programa de pausas activas', $accion->accion);
        $this->assertSame($accion->id, $d->fresh()->acpm_action_id);
        $this->assertSame('en_proceso', $d->fresh()->estado);
    }

    public function test_el_word_sale_con_caracteres_especiales(): void
    {
        $r = $this->revision();
        $this->completar($r);
        $this->comoConsultor()->put("/revision-direccion/{$r->id}", [
            'periodo_desde' => '2026-01-01', 'periodo_hasta' => '2026-09-30', 'sistemas' => ['sst', 'pesv'],
            'participantes' => 'Seguridad & Salud <SST>', 'conclusiones' => 'Riesgo < aceptable & controlado',
        ]);

        $respuesta = $this->comoConsultor()->get("/revision-direccion/{$r->id}/word")->assertOk();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        // El XML es válido (sin escapar, el «&» lo rompe) y trae el texto.
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('Seguridad &amp; Salud', $xml);
    }

    public function test_otra_empresa_no_ve_la_revision(): void
    {
        $r = $this->revision();
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '900000009-1']);
        $admin = tap(User::factory()->create(['tenant_id' => $otra->id]))->assignRole('cliente_admin');

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($admin)->get("/revision-direccion/{$r->id}")->assertNotFound();
    }
}
