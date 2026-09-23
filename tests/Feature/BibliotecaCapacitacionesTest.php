<?php

namespace Tests\Feature;

use App\Models\TrainingTopic;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TrainingTopicsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Biblioteca de temas de capacitación: el administrador de CMK crea temas y
 * carga o reemplaza su material; el seeder no deshace lo cargado.
 */
class BibliotecaCapacitacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function datos(array $extra = []): array
    {
        return ['codigo' => 'cap-alturas', 'titulo' => 'Trabajo en alturas', 'categoria' => 'SST', 'descripcion' => '', 'duracion_sugerida' => '90', 'orden' => '', ...$extra];
    }

    public function test_crea_un_tema_con_material_y_lo_reemplaza(): void
    {
        $this->actingAs($this->admin)->post('/capacitaciones/temas', $this->datos([
            'archivo' => UploadedFile::fake()->create('alturas.pptx', 300),
        ]))->assertSessionHasNoErrors();

        $tema = TrainingTopic::where('codigo', 'CAP-ALTURAS')->firstOrFail();
        $viejo = $tema->archivo;
        $this->assertStringStartsWith('capacitaciones/cap-alturas-', $viejo);
        $this->assertTrue($tema->tieneArchivo());
        $this->assertSame(90, $tema->duracion_sugerida);

        // Editar sin archivo conserva el material.
        $this->actingAs($this->admin)->post("/capacitaciones/temas/{$tema->id}", $this->datos(['titulo' => 'Alturas (res. 4272)']))
            ->assertSessionHasNoErrors();
        $this->assertSame($viejo, $tema->fresh()->archivo);

        // Reemplazar: el nuevo queda y el viejo se borra.
        $this->travel(2)->seconds();
        $this->actingAs($this->admin)->post("/capacitaciones/temas/{$tema->id}", $this->datos([
            'archivo' => UploadedFile::fake()->create('alturas-v2.pdf', 200),
        ]))->assertSessionHasNoErrors();
        $nuevo = $tema->fresh()->archivo;
        $this->assertStringEndsWith('.pdf', $nuevo);
        Storage::disk('local')->assertExists($nuevo);
        Storage::disk('local')->assertMissing($viejo);

        // Y se descarga por la ruta de siempre.
        $this->actingAs($this->admin)->get("/capacitaciones/tema/{$tema->id}/material")->assertOk();
    }

    public function test_no_borra_un_archivo_que_otro_tema_sigue_usando(): void
    {
        Storage::disk('local')->put('capacitaciones/compartido.pptx', 'x');
        $a = TrainingTopic::create(['codigo' => 'A', 'titulo' => 'A', 'categoria' => 'SST', 'archivo' => 'capacitaciones/compartido.pptx']);
        TrainingTopic::create(['codigo' => 'B', 'titulo' => 'B', 'categoria' => 'SST', 'archivo' => 'capacitaciones/compartido.pptx']);

        $this->actingAs($this->admin)->post("/capacitaciones/temas/{$a->id}", $this->datos([
            'codigo' => 'A', 'archivo' => UploadedFile::fake()->create('nuevo.pptx', 10),
        ]))->assertSessionHasNoErrors();

        Storage::disk('local')->assertExists('capacitaciones/compartido.pptx');
    }

    public function test_rechaza_extensiones_no_permitidas(): void
    {
        $this->actingAs($this->admin)->post('/capacitaciones/temas', $this->datos([
            'archivo' => UploadedFile::fake()->create('virus.exe', 10),
        ]))->assertSessionHasErrors('archivo');
        $this->assertSame(0, TrainingTopic::count());
    }

    public function test_el_seeder_no_devuelve_el_material_viejo(): void
    {
        $this->seed(TrainingTopicsSeeder::class);
        $tema = TrainingTopic::where('codigo', 'CAP-COPASST')->firstOrFail();

        $this->actingAs($this->admin)->post("/capacitaciones/temas/{$tema->id}", $this->datos([
            'codigo' => 'CAP-COPASST', 'titulo' => $tema->titulo, 'archivo' => UploadedFile::fake()->create('copasst-2026.pptx', 10),
        ]))->assertSessionHasNoErrors();
        $cargado = $tema->fresh()->archivo;

        $this->seed(TrainingTopicsSeeder::class);

        $this->assertSame($cargado, $tema->fresh()->archivo);
        $this->assertNotSame('capacitaciones/copasst.pptx', $cargado);
    }

    public function test_solo_el_administrador_de_cmk_entra_y_retirar_oculta_el_tema(): void
    {
        $tema = TrainingTopic::create(['codigo' => 'X', 'titulo' => 'X', 'categoria' => 'SST']);
        $operativo = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_operativo');

        $this->actingAs($operativo)->get('/capacitaciones/temas')->assertForbidden();
        $this->actingAs($operativo)->post("/capacitaciones/temas/{$tema->id}", $this->datos(['codigo' => 'X']))->assertForbidden();
        $this->actingAs($operativo)->patch("/capacitaciones/temas/{$tema->id}/activo")->assertForbidden();
        $this->assertTrue($tema->fresh()->activo);

        $this->actingAs($this->admin)->get('/capacitaciones/temas')->assertOk()
            ->assertInertia(fn ($p) => $p->component('capacitaciones/temas')->where('topics.0.material', null)->where('topics.0.usos', 0));
        $this->actingAs($this->admin)->patch("/capacitaciones/temas/{$tema->id}/activo");
        $this->assertFalse($tema->fresh()->activo);
    }
}
