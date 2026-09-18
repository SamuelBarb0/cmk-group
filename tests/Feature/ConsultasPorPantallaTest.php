<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\Audit;
use App\Models\Committee;
use App\Models\Employee;
use App\Models\PpeDelivery;
use App\Models\PpeItem;
use App\Models\SafetyReport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkAccident;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guarda contra el N+1 en las pantallas de listado.
 *
 * Con los datos de prueba de tres filas un N+1 no se nota; con la nomina de un
 * cliente real son cientos de consultas y la pantalla tarda segundos. Este test
 * carga 30 filas y comprueba que el numero de consultas no crece con ellas.
 */
class ConsultasPorPantallaTest extends TestCase
{
    use RefreshDatabase;

    private const FILAS = 30;

    /** Margen generoso: lo que importa es que NO escale con las filas. */
    private const TOPE = 25;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->empresa = Tenant::create(['name' => 'Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function crear(string $modelo, array $attrs): void
    {
        $m = new $modelo($attrs);
        $m->tenant_id = $this->empresa->id;
        $m->save();
    }

    private function consultasDe(string $ruta): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id])
            ->get($ruta)->assertOk();

        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_los_listados_no_hacen_una_consulta_por_fila(): void
    {
        $empleados = [];
        for ($i = 0; $i < self::FILAS; $i++) {
            $e = new Employee([
                'nombres' => 'T'.$i, 'apellidos' => 'X', 'tipo_documento' => 'CC',
                'numero_documento' => (string) (10000000 + $i), 'cargo' => 'Operario',
            ]);
            $e->tenant_id = $this->empresa->id;
            $e->save();
            $empleados[] = $e;
        }

        $item = new PpeItem(['nombre' => 'Casco', 'categoria' => 'cabeza']);
        $item->tenant_id = $this->empresa->id;
        $item->save();

        for ($i = 0; $i < self::FILAS; $i++) {
            $emp = $empleados[$i];

            $this->crear(Absence::class, [
                'employee_id' => $emp->id, 'fecha_inicio' => now()->toDateString(),
                'fecha_fin' => now()->toDateString(), 'tipo' => 'enfermedad_general',
            ]);
            $this->crear(WorkAccident::class, [
                'employee_id' => $emp->id, 'clase' => 'accidente',
                'fecha' => now()->toDateString(), 'descripcion' => 'x',
            ]);
            $this->crear(SafetyReport::class, [
                'fecha' => now()->toDateString(), 'reportado_por' => 'X',
                'employee_id' => $emp->id, 'tipo' => 'acto', 'descripcion' => 'x',
            ]);
            $this->crear(PpeDelivery::class, [
                'employee_id' => $emp->id, 'ppe_item_id' => $item->id,
                'fecha_entrega' => now()->toDateString(), 'cantidad' => 1, 'motivo' => 'dotacion',
            ]);

            // Un comite y una auditoria por fila, cada uno con hijos.
            $c = new Committee(['tipo' => 'copasst', 'periodo' => 2000 + $i, 'fecha_conformacion' => '2026-01-15']);
            $c->tenant_id = $this->empresa->id;
            $c->save();
            $c->members()->create(['nombres' => 'M', 'rol' => 'principal', 'representa' => 'empleador']);
            $c->activities()->create(['descripcion' => 'A', 'programada' => true]);

            $a = new Audit(['tipo' => 'interna', 'objetivo' => 'O', 'fecha_programada' => now()->toDateString()]);
            $a->tenant_id = $this->empresa->id;
            $a->save();
            $a->findings()->create(['tipo' => 'observacion', 'descripcion' => 'x']);
        }

        $rutas = [
            '/ausentismo' => null,
            '/accidentes' => null,
            '/reportes-ac' => null,
            '/acpm' => null,
            '/comites' => null,
            '/auditoria' => null,
            '/epp' => null,
            '/requisitos-legales' => null,
        ];

        $excedidos = [];
        foreach (array_keys($rutas) as $ruta) {
            $n = $this->consultasDe($ruta);
            if ($n > self::TOPE) {
                $excedidos[] = sprintf('%s = %d consultas con %d filas', $ruta, $n, self::FILAS);
            }
        }

        $this->assertSame([], $excedidos,
            'Estas pantallas hacen una consulta por fila (N+1): '.implode(' | ', $excedidos));
    }
}
