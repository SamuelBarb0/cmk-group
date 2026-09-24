<?php

namespace Tests\Feature;

use App\Models\FormFormat;
use App\Models\FormRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\FormFormatsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un registro completado es evidencia: no se edita ni se borra, se anula y se
 * reemplaza (informe de estructura documental, sección 7). Antes se podía
 * devolver a borrador y cambiar sin dejar rastro.
 */
class RegistrosInmutablesTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(FormFormatsSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function nuevo(string $codigo = 'FT-INS-EXT'): FormRecord
    {
        $formato = FormFormat::where('codigo', $codigo)->firstOrFail();
        $this->comoConsultor()->post('/formatos', ['form_format_id' => $formato->id])->assertSessionHasNoErrors();

        return FormRecord::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function guardar(FormRecord $r, string $estado, array $extra = [])
    {
        return $this->comoConsultor()->put("/formatos/{$r->id}", array_merge([
            'titulo' => $r->titulo, 'fecha' => '2026-09-20', 'responsable' => 'Ana', 'data' => ['obs' => 'ok'], 'estado' => $estado,
        ], $extra));
    }

    public function test_completar_asigna_consecutivo_por_formato_y_anio(): void
    {
        $a = $this->nuevo();
        $b = $this->nuevo();
        $c = $this->nuevo('FT-INS-EPP');

        $this->guardar($a, 'completado')->assertSessionHasNoErrors();
        $this->guardar($b, 'completado')->assertSessionHasNoErrors();
        $this->guardar($c, 'completado')->assertSessionHasNoErrors();

        $this->assertSame('FT-INS-EXT-2026-0001', $a->fresh()->consecutivo);
        $this->assertSame('FT-INS-EXT-2026-0002', $b->fresh()->consecutivo);
        $this->assertSame('FT-INS-EPP-2026-0001', $c->fresh()->consecutivo);
        $this->assertSame($this->consultor->name, $a->fresh()->completado_por);
        $this->assertNotNull($a->fresh()->completado_at);
    }

    public function test_un_borrador_no_tiene_consecutivo_y_se_puede_editar_y_borrar(): void
    {
        $r = $this->nuevo();
        $this->guardar($r, 'borrador', ['responsable' => 'Luis'])->assertSessionHasNoErrors();

        $this->assertNull($r->fresh()->consecutivo);
        $this->assertSame('Luis', $r->fresh()->responsable);

        $this->comoConsultor()->delete("/formatos/{$r->id}")->assertSessionHasNoErrors();
        $this->assertNull(FormRecord::withoutTenantScope()->find($r->id));
    }

    public function test_un_completado_no_se_edita_ni_vuelve_a_borrador(): void
    {
        $r = $this->nuevo();
        $this->guardar($r, 'completado');

        $this->guardar($r, 'borrador', ['responsable' => 'Otra persona'])->assertSessionHasErrors('estado');
        $this->guardar($r, 'completado', ['responsable' => 'Otra persona'])->assertSessionHasErrors('estado');

        $this->assertSame('completado', $r->fresh()->estado);
        $this->assertSame('Ana', $r->fresh()->responsable);
    }

    public function test_un_completado_no_se_borra(): void
    {
        $r = $this->nuevo();
        $this->guardar($r, 'completado');

        $this->comoConsultor()->delete("/formatos/{$r->id}")->assertSessionHasErrors('estado');
        $this->assertNotNull($r->fresh());
    }

    public function test_el_modelo_tampoco_deja_cambiar_un_completado(): void
    {
        $r = $this->nuevo();
        $this->guardar($r, 'completado');

        $this->expectException(\LogicException::class);
        FormRecord::withoutTenantScope()->find($r->id)->update(['responsable' => 'Por la puerta de atrás']);
    }

    public function test_anular_exige_motivo_y_crea_el_reemplazo(): void
    {
        $r = $this->nuevo();
        $this->guardar($r, 'completado');

        $this->comoConsultor()->post("/formatos/{$r->id}/anular", ['motivo' => ''])->assertSessionHasErrors('motivo');

        $this->comoConsultor()->post("/formatos/{$r->id}/anular", ['motivo' => 'Se anotó el extintor equivocado', 'reemplazar' => true])
            ->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('anulado', $r->estado);
        $this->assertSame('Se anotó el extintor equivocado', $r->motivo_anulacion);
        $this->assertSame('FT-INS-EXT-2026-0001', $r->consecutivo);

        $nuevo = FormRecord::withoutTenantScope()->where('reemplaza_id', $r->id)->firstOrFail();
        $this->assertSame('borrador', $nuevo->estado);
        $this->assertSame(['obs' => 'ok'], $nuevo->data);

        // El número del anulado no se reutiliza.
        $this->guardar($nuevo, 'completado')->assertSessionHasNoErrors();
        $this->assertSame('FT-INS-EXT-2026-0002', $nuevo->fresh()->consecutivo);

        // Y un anulado no se vuelve a anular ni se edita.
        $this->comoConsultor()->post("/formatos/{$r->id}/anular", ['motivo' => 'otra vez'])->assertSessionHasErrors('estado');
    }

    /**
     * El editor recibía la fecha como ISO con hora y la devolvía así al
     * guardar; MySQL la rechazaba (en SQLite pasaba, por eso no se veía).
     */
    public function test_la_fecha_viaja_y_se_guarda_como_y_m_d(): void
    {
        $r = $this->nuevo();

        $this->comoConsultor()->get("/formatos/{$r->id}")
            ->assertInertia(fn ($p) => $p->where('open.fecha', now()->toDateString()));

        $this->guardar($r, 'borrador', ['fecha' => '2026-09-23T05:00:00.000000Z'])->assertSessionHasNoErrors();
        // Lo que llega a la base ya no es el ISO con «T…Z» que MySQL rechaza.
        $this->assertStringStartsWith('2026-09-23', $r->fresh()->getRawOriginal('fecha'));
        $this->assertStringNotContainsString('T', $r->fresh()->getRawOriginal('fecha'));
    }

    public function test_un_borrador_no_se_anula(): void
    {
        $r = $this->nuevo();

        $this->comoConsultor()->post("/formatos/{$r->id}/anular", ['motivo' => 'x'])->assertSessionHasErrors('estado');
    }

    public function test_los_anulados_no_cuentan_como_evidencia(): void
    {
        $r = $this->nuevo('FT-INS-VIAS');
        $this->guardar($r, 'completado');
        $this->comoConsultor()->post("/formatos/{$r->id}/anular", ['motivo' => 'Duplicado']);

        $this->assertSame(0, FormRecord::withoutTenantScope()->validos()->where('codigo', 'FT-INS-VIAS')->count());
    }
}
