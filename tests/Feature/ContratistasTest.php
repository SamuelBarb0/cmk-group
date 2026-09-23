<?php

namespace Tests\Feature;

use App\Models\ContractorEvaluation;
use App\Models\PesvContractor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EvaluacionesContratistas as EC;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contratistas: la calificación de cada formulario del Excel (ponderada,
 * por puntos y de chequeo), el enlace con el registro del PESV y el
 * aislamiento entre empresas.
 */
class ContratistasTest extends TestCase
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

    private function contratista(array $extra = []): PesvContractor
    {
        $this->comoConsultor()->post('/contratistas', array_merge([
            'nombre' => 'Transportes ABC', 'tipo' => 'contratista', 'persona' => 'juridica', 'is_active' => true,
        ], $extra))->assertSessionHasNoErrors();

        return PesvContractor::withoutTenantScope()->latest('id')->firstOrFail();
    }

    /** Todas las respuestas en la opción $i (o 'na'). */
    private function todas(string $formato, int|string $opcion): array
    {
        return collect(EC::claves(EC::formatos()[$formato]))
            ->mapWithKeys(fn ($k) => [$k => ['opcion' => $opcion]])->all();
    }

    /** La opción más baja de cada ítem (los ítems no tienen todos el mismo número de opciones). */
    private function peores(string $formato): array
    {
        return collect(EC::formatos()[$formato]['secciones'])->flatMap(fn ($s) => $s['items'])
            ->mapWithKeys(fn ($i) => [$i['key'] => ['opcion' => count($i['opciones']) - 1]])->all();
    }

    private function evaluar(PesvContractor $c, string $formato, array $respuestas, array $extra = [])
    {
        return $this->comoConsultor()->post("/contratistas/{$c->id}/evaluaciones", array_merge([
            'formato' => $formato, 'fecha' => now()->toDateString(), 'respuestas' => $respuestas,
        ], $extra));
    }

    public function test_las_pantallas_cargan(): void
    {
        $c = $this->contratista();
        $this->comoConsultor()->get('/contratistas')->assertOk()
            ->assertInertia(fn ($p) => $p->component('contratistas/index')->has('contratistas', 1));
        $this->comoConsultor()->get("/contratistas/{$c->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('contratistas/show')->has('documentos', 8));

        $this->flushSession();
        $this->actingAs($this->consultor)->get('/contratistas')->assertInertia(fn ($p) => $p->where('needsClient', true));
    }

    public function test_el_catalogo_coincide_con_los_excel(): void
    {
        $f = EC::formatos();
        $n = fn ($k) => count(EC::claves($f[$k]));
        $this->assertSame([12, 9, 21, 12, 20, 9], [$n('SELECCION'), $n('EVALUACION'), $n('SST_JURIDICA'), $n('SST_NATURAL'), $n('TERCERO_CONDUCTOR'), $n('TERCERO_VEHICULO')]);
        // Los pesos de la selección suman 100 % (40 comercial + 60 HSE).
        $pesos = collect($f['SELECCION']['secciones'])->flatMap(fn ($s) => $s['items'])->sum('peso');
        $this->assertEqualsWithDelta(1.0, $pesos, 0.0001);
    }

    public function test_la_seleccion_ponderada_entra_al_listado_con_3_8(): void
    {
        $f = EC::formatos()['SELECCION'];
        $this->assertSame(['puntaje' => 5.0, 'porcentaje' => 100.0, 'resultado' => 'apto', 'incumplimientos' => 0], EC::calificar($f, $this->todas('SELECCION', 0)));
        $this->assertSame('no_apto', EC::calificar($f, $this->todas('SELECCION', 1))['resultado']);  // todo «3» = 3,0 o menos

        // N/A vale la máxima (regla de la hoja): todo N/A es un 5.
        $this->assertSame(5.0, EC::calificar($f, $this->todas('SELECCION', 'na'))['puntaje']);

        // Todo en máximo menos «Requisitos legales» (15 %) en el nivel más bajo:
        // 5 − 0,15 × (5 − 1) = 4,40 → apto. Y además «SG-SST» (15 %) abajo: 3,80 → apto justo en el umbral.
        $r = $this->todas('SELECCION', 0);
        $r['requisitos_legales'] = ['opcion' => 1];   // ese criterio solo tiene dos niveles: el segundo vale 1
        $this->assertSame(4.4, EC::calificar($f, $r)['puntaje']);
        $r['sgsst'] = ['opcion' => 1];
        $this->assertSame(['puntaje' => 3.8, 'resultado' => 'apto'], array_intersect_key(EC::calificar($f, $r), ['puntaje' => 1, 'resultado' => 1]));
        $r['riesgos'] = ['opcion' => 1];               // «accidentes menores» = 3: 3,8 − 0,1 × 2 = 3,6
        $this->assertSame('no_apto', EC::calificar($f, $r)['resultado']);
    }

    public function test_la_evaluacion_por_puntos_clasifica_con_las_bandas(): void
    {
        $f = EC::formatos()['EVALUACION'];
        $this->assertSame('confiable', EC::calificar($f, $this->todas('EVALUACION', 0))['resultado']);

        // Todo «casi siempre» (3) y los sí/no en «sí» (5): 6×3 + 3×5 = 33 de 45 = 73,3 % → regular.
        $r = $this->todas('EVALUACION', 1);
        foreach (['fichas_seguridad', 'ambiental', 'sst'] as $k) {
            $r[$k] = ['opcion' => 0];
        }
        $this->assertSame(['porcentaje' => 73.3, 'resultado' => 'regular'], array_intersect_key(EC::calificar($f, $r), ['porcentaje' => 1, 'resultado' => 1]));

        // Todo «nunca»/«no»: 6 de 45 = 13,3 % → no confiable.
        $this->assertSame(['porcentaje' => 13.3, 'resultado' => 'no_confiable'], array_intersect_key(EC::calificar($f, $this->peores('EVALUACION')), ['porcentaje' => 1, 'resultado' => 1]));

        $this->assertSame('confiable', EC::clasificar(87.5));
        $this->assertSame('regular', EC::clasificar(87.4));
        $this->assertSame('regular', EC::clasificar(50.0));
        $this->assertSame('no_confiable', EC::clasificar(49.9));
    }

    public function test_la_lista_de_chequeo_cuenta_incumplimientos_y_na_cumple(): void
    {
        $f = EC::formatos()['SST_NATURAL'];
        $r = $this->todas('SST_NATURAL', 'na');
        $this->assertSame('cumple', EC::calificar($f, $r)['resultado']);
        $r['n2_planillas'] = ['opcion' => 1];
        $r['n2_epp'] = ['opcion' => 1];
        $this->assertSame(['resultado' => 'no_cumple', 'incumplimientos' => 2], array_intersect_key(EC::calificar($f, $r), ['resultado' => 1, 'incumplimientos' => 1]));
    }

    public function test_arranca_con_los_documentos_de_su_tipo_de_persona(): void
    {
        $this->assertSame(8, $this->contratista()->documents()->count());
        $natural = $this->contratista(['nombre' => 'Juan Pérez', 'persona' => 'natural']);
        $this->assertSame(['rut', 'cedula_representante', 'certificacion_bancaria', 'seguridad_social', 'carta_arl'], $natural->documents()->pluck('tipo')->all());
    }

    public function test_una_evaluacion_incompleta_no_se_guarda(): void
    {
        $c = $this->contratista();
        $r = $this->todas('EVALUACION', 0);
        unset($r['precios']);
        $r['calidad'] = ['opcion' => 7];   // opción que no existe

        $this->evaluar($c, 'EVALUACION', $r)->assertSessionHasErrors('respuestas');
        $this->evaluar($c, 'NO_EXISTE', $r)->assertSessionHasErrors('formato');
        $this->assertSame(0, ContractorEvaluation::withoutTenantScope()->count());
    }

    public function test_la_evaluacion_se_refleja_en_el_registro_del_pesv(): void
    {
        $c = $this->contratista();
        $this->evaluar($c, 'EVALUACION', $this->todas('EVALUACION', 0), ['fecha' => '2026-05-10'])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('2026-05-10', $c->evaluado_at->toDateString());
        $this->assertSame(100, $c->calificacion);

        // Una selección no toca la calificación del PESV.
        $this->evaluar($c, 'SELECCION', $this->peores('SELECCION'))->assertSessionHasNoErrors();
        $this->assertSame(100, $c->fresh()->calificacion);

        // Borrar la única evaluación deja el PESV sin nota, no con una que ya no existe.
        $id = ContractorEvaluation::withoutTenantScope()->where('uso', 'evaluacion')->value('id');
        $this->comoConsultor()->delete("/contratistas/evaluaciones/{$id}");
        $this->assertNull($c->fresh()->calificacion);
        $this->assertNull($c->fresh()->evaluado_at);
    }

    public function test_editar_califica_con_la_estructura_guardada(): void
    {
        $c = $this->contratista();
        $this->evaluar($c, 'EVALUACION', $this->todas('EVALUACION', 0))->assertSessionHasNoErrors();
        $e = ContractorEvaluation::withoutTenantScope()->first();

        // CMK cambia el formulario: la evaluación vieja conserva sus 9 criterios.
        $estructura = $e->estructura;
        array_pop($estructura['secciones'][0]['items']);
        $e->estructura = $estructura;
        $e->save();

        $r = $this->todas('EVALUACION', 0);
        unset($r['sst']);   // ya no está en SU estructura: no se exige
        $this->comoConsultor()->put("/contratistas/evaluaciones/{$e->id}", ['fecha' => now()->toDateString(), 'respuestas' => $r])
            ->assertSessionHasNoErrors();
        $this->assertSame(100.0, $e->fresh()->porcentaje);
    }

    public function test_la_situacion_calcula_proxima_reevaluacion_y_documentos(): void
    {
        $c = $this->contratista();
        $mitad = $this->todas('EVALUACION', 1);        // 6×3 + 3×0 = 18/45 = 40 %
        $this->evaluar($c, 'EVALUACION', $this->todas('EVALUACION', 0), ['fecha' => now()->subMonths(6)->toDateString()]);
        $this->evaluar($c, 'EVALUACION', $mitad, ['fecha' => now()->subMonths(5)->toDateString()]);

        $this->comoConsultor()->put("/contratistas/{$c->id}", [
            'nombre' => $c->nombre, 'tipo' => 'contratista', 'persona' => 'juridica', 'is_active' => true,
            'documentos' => [
                ['tipo' => 'rut', 'estado' => 'recibido'],
                ['tipo' => 'poliza', 'estado' => 'recibido', 'fecha_vencimiento' => now()->subDay()->toDateString()],
                ['tipo' => 'sgsst_arl', 'estado' => 'recibido', 'fecha_vencimiento' => now()->addDays(10)->toDateString()],
                ['tipo' => 'camara_comercio', 'estado' => 'no_entregado'],
                ['tipo' => 'certificaciones_calidad', 'estado' => 'no_aplica', 'fecha_vencimiento' => now()->subYear()->toDateString()],
            ],
        ])->assertSessionHasNoErrors();

        $s = $c->fresh()->load(['documents', 'evaluations'])->situacion((int) now()->year);
        $this->assertSame('no_confiable', $s['evaluacion']['resultado']);   // la última: 40 %
        $this->assertTrue($s['evaluacion_vencida']);                        // hace 5 meses > 4
        $this->assertSame(1, $s['documentos_vencidos']);
        $this->assertSame(1, $s['documentos_por_vencer']);
        $this->assertSame(1, $s['documentos_pendientes']);                  // el «no aplica» vencido no alerta

        // Reevaluación = promedio de las del año (100 y 40 → 70 = regular), si las dos caen en este año.
        if (now()->subMonths(6)->year === now()->year) {
            $this->assertSame(['porcentaje' => 70.0, 'resultado' => 'regular'], array_intersect_key($s['reevaluacion'], ['porcentaje' => 1, 'resultado' => 1]));
        }
    }

    public function test_el_pesv_sigue_funcionando_y_no_borra_lo_nuevo(): void
    {
        $c = $this->contratista(['ciudad' => 'Barranquilla', 'supervisor' => 'Ana']);
        $this->comoConsultor()->get('/pesv/contratistas')->assertOk();

        $this->comoConsultor()->put("/pesv/contratistas/{$c->id}", [
            'nombre' => 'Transportes ABC SAS', 'tipo' => 'contratista', 'tiene_pesv' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('Transportes ABC SAS', $c->nombre);
        $this->assertSame('Barranquilla', $c->ciudad);
        $this->assertSame('juridica', $c->persona);
    }

    public function test_no_se_toca_un_contratista_ni_una_evaluacion_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Otra', 'nit' => '800000000-1']);
        $ajeno = new PesvContractor(['nombre' => 'Ajeno', 'tipo' => 'contratista']);
        $ajeno->tenant_id = $otra->id;
        $ajeno->save();
        $ev = new ContractorEvaluation([
            'pesv_contractor_id' => $ajeno->id, 'formato' => 'EVALUACION', 'uso' => 'evaluacion', 'fecha' => now(),
            'estructura' => EC::formatos()['EVALUACION'], 'respuestas' => $this->todas('EVALUACION', 0),
            'puntaje' => 45, 'porcentaje' => 100, 'resultado' => 'confiable',
        ]);
        $ev->tenant_id = $otra->id;
        $ev->save();

        foreach ([
            fn () => $this->comoConsultor()->get("/contratistas/{$ajeno->id}"),
            fn () => $this->comoConsultor()->put("/contratistas/{$ajeno->id}", ['nombre' => 'x', 'tipo' => 'contratista', 'persona' => 'natural', 'documentos' => []]),
            fn () => $this->evaluar($ajeno, 'EVALUACION', $this->peores('EVALUACION')),
            fn () => $this->comoConsultor()->put("/contratistas/evaluaciones/{$ev->id}", ['fecha' => now()->toDateString(), 'respuestas' => $this->peores('EVALUACION')]),
            fn () => $this->comoConsultor()->delete("/contratistas/evaluaciones/{$ev->id}"),
            fn () => $this->comoConsultor()->delete("/contratistas/{$ajeno->id}"),
        ] as $peticion) {
            // Singleton fresco: ver la cabecera de AislamientoEntreEmpresasTest.
            $this->app->forgetInstance(TenantContext::class);
            $peticion()->assertNotFound();
        }

        $this->assertSame('Ajeno', PesvContractor::withoutTenantScope()->find($ajeno->id)->nombre);
        $this->assertSame(100.0, ContractorEvaluation::withoutTenantScope()->find($ev->id)->porcentaje);
        $this->assertSame(1, ContractorEvaluation::withoutTenantScope()->count());
    }
}
