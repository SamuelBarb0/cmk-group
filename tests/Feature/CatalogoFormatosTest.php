<?php

namespace Tests\Feature;

use App\Models\FormFormat;
use App\Models\FormRecord;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FormFormatsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editor del catálogo de formatos: solo el administrador de CMK, el esquema se
 * guarda normalizado, lo diligenciado no cambia y el seeder no pisa lo editado.
 */
class CatalogoFormatosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->admin = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function payload(array $cambios = []): array
    {
        return array_replace_recursive([
            'codigo' => 'ins-ext-01',
            'nombre' => 'Inspección de extintores',
            'categoria' => 'SST',
            'grupo' => 'inspeccion',
            'descripcion' => '',
            'orden' => null,
            'schema' => ['secciones' => [
                ['titulo' => 'Datos', 'campos' => [
                    ['key' => null, 'label' => 'Área inspeccionada', 'tipo' => 'text', 'requerido' => true],
                    ['key' => null, 'label' => 'Área inspeccionada', 'tipo' => 'select', 'opciones' => [' Sede norte ', 'Sede sur', '', 'Sede sur']],
                ]],
                ['titulo' => 'Verificación', 'campos' => [
                    ['key' => null, 'label' => 'Estado del extintor', 'tipo' => 'checklist', 'items' => ['Presión', 'Sello'], 'basura' => 'x'],
                ]],
            ]],
        ], $cambios);
    }

    public function test_crea_un_formato_con_el_esquema_normalizado(): void
    {
        $this->actingAs($this->admin)->post('/formatos/catalogo', $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect('/formatos/catalogo');

        $f = FormFormat::where('codigo', 'INS-EXT-01')->firstOrFail();
        $this->assertTrue($f->activo);
        $this->assertNotNull($f->editado_at);
        $campos = $f->schema['secciones'][0]['campos'];
        // Claves derivadas de la etiqueta y nunca repetidas.
        $this->assertSame(['area_inspeccionada', 'area_inspeccionada_2'], array_column($campos, 'key'));
        $this->assertTrue($campos[0]['requerido']);
        $this->assertSame(['Sede norte', 'Sede sur'], $campos[1]['opciones']);
        // Lo que el motor no entiende no se guarda.
        $this->assertSame(['key', 'label', 'tipo', 'items'], array_keys($f->schema['secciones'][1]['campos'][0]));
    }

    public function test_rechaza_esquemas_invalidos(): void
    {
        $malo = $this->payload(['codigo' => 'con espacios']);
        $malo['schema']['secciones'][1]['campos'][0]['items'] = [];
        $this->actingAs($this->admin)->post('/formatos/catalogo', $malo)
            ->assertSessionHasErrors(['codigo', 'schema.secciones.1.campos.0.items']);

        // Solo líneas en blanco cuenta como lista vacía.
        $blancos = $this->payload();
        $blancos['schema']['secciones'][0]['campos'][1]['opciones'] = ['  ', ''];
        $this->actingAs($this->admin)->post('/formatos/catalogo', $blancos)
            ->assertSessionHasErrors('schema.secciones.0.campos.1.opciones');

        $this->actingAs($this->admin)->post('/formatos/catalogo', [...$this->payload(), 'schema' => ['secciones' => []]])
            ->assertSessionHasErrors('schema.secciones');

        $this->assertSame(0, FormFormat::count());
    }

    public function test_editar_conserva_las_claves_y_no_toca_lo_diligenciado(): void
    {
        $this->actingAs($this->admin)->post('/formatos/catalogo', $this->payload());
        $f = FormFormat::firstOrFail();
        $registro = new FormRecord(['form_format_id' => $f->id, 'codigo' => $f->codigo, 'titulo' => $f->nombre, 'categoria' => 'SST', 'grupo' => 'inspeccion', 'schema' => $f->schema, 'data' => [], 'estado' => 'borrador']);
        $registro->tenant_id = $this->empresa->id;
        $registro->save();

        $schema = $f->schema;
        $schema['secciones'][0]['campos'][0]['label'] = 'Área o sede';
        $schema['secciones'][0]['campos'][] = ['key' => null, 'label' => 'Observaciones', 'tipo' => 'textarea'];
        $this->actingAs($this->admin)->put("/formatos/catalogo/{$f->id}", [...$this->payload(), 'schema' => $schema])
            ->assertSessionHasNoErrors();

        $campos = $f->fresh()->schema['secciones'][0]['campos'];
        $this->assertSame(['area_inspeccionada', 'area_inspeccionada_2', 'observaciones'], array_column($campos, 'key'));
        $this->assertSame('Área o sede', $campos[0]['label']);
        // El registro sigue con su copia del esquema.
        $this->assertCount(2, FormRecord::withoutTenantScope()->find($registro->id)->schema['secciones'][0]['campos']);

        // Con registros no se borra: se retira.
        $this->actingAs($this->admin)->delete("/formatos/catalogo/{$f->id}")->assertSessionHasErrors('formato');
        $this->assertModelExists($f);
        $this->actingAs($this->admin)->patch("/formatos/catalogo/{$f->id}/activo");
        $this->assertFalse($f->fresh()->activo);
    }

    public function test_el_seeder_no_pisa_lo_editado_en_la_plataforma(): void
    {
        $this->seed(FormFormatsSeeder::class);
        $f = FormFormat::orderBy('id')->firstOrFail();

        $f->update(['nombre' => 'Renombrado por CMK', 'editado_at' => now()]);
        $otro = FormFormat::orderBy('id')->skip(1)->firstOrFail();
        $otro->update(['nombre' => 'Cambio sin marca']);

        $this->seed(FormFormatsSeeder::class);

        $this->assertSame('Renombrado por CMK', $f->fresh()->nombre);
        // Lo no marcado sigue recibiendo lo del seeder.
        $this->assertNotSame('Cambio sin marca', $otro->fresh()->nombre);
    }

    public function test_solo_el_administrador_de_cmk_entra(): void
    {
        $operativo = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_operativo');
        $f = FormFormat::create(['codigo' => 'X-1', 'nombre' => 'X', 'categoria' => 'SST', 'grupo' => 'acta', 'schema' => ['secciones' => []]]);

        $this->actingAs($operativo)->get('/formatos/catalogo')->assertForbidden();
        $this->actingAs($operativo)->put("/formatos/catalogo/{$f->id}", $this->payload())->assertForbidden();
        $this->actingAs($operativo)->delete("/formatos/catalogo/{$f->id}")->assertForbidden();
        $this->assertModelExists($f);

        $this->actingAs($this->admin)->get('/formatos/catalogo')->assertOk()
            ->assertInertia(fn ($p) => $p->component('formatos/catalogo')->where('formats.0.registros', 0));
        $this->actingAs($this->admin)->get("/formatos/catalogo/nuevo?desde={$f->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('formato.codigo', 'X-1-COPIA')->where('formato.nombre', 'X (copia)'));
    }
}
