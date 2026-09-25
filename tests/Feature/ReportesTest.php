<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\BrigadeMember;
use App\Models\ChangeRequest;
use App\Models\Committee;
use App\Models\CommunicationLog;
use App\Models\ContractorEvaluation;
use App\Models\EmergencyDrill;
use App\Models\EmergencyEquipment;
use App\Models\Employee;
use App\Models\FormRecord;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IpercRow;
use App\Models\LegalRequirement;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceRecord;
use App\Models\ManagementProgram;
use App\Models\MedicalExam;
use App\Models\PesvContractor;
use App\Models\PesvInfraction;
use App\Models\PesvPlan;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Models\PpeDelivery;
use App\Models\PpeItem;
use App\Models\ProgramPlan;
use App\Models\SafetyReport;
use App\Models\SstDiagnostic;
use App\Models\SstStandard;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\User;
use App\Models\WorkAccident;
use App\Models\WorkPlan;
use App\Models\WorkPlanActivity;
use App\Support\TenantContext;
use Database\Seeders\IndicatorsSeeder;
use Database\Seeders\ManagementProgramsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SstStandardsSeeder;
use Database\Seeders\WorkPlanActivitiesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * Módulo de reportes: el informe de gestión cuenta lo del periodo y solo lo de
 * la empresa, respeta módulos contratados y permisos, y las descargas (Word,
 * PDF, Excel) salen con los mismos datos.
 *
 * Hoy fijo en el 15-jul-2026 y periodo = primer semestre, para que «vencido»
 * y «fuera del periodo» sean deterministas.
 */
class ReportesTest extends TestCase
{
    use RefreshDatabase;

    private const DESDE = '2026-01-01';

    private const HASTA = '2026-06-30';

    private Tenant $empresa;

    private Tenant $otra;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-15 10:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1', 'responsable_sgsst' => 'Ana Ruiz']);
        $this->otra = Tenant::create(['name' => 'Otra SAS', 'nit' => '800111222-3']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
        $this->sembrar();
    }

    /** Crea un registro de la empresa dada sin pasar por la sesión. */
    private function de(Tenant $t, string $clase, array $datos)
    {
        app(TenantContext::class)->set($t);
        $m = $clase::create($datos);
        app()->forgetInstance(TenantContext::class);

        return $m;
    }

