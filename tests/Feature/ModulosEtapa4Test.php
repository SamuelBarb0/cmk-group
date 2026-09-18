<?php

namespace Tests\Feature;

use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\Committee;
use App\Models\Employee;
use App\Models\PpeDelivery;
use App\Models\PpeItem;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comités, auditoría y EPP. Mismo criterio que en la etapa 3: que las pantallas
 * respondan, que el guardado valide, y que los números que alimentan
 * indicadores salgan bien.
 */
class ModulosEtapa4Test extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private Tenant $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Tenant::create(['name' => 'Empresa Demo', 'nit' => '900123456-1']);
        $this->consultor = tap(User::factory()->create(['tenant_id' => null]))->assignRole('consultor_admin');
    }

    private function comoConsultor()
    {
        return $this->actingAs($this->consultor)->withSession(['active_tenant_id' => $this->empresa->id]);
    }

    private function empleado(): Employee
    {
        $e = new Employee([
            'nombres' => 'Ana', 'apellidos' => 'Perez', 'tipo_documento' => 'CC',
            'numero_documento' => (string) random_int(10000000, 99999999), 'cargo' => 'Operario',
        ]);
        $e->tenant_id = $this->empresa->id;
        $e->save();

        return $e;
    }

    public function test_las_tres_pantallas_cargan(): void
    {
        foreach (['comites', 'auditoria', 'epp'] as $ruta) {
            $this->comoConsultor()->get('/'.$ruta)->assertOk();
        }
    }

    /**
     * /auditoria servia un SHELL de marcador de posicion («Fase 1»), no el
     * modulo. Y como ese shell se registra ~200 lineas mas abajo con la misma
     * URI, Laravel se quedaba con el y TAPABA la ruta real: la pantalla nueva
     * era inalcanzable aunque su ruta estuviera escrita.
     */
    public function test_auditoria_sirve_el_modulo_y_no_el_shell(): void
    {
        $this->comoConsultor()->get('/auditoria')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('auditoria/index')->etc());
    }

    public function test_el_comite_calcula_su_vencimiento_a_dos_anios(): void
    {
        $this->comoConsultor()->post('/comites', [
            'tipo' => 'copasst', 'periodo' => 2026,
            'fecha_conformacion' => '2026-01-15', 'numero_trabajadores' => 120,
        ])->assertRedirect();

        $c = Committee::withoutTenantScope()->first();
        $this->assertSame('2028-01-15', $c->fecha_vencimiento->toDateString());
    }

    public function test_no_se_pueden_conformar_dos_comites_del_mismo_tipo_y_periodo(): void
    {
        $datos = ['tipo' => 'copasst', 'periodo' => 2026, 'fecha_conformacion' => '2026-01-15'];

        $this->comoConsultor()->post('/comites', $datos)->assertRedirect();
        $this->comoConsultor()->post('/comites', $datos)->assertSessionHasErrors('periodo');

        // El del otro comité sí entra en el mismo año.
        $this->comoConsultor()->post('/comites', ['tipo' => 'cocolab'] + $datos)->assertRedirect();
        $this->assertSame(2, Committee::withoutTenantScope()->count());
    }

    public function test_el_plan_del_comite_alimenta_el_indicador(): void
    {
        $this->comoConsultor()->post('/comites', [
            'tipo' => 'copasst', 'periodo' => 2026, 'fecha_conformacion' => '2026-01-15',
            'activities' => [
                ['descripcion' => 'Acta de conformación', 'programada' => true, 'ejecutada' => true],
                ['descripcion' => 'Reuniones mensuales', 'programada' => true, 'ejecutada' => false],
                ['descripcion' => 'Capacitación', 'programada' => true, 'ejecutada' => true],
                // Ejecutada sin programar: suma al numerador, no al denominador.
                ['descripcion' => 'Actividad extra', 'programada' => false, 'ejecutada' => true],
            ],
        ])->assertRedirect();

        // 3 ejecutadas / 3 programadas = 100 %
        $this->comoConsultor()->get('/comites')
            ->assertInertia(fn ($p) => $p->where('stats.cumplimiento', 100)->etc());
    }

    public function test_el_copasst_exige_paridad_entre_las_dos_partes(): void
    {
        $this->comoConsultor()->post('/comites', [
            'tipo' => 'copasst', 'periodo' => 2026, 'fecha_conformacion' => '2026-01-15',
            'numero_trabajadores' => 120,   // exige 2 por parte
            'members' => [
                ['nombres' => 'A', 'rol' => 'presidente', 'representa' => 'empleador'],
                ['nombres' => 'B', 'rol' => 'principal', 'representa' => 'empleador'],
                ['nombres' => 'C', 'rol' => 'principal', 'representa' => 'trabajadores'],
            ],
        ])->assertRedirect();

        $this->comoConsultor()->get('/comites')
            ->assertInertia(fn ($p) => $p->where('stats.sin_paridad', 1)->etc());
    }

    public function test_la_auditoria_solo_cuenta_no_conformidades(): void
    {
        $this->comoConsultor()->post('/auditoria', [
            'tipo' => 'interna', 'objetivo' => 'Verificar el SG-SST',
            'fecha_programada' => '2026-06-01', 'estado' => 'cerrada',
            'findings' => [
                ['tipo' => 'no_conformidad_mayor', 'descripcion' => 'X'],
                ['tipo' => 'no_conformidad_menor', 'descripcion' => 'Y'],
                ['tipo' => 'observacion', 'descripcion' => 'Z'],
                ['tipo' => 'fortaleza', 'descripcion' => 'W'],
            ],
        ])->assertRedirect();

        // 4 hallazgos, pero solo 2 son incumplimiento.
        $this->comoConsultor()->get('/auditoria')
            ->assertInertia(fn ($p) => $p
                ->where('stats.no_conformidades', 2)
                ->where('stats.sin_accion', 2)
                ->etc());
    }

    public function test_una_no_conformidad_con_accion_deja_de_contar_como_pendiente(): void
    {
        $accion = new AcpmAction([
            'tipo' => 'correctiva', 'hallazgo' => 'H', 'accion' => 'A', 'responsable' => 'Navi',
            'fecha_deteccion' => '2026-06-01', 'fecha_limite' => '2026-07-01',
        ]);
        $accion->tenant_id = $this->empresa->id;
        $accion->save();

        $this->comoConsultor()->post('/auditoria', [
            'tipo' => 'interna', 'objetivo' => 'O', 'fecha_programada' => '2026-06-01', 'estado' => 'cerrada',
            'findings' => [
                ['tipo' => 'no_conformidad_mayor', 'descripcion' => 'X', 'acpm_action_id' => $accion->id],
            ],
        ])->assertRedirect();

        $this->comoConsultor()->get('/auditoria')
            ->assertInertia(fn ($p) => $p->where('stats.sin_accion', 0)->etc());
    }

    public function test_la_auditoria_no_puede_terminar_antes_de_empezar(): void
    {
        $this->comoConsultor()->post('/auditoria', [
            'tipo' => 'interna', 'objetivo' => 'O', 'fecha_programada' => '2026-06-01', 'estado' => 'en_curso',
            'fecha_inicio' => '2026-06-10', 'fecha_fin' => '2026-06-01',
        ])->assertSessionHasErrors('fecha_fin');
    }

    public function test_el_mismo_epp_no_se_asigna_dos_veces_al_mismo_cargo(): void
    {
        $this->comoConsultor()->post('/epp/items', ['nombre' => 'Casco', 'categoria' => 'cabeza'])->assertRedirect();
        $item = PpeItem::withoutTenantScope()->first();

        $datos = ['ppe_item_id' => $item->id, 'cargo' => 'Operario', 'requerimiento' => 'requerido'];
        $this->comoConsultor()->post('/epp/matriz', $datos)->assertRedirect();
        $this->comoConsultor()->post('/epp/matriz', $datos)->assertSessionHasErrors('cargo');
    }

    public function test_una_entrega_sin_firma_queda_marcada(): void
    {
        $this->comoConsultor()->post('/epp/items', ['nombre' => 'Casco', 'categoria' => 'cabeza'])->assertRedirect();
        $item = PpeItem::withoutTenantScope()->first();
        $emp = $this->empleado();

        // Con firma.
        $this->comoConsultor()->post('/epp/entregas', [
            'employee_id' => $emp->id, 'ppe_item_id' => $item->id,
            'fecha_entrega' => '2026-09-01', 'cantidad' => 1, 'motivo' => 'dotacion',
            'recibido_por' => 'Ana Perez',
        ])->assertRedirect();

        // Sin firma.
        $this->comoConsultor()->post('/epp/entregas', [
            'employee_id' => $emp->id, 'ppe_item_id' => $item->id,
            'fecha_entrega' => '2026-09-02', 'cantidad' => 1, 'motivo' => 'reposicion',
        ])->assertRedirect();

        $firmada = PpeDelivery::withoutTenantScope()->where('fecha_entrega', '2026-09-01')->first();
        $this->assertTrue($firmada->firmada, 'con recibido_por la entrega tiene que quedar firmada');
        $this->assertSame('2026-09-01', $firmada->fecha_firma->toDateString(), 'la firma se fecha con la entrega');

        $this->comoConsultor()->get('/epp')
            ->assertInertia(fn ($p) => $p->where('stats.sin_firmar', 1)->etc());
    }

    /**
     * Un hallazgo invalido no puede dejar creada la auditoria.
     *
     * Antes se creaba el registro padre y DESPUES se validaban los hijos, asi
     * que un hallazgo sin descripcion dejaba una auditoria huerfana en la base
     * mientras la pantalla mostraba el error.
     */
    public function test_un_hallazgo_invalido_no_deja_la_auditoria_a_medias(): void
    {
        $this->comoConsultor()->post('/auditoria', [
            'tipo' => 'interna', 'objetivo' => 'O', 'fecha_programada' => '2026-06-01', 'estado' => 'programada',
            'findings' => [['tipo' => 'no_conformidad_mayor', 'descripcion' => '']],
        ])->assertSessionHasErrors('findings.0.descripcion');

        $this->assertSame(0, Audit::withoutTenantScope()->count(), 'no se pudo quedar creada la auditoria');
    }

    /** Lo mismo en comites: un integrante invalido no deja el comite a medias. */
    public function test_un_integrante_invalido_no_deja_el_comite_a_medias(): void
    {
        $this->comoConsultor()->post('/comites', [
            'tipo' => 'copasst', 'periodo' => 2026, 'fecha_conformacion' => '2026-01-15',
            'members' => [['nombres' => 'A', 'rol' => 'inventado', 'representa' => 'empleador']],
        ])->assertSessionHasErrors('members.0.rol');

        $this->assertSame(0, Committee::withoutTenantScope()->count(), 'no se pudo quedar creado el comite');
    }

    /**
     * La puerta por contrato tiene que cerrarse de verdad.
     *
     * Con el cliente demo no se notaba nada porque tiene `modulos = null` (todos
     * habilitados). El caso que importa es el cliente que contrata solo algunos:
     * ahi es donde un modulo fuera del catalogo queda inalcanzable para siempre.
     */
    public function test_un_cliente_sin_el_modulo_contratado_no_entra(): void
    {
        $this->empresa->update(['modulos' => ['iperc', 'comites']]);

        $this->comoConsultor()->get('/comites')->assertOk();
        $this->comoConsultor()->get('/epp')->assertForbidden();
        $this->comoConsultor()->get('/auditoria')->assertForbidden();
        $this->comoConsultor()->get('/acpm')->assertForbidden();
    }

    public function test_un_cliente_no_ve_los_comites_ni_las_auditorias_de_otro(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '900999999-1']);

        foreach ([$this->empresa, $otra] as $t) {
            $c = new Committee(['tipo' => 'copasst', 'periodo' => 2026, 'fecha_conformacion' => '2026-01-15']);
            $c->tenant_id = $t->id;
            $c->save();

            $a = new Audit(['tipo' => 'interna', 'objetivo' => 'O', 'fecha_programada' => '2026-06-01']);
            $a->tenant_id = $t->id;
            $a->save();
        }

        $this->comoConsultor()->get('/comites')->assertInertia(fn ($p) => $p->where('stats.total', 1)->etc());
        $this->comoConsultor()->get('/auditoria')->assertInertia(fn ($p) => $p->where('stats.total', 1)->etc());
    }
}
