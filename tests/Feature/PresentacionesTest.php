<?php

namespace Tests\Feature;

use App\Jobs\GenerarPresentacionJob;
use App\Models\AcpmAction;
use App\Models\Presentation;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\User;
use App\Services\Ai\ContextoCliente;
use App\Services\AiService;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Presentaciones (.pptx) generadas por la IA con el contexto del cliente, y
 * los códigos del mapa documental (M01–M20) en las pantallas.
 */
class PresentacionesTest extends TestCase
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

        $this->empresa = Tenant::create(['name' => 'Constructora Andina', 'nit' => '900123456-1', 'sector' => 'Construcción']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function pedir(array $extra = [])
    {
        return $this->comoConsultor()->post('/presentaciones', array_merge([
            'modulo' => 'M15', 'proposito' => 'gerencia', 'diapositivas' => 5,
            'desde' => now()->subYear()->toDateString(), 'hasta' => now()->toDateString(),
        ], $extra));
    }

    /** La IA simulada: devuelve diapositivas y guarda el prompt que recibió. */
    private function iaSimulada(?string &$prompt): void
    {
        $this->mock(AiService::class, function ($m) use (&$prompt) {
            $m->shouldReceive('herramienta')->once()->andReturnUsing(function ($system, $p) use (&$prompt) {
                $prompt = $p;

                return [
                    'titulo' => 'Acciones correctivas y de mejora',
                    'subtitulo' => 'Seguimiento del periodo',
                    'diapositivas' => [
                        ['tipo' => 'seccion', 'titulo' => 'Estado'],
                        ['tipo' => 'cifras', 'titulo' => 'Cifras', 'cifras' => [['valor' => '1', 'etiqueta' => 'Acciones abiertas']]],
                        ['tipo' => 'tabla', 'titulo' => 'Abiertas', 'tabla' => ['columnas' => ['Código', 'Acción'], 'filas' => [['ACPM-2026-001', 'Señalizar']]]],
                        ['tipo' => 'vinetas', 'titulo' => 'Prioridades', 'puntos' => ['Cerrar a tiempo'], 'notas' => 'Explicar.'],
                        ['tipo' => 'cierre', 'titulo' => 'Decisiones', 'puntos' => ['Asignar recursos']],
                    ],
                ];
            });
        });
    }

    public function test_la_pantalla_carga_con_los_20_modulos_y_el_modulo_pedido(): void
    {
        $this->comoConsultor()->get('/presentaciones?modulo=M09')->assertOk()
            ->assertInertia(fn ($p) => $p->component('presentaciones/index')->has('modulos', 20)->where('moduloInicial', 'M09'));
    }

    public function test_pedirla_la_encola_en_estado_generando(): void
    {
        Queue::fake();
        $this->pedir()->assertSessionHasNoErrors();

        $p = Presentation::withoutTenantScope()->firstOrFail();
        $this->assertSame(['generando', $this->consultor->id], [$p->estado, $p->user_id]);
        Queue::assertPushed(GenerarPresentacionJob::class, fn ($job) => $job->presentationId === $p->id);
    }

    public function test_valida_el_proposito_libre_y_el_periodo(): void
    {
        Queue::fake();
        $this->pedir(['proposito' => 'otro'])->assertSessionHasErrors('instrucciones');
        $this->pedir(['hasta' => now()->addDay()->toDateString()])->assertSessionHasErrors('hasta');
        $this->pedir(['diapositivas' => 40])->assertSessionHasErrors('diapositivas');
        $this->pedir(['modulo' => 'M99'])->assertSessionHasErrors('modulo');
    }

    public function test_genera_el_pptx_con_el_contexto_real_del_cliente(): void
    {
        $accion = new AcpmAction([
            'tipo' => 'correctiva', 'origen_tipo' => 'manual', 'hallazgo' => 'Bodega sin señalización',
            'accion' => 'Señalizar', 'responsable' => 'Jefe de planta', 'fecha_deteccion' => now()->subMonth()->toDateString(),
            'fecha_limite' => now()->addMonth()->toDateString(), 'estado' => 'abierta',
        ]);
        $accion->tenant_id = $this->empresa->id;
        $accion->save();

        $prompt = null;
        $this->iaSimulada($prompt);
        $this->pedir()->assertSessionHasNoErrors();   // la cola de pruebas es síncrona

        $p = Presentation::withoutTenantScope()->firstOrFail();
        $this->assertSame('lista', $p->estado, (string) $p->error);
        $this->assertSame('Acciones correctivas y de mejora', $p->titulo);

        // El contexto lleva la organización, el módulo y cifras reales del informe.
        $this->assertStringContainsString('Constructora Andina', $prompt);
        $this->assertStringContainsString('M15 · Acciones correctivas y mejora (ACPM)', $prompt);
        $this->assertStringContainsString('Construcción', $prompt);
        $this->assertMatchesRegularExpression('/ACPM|acciones/i', $prompt);

        // Un .pptx de verdad: portada + 5 diapositivas.
        $ruta = Storage::disk('local')->path($p->archivo);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $slides = collect(range(0, $zip->numFiles - 1))->filter(fn ($i) => preg_match('#^ppt/slides/slide\d+\.xml$#', $zip->getNameIndex($i)))->count();
        $this->assertSame(6, $slides);
        $this->assertStringContainsString('Constructora Andina', $zip->getFromName('ppt/slides/slide1.xml'));
        $zip->close();

        // Queda en el repositorio de documentos de la empresa y se descarga.
        $this->assertSame('Presentaciones', TenantDocument::withoutTenantScope()->where('path', $p->archivo)->value('categoria'));
        $this->comoConsultor()->get("/presentaciones/{$p->id}/descargar")->assertOk();

        $this->comoConsultor()->delete("/presentaciones/{$p->id}");
        Storage::disk('local')->assertMissing($p->archivo);
        $this->assertSame(0, TenantDocument::withoutTenantScope()->count());
    }

    public function test_si_la_ia_falla_queda_en_error_con_el_motivo(): void
    {
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('herramienta')->andThrow(new RuntimeException('Claude está saturado (529).')));

        try {
            $this->pedir();
        } catch (RuntimeException) {
            // El job síncrono relanza; en la cola real lo atrapa `failed()`.
        }
        $p = Presentation::withoutTenantScope()->firstOrFail();
        (new GenerarPresentacionJob($p->id))->failed(new RuntimeException('Claude está saturado (529).'));

        $p->refresh();
        $this->assertSame('error', $p->estado);
        $this->assertStringContainsString('529', $p->error);
        $this->comoConsultor()->get("/presentaciones/{$p->id}/descargar")->assertNotFound();
    }

    public function test_cada_modulo_del_mapa_sabe_sus_pantallas(): void
    {
        $this->assertEqualsCanonicalizing(
            ['epp', 'salud-ocupacional', 'inspecciones', 'programas', 'mantenimiento', 'equipos-medicion'],
            ContextoCliente::pantallasDe('M09'),
        );
        $this->assertSame(['acpm'], ContextoCliente::pantallasDe('M15'));
    }

    public function test_las_pantallas_reciben_su_codigo_del_mapa(): void
    {
        $this->comoConsultor()->get('/calidad')->assertOk()
            ->assertInertia(fn ($p) => $p->where('codigos_sig.calidad', ['M17'])->where('codigos_sig.pesv.0', 'M10'));
    }
}
