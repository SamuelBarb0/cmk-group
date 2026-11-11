<?php

namespace App\Http\Controllers;

use App\Models\GeneratedDocument;
use App\Models\SstDiagnostic;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\Agenda;
use App\Services\Indicadores\Tablero;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Cifras por tarjeta de módulo: las primeras de su sección del informe. */
    private const CIFRAS_POR_MODULO = 4;

    /** Alertas de la vista consolidada: las más urgentes de todos los clientes. */
    private const ALERTAS_CONSOLIDADAS = 20;

    /**
     * Cifras de cumplimiento del sistema: [sección del informe, cifra, título
     * en el dashboard]. Se toman tal cual de la sección (valor y alerta), así
     * que el semáforo del panel es el mismo criterio del informe de gestión.
     */
    private const CUMPLIMIENTO = [
        ['diagnostico', 'Puntaje', 'Estándares mínimos 0312'],
        ['plan-trabajo', 'Cumplimiento del plan', 'Plan de trabajo'],
        ['capacitaciones', 'Ejecución', 'Capacitaciones ejecutadas'],
        ['acpm', 'Vencidas hoy', 'ACPM vencidas'],
    ];

    public function __invoke(Request $request, TenantContext $context, Agenda $agenda, InformeGestion $informe, Tablero $tablero): Response
    {
        $user = $request->user();

        if ($user->belongsToCmk() && ! $context->has()) {
            return $this->maestro($user, $context, $agenda, $tablero);
        }

        // Dashboard individual del cliente (o consultor trabajando un cliente).
        $tenant = $context->get();
        $items = $tenant ? $agenda->items($user) : [];

        // Cada sección del informe se genera UNA vez y alimenta las tarjetas,
        // el cumplimiento y las alertas.
        $periodo = Periodo::desde(null, null);
        $secciones = $tenant
            ? collect($informe->disponibles($user))->map(fn (Seccion $s) => ['seccion' => $s, 'datos' => $s->generar($periodo)])->values()
            : collect();

        $indicadores = $tenant && $this->veIndicadores($user, $tenant)
            ? $tablero->indicadores((int) now()->year)
            : collect();

        $modulos = $this->modulos($secciones, $items);

        return Inertia::render('dashboard', [
            'view' => 'client',
            'tenant' => $tenant?->only(['id', 'name', 'nit', 'city']),
            'stats' => [
                'users' => $tenant ? User::where('tenant_id', $tenant->id)->count() : 0,
            ],
            'agenda' => $items,
            'agenda_resumen' => Agenda::resumen($items),
            'agenda_dias' => ['por_vencer' => Agenda::DIAS_POR_VENCER, 'horizonte' => Agenda::HORIZONTE],
            'modulos' => $modulos,
            'kpis' => $this->kpis($indicadores),
            'indicadores' => $this->tablaIndicadores($indicadores),
            'cumplimiento' => $this->cumplimiento($secciones),
            'alertas' => $tenant ? $this->alertas($user, $tenant, $indicadores, $modulos, $tablero) : [],
        ]);
    }

    /** ¿Tiene sentido mostrarle indicadores? Módulo contratado y permiso de verlos. */
    private function veIndicadores(User $user, Tenant $tenant): bool
    {
        return $tenant->moduloHabilitado('indicadores') && $user->can('sst.view');
    }

    /**
     * Una tarjeta por módulo contratado que el usuario puede ver: sus cifras
     * del año en curso y sus alertas. Salen de la MISMA sección del informe de
     * gestión, así que el panel y el informe nunca cuentan distinto.
     *
     * @param  Collection<int, array{seccion: Seccion, datos: array}>  $secciones
     * @param  list<array>  $agenda
     * @return list<array>
     */
    private function modulos(Collection $secciones, array $agenda): array
    {
        $porModulo = collect($agenda)->groupBy('modulo');

        return $secciones
            ->map(function (array $g) use ($porModulo) {
                ['seccion' => $s, 'datos' => $datos] = $g;
                $modulo = $s->modulo() ?? ($s->clave() === 'empresa' ? 'organizacion' : $s->clave());
                $fechas = collect($porModulo->get($modulo, []));

                return [
                    'clave' => $s->clave(),
                    'modulo' => $modulo,
                    'titulo' => $datos['titulo'],
                    'url' => $this->urlModulo($modulo),
                    'cifras' => collect($datos['cifras'])->take(self::CIFRAS_POR_MODULO)
                        ->map(fn ($c) => ['etiqueta' => $c['etiqueta'], 'valor' => $c['valor']])->values()->all(),
                    'alertas' => collect($datos['cifras'])->pluck('alerta')->filter()->values()->all(),
                    'vencidos' => $fechas->where('estado', 'vencido')->count(),
                    'por_vencer' => $fechas->where('estado', 'por_vencer')->count(),
                ];
            })
            ->values()->all();
    }

    /**
     * Lo que el dashboard necesita de un indicador: resultado, meta, semáforo
     * y la serie de 12 meses para la mini-gráfica.
     *
     * @param  array<string, mixed>  $i  un registro de Tablero::indicadores()
     * @return array<string, mixed>
     */
    private static function indicador(array $i): array
    {
        return [
            'codigo' => $i['codigo'],
            'nombre' => $i['nombre'],
            'categoria' => $i['categoria'],
            'unidad' => $i['unidad'],
            'sentido' => $i['sentido'],
            'meta' => $i['meta'],
            'valor' => $i['promedio'],
            'cumple' => $i['cumple'],
            'es_legal' => $i['es_legal'],
            'serie' => array_values(array_map(fn (array $m) => $m['valor'], $i['meses'])),
        ];
    }

    /**
     * Los mínimos de la Res. 0312 en su orden, midan o no: un mínimo sin
     * medir es justo lo que el panel debe dejar a la vista.
     *
     * @param  Collection<int, array<string, mixed>>  $indicadores
     * @return list<array<string, mixed>>
     */
    private function kpis(Collection $indicadores): array
    {
        $porCodigo = $indicadores->keyBy('codigo');

        return collect(Tablero::MINIMOS_0312)
            ->map(fn (string $codigo) => $porCodigo->get($codigo))
            ->filter()
            ->map(fn (array $i) => self::indicador($i))
            ->values()->all();
    }

    /**
     * Todos los indicadores que ya tienen lecturas en el año, los que no
     * cumplen primero. Los que no se han medido solo se cuentan: listar 50
     * filas vacías esconde las que importan.
     *
     * @param  Collection<int, array<string, mixed>>  $indicadores
     * @return array{filas: list<array<string, mixed>>, sin_medir: int, total: int}
     */
    private function tablaIndicadores(Collection $indicadores): array
    {
        $medidos = $indicadores->filter(fn (array $i) => $i['promedio'] !== null);

        return [
            'filas' => $medidos
                ->sortBy(fn (array $i) => match ($i['cumple']) {
                    false => 0,
                    null => 1,
                    true => 2,
                })
                ->map(fn (array $i) => self::indicador($i))
                ->values()->all(),
            'sin_medir' => $indicadores->count() - $medidos->count(),
            'total' => $indicadores->count(),
        ];
    }

    /**
     * Las cifras de cumplimiento, cada una con su semáforo: «mal» si la
     * sección le puso alerta, «sin_dato» si no hay con qué calcularla.
     *
     * @param  Collection<int, array{seccion: Seccion, datos: array}>  $secciones
     * @return list<array<string, mixed>>
     */
    private function cumplimiento(Collection $secciones): array
    {
        $porClave = $secciones->keyBy(fn (array $g) => $g['seccion']->clave());

        return collect(self::CUMPLIMIENTO)
            ->filter(fn (array $c) => $porClave->has($c[0]))
            ->map(function (array $c) use ($porClave) {
                [$clave, $etiqueta, $titulo] = $c;
                $g = $porClave->get($clave);
                $cifra = collect($g['datos']['cifras'])->firstWhere('etiqueta', $etiqueta);
                $valor = $cifra['valor'] ?? null;

                return [
                    'clave' => $clave,
                    'modulo' => $g['seccion']->modulo() ?? $clave,
                    'titulo' => $titulo,
                    'valor' => $valor === '—' ? null : $valor,
                    'estado' => match (true) {
                        $valor === null || $valor === '—' => 'sin_dato',
                        filled($cifra['alerta'] ?? null) => 'mal',
                        default => 'bien',
                    },
                    'detalle' => $cifra['alerta'] ?? null,
                    'url' => $this->urlModulo($g['seccion']->modulo() ?? $clave),
                ];
            })
            ->values()->all();
    }

    /**
     * Centro de alertas: lo que hay que mirar hoy, lo grave primero.
     *
     * - alta: indicadores LEGALES por fuera de la meta.
     * - media: indicadores propios por fuera de la meta, el mes pasado sin
     *   reportar, mínimos de la 0312 sin medir, documentos esperando
     *   aprobación y las alertas de cada módulo (las del informe de gestión).
     *
     * Lo vencido por fecha ya tiene su agenda y no se repite aquí.
     *
     * @param  Collection<int, array<string, mixed>>  $indicadores
     * @param  list<array>  $modulos
     * @return list<array{nivel: string, modulo: string, titulo: string, texto: string, url: ?string}>
     */
    private function alertas(User $user, Tenant $tenant, Collection $indicadores, array $modulos, Tablero $tablero): array
    {
        $alertas = collect();
        $urlIndicadores = $this->urlModulo('indicadores');

        foreach ($indicadores->whereStrict('cumple', false) as $i) {
            $comparador = $i['sentido'] === 'asc' ? '≥' : '≤';
            // «tasa» es el tipo de unidad, no algo que se escriba tras el número.
            $pct = $i['unidad'] === '%' ? ' %' : '';
            $alertas->push([
                'nivel' => $i['es_legal'] ? 'alta' : 'media',
                'modulo' => 'indicadores',
                'titulo' => "{$i['codigo']} fuera de meta",
                'texto' => "{$i['nombre']}: ".self::numero($i['promedio'])."{$pct} (meta {$comparador} ".self::numero($i['meta'])."{$pct})",
                'url' => $urlIndicadores,
            ]);
        }

        if ($indicadores->isNotEmpty()) {
            // El mes pasado, solo para los que SÍ se vienen midiendo este año:
            // un indicador que la empresa no usa no «falta», no le aplica. En
            // enero no se revisa: el mes pasado es del año anterior y este año
            // todavía no hay nada medido con qué compararlo.
            $anterior = now()->startOfMonth()->subMonth();
            $sinReportar = collect();
            if ($anterior->year === (int) now()->year) {
                $conLectura = $tablero->conLecturaEn($anterior->year, $anterior->month);
                $sinReportar = $indicadores
                    ->filter(fn (array $i) => $i['promedio'] !== null)
                    ->reject(fn (array $i) => in_array($i['id'], $conLectura, true));
            }
            if ($sinReportar->isNotEmpty()) {
                $mes = $anterior->locale('es')->translatedFormat('F');
                $alertas->push([
                    'nivel' => 'media',
                    'modulo' => 'indicadores',
                    'titulo' => "Sin reportar {$mes}",
                    'texto' => $sinReportar->count().' indicador(es) que se vienen midiendo no tienen lectura de '.$mes.': '
                        .$sinReportar->pluck('codigo')->take(6)->implode(', ').($sinReportar->count() > 6 ? '…' : '').'.',
                    'url' => $urlIndicadores,
                ]);
            }

            $minimosSinMedir = $indicadores->whereIn('codigo', Tablero::MINIMOS_0312)->whereNull('promedio');
            if ($minimosSinMedir->isNotEmpty()) {
                $alertas->push([
                    'nivel' => 'media',
                    'modulo' => 'indicadores',
                    'titulo' => 'Mínimos de la Res. 0312 sin medir',
                    'texto' => $minimosSinMedir->count().' de '.count(Tablero::MINIMOS_0312).' indicadores mínimos sin lecturas este año: '
                        .$minimosSinMedir->pluck('codigo')->implode(', ').'.',
                    'url' => $urlIndicadores,
                ]);
            }
        }

        if ($tenant->moduloHabilitado('documentos-ia') && $user->can('documents.view')) {
            $enRevision = GeneratedDocument::where('estado', 'en_revision')->count();
            if ($enRevision > 0) {
                $alertas->push([
                    'nivel' => 'media',
                    'modulo' => 'documentos-ia',
                    'titulo' => 'Documentos por aprobar',
                    'texto' => "{$enRevision} documento(s) en revisión esperando aprobación.",
                    'url' => $this->urlModulo('documentos-ia'),
                ]);
            }
        }

        // Las alertas de cada módulo. La de Indicadores se omite: arriba ya
        // va desglosada indicador por indicador.
        foreach ($modulos as $m) {
            if ($m['modulo'] === 'indicadores') {
                continue;
            }
            foreach ($m['alertas'] as $texto) {
                $alertas->push(['nivel' => 'media', 'modulo' => $m['modulo'], 'titulo' => $m['titulo'], 'texto' => $texto, 'url' => $m['url']]);
            }
        }

        return $alertas->sortBy(fn (array $a) => $a['nivel'] === 'alta' ? 0 : 1)->values()->all();
    }

    /** «7,25»: coma decimal y sin ceros de más, como en el informe. */
    private static function numero(?float $valor): string
    {
        if ($valor === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');
    }

    private function urlModulo(string $modulo): ?string
    {
        $nombre = match ($modulo) {
            'organizacion' => 'organizacion.show',
            'inspecciones' => 'formatos.index',
            default => $modulo.'.index',
        };

        return Route::has($nombre) ? route($nombre) : null;
    }

    /**
     * Vista consolidada del consultor: por cada cliente activo, cuánto tiene
     * vencido y por vencer, y las alertas más urgentes de todos juntos.
     *
     * La agenda de cada cliente se calcula con SU contexto (módulos contratados
     * y scope de datos) y al final se deja el contexto como estaba: sin cliente.
     */
    private function maestro(User $user, TenantContext $context, Agenda $agenda, Tablero $tablero): Response
    {
        $clientes = [];
        $alertas = collect();

        try {
            foreach (Tenant::where('is_active', true)->orderBy('name')->get() as $tenant) {
                $context->set($tenant);
                $items = collect($agenda->items($user));

                // null = no aplica (módulo no contratado o sin datos), distinto de 0.
                $fueraDeMeta = $this->veIndicadores($user, $tenant)
                    ? $tablero->indicadores((int) now()->year)->whereStrict('cumple', false)->count()
                    : null;
                // Igual que la sección del informe: una autoevaluación sin
                // estándares evaluados no es un 0 %, es «sin dato».
                $diagnostico = $tenant->moduloHabilitado('diagnostico') && $user->can('sst.view')
                    ? SstDiagnostic::withCount('items')->first()
                    : null;
                $estandares = $diagnostico && $diagnostico->items_count > 0 ? $diagnostico->puntaje : null;

                $clientes[] = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'nit' => $tenant->nit,
                    'city' => $tenant->city,
                    'users_count' => User::where('tenant_id', $tenant->id)->count(),
                    'fuera_meta' => $fueraDeMeta,
                    'estandares' => $estandares === null ? null : (float) $estandares,
                ] + Agenda::resumen($items->all());

                $alertas = $alertas->concat($items->whereIn('estado', ['vencido', 'por_vencer'])
                    ->map(fn (array $i) => $i + ['tenant' => ['id' => $tenant->id, 'name' => $tenant->name]]));
            }
        } finally {
            $context->set(null);
        }

        // Con alertas primero: el consultor abre el panel para saber dónde ir.
        usort($clientes, fn ($a, $b) => [$b['vencido'], $b['por_vencer'], $a['name']] <=> [$a['vencido'], $a['por_vencer'], $b['name']]);

        return Inertia::render('dashboard', [
            'view' => 'master',
            'stats' => [
                'clients' => Tenant::count(),
                'clients_active' => Tenant::where('is_active', true)->count(),
                'client_users' => User::whereNotNull('tenant_id')->count(),
                'cmk_users' => User::whereNull('tenant_id')->count(),
            ],
            'clientes' => $clientes,
            'alertas' => $alertas->sortBy('fecha')->take(self::ALERTAS_CONSOLIDADAS)->values()->all(),
            'agenda_dias' => ['por_vencer' => Agenda::DIAS_POR_VENCER, 'horizonte' => Agenda::HORIZONTE],
        ]);
    }
}
