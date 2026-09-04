<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlantillaImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Subida de plantillas: el consultor carga un .docx y queda disponible para
 * que la IA genere documentos a partir de él.
 *
 * Lo que se protege aquí es sobre todo el ALCANCE: el catálogo de CMK lo ven
 * todas las empresas, pero el formato propio de una empresa no puede verlo ni
 * usarlo otra.
 */
class PlantillasSubidasTest extends TestCase
{
    use RefreshDatabase;

    private function permisos(): void
    {
        foreach (['documents.view', 'documents.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        foreach (['consultor_admin', 'consultor_operativo', 'cliente_admin'] as $rol) {
            Role::findOrCreate($rol, 'web')->givePermissionTo(['documents.view', 'documents.manage']);
        }
    }

    private function consultor(): User
    {
        $this->permisos();

        return tap(User::factory()->create())->assignRole('consultor_admin');
    }

    private function consultorOperativo(): User
    {
        $this->permisos();

        return tap(User::factory()->create())->assignRole('consultor_operativo');
    }

    private function clienteAdmin(Tenant $tenant): User
    {
        $this->permisos();

        return tap(User::factory()->create(['tenant_id' => $tenant->id]))->assignRole('cliente_admin');
    }

    private function tenant(string $nombre = 'Empresa Demo'): Tenant
    {
        return Tenant::create(['name' => $nombre, 'nit' => '900'.random_int(100000, 999999).'-1']);
    }

    /** Un .docx de verdad: los de CMK, que es contra lo que hay que probar. */
    private function docxReal(string $codigo = 'POL-SGI'): UploadedFile
    {
        $ruta = storage_path("app/private/plantillas-base/{$codigo}.docx");

        if (! is_file($ruta)) {
            $this->markTestSkipped("No está el .docx de referencia {$codigo}.");
        }

        return new UploadedFile($ruta, "{$codigo}.docx", null, null, true);
    }

    /** @return array<string,mixed> */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'codigo' => 'POL-TEST',
            'nombre' => 'Política de prueba',
            'tipo' => 'Política',
            'categoria' => 'SST',
            'normas' => 'ISO 45001, Decreto 1072',
            'descripcion' => 'Para las pruebas.',
            'prompt' => '',
            'alcance' => 'cliente',
        ], $extra);
    }

    public function test_el_docx_subido_queda_como_modelo_de_la_plantilla(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $this->actingAs($this->consultor())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario(['archivo' => $this->docxReal()]))
            ->assertSessionHasNoErrors();

        $plantilla = DocumentTemplate::where('codigo', 'POL-TEST')->firstOrFail();

        $this->assertSame($tenant->id, $plantilla->tenant_id);
        $this->assertTrue($plantilla->tieneBase(), 'La plantilla debería tener contenido base extraído del .docx.');
        $this->assertStringContainsString('POLÍTICA', $plantilla->contenido_base);
        $this->assertSame(['ISO 45001', 'Decreto 1072'], $plantilla->normas);

        // Sin instrucción escrita se genera una, para que la IA sepa qué hacer
        // si algún día se usa la plantilla en modo redacción.
        $this->assertNotEmpty($plantilla->prompt);
    }

    public function test_un_docx_tokenizado_conserva_el_archivo_para_exportar_con_su_formato(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $this->actingAs($this->consultor())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario(['archivo' => $this->docxReal('POL-SGI')]));

        $plantilla = DocumentTemplate::where('codigo', 'POL-TEST')->firstOrFail();

        // POL-SGI trae ${EMPRESA}: se guarda el Word original para exportar
        // sobre él y conservar membrete y formato.
        $this->assertNotNull($plantilla->archivo);
        Storage::disk('local')->assertExists($plantilla->archivo);
    }

    /**
     * Antes el .docx solo se guardaba si traía `${TOKEN}`. El efecto fue el
     * contrario del buscado: los formatos y actas —que son tablas y membrete, y
     * casi nunca llevan tokens— se quedaban sin modelo y se descargaban como un
     * muro de texto. Son justo los que más formato tienen que perder.
     */
    public function test_un_docx_sin_tokens_tambien_conserva_su_formato(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $this->actingAs($this->consultor())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario([
                'archivo' => $this->docxReal('FT-AUTOGESTION-PESV'),
                'codigo' => 'FT-TEST',
                'tipo' => 'Formato',
            ]));

        $plantilla = DocumentTemplate::where('codigo', 'FT-TEST')->firstOrFail();

        $this->assertNotNull($plantilla->archivo, 'El formato se quedó sin su Word modelo.');
        Storage::disk('local')->assertExists($plantilla->archivo);
    }

    public function test_una_plantilla_de_una_empresa_no_la_ve_otra(): void
    {
        Storage::fake('local');
        $unaEmpresa = $this->tenant('Empresa A');
        $otraEmpresa = $this->tenant('Empresa B');

        DocumentTemplate::create([
            'tenant_id' => $unaEmpresa->id,
            'codigo' => 'PRIV-A', 'nombre' => 'Formato privado de A', 'tipo' => 'Formato',
            'categoria' => 'SST', 'normas' => [], 'prompt' => 'x', 'contenido_base' => 'contenido',
        ]);

        DocumentTemplate::create([
            'tenant_id' => null,
            'codigo' => 'GLOBAL-1', 'nombre' => 'Del catálogo de CMK', 'tipo' => 'Política',
            'categoria' => 'SGI', 'normas' => [], 'prompt' => 'x',
        ]);

        $visiblesParaB = DocumentTemplate::visibles($otraEmpresa->id)->pluck('codigo')->all();

        $this->assertContains('GLOBAL-1', $visiblesParaB, 'El catálogo de CMK lo ven todas las empresas.');
        $this->assertNotContains('PRIV-A', $visiblesParaB, 'El formato propio de una empresa no puede verlo otra.');
    }

    public function test_solo_el_admin_de_cmk_publica_en_el_catalogo_compartido(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        // Un consultor operativo ES del equipo de CMK, pero publicar en el
        // catalogo global le cambiaria el catalogo a todas las empresas.
        $this->actingAs($this->consultorOperativo())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario([
                'archivo' => $this->docxReal(),
                'alcance' => 'global',
            ]))
            ->assertSessionHasErrors('alcance');

        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_el_consultor_operativo_si_puede_subirle_una_plantilla_a_su_empresa(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $this->actingAs($this->consultorOperativo())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario(['archivo' => $this->docxReal()]))
            ->assertSessionHasNoErrors();

        $this->assertSame($tenant->id, DocumentTemplate::firstOrFail()->tenant_id);
    }

    public function test_quien_no_es_admin_de_cmk_no_puede_editar_una_plantilla_del_catalogo(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $global = DocumentTemplate::create([
            'tenant_id' => null,
            'codigo' => 'GLOBAL-1', 'nombre' => 'Del catalogo', 'tipo' => 'Politica',
            'categoria' => 'SGI', 'normas' => [], 'prompt' => 'x',
        ]);

        $this->actingAs($this->consultorOperativo())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.update', $global->id), $this->formulario(['alcance' => 'global']))
            ->assertForbidden();

        $this->actingAs($this->consultorOperativo())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->delete(route('plantillas.destroy', $global->id))
            ->assertForbidden();

        $this->assertDatabaseHas('document_templates', ['codigo' => 'GLOBAL-1']);
    }

    public function test_el_personal_del_cliente_no_puede_publicar_en_el_catalogo_de_cmk(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $this->actingAs($this->clienteAdmin($tenant))
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario([
                'archivo' => $this->docxReal(),
                'alcance' => 'global',
            ]))
            ->assertSessionHasErrors('alcance');

        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_no_se_admite_un_archivo_de_otro_tipo(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();

        $this->actingAs($this->consultor())
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(route('plantillas.store'), $this->formulario([
                'archivo' => UploadedFile::fake()->create('hoja.xlsx', 10),
            ]))
            ->assertSessionHasErrors('archivo');
    }

    public function test_el_codigo_puede_repetirse_entre_empresas_pero_no_dentro_de_una(): void
    {
        Storage::fake('local');
        $unaEmpresa = $this->tenant('Empresa A');
        $otraEmpresa = $this->tenant('Empresa B');
        $consultor = $this->consultor();

        $this->actingAs($consultor)->withSession(['active_tenant_id' => $unaEmpresa->id])
            ->post(route('plantillas.store'), $this->formulario(['archivo' => $this->docxReal()]))
            ->assertSessionHasNoErrors();

        // La misma empresa, el mismo código: choca.
        $this->actingAs($consultor)->withSession(['active_tenant_id' => $unaEmpresa->id])
            ->post(route('plantillas.store'), $this->formulario(['archivo' => $this->docxReal()]))
            ->assertSessionHasErrors('codigo');

        // Otra empresa, el mismo código: es su propio formato, no choca.
        $this->actingAs($consultor)->withSession(['active_tenant_id' => $otraEmpresa->id])
            ->post(route('plantillas.store'), $this->formulario(['archivo' => $this->docxReal()]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, DocumentTemplate::count());
    }

    public function test_el_importador_lee_los_docx_reales_de_cmk_con_su_estructura(): void
    {
        $importer = app(PlantillaImporter::class);
        $texto = $importer->extraer($this->docxReal('MAN-SGSST'));

        $this->assertGreaterThan(10000, mb_strlen($texto), 'El manual debería traer bastante texto.');
        $this->assertMatchesRegularExpression('/^#{1,6} /m', $texto, 'Los encabezados del Word deben llegar como Markdown.');
        $this->assertMatchesRegularExpression('/^\| --- \|/m', $texto, 'Las tablas del Word deben llegar como tabla Markdown.');
    }

    public function test_un_docx_con_imagenes_que_phpword_no_sabe_leer_se_importa_igual(): void
    {
        // PR-TERCEROS lleva una imagen EMF: el lector de PhpWord aborta con
        // «Invalid image», y por eso el importador lee el XML por su cuenta.
        $texto = app(PlantillaImporter::class)->extraer($this->docxReal('PR-TERCEROS'));

        $this->assertGreaterThan(5000, mb_strlen($texto));
    }
}
