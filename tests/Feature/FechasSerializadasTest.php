<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las fechas de negocio tienen que llegar al front como YYYY-MM-DD.
 *
 * Un <input type="date"> RECHAZA el ISO-8601 con hora y se queda vacio. Eso no
 * rompe nada visible: la tabla se ve bien, pero abrir la ficha y guardar BORRA
 * la fecha. Paso en dos sitios distintos, con dos causas distintas:
 *
 *   1. El cast `date` (sin formato) serializa a ISO. Se arreglo poniendo
 *      `date:Y-m-d` en los modelos.
 *   2. `->only([...])` devuelve el Carbon EN CRUDO y se salta la serializacion
 *      del modelo, asi que el cast no se aplica. Ahi hay que formatear a mano.
 */
class FechasSerializadasTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_fechas_de_la_organizacion_llegan_sin_hora(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $empresa = Tenant::create([
            'name' => 'Empresa Demo', 'nit' => '900123456-1',
            'licencia_sgsst_vence' => '2027-05-01',
            'curso_sst_fecha' => '2025-03-10',
        ]);
        $consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');

        $this->actingAs($consultor)->withSession(['active_tenant_id' => $empresa->id])
            ->get('/organizacion')
            ->assertInertia(fn ($p) => $p
                ->where('organizacion.licencia_sgsst_vence', '2027-05-01')
                ->where('organizacion.curso_sst_fecha', '2025-03-10')
                ->etc());
    }

    /**
     * Guarda de fondo: ningun modelo puede quedarse con el cast `date` pelado.
     * Es lo que hace que la fecha llegue con hora al formulario.
     */
    public function test_ningun_modelo_usa_el_cast_date_sin_formato(): void
    {
        $malos = [];

        foreach (glob(app_path('Models/*.php')) as $archivo) {
            $codigo = file_get_contents($archivo);
            // Se busca `=> 'date',` exacto: `date:Y-m-d` y `datetime` estan bien.
            if (preg_match_all("/=> 'date',/", $codigo, $m)) {
                $malos[] = basename($archivo).' ('.count($m[0]).')';
            }
        }

        $this->assertSame([], $malos,
            'Estos modelos usan el cast `date` sin formato, asi que sus fechas '.
            'llegaran al front con hora y vaciaran los <input type="date">: '.implode(', ', $malos));
    }
}
