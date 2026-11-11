<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\ChangeRequest;
use App\Models\ChemicalProduct;
use App\Models\Committee;
use App\Models\CommunicationLog;
use App\Models\ContextProfile;
use App\Models\ControlledDocument;
use App\Models\CustomerRequest;
use App\Models\EmergencyDrill;
use App\Models\EmergencyEquipment;
use App\Models\Employee;
use App\Models\MaintenanceAsset;
use App\Models\ManagementReview;
use App\Models\MeasuringEquipment;
use App\Models\MedicalExam;
use App\Models\PesvContractor;
use App\Models\PesvVehicle;
use App\Models\Process;
use App\Models\RiskOpportunity;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\User;
use App\Models\WorkAccident;
use App\Services\Dashboard\Agenda;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SigCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Panel general: agenda de vencimientos de los módulos, tarjetas por módulo y
 * la vista consolidada del consultor con las alertas de todos los clientes.
 */
class DashboardAgendaTest extends TestCase
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

    /** Crea registros de la empresa con su contexto puesto, como lo haría el módulo. */
    private function enEmpresa(Tenant $tenant, callable $fn): mixed
    {
        $ctx = app(TenantContext::class);
        $ctx->set($tenant);
        try {
            return $fn();
        } finally {
            $ctx->set(null);
            $this->app->forgetInstance(TenantContext::class);
        }
    }

    private function accion(Tenant $tenant, string $limite, string $estado = 'abierta'): AcpmAction
    {
        return $this->enEmpresa($tenant, fn () => AcpmAction::create([
            'tipo' => 'correctiva', 'origen_tipo' => 'inspeccion', 'hallazgo' => 'Extintor sin señalizar',
            'accion' => 'Señalizar', 'responsable' => 'Jefe de planta', 'fecha_deteccion' => now()->subMonth()->toDateString(),
            'fecha_limite' => $limite, 'estado' => $estado,
        ]));
    }

    private function extintor(Tenant $tenant, string $vence): EmergencyEquipment
    {
        return $this->enEmpresa($tenant, fn () => EmergencyEquipment::create([
            'ubicacion' => 'Bodega', 'elemento' => 'Extintor ABC 10 lb', 'cantidad' => 1, 'tipo' => 'extintor',
            'estado' => 'bueno', 'fecha_vencimiento' => $vence,
        ]));
    }

    private function comoConsultor(?Tenant $tenant = null)
    {
        $this->app->forgetInstance(TenantContext::class);

        return $this->actingAs($this->consultor)->withSession($tenant ? ['active_tenant_id' => $tenant->id] : []);
    }

    public function test_la_agenda_junta_lo_vencido_y_lo_que_vence_de_varios_modulos(): void
    {
        $this->accion($this->empresa, now()->subDays(10)->toDateString());
        $this->extintor($this->empresa, now()->addDays(12)->toDateString());

        $this->comoConsultor($this->empresa)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('dashboard')->where('view', 'client')
                ->has('agenda', 2)
                ->where('agenda.0.modulo', 'acpm')
                ->where('agenda.0.estado', 'vencido')
                ->where('agenda.0.dias', -10)
                ->where('agenda.1.modulo', 'emergencias')
                ->where('agenda.1.estado', 'por_vencer')
                ->where('agenda_resumen.vencido', 1)
                ->where('agenda_resumen.por_vencer', 1));
    }

    public function test_lo_cerrado_y_lo_lejano_no_salen_pero_lo_vencido_viejo_si(): void
    {
        $this->accion($this->empresa, now()->subYear()->toDateString());               // vencida hace un año: sale
        $this->accion($this->empresa, now()->subDays(3)->toDateString(), 'cerrada');   // cerrada: no sale
        $this->extintor($this->empresa, now()->addDays(Agenda::HORIZONTE + 5)->toDateString()); // fuera del horizonte

        $this->comoConsultor($this->empresa)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->has('agenda', 1)->where('agenda.0.estado', 'vencido'));
    }

    public function test_un_modulo_no_contratado_no_aparece_en_la_agenda_ni_en_las_tarjetas(): void
    {
        $this->empresa->update(['modulos' => ['acpm']]);
        $this->accion($this->empresa, now()->addDays(5)->toDateString());
        $this->extintor($this->empresa, now()->addDays(5)->toDateString());

        $this->comoConsultor($this->empresa)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->has('agenda', 1)->where('agenda.0.modulo', 'acpm')
                ->where('modulos', fn ($modulos) => collect($modulos)->pluck('modulo')->contains('acpm')
                    && ! collect($modulos)->pluck('modulo')->contains('emergencias')));
    }

    public function test_cada_item_lleva_al_registro_para_editarlo(): void
    {
        $vehiculo = $this->enEmpresa($this->empresa, fn () => PesvVehicle::create([
            'placa' => 'ABC123', 'tipo' => 'camioneta', 'soat_vence' => now()->addDays(3)->toDateString(), 'is_active' => true,
        ]));

        $this->comoConsultor($this->empresa)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->where('agenda.0.tipo', 'SOAT')
                ->where('agenda.0.url', route('pesv.vehiculos.show', $vehiculo)));
    }

    public function test_las_tarjetas_de_modulo_traen_las_cifras_del_informe_y_sus_vencimientos(): void
    {
        $this->accion($this->empresa, now()->subDays(2)->toDateString());

        $this->comoConsultor($this->empresa)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->where('modulos', function ($modulos) {
                $acpm = collect($modulos)->firstWhere('modulo', 'acpm');

                return $acpm && count($acpm['cifras']) > 0 && $acpm['vencidos'] === 1 && $acpm['url'] === route('acpm.index');
            }));
    }

    public function test_el_usuario_del_cliente_solo_ve_su_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra S.A.S.', 'nit' => '800000000-1']);
        $this->accion($this->empresa, now()->addDays(4)->toDateString());
        $this->accion($otra, now()->addDays(4)->toDateString());
        $cliente = tap(User::factory()->create(['tenant_id' => $otra->id]))->assignRole('cliente_admin');

        $this->app->forgetInstance(TenantContext::class);
        $this->actingAs($cliente)->get('/dashboard')
            ->assertInertia(fn (Assert $p) => $p->where('view', 'client')->where('tenant.id', $otra->id)->has('agenda', 1));
    }

    public function test_la_vista_consolidada_ordena_clientes_por_vencimientos_y_junta_sus_alertas(): void
    {
        $tranquila = Tenant::create(['name' => 'Aaa Tranquila', 'nit' => '811111111-1']);
        $this->accion($this->empresa, now()->subDays(1)->toDateString());
        $this->extintor($this->empresa, now()->addDays(20)->toDateString());

        $this->comoConsultor()->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('view', 'master')
                ->where('clientes.0.id', $this->empresa->id)
                ->where('clientes.0.vencido', 1)
                ->where('clientes.0.por_vencer', 1)
                ->where('clientes.1.id', $tranquila->id)
                ->where('clientes.1.vencido', 0)
                ->has('alertas', 2)
                ->where('alertas.0.tenant.id', $this->empresa->id));

        // El panel deja la sesión sin cliente: sigue siendo la vista consolidada.
        $this->assertNull(session('active_tenant_id'));
    }

    /**
     * Una fecha en CADA fuente de la agenda. Sin esto, el código que arma el
     * ítem de un módulo solo corre cuando hay datos: un fallo ahí tumbaría el
     * panel entero del primer cliente que registre algo en ese módulo.
     */
    public function test_todas_las_fuentes_producen_su_fecha(): void
    {
        $pronto = now()->addDays(10)->toDateString();
        $hace = fn (int $d) => now()->subDays($d)->toDateString();
        $this->empresa->update(['licencia_sgsst_vence' => $pronto]);

        $this->enEmpresa($this->empresa, function () use ($pronto, $hace) {
            $emp = Employee::create([
                'nombres' => 'Ana', 'apellidos' => 'Pérez', 'tipo_documento' => 'CC', 'numero_documento' => '1010',
                'cargo' => 'Conductor', 'is_active' => true, 'es_conductor' => true, 'licencia_vence' => $pronto,
                'examen_psicosensometrico_vence' => $pronto,
            ]);
            MedicalExam::create(['employee_id' => $emp->id, 'fecha' => $hace(355), 'tipo' => 'periodico', 'concepto' => 'apto', 'proximo_examen' => $pronto]);

            Audit::create(['tipo' => 'interna', 'objetivo' => 'Verificar', 'fecha_programada' => $pronto, 'estado' => 'programada']);

            $cambio = ChangeRequest::create(['fecha_solicitud' => $hace(5), 'solicitante' => 'Ana', 'area' => 'Producción', 'tipo' => 'proceso', 'descripcion' => 'Nueva línea', 'fecha_limite' => $pronto]);
            $cambio->actions()->create(['descripcion' => 'Actualizar IPERC', 'responsable' => 'SST', 'fecha_compromiso' => $pronto, 'ejecutada' => false, 'orden' => 1]);

            Committee::create(['tipo' => 'copasst', 'periodo' => 2024, 'fecha_conformacion' => now()->subYears(2)->addDays(10)->toDateString()]);
            CommunicationLog::create(['fecha' => $hace(2), 'tipo' => 'externa', 'direccion' => 'entrante', 'parte_interesada' => 'Secretaría de Salud', 'asunto' => 'Requerimiento', 'requiere_respuesta' => true, 'fecha_limite_respuesta' => $pronto]);
            ContextProfile::create(['alcance' => 'Todo', 'revisado_at' => now()->subYear()->addDays(10)]);

            $contratista = PesvContractor::create(['nombre' => 'Transportes XYZ', 'is_active' => true]);
            $contratista->documents()->create(['tipo' => 'arl', 'nombre' => 'Afiliación ARL', 'estado' => 'recibido', 'fecha_vencimiento' => $pronto, 'orden' => 1]);
            $contratista->evaluations()->create(['formato' => 'evaluacion', 'uso' => 'evaluacion', 'fecha' => now()->subMonths(4)->addDays(10)->toDateString(),
                'estructura' => [], 'respuestas' => [], 'puntaje' => 90, 'porcentaje' => 90, 'resultado' => 'aceptable']);

            CustomerRequest::create(['fecha' => $hace(3), 'tipo' => 'queja', 'cliente' => 'Cliente S.A.', 'descripcion' => 'Demora', 'fecha_limite' => $pronto, 'estado' => 'abierta']);

            $simulacro = EmergencyDrill::create(['fecha' => $pronto, 'tipo' => 'evacuacion', 'escenario' => 'Sismo', 'estado' => 'programado']);
            $simulacro->recommendations()->create(['descripcion' => 'Despejar salida', 'fecha_limite' => $pronto, 'implementada' => false, 'orden' => 1]);

            $equipo = MeasuringEquipment::create(['codigo' => 'EQ-1', 'nombre' => 'Sonómetro', 'control' => 'calibracion', 'frecuencia_meses' => 12, 'estado' => 'en_uso']);
            $equipo->calibrations()->create(['fecha' => now()->subYear()->addDays(10)->toDateString(), 'tipo' => 'calibracion', 'resultado' => 'conforme']);

            $activo = MaintenanceAsset::create(['tipo' => 'maquina', 'nombre' => 'Compresor', 'activo' => true]);
            $item = $activo->planItems()->create(['actividad' => 'Cambio de aceite', 'frecuencia_valor' => 30, 'frecuencia_unidad' => 'dias', 'orden' => 1]);
            $activo->records()->create(['maintenance_plan_item_id' => $item->id, 'tipo' => 'preventivo', 'fecha' => $hace(20), 'estado' => 'cerrada', 'descripcion' => 'Hecho']);

            Training::create(['titulo' => 'Trabajo en alturas', 'categoria' => 'sst', 'fecha' => $pronto, 'estado' => 'programada']);

            $revision = ManagementReview::create(['periodo_desde' => $hace(365), 'periodo_hasta' => $hace(1), 'sistemas' => ['sst'], 'fecha_reunion' => $pronto]);
            $revision->decisions()->create(['tipo' => 'mejora', 'descripcion' => 'Contratar brigadista', 'fecha_limite' => $pronto, 'estado' => 'pendiente']);

            RiskOpportunity::create(['tipo' => 'riesgo', 'descripcion' => 'Pérdida de cliente', 'probabilidad' => 2, 'impacto' => 2, 'tratamiento' => 'mitigar', 'sistemas' => ['iso9001'], 'fecha_limite' => $pronto, 'estado' => 'abierto']);
            WorkAccident::create(['employee_id' => $emp->id, 'clase' => 'trabajo', 'fecha' => $hace(1), 'descripcion' => 'Caída', 'reportado_arl' => false, 'investigado' => false]);
            ChemicalProduct::create(['nombre' => 'Thinner', 'peligros' => ['inflamable'], 'hds_fecha' => now()->subYears(5)->addDays(10)->toDateString(), 'activo' => true]);
            $proceso = Process::create(['sigla' => 'SST', 'nombre' => 'Gestión SST', 'tipo' => 'apoyo', 'orden' => 1]);
            (new ControlledDocument(['tipo' => 'procedimiento', 'nivel' => 'operativo', 'process_id' => $proceso->id,
                'titulo' => 'Procedimiento de alturas', 'sistemas' => ['sst'], 'frecuencia_revision_meses' => 12]))
                ->forceFill(['codigo' => 'PRC-SST-001', 'estado' => 'vigente', 'proxima_revision' => $pronto])->save();
            PesvVehicle::create(['placa' => 'XYZ987', 'tipo' => 'camion', 'soat_vence' => $pronto, 'tecnomecanica_vence' => $pronto, 'is_active' => true]);
            $this->accion($this->empresa, $pronto);
            $this->extintor($this->empresa, $pronto);
        });

        $esperados = [
            'acpm', 'auditoria', 'gestion-cambio', 'comites', 'comunicaciones', 'contexto', 'contratistas',
            'control-documental', 'calidad', 'emergencias', 'salud-ocupacional', 'equipos-medicion', 'mantenimiento',
            'pesv', 'capacitaciones', 'revision-direccion', 'riesgos-oportunidades', 'accidentes', 'ambiental', 'organizacion',
        ];

        $this->comoConsultor($this->empresa)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('agenda', function ($agenda) use ($esperados) {
                $modulos = collect($agenda)->pluck('modulo')->unique()->values();
                $faltan = array_values(array_diff($esperados, $modulos->all()));
                $this->assertSame([], $faltan, 'Fuentes sin fecha en la agenda: '.implode(', ', $faltan));

                return true;
            }));
    }

    public function test_desde_una_alerta_se_selecciona_el_cliente_y_se_va_al_registro(): void
    {
        $this->comoConsultor()->post(route('clientes.select', $this->empresa), ['ir' => '/acpm'])
            ->assertRedirect('/acpm')->assertSessionHas('active_tenant_id', $this->empresa->id);
    }

    public function test_la_seleccion_no_redirige_fuera_de_la_aplicacion(): void
    {
        foreach (['https://malo.example', '//malo.example', '/\\malo.example'] as $ir) {
            $r = $this->comoConsultor()->from('/clientes')->post(route('clientes.select', $this->empresa), ['ir' => $ir]);
            $this->assertStringNotContainsString('malo.example', $r->headers->get('Location'));
        }
    }
}