    private function sembrar(): void
    {
        $e = $this->empresa;
        $ana = $this->de($e, Employee::class, ['nombres' => 'Ana', 'apellidos' => 'Pérez', 'numero_documento' => '101', 'cargo' => 'Operaria', 'area' => 'Producción', 'is_active' => true]);
        $this->de($e, Employee::class, ['nombres' => 'Luis', 'apellidos' => 'Gómez', 'numero_documento' => '102', 'cargo' => 'Operario', 'area' => 'Producción', 'is_active' => true]);
        $this->de($e, Employee::class, ['nombres' => 'Eva', 'apellidos' => 'Díaz', 'numero_documento' => '103', 'cargo' => 'Auxiliar', 'area' => 'Bodega', 'is_active' => true]);

        // Accidentalidad: 1 AT en marzo (5 días, sin ARL, sin investigar →
        // vencida), 1 incidente en mayo y 1 AT en agosto (fuera del periodo).
        $at = $this->de($e, Absence::class, ['employee_id' => $ana->id, 'tipo' => 'accidente_trabajo', 'fecha_inicio' => '2026-03-10', 'fecha_fin' => '2026-03-14']);
        $this->de($e, WorkAccident::class, ['employee_id' => $ana->id, 'clase' => 'accidente', 'fecha' => '2026-03-10', 'descripcion' => 'Golpe con estiba', 'tipo_lesion' => 'Contusión', 'absence_id' => $at->id, 'reportado_arl' => false, 'investigado' => false]);
        $this->de($e, WorkAccident::class, ['clase' => 'incidente', 'fecha' => '2026-05-02', 'descripcion' => 'Caída de caja', 'investigado' => true, 'reportado_arl' => false]);
        $this->de($e, WorkAccident::class, ['clase' => 'accidente', 'fecha' => '2026-08-01', 'descripcion' => 'Corte', 'investigado' => false, 'reportado_arl' => true]);

        // Ausentismo: 5 (AT) + 3 (enfermedad) + 1 (permiso) en el periodo; julio fuera.
        $this->de($e, Absence::class, ['employee_id' => $ana->id, 'tipo' => 'enfermedad_general', 'fecha_inicio' => '2026-04-06', 'fecha_fin' => '2026-04-08']);
        $this->de($e, Absence::class, ['employee_id' => $ana->id, 'tipo' => 'permiso', 'fecha_inicio' => '2026-05-20', 'fecha_fin' => '2026-05-20']);
        $this->de($e, Absence::class, ['employee_id' => $ana->id, 'tipo' => 'enfermedad_general', 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-10']);

        // ACPM: una vencida (límite 31-mar) y una cerrada eficaz en mayo.
        $this->de($e, AcpmAction::class, ['tipo' => 'correctiva', 'origen_tipo' => 'accidente', 'hallazgo' => 'Piso resbaloso en bodega', 'accion' => 'Instalar antideslizante', 'responsable' => 'Jefe de bodega', 'fecha_deteccion' => '2026-02-10', 'fecha_limite' => '2026-03-31', 'estado' => 'abierta']);
        $this->de($e, AcpmAction::class, ['tipo' => 'mejora', 'origen_tipo' => 'manual', 'hallazgo' => 'Señalización', 'accion' => 'Señalizar rutas', 'responsable' => 'SST', 'fecha_deteccion' => '2026-04-01', 'fecha_limite' => '2026-05-30', 'estado' => 'cerrada', 'fecha_cierre' => '2026-05-15', 'eficaz' => true]);

        // Capacitaciones: realizada en abril (4 convocados, 3 asistieron, 2 de
        // 3 aprobaron) y programada en junio (2 convocados, no cuentan aún).
        $realizada = $this->de($e, Training::class, ['titulo' => 'Trabajo en alturas', 'fecha' => '2026-04-15', 'estado' => 'realizada', 'evalua_eficacia' => true, 'nota_minima' => 70, 'modalidad' => 'presencial', 'categoria' => 'SST']);
        foreach ([[true, 90], [true, 80], [true, 50], [false, null]] as $i => [$asistio, $nota]) {
            $realizada->attendees()->create(['nombres' => "Asistente {$i}", 'numero_documento' => "20{$i}", 'asistio' => $asistio, 'nota' => $nota]);
        }
        $programada = $this->de($e, Training::class, ['titulo' => 'Primeros auxilios', 'fecha' => '2026-06-20', 'estado' => 'programada', 'modalidad' => 'presencial', 'categoria' => 'SST']);
        $programada->attendees()->create(['nombres' => 'X', 'asistio' => false]);
        $programada->attendees()->create(['nombres' => 'Y', 'asistio' => false]);

        // La otra empresa: nada de esto puede aparecer.
        $this->de($this->otra, WorkAccident::class, ['clase' => 'accidente', 'fecha' => '2026-02-01', 'descripcion' => 'Ajeno', 'mortal' => true, 'investigado' => false, 'reportado_arl' => false]);
        $ajena = $this->de($this->otra, Training::class, ['titulo' => 'Capacitación ajena', 'fecha' => '2026-03-01', 'estado' => 'realizada', 'modalidad' => 'presencial', 'categoria' => 'SST']);
        $ajena->attendees()->create(['nombres' => 'Persona de otra empresa', 'numero_documento' => '999', 'asistio' => true]);
    }

    private function como(User $u): static
    {
        app()->forgetInstance(TenantContext::class);

        return $this->actingAs($u)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function informe(User $u, array $params = []): array
    {
        $res = $this->como($u)->get('/reportes?'.http_build_query(['desde' => self::DESDE, 'hasta' => self::HASTA, ...$params]))->assertOk();

        return $res->viewData('page')['props'];
    }

    /** Valor de una cifra por sección y etiqueta. */
    private static function cifra(array $informe, string $seccion, string $etiqueta): ?string
    {
        $s = collect($informe['secciones'])->firstWhere('clave', $seccion);

        return collect($s['cifras'] ?? [])->firstWhere('etiqueta', $etiqueta)['valor'] ?? null;
    }

    public function test_el_informe_cuenta_solo_lo_del_periodo_y_de_la_empresa(): void
    {
        $i = $this->informe($this->consultor)['informe'];

        $this->assertSame('Empresa Demo', $i['empresa']['nombre']);
        $this->assertSame('3', self::cifra($i, 'empresa', 'Trabajadores activos'));

        // Accidentalidad: agosto y la otra empresa (mortal) no cuentan.
        $this->assertSame('1', self::cifra($i, 'accidentes', 'Accidentes de trabajo'));
        $this->assertSame('1', self::cifra($i, 'accidentes', 'Incidentes'));
        $this->assertSame('0', self::cifra($i, 'accidentes', 'Accidentes mortales'));
        $this->assertSame('5', self::cifra($i, 'accidentes', 'Días perdidos'));
        $this->assertSame('1', self::cifra($i, 'accidentes', 'Sin reportar a la ARL'));
        $this->assertSame('1', self::cifra($i, 'accidentes', 'Investigación vencida'));

        $this->assertSame('9', self::cifra($i, 'ausentismo', 'Días de ausencia'));
        $this->assertSame('8', self::cifra($i, 'ausentismo', 'Días por causa médica'));

        $this->assertSame('1', self::cifra($i, 'acpm', 'Vencidas hoy'));
        $this->assertSame('1', self::cifra($i, 'acpm', 'Cerradas en el periodo'));

        $this->assertSame('2', self::cifra($i, 'capacitaciones', 'Programadas en el periodo'));
        $this->assertSame('50 %', self::cifra($i, 'capacitaciones', 'Ejecución'));
        $this->assertSame('75 %', self::cifra($i, 'capacitaciones', 'Cobertura (asistieron / convocados)'));
        $this->assertSame('66,7 %', self::cifra($i, 'capacitaciones', 'Eficacia (aprobaron / evaluados)'));

        // Los puntos de atención reúnen las alertas de todas las secciones.
        $atencion = collect($i['atencion'])->pluck('texto')->implode(' | ');
        $this->assertStringContainsString('sin reporte a la ARL', $atencion);
        $this->assertStringContainsString('sin investigar', $atencion);
        $this->assertStringContainsString('vencida', $atencion);
        $this->assertStringNotContainsString('mortal', $atencion);

        // Periodo invertido: error de validación, no un informe vacío.
        $this->como($this->consultor)->get('/reportes?desde=2026-06-30&hasta=2026-01-01')->assertSessionHasErrors('hasta');
    }

    public function test_respeta_los_modulos_contratados_y_los_permisos(): void
    {
        $this->empresa->update(['modulos' => ['reportes', 'accidentes', 'inspecciones', 'auditoria']]);
        $props = $this->informe($this->consultor);
        $this->assertSame(['empresa', 'accidentes', 'inspecciones', 'auditoria'], array_column($props['secciones'], 'clave'));
        $this->assertSame(['empleados', 'accidentes', 'formatos', 'hallazgos-auditoria'], array_column($props['exportaciones'], 'clave'));
        $this->como($this->consultor)->get('/reportes/exportar/ausentismo')->assertNotFound();
        $this->como($this->consultor)->get('/reportes/exportar/no-existe')->assertNotFound();

        // Usuario del cliente: ve el informe, pero sin inspecciones ni
        // auditoría (no tiene esos permisos) y no puede descargar.
        $usuario = tap(User::factory()->create(['tenant_id' => $this->empresa->id]))->assignRole('cliente_usuario');
        $props = $this->informe($usuario);
        $this->assertSame(['empresa', 'accidentes'], array_column($props['secciones'], 'clave'));
        $this->assertFalse($props['canGenerate']);
        $this->como($usuario)->post('/reportes/informe', ['formato' => 'pdf'])->assertForbidden();
        $this->como($usuario)->get('/reportes/exportar/accidentes')->assertForbidden();

        // Sin el módulo de reportes contratado, no entra.
        $this->empresa->update(['modulos' => ['accidentes']]);
        $this->como($this->consultor)->get('/reportes')->assertForbidden();
    }

    public function test_descarga_el_informe_en_word_y_pdf(): void
    {
        $datos = ['desde' => self::DESDE, 'hasta' => self::HASTA, 'observaciones' => "Prioridad: cerrar la investigación de marzo.\n\nSegundo punto."];

        $word = $this->como($this->consultor)->post('/reportes/informe', [...$datos, 'formato' => 'word'])->assertOk();
        $ruta = $this->archivo($word);
        $this->assertStringEndsWith('informe-gestion-empresa-demo-2026-01-01-a-2026-06-30.docx', $ruta);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertStringContainsString('INFORME DE GESTIÓN DEL SG-SST', $xml);
        $this->assertStringContainsString('cerrar la investigación de marzo', $xml);
        $this->assertStringContainsString('Accidentalidad', $xml);
        $this->assertStringNotContainsString('Otra SAS', $xml);

        $pdf = $this->como($this->consultor)->post('/reportes/informe', [...$datos, 'formato' => 'pdf', 'secciones' => ['empresa', 'accidentes']])->assertOk();
        $contenido = file_get_contents($this->archivo($pdf));
        $this->assertStringStartsWith('%PDF', $contenido);
        $this->assertGreaterThan(5000, strlen($contenido));

        $this->como($this->consultor)->post('/reportes/informe', [...$datos, 'formato' => 'odt'])->assertSessionHasErrors('formato');
    }

    public function test_exporta_a_excel_del_periodo_o_completo_y_sin_datos_ajenos(): void
    {
        $filas = $this->excel('accidentes', ['desde' => self::DESDE, 'hasta' => self::HASTA]);
        $this->assertSame('Código', $filas[4]['A']);
        $this->assertCount(2, array_slice($filas, 4));   // marzo y mayo, sin agosto
        $this->assertStringContainsString('Empresa Demo', $filas[2]['A']);
        $this->assertSame('10/03/2026', $filas[5]['B']);   // fecha real de Excel, con formato

        $this->assertCount(3, array_slice($this->excel('accidentes', ['todo' => 1]), 4));

        // Asistentes no tienen tenant propio: se filtran por su capacitación.
        $asistentes = array_slice($this->excel('asistencia', ['desde' => self::DESDE, 'hasta' => self::HASTA]), 4);
        $this->assertCount(6, $asistentes);
        $this->assertNotContains('Persona de otra empresa', array_column($asistentes, 'F'));

        // Las de estado (nómina) salen completas aunque haya periodo.
        $this->assertCount(3, array_slice($this->excel('empleados', ['desde' => self::DESDE, 'hasta' => self::HASTA]), 4));
    }

    /**
     * Un registro en CADA módulo: todas las secciones y todas las exportaciones
     * corren con datos reales (no solo con tablas vacías) y el PDF completo sale.
     */
    public function test_todas_las_secciones_y_exportaciones_funcionan_con_datos(): void
    {
        $this->seed([SstStandardsSeeder::class, WorkPlanActivitiesSeeder::class, IndicatorsSeeder::class, ManagementProgramsSeeder::class]);
        $e = $this->empresa;
        $ana = Employee::withoutTenantScope()->where('numero_documento', '101')->first();

        $d = $this->de($e, SstDiagnostic::class, ['fecha' => '2026-05-01', 'evaluador' => 'Consultor']);
        foreach (SstStandard::limit(5)->get() as $i => $std) {
            $d->items()->create(['sst_standard_id' => $std->id, 'estado' => $i < 3 ? 'cumple' : 'no_cumple']);
        }
        $d->recalcular();

        $plan = $this->de($e, WorkPlan::class, ['anio' => 2026, 'responsable' => 'Ana Ruiz']);
        $plan->items()->create(['work_plan_activity_id' => WorkPlanActivity::first()->id, 'meses_programados' => [2, 3, 8], 'meses_ejecutados' => [2]]);

        $this->de($e, IndicatorReading::class, ['indicator_id' => Indicator::where('codigo', 'AUS-CM')->value('id'), 'anio' => 2026, 'mes' => 3, 'numerador' => 5, 'denominador' => 100]);
        $this->de($e, SafetyReport::class, ['fecha' => '2026-03-02', 'reportado_por' => 'Luis', 'tipo' => 'condicion', 'descripcion' => 'Cable suelto', 'severidad' => 'critico', 'estado' => 'reportado']);
        $this->de($e, FormRecord::class, ['codigo' => 'FT-01', 'titulo' => 'Inspección de extintores', 'categoria' => 'SST', 'grupo' => 'inspeccion', 'schema' => ['secciones' => []], 'data' => ['items' => [['item' => 'Presión', 'estado' => 'no_cumple']]], 'estado' => 'completado', 'fecha' => '2026-04-01']);
        $this->de($e, MedicalExam::class, ['employee_id' => $ana->id, 'fecha' => '2025-01-10', 'tipo' => 'periodico', 'concepto' => 'apto_con_restricciones', 'proximo_examen' => '2026-01-10']);
        $this->de($e, LegalRequirement::class, ['norma' => 'Decreto 1072', 'anio' => 2015, 'requisito' => 'Implementar el SG-SST', 'aplica' => true, 'cumplimiento' => 'no_cumple']);
        $this->de($e, IpercRow::class, ['proceso' => 'Producción', 'actividad' => 'Corte', 'peligro' => 'Mecánico', 'clasificacion' => 'mecanico', 'nd' => 10, 'ne' => 4, 'nc' => 25, 'expuestos' => 3]);

        $sim = $this->de($e, EmergencyDrill::class, ['fecha' => '2026-05-10', 'tipo' => 'interno', 'escenario' => 'Sismo', 'estado' => 'realizado', 'convocados' => 10, 'participantes' => 7, 'tiempo_evacuacion_segundos' => 190]);
        $sim->recommendations()->create(['descripcion' => 'Señalizar salida', 'implementada' => false]);
        $this->de($e, BrigadeMember::class, ['nombres' => 'Ana Pérez', 'rol' => 'lider', 'activo' => true, 'curso_primer_respondiente' => false]);
        $this->de($e, EmergencyEquipment::class, ['ubicacion' => 'Bodega', 'elemento' => 'Extintor', 'tipo' => 'extintor', 'estado' => 'bueno', 'fecha_vencimiento' => '2026-02-01']);

        $comite = $this->de($e, Committee::class, ['tipo' => 'copasst', 'periodo' => '2025-2027', 'fecha_conformacion' => '2025-03-01', 'numero_trabajadores' => 3]);
        $comite->activities()->create(['descripcion' => 'Reunión mensual', 'programada' => true, 'ejecutada' => true, 'fecha_ejecucion' => '2026-02-15']);

        $epp = $this->de($e, PpeItem::class, ['nombre' => 'Casco', 'categoria' => 'cabeza', 'activo' => true]);
        $this->de($e, PpeDelivery::class, ['employee_id' => $ana->id, 'ppe_item_id' => $epp->id, 'fecha_entrega' => '2026-02-01', 'cantidad' => 1, 'motivo' => 'dotacion']);

        $activo = $this->de($e, MaintenanceAsset::class, ['tipo' => 'maquina', 'nombre' => 'Sierra', 'activo' => true]);
        $item = $activo->planItems()->create(['actividad' => 'Lubricación', 'frecuencia_valor' => 30, 'frecuencia_unidad' => 'dias']);
        $this->de($e, MaintenanceRecord::class, ['maintenance_asset_id' => $activo->id, 'maintenance_plan_item_id' => $item->id, 'fecha' => '2026-01-05', 'tipo' => 'preventivo', 'descripcion' => 'Lubricado', 'valor' => 120000, 'estado' => 'cerrada']);

        $contratista = $this->de($e, PesvContractor::class, ['nombre' => 'Transportes ABC', 'tipo' => 'contratista', 'persona' => 'juridica', 'is_active' => true]);
        $this->de($e, ContractorEvaluation::class, ['pesv_contractor_id' => $contratista->id, 'formato' => 'evaluacion', 'uso' => 'evaluacion', 'estructura' => [], 'respuestas' => [], 'fecha' => '2026-03-01', 'puntaje' => 40, 'porcentaje' => 40, 'resultado' => 'no_confiable', 'incumplimientos' => 3]);

        $this->de($e, ChangeRequest::class, ['fecha_solicitud' => '2026-02-20', 'solicitante' => 'Gerencia', 'area' => 'Logística', 'tipo' => 'infraestructura', 'condicion' => 'fijo', 'descripcion' => 'Nueva bodega', 'fecha_limite' => '2026-04-30']);
        $prog = $this->de($e, ProgramPlan::class, ['management_program_id' => ManagementProgram::first()->id, 'anio' => 2026, 'codigo' => 'PVE-01', 'nombre' => 'PVE osteomuscular', 'categoria' => 'pve']);
        $prog->activities()->create(['nombre' => 'Pausas activas', 'fase' => 'hacer', 'meses_programados' => [1, 2, 3], 'meses_ejecutados' => [1, 2]]);
        $aud = $this->de($e, Audit::class, ['tipo' => 'interna', 'objetivo' => 'Verificar el SG-SST', 'fecha_programada' => '2026-06-01', 'estado' => 'cerrada']);
        $aud->findings()->create(['tipo' => 'no_conformidad_menor', 'descripcion' => 'Sin actas del COPASST']);

        $this->de($e, PesvPlan::class, ['nivel' => 'estandar', 'avance' => 40]);
        $carro = $this->de($e, PesvVehicle::class, ['placa' => 'ABC123', 'tipo' => 'camion', 'propiedad' => 'propio', 'soat_vence' => '2026-06-01', 'is_active' => true]);
        $this->de($e, PesvSiniestro::class, ['fecha' => '2026-04-04', 'tipo' => 'choque', 'gravedad' => 'con_heridos', 'pesv_vehicle_id' => $carro->id, 'descripcion' => 'Choque en patio']);
        $this->de($e, PesvInfraction::class, ['employee_id' => $ana->id, 'pesv_vehicle_id' => $carro->id, 'fecha' => '2026-03-03', 'codigo' => 'C29', 'estado' => 'pendiente']);

        $this->de($e, CommunicationLog::class, ['fecha' => '2026-03-10', 'tipo' => 'externa', 'direccion' => 'entrante', 'parte_interesada' => 'ARL', 'asunto' => 'Solicitud de soportes', 'requiere_respuesta' => true, 'fecha_limite_respuesta' => '2026-03-20']);

        $i = $this->informe($this->consultor)['informe'];
        $this->assertCount(26, $i['secciones']);
        foreach ($i['secciones'] as $s) {
            $this->assertNotEmpty($s['cifras'] ?: $s['notas'], "La sección {$s['clave']} salió vacía");
        }
        $this->assertSame('1', self::cifra($i, 'mantenimiento', 'Mantenimientos vencidos'));
        $this->assertSame('1', self::cifra($i, 'contratistas', 'No confiables'));
        $this->assertSame('1', self::cifra($i, 'comunicaciones', 'Respuestas vencidas'));
        $this->assertSame('1', self::cifra($i, 'inspecciones', 'Ítems «no cumple» encontrados'));
        $this->assertSame('1', self::cifra($i, 'salud-ocupacional', 'Trabajadores con examen vencido'));
        $this->assertSame('1', self::cifra($i, 'pesv', 'Vehículos con documentos vencidos'));
        $this->assertSame('50 %', self::cifra($i, 'plan-trabajo', 'Cumplimiento del plan'));   // feb sí, mar no; ago fuera

        foreach ($this->informe($this->consultor)['exportaciones'] as $x) {
            $this->assertGreaterThan(4, count($this->excel($x['clave'], ['todo' => 1])), "Exportación {$x['clave']} vacía");
        }
        $pdf = $this->como($this->consultor)->post('/reportes/informe', ['desde' => self::DESDE, 'hasta' => self::HASTA, 'formato' => 'pdf'])->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($this->archivo($pdf)));
    }

    public function test_sin_empresa_activa_pide_elegirla(): void
    {
        $this->actingAs($this->consultor)->get('/reportes')->assertOk()
            ->assertInertia(fn ($p) => $p->component('reportes/index')->where('needsClient', true));
    }

    /** @return array<int, array<string, mixed>> filas de la hoja, con índice de Excel */
    private function excel(string $clave, array $params): array
    {
        $res = $this->como($this->consultor)->get("/reportes/exportar/{$clave}?".http_build_query($params))->assertOk();

        return IOFactory::load($this->archivo($res))->getActiveSheet()->toArray(null, true, true, true);
    }

    private function archivo(TestResponse $res): string
    {
        return $res->baseResponse->getFile()->getPathname();
    }
}
