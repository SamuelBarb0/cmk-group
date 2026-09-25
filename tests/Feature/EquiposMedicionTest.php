<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\ControlledDocument;
use App\Models\EquipmentCalibration;
use App\Models\MeasuringEquipment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M09 — Equipos de seguimiento y medición: situación de la calibración, el
 * equipo no conforme sale de uso, certificados, acciones en ACPM, documentos
 * e informe.
 */
class EquiposMedicionTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SigCatalogSeeder::class);
        Storage::fake('local');

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor(?Tenant $tenant = null)
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => ($tenant ?? $this->empresa)->id]);
    }

    private function equipo(array $extra = [], ?Tenant $tenant = null): MeasuringEquipment
    {
        $this->comoConsultor($tenant)->post('/equipos-medicion', array_merge([
            'codigo' => 'EQ-001', 'nombre' => 'Sonómetro', 'magnitud' => 'Nivel sonoro', 'unidad' => 'dB',
            'error_maximo' => '± 1,5 dB', 'control' => 'calibracion', 'frecuencia_meses' => 12, 'estado' => 'en_uso',
        ], $extra))->assertSessionHasNoErrors();

        return MeasuringEquipment::withoutTenantScope()->where('tenant_id', ($tenant ?? $this->empresa)->id)->latest('id')->firstOrFail();
    }

    private function calibrar(MeasuringEquipment $e, array $extra = [])
    {
        return $this->comoConsultor()->post("/equipos-medicion/{$e->id}/calibraciones", array_merge([
            'fecha' => now()->subMonth()->toDateString(), 'tipo' => 'calibracion', 'realizado_por' => 'Metrolab',
            'acreditado_onac' => true, 'resultado' => 'conforme',
        ], $extra));
    }

    private function situacion(MeasuringEquipment $e): string
    {
        return MeasuringEquipment::withoutTenantScope()->with('calibrations')->findOrFail($e->id)->situacion()['estado'];
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/equipos-medicion')->assertOk()
            ->assertInertia(fn ($p) => $p->component('equipos-medicion/index')->where('needsClient', false)->has('documentos', 2));
    }

    public function test_la_situacion_sale_de_la_ultima_calibracion_y_la_frecuencia(): void
    {
        $e = $this->equipo();
        $this->assertSame('sin_calibrar', $this->situacion($e));

        $this->calibrar($e, ['fecha' => now()->subMonths(13)->toDateString()]);
        $this->assertSame('vencido', $this->situacion($e));

        $this->calibrar($e, ['fecha' => now()->subMonths(11)->subDays(10)->toDateString()]);
        $this->assertSame('por_vencer', $this->situacion($e));

        $this->calibrar($e, ['fecha' => now()->subMonth()->toDateString()]);
        $this->assertSame('vigente', $this->situacion($e));
    }

    public function test_no_conforme_exige_evaluar_las_mediciones_y_saca_el_equipo_de_uso(): void
    {
        $e = $this->equipo();
        $this->calibrar($e, ['resultado' => 'no_conforme'])->assertSessionHasErrors('impacto_mediciones');

        $this->calibrar($e, ['resultado' => 'no_conforme', 'error_encontrado' => '+3,2 dB', 'impacto_mediciones' => 'Repetir las mediciones de ruido de agosto.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('fuera_servicio', $e->refresh()->estado);
        $this->assertSame('fuera_servicio', $this->situacion($e));

        // Vuelve al uso solo con una calibración conforme posterior.
        $this->calibrar($e, ['fecha' => now()->toDateString()]);
        $this->assertSame('en_uso', $e->refresh()->estado);
        $this->assertSame('vigente', $this->situacion($e));
    }

    public function test_una_calibracion_vieja_registrada_tarde_no_cambia_el_estado(): void
    {
        $e = $this->equipo();
        $this->calibrar($e, ['fecha' => now()->subMonth()->toDateString()]);
        $this->calibrar($e, ['fecha' => now()->subYears(2)->toDateString(), 'resultado' => 'no_conforme', 'impacto_mediciones' => 'Histórico.']);

        $this->assertSame('en_uso', $e->refresh()->estado);
        $this->assertSame('vigente', $this->situacion($e));
    }

    public function test_no_se_registra_una_calibracion_futura(): void
    {
        $e = $this->equipo();
        $this->calibrar($e, ['fecha' => now()->addDay()->toDateString()])->assertSessionHasErrors('fecha');
    }

    public function test_el_certificado_se_guarda_se_descarga_y_se_borra_con_el_equipo(): void
    {
        $e = $this->equipo();
        $this->calibrar($e, ['certificado' => 'CC-123', 'archivo' => UploadedFile::fake()->create('certificado.pdf', 120, 'application/pdf')])
            ->assertSessionHasNoErrors();

        $cal = EquipmentCalibration::withoutTenantScope()->firstOrFail();
        Storage::disk('local')->assertExists($cal->archivo);
        $this->assertStringStartsWith("tenants/{$this->empresa->id}/calibraciones/", $cal->archivo);

        $this->comoConsultor()->get("/equipos-medicion/{$e->id}/calibraciones/{$cal->id}/certificado")->assertOk()->assertDownload('certificado.pdf');

        $this->comoConsultor()->delete("/equipos-medicion/{$e->id}");
        Storage::disk('local')->assertMissing($cal->archivo);
    }

    public function test_otra_empresa_no_ve_ni_toca_los_equipos(): void
    {
        $e = $this->equipo();
        $this->calibrar($e, ['archivo' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')]);
        $cal = EquipmentCalibration::withoutTenantScope()->firstOrFail();
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800111222-3']);

        $this->comoConsultor($otra)->get("/equipos-medicion/{$e->id}/calibraciones/{$cal->id}/certificado")->assertNotFound();
        $this->comoConsultor($otra)->delete("/equipos-medicion/{$e->id}")->assertNotFound();
        $this->assertNotNull($e->fresh());

        // El código es único por empresa, no global. Se crea directo: en una
        // prueba el controlador se reutiliza entre peticiones y conserva el
        // TenantContext de la primera empresa.
        $ajeno = new MeasuringEquipment(['codigo' => 'EQ-001', 'nombre' => 'Ajeno', 'control' => 'calibracion', 'frecuencia_meses' => 12]);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();
        $this->assertSame(2, MeasuringEquipment::withoutTenantScope()->where('codigo', 'EQ-001')->count());
        $this->comoConsultor()->post('/equipos-medicion', [
            'codigo' => 'EQ-001', 'nombre' => 'Otro', 'control' => 'verificacion', 'frecuencia_meses' => 6, 'estado' => 'en_uso',
        ])->assertSessionHasErrors('codigo');
    }

    public function test_el_no_conforme_crea_su_accion_correctiva_y_el_conforme_no(): void
    {
        $e = $this->equipo();
        $this->calibrar($e);
        $conforme = EquipmentCalibration::withoutTenantScope()->firstOrFail();
        $datos = ['accion' => 'Ajustar y recalibrar', 'responsable' => 'Coordinador SST', 'fecha_limite' => now()->addMonth()->toDateString()];
        $this->comoConsultor()->post("/equipos-medicion/{$e->id}/calibraciones/{$conforme->id}/accion", $datos)->assertSessionHasErrors('acpm');

        $this->calibrar($e, ['fecha' => now()->toDateString(), 'resultado' => 'no_conforme', 'error_encontrado' => '+3 dB', 'impacto_mediciones' => 'Repetir mediciones.']);
        $mala = EquipmentCalibration::withoutTenantScope()->where('resultado', 'no_conforme')->firstOrFail();
        $this->comoConsultor()->post("/equipos-medicion/{$e->id}/calibraciones/{$mala->id}/accion", $datos)->assertSessionHasNoErrors();

        $acpm = AcpmAction::withoutTenantScope()->firstOrFail();
        $this->assertSame(['correctiva', 'calibracion'], [$acpm->tipo, $acpm->origen_tipo]);
        $this->assertStringContainsString('EQ-001', $acpm->hallazgo);
        $this->assertStringContainsString('± 1,5 dB', $acpm->hallazgo);
        $this->assertSame($acpm->id, $mala->refresh()->acpm_action_id);
    }

    public function test_los_documentos_van_al_control_documental_con_su_requisito(): void
    {
        $e = $this->equipo();
        $this->calibrar($e, ['certificado' => 'CC-123', 'error_encontrado' => '+0,4 dB']);

        foreach (['procedimiento', 'hojas'] as $d) {
            $this->comoConsultor()->post('/equipos-medicion/enviar', ['documento' => $d])->assertSessionHasNoErrors();
        }

        $docs = ControlledDocument::withoutTenantScope()->where('tenant_id', $this->empresa->id)->get()->keyBy('titulo');
        $this->assertCount(2, $docs);

        $prc = $docs['Procedimiento de control de equipos de seguimiento y medición'];
        $this->assertStringContainsString('| EQ-001 |', $prc->versions()->firstOrFail()->contenido);
        $this->assertSame(['7.1.5', '9.1.1', '9.1.1'], $prc->requirements()->pluck('referencia')->sort()->values()->all());

        $hojas = $docs['Hoja de vida y calibración de equipos de medición']->versions()->firstOrFail()->contenido;
        $this->assertStringContainsString('Metrolab (acreditado ONAC)', $hojas);
        $this->assertStringContainsString('CC-123', $hojas);
    }

    public function test_el_informe_trae_la_seccion(): void
    {
        $vencido = $this->equipo();
        $this->calibrar($vencido, ['fecha' => '2025-01-10']);
        $this->equipo(['codigo' => 'EQ-002', 'nombre' => 'Luxómetro']);

        $this->app->forgetInstance(TenantContext::class);
        app(TenantContext::class)->set($this->empresa);
        $datos = app(InformeGestion::class)->generar($this->consultor, Periodo::desde('2025-01-01', '2025-12-31'), ['equipos-medicion']);

        $cifras = collect($datos['secciones'][0]['cifras'])->pluck('valor', 'etiqueta');
        $this->assertSame('2', $cifras['Equipos en uso']);
        $this->assertSame('2', $cifras['Vencidos o sin calibrar']);
        $this->assertSame('1', $cifras['Calibraciones y verificaciones en el periodo']);
    }
}
