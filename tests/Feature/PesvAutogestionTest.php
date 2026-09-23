<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Employee;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\PesvAutogestion;
use App\Models\PesvInfraction;
use App\Models\PesvKmPeriodo;
use App\Models\PesvPlan;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PesvFeed;
use App\Services\Reportes\ReporteAutogestion;
use App\Support\TenantContext;
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\PesvCriteriaSeeder;
use Database\Seeders\PesvStepsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PESV · paso 20: reporte de autogestión anual (literales a–l y Tabla 10).
 */
class PesvAutogestionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-01-20 10:00');   // enero: se reporta el año que cerró
        $this->seed([RolesAndPermissionsSeeder::class, PesvStepsSeeder::class, PesvCriteriaSeeder::class, IndicatorsSeeder::class]);
        $this->empresa = Tenant::create([
            'name' => 'Transportes Demo', 'legal_name' => 'Transportes Demo S.A.S.', 'nit' => '900123456-1',
            'address' => 'Calle 1 # 2-3', 'city' => 'Bogotá', 'phone' => '6011234567', 'representante_legal' => 'María Ruiz',
            'modulos' => ['pesv'],
        ]);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');

        $this->en(function () {
            PesvPlan::create(['nivel' => 'basico', 'misionalidad' => 2, 'lider_nombre' => 'Ana Pérez', 'lider_cargo' => 'Coordinadora HSE']);
            PesvVehicle::create(['placa' => 'ABC123', 'tipo' => 'camioneta', 'propiedad' => 'propio', 'is_active' => true]);
            PesvVehicle::create(['placa' => 'DEF456', 'tipo' => 'camioneta', 'propiedad' => 'arrendado', 'is_active' => true]);
            PesvVehicle::create(['placa' => 'GHI789', 'tipo' => 'motocicleta', 'propiedad' => 'colaborador', 'is_active' => true]);
            Employee::create(['nombres' => 'Luis', 'apellidos' => 'Gómez', 'numero_documento' => '1', 'is_active' => true, 'es_conductor' => true]);
            Employee::create(['nombres' => 'Eva', 'apellidos' => 'Díaz', 'numero_documento' => '2', 'is_active' => true, 'es_conductor' => true]);
        });
    }

    private function web()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function en(callable $fn, ?Tenant $tenant = null): mixed
    {
        app(TenantContext::class)->set($tenant ?? $this->empresa);
        $r = $fn();
        app()->forgetInstance(TenantContext::class);

        return $r;
    }

    private function informe(int $anio = 2026): array
    {
        return $this->en(fn () => app(ReporteAutogestion::class)->generar(
            $this->empresa, PesvPlan::firstOrFail(), PesvAutogestion::firstWhere('anio', $anio) ?? new PesvAutogestion(['anio' => $anio]), $anio, 'Consultor',
        ));
    }

    private function seccion(array $informe, string $inicio): array
    {
        return collect($informe['secciones'])->first(fn ($s) => str_starts_with($s['titulo'], $inicio));
    }

    private function cifras(array $seccion): array
    {
        return collect($seccion['cifras'])->pluck('valor', 'etiqueta')->all();
    }

    public function test_arma_los_literales_con_los_datos_del_modulo(): void
    {
        $informe = $this->informe();

        $this->assertSame('Reporte de autogestión del PESV', $informe['titulo']);
        $this->assertSame(['Transportes Demo S.A.S.', '900123456-1', 'Calle 1 # 2-3, Bogotá', '6011234567'],
            array_values($this->cifras($this->seccion($informe, 'a)'))));
        $this->assertSame('Ana Pérez', $this->cifras($this->seccion($informe, 'b)'))['Nombre']);
        $this->assertSame('María Ruiz', $this->cifras($this->seccion($informe, 'd)'))['Nombre']);
        $this->assertSame('Básico', $this->cifras($this->seccion($informe, 'e)'))['Nivel del PESV']);

        // f) flota por tipo y propiedad: Tipo, propio, arrendado, leasing, contratista, colaborador, total
        $flota = $this->seccion($informe, 'f)')['tablas'][0]['filas'];
        $this->assertSame(['Camioneta', '1', '1', '0', '0', '0', '2'], $flota[0]);
        $this->assertSame(['Motocicleta', '0', '0', '0', '0', '1', '1'], $flota[1]);

        // Los 24 pasos van en el literal l).
        $this->assertCount(24, $this->seccion($informe, 'l)')['tablas'][0]['filas']);
    }

    public function test_lista_lo_que_falta_y_deja_de_pedirlo_al_completarlo(): void
    {
        $pendiente = collect($this->informe()['atencion'])->pluck('texto');
        $this->assertContains('Falta el correo institucional del líder del PESV.', $pendiente);
        $this->assertTrue($pendiente->contains(fn ($t) => str_starts_with($t, 'Registra el nombre, cargo y correo')));
        $this->assertContains('Faltan los objetivos y metas propuestos para 2027.', $pendiente);

        $this->web()->put('/pesv/autogestion', [
            'anio' => 2026, 'lider_email' => 'ana@transportes.co',
            'auditores' => [['nombre' => 'Pedro Auditor', 'cargo' => 'Auditor externo', 'email' => 'pedro@audita.co']],
            'objetivos_siguiente' => 'Reducir la TSV en 10 %', 'programas_siguiente' => 'Programa de fatiga',
            'analisis' => 'El comité revisó los indicadores.', 'entidad_verificadora' => 'mintrabajo',
        ])->assertSessionHasNoErrors();

        $pendiente = collect($this->informe()['atencion'])->pluck('texto');
        $this->assertNotContains('Falta el correo institucional del líder del PESV.', $pendiente);
        $this->assertFalse($pendiente->contains(fn ($t) => str_starts_with($t, 'Registra el nombre, cargo y correo')));
        $this->assertNotContains('Faltan los objetivos y metas propuestos para 2027.', $pendiente);
    }

    public function test_valida_los_datos_a_mano(): void
    {
        $this->web()->put('/pesv/autogestion', [
            'anio' => 2026, 'lider_email' => 'no-es-correo', 'auditores' => [['nombre' => '', 'email' => 'x']],
            'entidad_verificadora' => 'inventada', 'reportado_at' => '2027-03-01',
        ])->assertSessionHasErrors(['lider_email', 'auditores.0.nombre', 'auditores.0.email', 'entidad_verificadora', 'reportado_at']);
    }

    public function test_tabla_10_calcula_tsv_indicadores_e_infracciones_y_marca_lo_que_no_aplica(): void
    {
        $this->en(function () {
            PesvSiniestro::create(['fecha' => '2026-02-10', 'tipo' => 'choque', 'gravedad' => 'solo_danos', 'descripcion' => 'x']);
            PesvKmPeriodo::create(['anio' => 2026, 'trimestre' => 1, 'km' => 200000]);
            PesvKmPeriodo::create(['anio' => 2026, 'trimestre' => 2, 'km' => 300000]);
            $idp = Indicator::where('codigo', 'PESV-IDP')->firstOrFail();
            IndicatorReading::create(['indicator_id' => $idp->id, 'anio' => 2026, 'mes' => 1, 'numerador' => 18, 'denominador' => 20]);
            IndicatorReading::create(['indicator_id' => $idp->id, 'anio' => 2026, 'mes' => 2, 'numerador' => 20, 'denominador' => 20]);
            $conductor = Employee::firstOrFail()->id;
            PesvInfraction::create(['employee_id' => $conductor, 'fecha' => '2026-05-01', 'codigo' => 'C02', 'descripcion' => 'Estacionar en sitio prohibido', 'valor' => 604100]);
            PesvInfraction::create(['employee_id' => $conductor, 'fecha' => '2026-06-01', 'codigo' => 'C02', 'descripcion' => 'Estacionar en sitio prohibido', 'valor' => 604100]);
            PesvInfraction::create(['employee_id' => $conductor, 'fecha' => '2025-06-01', 'codigo' => 'D12', 'descripcion' => 'Otro año', 'valor' => 1]);
        });

        $informe = $this->informe();
        $t10 = collect($this->seccion($informe, 'Indicadores de gestión')['tablas'][0]['filas'])->keyBy(0);

        // 1 choque simple en 500.000 km -> 1 × 1.000.000 / 500.000 = 2
        $this->assertStringContainsString('Leve: 2,00', $t10['1'][3]);
        $this->assertSame('No aplica al nivel básico', $t10['2'][3]);
        $this->assertSame('No aplica al nivel básico', $t10['7'][3]);
        $this->assertSame('No aplica al nivel básico', $t10['8'][3]);
        // IDP acumulado = 38 / 40 × 100
        $this->assertSame('95,00 %', $t10['9'][3]);
        $this->assertStringStartsWith('No cumple', $t10['9'][5]);
        $this->assertSame('Sin medición', $t10['4'][3]);

        $k = $this->seccion($informe, 'k)');
        $this->assertSame('2', $this->cifras($k)['Infracciones en 2026']);
        $this->assertSame(['C02', 'Estacionar en sitio prohibido', '2', '$ 1.208.200'], $k['tablas'][0]['filas'][0]);
    }

    public function test_ncac_sale_de_las_auditorias_del_pesv_si_no_hay_lecturas(): void
    {
        $this->en(function () {
            $a = Audit::create(['tipo' => 'interna', 'objetivo' => 'Auditoría interna al PESV', 'fecha_programada' => '2026-10-01',
                'fecha_fin' => '2026-10-03', 'auditor_lider' => 'Pedro', 'estado' => 'cerrada', 'conclusiones' => 'PESV implementado.']);
            $a->findings()->create(['tipo' => 'no_conformidad_menor', 'descripcion' => 'Sin acta del comité']);
            $a->findings()->create(['tipo' => 'observacion', 'descripcion' => 'Mejorar archivo']);
        });

        $informe = $this->informe();
        $t10 = collect($this->seccion($informe, 'Indicadores de gestión')['tablas'][0]['filas'])->keyBy(0);
        $this->assertSame('0,00 % (0 de 1)', $t10['13'][3]);

        $l = $this->cifras($this->seccion($informe, 'l)'));
        $this->assertSame('Pedro', $l['Auditor líder']);
        $this->assertSame('1', $l['No conformidades']);
    }

    public function test_descarga_word_y_pdf(): void
    {
        $word = $this->web()->get('/pesv/autogestion/descargar?anio=2026&formato=word')->assertOk();
        $this->assertStringContainsString('reporte-autogestion-pesv-transportes-demo-2026.docx', $word->headers->get('content-disposition'));

        $pdf = $this->web()->get('/pesv/autogestion/descargar?anio=2026&formato=pdf')->assertOk();
        $this->assertStringContainsString('.pdf', $pdf->headers->get('content-disposition'));

        $this->web()->get('/pesv/autogestion/descargar?anio=2026&formato=exe')->assertSessionHasErrors('formato');
    }

    public function test_la_pantalla_arranca_en_el_anio_que_cerro_y_hereda_los_datos_del_anterior(): void
    {
        $this->en(fn () => PesvAutogestion::create(['anio' => 2025, 'lider_email' => 'ana@transportes.co',
            'auditores' => [['nombre' => 'Pedro', 'cargo' => 'Auditor', 'email' => 'p@a.co']], 'reportado_at' => '2026-01-15']));

        $this->web()->get('/pesv/autogestion')->assertOk()->assertInertia(fn ($p) => $p
            ->component('pesv/autogestion')
            ->where('anio', 2026)
            ->where('datos.lider_email', 'ana@transportes.co')
            ->where('datos.auditores.0.nombre', 'Pedro')
            ->where('datos.reportado_at', null)
            ->where('entidadSugerida', 'mintrabajo')
            ->has('informe.secciones', 13)
            ->has('historial', 1)
        );
    }

    public function test_la_hoja_del_paso_20_sabe_si_se_radico(): void
    {
        $insumo = fn () => collect($this->en(fn () => (new PesvFeed($this->empresa))->paraPaso(20)))->firstWhere('etiqueta', 'Reporte de autogestión 2026');

        $this->assertSame('parcial', $insumo()['estado']);   // enero: aún en plazo

        $this->web()->put('/pesv/autogestion', ['anio' => 2026, 'reportado_at' => '2027-01-18', 'radicado' => 'MT-123'])->assertSessionHasNoErrors();
        $this->assertSame('ok', $insumo()['estado']);
        $this->assertStringContainsString('MT-123', $insumo()['detalle']);
    }

    public function test_cada_empresa_ve_solo_su_reporte(): void
    {
        $otra = Tenant::create(['name' => 'Otra SAS', 'nit' => '800', 'modulos' => ['pesv']]);
        $this->en(function () {
            PesvAutogestion::create(['anio' => 2026, 'lider_email' => 'secreto@otra.co']);
            $conductor = Employee::create(['nombres' => 'X', 'apellidos' => 'Y', 'numero_documento' => '9', 'is_active' => true])->id;
            PesvInfraction::create(['employee_id' => $conductor, 'fecha' => '2026-05-01', 'codigo' => 'X99', 'descripcion' => 'Ajena', 'valor' => 1]);
        }, $otra);

        $this->web()->get('/pesv/autogestion?anio=2026')->assertOk()->assertInertia(fn ($p) => $p
            ->where('datos.lider_email', null)
            ->where('informe.secciones.10.cifras.0.valor', '0')
        );

        $this->web()->put('/pesv/autogestion', ['anio' => 2026, 'lider_email' => 'propio@demo.co'])->assertSessionHasNoErrors();
        $this->assertSame('secreto@otra.co', PesvAutogestion::withoutGlobalScopes()->where('tenant_id', $otra->id)->value('lider_email'));
    }
}
