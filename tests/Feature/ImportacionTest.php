<?php

namespace Tests\Feature;

use App\Models\DataImport;
use App\Models\Employee;
use App\Models\LegalRequirement;
use App\Models\Tenant;
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
use Tests\TestCase;
use ZipArchive;

/**
 * Importación asistida. La API de Claude va simulada con Http::fake: lo que
 * se prueba es lo que hace la plataforma con su respuesta (sanearla, aplicar
 * el mapeo a todas las filas, validar, importar, deshacer), no a Claude.
 */
class ImportacionTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    private const NOMINA = "LISTADO DE PERSONAL\n\nNo;APELLIDOS Y NOMBRES;CÉDULA;CARGO;INGRESO;RIESGO\n"
        ."1;GOMEZ PEREZ JUAN CARLOS;1.098.765.432;Conductor;15/03/2021;4\n"
        ."2;RUIZ DIAZ ANA MARIA;52.123.456;Auxiliar;2023-02-03;1\n"
        ."3;GOMEZ PEREZ JUAN CARLOS;1098765432;Conductor;15/03/2021;4\n"
        ."4;SIN DOCUMENTO;;Ayudante;13/13/2025;4\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        // Clave de mentira: sin ninguna, AiService falla ANTES de llegar a Http::fake
        // (pasaba en local por la clave real del .env y fallaba en el CI).
        config(['ai.anthropic.api_key' => 'sk-test']);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    /** Respuesta simulada de la API con la herramienta proponer_mapeo. */
    private function fakeIa(array $input): void
    {
        Http::fake(['*/v1/messages' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'proponer_mapeo', 'input' => $input]],
            'stop_reason' => 'tool_use',
        ])]);
    }

    private function mapeoNomina(array $extra = []): array
    {
        return array_merge([
            'fila_encabezado' => 2,
            'fila_inicio_datos' => 3,
            'columnas' => [
                ['campo' => 'numero_documento', 'columna' => 2],
                ['campo' => 'cargo', 'columna' => 3],
                ['campo' => 'fecha_ingreso', 'columna' => 4],
                ['campo' => 'nivel_riesgo', 'columna' => 5],
                // Basura que el saneado tiene que tirar:
                ['campo' => 'area', 'columna' => 99],          // columna que no existe
                ['campo' => 'eps', 'columna' => 3],            // columna ya usada por cargo
            ],
            'nombre_completo' => ['columna' => 1, 'orden' => 'apellidos_nombres'],
            'valores' => [
                ['campo' => 'nivel_riesgo', 'original' => '4', 'destino' => 'IV'],
                ['campo' => 'nivel_riesgo', 'original' => '1', 'destino' => 'I'],
                ['campo' => 'nivel_riesgo', 'original' => '9', 'destino' => 'IX'],   // no es opción
            ],
            'fijos' => [['campo' => 'tipo_documento', 'valor' => 'CC']],
            'advertencias' => ['La fila 3 repite la cédula de la fila 1.'],
        ], $extra);
    }

    private function subir(string $contenido = self::NOMINA, string $destino = 'empleados', string $nombre = 'nomina.csv')
    {
        return $this->comoConsultor()->post('/importar', [
            'destino' => $destino,
            'archivo' => UploadedFile::fake()->createWithContent($nombre, $contenido),
        ]);
    }

    public function test_subir_mapear_revisar_importar_y_deshacer(): void
    {
        $this->fakeIa($this->mapeoNomina());
        $this->subir()->assertRedirect();

        // Una sola hoja: la IA se llama sola (cola sync en los tests).
        $imp = DataImport::withoutTenantScope()->firstOrFail();
        $this->assertSame('listo', $imp->estado);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['tool_choice']['name'] === 'proponer_mapeo'
            && str_contains($r['messages'][0]['content'], 'APELLIDOS Y NOMBRES'));

        // El saneado descartó la columna 99, la columna repetida y el «IX».
        $this->assertNull($imp->mapeo['columnas']['area']);
        $this->assertNull($imp->mapeo['columnas']['eps']);
        $this->assertSame(['4' => 'IV', '1' => 'I'], $imp->mapeo['valores']['nivel_riesgo']);

        $this->comoConsultor()->get("/importar/{$imp->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('importar/show')
                ->where('previa.resumen', ['total' => 4, 'validas' => 2, 'errores' => 1, 'duplicadas' => 1])
                // Los valores de la columna de riesgo viajan como TEXTO: con «4» como
                // número la pantalla reventaba al normalizarlo (bug visto en navegador).
                ->where('distintos.5', ['4', '1']));

        $this->comoConsultor()->post("/importar/{$imp->id}/aplicar")->assertSessionHasNoErrors();
        $imp->refresh();
        $this->assertSame('aplicado', $imp->estado);
        $this->assertCount(2, $imp->resultado['creados']);

        $juan = Employee::withoutTenantScope()->where('numero_documento', '1098765432')->firstOrFail();
        $this->assertSame([$this->empresa->id, 'JUAN CARLOS', 'GOMEZ PEREZ', 'CC', 'IV', '2021-03-15'], [
            $juan->tenant_id, $juan->nombres, $juan->apellidos, $juan->tipo_documento, $juan->nivel_riesgo, $juan->fecha_ingreso->toDateString(),
        ]);

        // Un empleado que no vino de esta importación no lo toca el deshacer.
        $otro = new Employee(['nombres' => 'Otro', 'apellidos' => 'Previo', 'tipo_documento' => 'CC', 'numero_documento' => '999']);
        $otro->tenant_id = $this->empresa->id;
        $otro->save();

        // Deshacer borra SOLO lo que creó esta importación.
        $this->comoConsultor()->post("/importar/{$imp->id}/deshacer")->assertSessionHasNoErrors();
        $this->assertSame('deshecho', $imp->fresh()->estado);
        $this->assertSame(['999'], Employee::withoutTenantScope()->pluck('numero_documento')->all());
    }

    public function test_los_duplicados_contra_la_base_no_se_importan(): void
    {
        $e = new Employee(['nombres' => 'Juan', 'apellidos' => 'Gómez', 'tipo_documento' => 'CC', 'numero_documento' => '1098765432']);
        $e->tenant_id = $this->empresa->id;
        $e->save();

        $this->fakeIa($this->mapeoNomina());
        $this->subir();
        $imp = DataImport::withoutTenantScope()->firstOrFail();

        $this->comoConsultor()->get("/importar/{$imp->id}")
            ->assertInertia(fn ($p) => $p->where('previa.resumen.validas', 1)->where('previa.resumen.duplicadas', 2));
    }

    public function test_si_la_ia_falla_queda_en_error_y_no_se_crea_nada(): void
    {
        Http::fake(['*/v1/messages' => Http::response(['error' => ['message' => 'Overloaded']], 529)]);
        $this->subir();

        $imp = DataImport::withoutTenantScope()->firstOrFail();
        $this->assertSame('error', $imp->estado);
        $this->assertStringContainsString('sobrecargada', $imp->error);
        $this->assertSame(0, Employee::withoutTenantScope()->count());
    }

    public function test_corregir_el_mapeo_a_mano_conserva_valores_con_puntos(): void
    {
        $this->fakeIa($this->mapeoNomina());
        $this->subir();
        $imp = DataImport::withoutTenantScope()->firstOrFail();

        $this->comoConsultor()->put("/importar/{$imp->id}/mapeo", [
            'fila_inicio' => 3,
            'columnas' => ['numero_documento' => 2, 'cargo' => 3, 'fecha_ingreso' => null, 'nivel_riesgo' => 5],
            'nombre_completo' => ['columna' => 1, 'orden' => 'apellidos_nombres'],
            'valores' => [
                ['campo' => 'nivel_riesgo', 'original' => '4', 'destino' => 'III'],
                ['campo' => 'nivel_riesgo', 'original' => 'N.A.', 'destino' => 'I'],
            ],
            'fijos' => ['tipo_documento' => 'CE'],
        ])->assertSessionHasNoErrors();

        $imp->refresh();
        $this->assertTrue($imp->mapeo_editado);
        $this->assertSame(['4' => 'III', 'n.a.' => 'I'], $imp->mapeo['valores']['nivel_riesgo']);
        $this->assertSame('CE', $imp->mapeo['fijos']['tipo_documento']);
        // Sin la columna de fecha, la fecha imposible de la fila 4 ya no cuenta; y
        // como la nueva lista de traducciones no trae el «1», la fila de Ana pasa a
        // error (riesgo «1» sin opción): las traducciones se reemplazan enteras.
        $this->comoConsultor()->get("/importar/{$imp->id}")->assertInertia(fn ($p) => $p
            ->where('previa.resumen.errores', 2)
            ->where('previa.resumen.validas', 1));
    }

    public function test_solo_se_importa_a_modulos_contratados(): void
    {
        $this->empresa->update(['modulos' => ['importar', 'acpm']]);   // ni IPERC ni requisitos legales

        $this->assertSame(['empleados'], Destinos::permitidos($this->empresa->fresh()));
        $this->subir(self::NOMINA, 'iperc')->assertSessionHasErrors('destino');

        $this->fakeIa($this->mapeoNomina());
        $this->subir()->assertSessionHasNoErrors();   // empleados es módulo base
    }

    public function test_rechaza_archivos_que_no_son_tabulares(): void
    {
        $this->subir('no soy un excel', 'empleados', 'datos.xlsx')->assertSessionHasErrors('archivo');
        $this->subir('x', 'empleados', 'datos.pdf')->assertSessionHasErrors('archivo');
        $this->assertSame(0, DataImport::withoutTenantScope()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());   // el archivo inválido no queda guardado
    }

    public function test_los_fijos_del_modulo_se_aplican_aunque_el_campo_exista_en_null(): void
    {
        // La regresión del «+=»: aplica = true tiene que llegar aunque no haya columna.
        $filas = [['NORMA', 'REQUISITO'], ['Resolución 0312', 'Diseñar el SG-SST']];
        $mapeo = ['fila_inicio' => 1, 'columnas' => ['norma' => 0, 'requisito' => 1], 'valores' => [], 'fijos' => ['cumplimiento' => 'no_cumple']];
        $r = Aplicador::aplicar(Destinos::get('requisitos_legales'), $filas, $mapeo, $this->empresa->id);

        $this->assertSame('valida', $r['filas'][0]['estado']);
        $this->assertTrue($r['filas'][0]['datos']['aplica']);
    }

    public function test_conversiones_de_celdas(): void
    {
        $this->assertSame('2024-03-15', Aplicador::fecha('15/03/2024'));
        $this->assertSame('2024-03-15', Aplicador::fecha('45366'));          // serial de Excel
        $this->assertSame('1989-07-03', Aplicador::fecha('3-7-89'));
        $this->assertNull(Aplicador::fecha('31/02/2024'));
        $this->assertNull(Aplicador::fecha('01/13/2025'));                   // mes primero: no se adivina

        $this->assertSame(1500000.0, Aplicador::numero('$ 1.500.000'));
        $this->assertSame(1500000.5, Aplicador::numero('1.500.000,50'));
        $this->assertSame(2.5, Aplicador::numero('2,5'));
        $this->assertNull(Aplicador::numero('N/A'));

        $this->assertSame(['JUAN CARLOS', 'GOMEZ PEREZ'], Aplicador::partirNombre('GOMEZ PEREZ JUAN CARLOS', 'apellidos_nombres'));
        $this->assertSame(['Ana María del Pilar', 'Ruiz Díaz'], Aplicador::partirNombre('Ana María del Pilar Ruiz Díaz', 'nombres_apellidos'));
        $this->assertSame(['Ana', 'Ruiz'], Aplicador::partirNombre('Ana Ruiz', 'nombres_apellidos'));

        $this->assertTrue(Aplicador::booleano('Sí'));
        $this->assertFalse(Aplicador::booleano('N/A'));
        $this->assertNull(Aplicador::booleano('tal vez'));
        $this->assertSame('tecnico operacao', Aplicador::normalizar('  Técnico   Operação '));
    }

    public function test_el_lector_xlsx_entiende_textos_compartidos_fechas_y_celdas_vacias(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Personal" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Nombre</t></si><si><r><t xml:space="preserve">Ana </t></r><r><t>Ruiz</t></r></si></sst>');
        // Estilo 1 = formato propio dd/mm/yyyy (fecha); estilo 2 = moneda (no fecha).
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="&quot;$&quot;#,##0"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="165"/><xf numFmtId="166"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Ingreso</t></is></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>1</v></c><c r="C2" s="1"><v>45366</v></c><c r="D2" s="2" t="n"><v>1500000</v></c><c r="E2" s="1" t="n"><v>45367</v></c><c r="F2" t="e"><v>#N/A</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $hojas = LectorTabular::leer($ruta, 'xlsx');
        $this->assertSame(['Personal'], array_keys($hojas));
        $this->assertSame([
            ['Nombre', null, 'Ingreso'],
            ['Ana Ruiz', null, '2024-03-15', '1500000', '2024-03-16', null],   // B vacía; t="n" con fecha también; #N/A = nada
        ], $hojas['Personal']);
        unlink($ruta);
    }

    public function test_no_se_toca_una_importacion_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajena = new DataImport(['destino' => 'empleados', 'archivo' => 'x.csv', 'nombre_original' => 'x.csv', 'hojas' => [], 'estado' => 'listo']);
        $ajena->tenant_id = $otra->id;
        $ajena->save();

        foreach (['get' => '', 'post' => '/aplicar', 'delete' => ''] as $metodo => $sufijo) {
            $this->app->forgetInstance(TenantContext::class);
            $this->comoConsultor()->{$metodo}("/importar/{$ajena->id}{$sufijo}")->assertNotFound();
        }
        $this->assertTrue(DataImport::withoutTenantScope()->whereKey($ajena->id)->exists());
    }

    public function test_la_pantalla_carga(): void
    {
        $this->comoConsultor()->get('/importar')->assertOk()
            ->assertInertia(fn ($p) => $p->component('importar/index')->where('permitidos', ['empleados', 'iperc', 'requisitos_legales']));
    }

    /** Destino sin reglas propias no existe: cada uno pide las de su controlador. */
    public function test_cada_destino_valida_con_las_reglas_de_su_formulario(): void
    {
        foreach (Destinos::todos() as $clave => $d) {
            $reglas = ($d['reglas'])($this->empresa->id);
            foreach (array_filter($d['campos'], fn ($c) => ! empty($c['requerido'])) as $campo => $c) {
                $this->assertStringContainsString('required', json_encode($reglas[$campo] ?? []), "{$clave}.{$campo}");
            }
        }
        $this->assertSame(['cumple', 'parcial', 'no_cumple'], LegalRequirement::CUMPLIMIENTOS);
    }
}
