<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\DataImport;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\TrainingAttendee;
use App\Models\User;
use App\Support\Importacion\Aplicador;
use App\Support\Importacion\Destinos;
use App\Support\Importacion\LectorTabular;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;
use ZipArchive;

/**
 * Los destinos de la segunda tanda (ausentismo, vehículos del PESV y
 * asistentes a una capacitación) y los formatos .xls / .ods.
 */
class ImportacionDestinosTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config(['ai.anthropic.api_key' => 'sk-test']);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
        app(TenantContext::class)->set($this->empresa);
    }

    private function comoConsultor()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function empleado(string $nombres, string $apellidos, string $doc, ?Tenant $de = null, ?string $cargo = 'Operario'): Employee
    {
        $e = new Employee(['nombres' => $nombres, 'apellidos' => $apellidos, 'tipo_documento' => 'CC', 'numero_documento' => $doc, 'cargo' => $cargo]);
        $e->tenant_id = ($de ?? $this->empresa)->id;
        $e->save();

        return $e;
    }

    private function aplicar(string $destino, array $filas, array $mapeo, array $existentes = []): array
    {
        $d = Destinos::get($destino);

        return Aplicador::aplicar($d, $filas, $mapeo + ['fila_inicio' => 1, 'valores' => [], 'fijos' => []], $this->empresa->id, $existentes, Aplicador::contextoEmpleados());
    }

    public function test_ausentismo_encuentra_al_trabajador_por_cedula_o_por_nombre(): void
    {
        $ana = $this->empleado('Ana María', 'Ruiz Díaz', '52123456');
        $this->empleado('Pedro', 'López', '80111222');
        $this->empleado('Pedro', 'López', '80333444');                                // homónimo
        $this->empleado('Otra', 'Empresa', '99999999', Tenant::create(['name' => 'Otra', 'nit' => '1']));

        $filas = [
            ['TRABAJADOR', 'DESDE', 'HASTA', 'TIPO'],
            ['52.123.456', '01/09/2026', '03/09/2026', 'EG'],
            ['Ruiz Díaz Ana María', '10/09/2026', '10/09/2026', 'Permiso'],      // por nombre, orden apellidos-nombres
            ['Pedro López', '11/09/2026', '12/09/2026', 'EG'],                    // ambiguo
            ['99999999', '11/09/2026', '12/09/2026', 'EG'],                       // de OTRA empresa: no existe aquí
        ];
        $r = $this->aplicar('ausentismo', $filas, [
            'columnas' => ['employee_id' => 0, 'fecha_inicio' => 1, 'fecha_fin' => 2, 'tipo' => 3],
            'valores' => ['tipo' => ['eg' => 'enfermedad_general', 'permiso' => 'permiso']],
        ]);

        $this->assertSame(['valida', 'valida', 'error', 'error'], array_column($r['filas'], 'estado'));
        $this->assertSame($ana->id, $r['filas'][0]['datos']['employee_id']);
        $this->assertSame($ana->id, $r['filas'][1]['datos']['employee_id']);
        $this->assertStringContainsString('más de un trabajador', $r['filas'][2]['errores']['employee_id']);
        $this->assertStringContainsString('No hay un trabajador', $r['filas'][3]['errores']['employee_id']);

        // Sin columna de días, el modelo los calcula con las fechas.
        $a = Absence::create($r['filas'][0]['datos']);
        $this->assertSame(3, $a->dias);
    }

    public function test_vehiculos_normaliza_la_placa_y_detecta_duplicados(): void
    {
        $filas = [
            ['PLACA', 'CLASE', 'MARCA', 'SOAT'],
            ['abc-123', 'Camioneta', 'Toyota', '15/05/2027'],
            ['XYZ 987', 'Tractocamión', 'Kenworth', '2027-01-31'],
            ['ABC123', 'Camioneta', 'Toyota', '15/05/2027'],       // la misma placa escrita distinto
        ];
        $r = $this->aplicar('vehiculos', $filas, [
            'columnas' => ['placa' => 0, 'tipo' => 1, 'marca' => 2, 'soat_vence' => 3],
            'valores' => ['tipo' => ['camioneta' => 'camioneta', 'tractocamion' => 'camion']],
            'fijos' => ['propiedad' => 'propio'],
        ], ['XYZ987']);

        $this->assertSame(['ABC123', 'XYZ987', 'ABC123'], array_map(fn ($f) => $f['datos']['placa'], $r['filas']));
        $this->assertSame(['valida', 'duplicada', 'duplicada'], array_column($r['filas'], 'estado'));
        $this->assertSame('camion', $r['filas'][1]['datos']['tipo']);
        $this->assertTrue($r['filas'][0]['datos']['is_active']);
    }

    public function test_un_si_no_vacio_no_es_error_y_rige_el_valor_por_defecto(): void
    {
        // IPERC sin columna de «rutinaria»: antes el null chocaba con la regla boolean.
        $filas = [['PROCESO', 'ACTIVIDAD', 'PELIGRO', 'CLASE', 'ND', 'NE', 'NC'], ['Operación', 'Cargue', 'Caída', 'Condiciones de seguridad', '6', '3', '25']];
        $r = $this->aplicar('iperc', $filas, ['columnas' => ['proceso' => 0, 'actividad' => 1, 'peligro' => 2, 'clasificacion' => 3, 'nd' => 4, 'ne' => 5, 'nc' => 6]]);

        $this->assertSame('valida', $r['filas'][0]['estado'], json_encode($r['filas'][0]['errores']));
        $this->assertArrayNotHasKey('rutinaria', $r['filas'][0]['datos']);
    }

    public function test_asistentes_se_importan_dentro_de_su_capacitacion(): void
    {
        $ana = $this->empleado('Ana María', 'Ruiz Díaz', '52123456', null, 'Auxiliar contable');
        $t1 = $this->capacitacion('Manejo defensivo');
        $t2 = $this->capacitacion('Primeros auxilios');
        // Ya inscrito en t1 (duplicado ahí) y en t2 (no cuenta para t1).
        TrainingAttendee::create(['training_id' => $t1->id, 'nombres' => 'Pedro Viejo', 'numero_documento' => '80111222', 'asistio' => true]);
        TrainingAttendee::create(['training_id' => $t2->id, 'nombres' => 'Juan', 'numero_documento' => '77000000', 'asistio' => true]);

        Http::fake(['*/v1/messages' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'proponer_mapeo', 'input' => [
            'fila_encabezado' => 0, 'fila_inicio_datos' => 1,
            'columnas' => [['campo' => 'numero_documento', 'columna' => 0], ['campo' => 'nombres', 'columna' => 1], ['campo' => 'nota', 'columna' => 2]],
            'nombre_completo' => ['columna' => -1, 'orden' => 'nombres_apellidos'],
            'valores' => [], 'fijos' => [], 'advertencias' => [],
        ]]], 'stop_reason' => 'tool_use'])]);

        $csv = "CEDULA;NOMBRE;NOTA\n52.123.456;;85\n77000000;Juan Externo;60\n80111222;Pedro Viejo;90\n";
        $subir = fn (array $extra) => $this->comoConsultor()->post('/importar', [
            'destino' => 'asistentes', 'archivo' => UploadedFile::fake()->createWithContent('asistencia.csv', $csv),
        ] + $extra);

        // Sin capacitación, o con una de otra empresa, no se sube.
        $subir([])->assertSessionHasErrors('padre_id');
        $ajena = $this->capacitacion('Ajena', Tenant::create(['name' => 'Otra', 'nit' => '2']));
        $this->app->forgetInstance(TenantContext::class);
        $subir(['padre_id' => $ajena->id])->assertSessionHasErrors('padre_id');
        $this->assertSame(0, DataImport::withoutTenantScope()->count());

        $this->app->forgetInstance(TenantContext::class);
        $subir(['padre_id' => $t1->id])->assertSessionHasNoErrors();
        $imp = DataImport::withoutTenantScope()->firstOrFail();
        $this->assertSame($t1->id, $imp->padre_id);

        $this->comoConsultor()->get("/importar/{$imp->id}")
            ->assertInertia(fn ($p) => $p->where('previa.resumen', ['total' => 3, 'validas' => 2, 'errores' => 0, 'duplicadas' => 1])
                ->where('padre', fn ($v) => str_starts_with($v, 'Manejo defensivo')));

        $this->comoConsultor()->post("/importar/{$imp->id}/aplicar")->assertSessionHasNoErrors();

        $nueva = TrainingAttendee::query()->where('training_id', $t1->id)->where('numero_documento', '52123456')->firstOrFail();
        // La cédula estaba en la nómina: enlazada y completada desde ahí.
        $this->assertSame([$ana->id, 'Ana María Ruiz Díaz', 'Auxiliar contable', true, true], [
            $nueva->employee_id, $nueva->nombres, $nueva->cargo, $nueva->asistio, $nueva->eficaz,
        ]);
        $externo = TrainingAttendee::query()->where('training_id', $t1->id)->where('numero_documento', '77000000')->firstOrFail();
        $this->assertNull($externo->employee_id);
        $this->assertFalse($externo->eficaz);   // 60 < 70

        // Deshacer: solo las 2 nuevas de t1; lo que ya estaba en t1 y t2 sigue.
        $this->comoConsultor()->post("/importar/{$imp->id}/deshacer")->assertSessionHasNoErrors();
        $this->assertSame(['80111222'], TrainingAttendee::query()->where('training_id', $t1->id)->pluck('numero_documento')->all());
        $this->assertSame(1, TrainingAttendee::query()->where('training_id', $t2->id)->count());
    }

    public function test_lee_xls_y_ods_igual_que_xlsx(): void
    {
        $libro = new Spreadsheet;
        $h = $libro->getActiveSheet()->setTitle('Personal');
        $h->fromArray([['CÉDULA', 'INGRESO', 'SALARIO'], [1098765432, null, 1500000]], null, 'A1');
        $h->getCell('B2')->setValue(45366);
        $h->getStyle('B2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $h->setCellValue('C3', '=C2*2');

        foreach (['Xls' => 'xls', 'Ods' => 'ods'] as $escritor => $ext) {
            $ruta = tempnam(sys_get_temp_dir(), 'imp').".{$ext}";
            IOFactory::createWriter($libro, $escritor)->save($ruta);
            $hojas = LectorTabular::leer($ruta, $ext);
            $this->assertSame([
                ['CÉDULA', 'INGRESO', 'SALARIO'],
                ['1098765432', '2024-03-15', '1500000'],   // la cédula sin «.0», la fecha como fecha
                [null, null, '3000000'],                   // el valor calculado de la fórmula
            ], $hojas['Personal'], $ext);
            unlink($ruta);
        }
    }

    public function test_el_xlsx_respeta_el_numero_real_de_fila(): void
    {
        // Excel no escribe las filas vacías: la fila 5 viene justo después de la 2.
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="H" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="2"><c r="A2" t="inlineStr"><is><t>Título</t></is></c></row>'
            .'<row r="5"><c r="A5" t="inlineStr"><is><t>Dato</t></is></c></row></sheetData></worksheet>');
        $zip->close();

        $this->assertSame([[], ['Título'], [], [], ['Dato']], LectorTabular::leer($ruta, 'xlsx')['H']);
        unlink($ruta);
    }

    public function test_xlsb_da_un_mensaje_claro(): void
    {
        $this->comoConsultor()->post('/importar', [
            'destino' => 'empleados', 'archivo' => UploadedFile::fake()->createWithContent('herramienta.xlsb', 'binario'),
        ])->assertSessionHasErrors(['archivo' => 'Los .xlsb (Excel binario) no se pueden leer: ábrelo en Excel y guárdalo como .xlsx.']);
    }

    private function capacitacion(string $titulo, ?Tenant $de = null): Training
    {
        $t = new Training(['titulo' => $titulo, 'categoria' => 'SST', 'fecha' => '2026-09-01', 'modalidad' => 'presencial', 'estado' => 'realizada', 'nota_minima' => 70]);
        $t->tenant_id = ($de ?? $this->empresa)->id;
        $t->save();

        return $t;
    }
}
