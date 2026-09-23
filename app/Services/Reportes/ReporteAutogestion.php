<?php

namespace App\Services\Reportes;

use App\Http\Controllers\PesvEstadisticaController;
use App\Http\Controllers\PesvRiesgoVialController;
use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\Employee;
use App\Models\Indicator;
use App\Models\IndicatorGoal;
use App\Models\IndicatorReading;
use App\Models\ManagementProgram;
use App\Models\PesvAutogestion;
use App\Models\PesvContractor;
use App\Models\PesvCriterion;
use App\Models\PesvInfraction;
use App\Models\PesvKmPeriodo;
use App\Models\PesvMobilitySurvey;
use App\Models\PesvPlan;
use App\Models\PesvRoadRisk;
use App\Models\PesvSiniestro;
use App\Models\PesvStep;
use App\Models\PesvVehicle;
use App\Models\ProgramPlan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reporte de autogestión anual del PESV (Res. 40595, paso 20).
 *
 * Se radica ante la entidad verificadora a más tardar el 31 de enero, con
 * corte al 31 de diciembre, y lleva los literales a) a l) y los indicadores
 * de la Tabla 10. Casi todo sale de los datos del módulo; lo que la
 * plataforma no puede saber lo guarda PesvAutogestion. Lo que falta se
 * lista como «información pendiente», que es lo primero que ve el consultor.
 *
 * Devuelve la misma estructura que InformeGestion::generar(), así que se
 * exporta a Word y PDF con InformeWord / InformePdf.
 */
class ReporteAutogestion
{
    /**
     * Tabla 10: número => [código del indicador, nombre, frecuencia, niveles donde aplica].
     * Los tres primeros no son un cociente num/den y se calculan aparte.
     */
    public const TABLA_10 = [
        1 => [null, 'Tasa de siniestros viales por nivel de pérdida TSV(n)', 'Trimestral y acumulado año', ['basico', 'estandar', 'avanzado']],
        2 => [null, 'Costos de siniestros viales por nivel de pérdida $SV(n)', 'Trimestral y acumulado año', ['estandar', 'avanzado']],
        3 => [null, 'Riesgos de seguridad vial identificados (RSVI) y gestión de riesgos viales (GRV)', 'Anual', ['basico', 'estandar', 'avanzado']],
        4 => ['PESV-CM', 'Cumplimiento de metas del PESV (CM PESV)', 'Trimestral y acumulado año', ['basico', 'estandar', 'avanzado']],
        5 => ['PESV-CPLAN', 'Cumplimiento de actividades del plan anual (CPlan PESV)', 'Trimestral y acumulado año', ['basico', 'estandar', 'avanzado']],
        6 => ['PESV-EJL', 'Exceso de jornadas laborales de conductores (%EJLC)', 'Mensual y acumulado año', ['basico', 'estandar', 'avanzado']],
        7 => ['PESV-GVE', 'Cobertura del programa de gestión de la velocidad (GVE)', 'Mensual y acumulado año', ['estandar', 'avanzado']],
        8 => ['PESV-ELVL', 'Excesos del límite de velocidad laboral (ELVL)', 'Acumulado mes y año', ['avanzado']],
        9 => ['PESV-IDP', 'Inspecciones diarias preoperacionales (IDP)', 'Acumulado mes y año', ['basico', 'estandar', 'avanzado']],
        10 => ['PESV-CPMV', 'Cumplimiento del plan de mantenimiento preventivo (CPMVh)', 'Trimestral y acumulado año', ['basico', 'estandar', 'avanzado']],
        11 => ['PESV-CPF', 'Cumplimiento del plan de formación en seguridad vial', 'Trimestral y acumulado año', ['basico', 'estandar', 'avanzado']],
        12 => ['PESV-COBF', 'Cobertura del plan de formación en seguridad vial', 'Acumulado trimestre y año', ['basico', 'estandar', 'avanzado']],
        13 => ['PESV-NCAC', 'No conformidades de auditoría cerradas (NCAC)', 'Anual', ['basico', 'estandar', 'avanzado']],
    ];

    private const NIVELES = ['basico' => 'Básico', 'estandar' => 'Estándar', 'avanzado' => 'Avanzado'];

    private int $anio;

    private Tenant $tenant;

    private PesvPlan $plan;

    private PesvAutogestion $datos;

    /** @var list<array<string, mixed>> */
    private array $secciones = [];

    /**
     * @return array<string, mixed>
     */
    public function generar(Tenant $tenant, PesvPlan $plan, PesvAutogestion $datos, int $anio, string $por): array
    {
        $this->tenant = $tenant;
        $this->plan = $plan;
        $this->datos = $datos;
        $this->anio = $anio;
        $this->secciones = [];

        $this->identificacion();
        $this->lider();
        $this->auditores();
        $this->representante();
        $this->misionalidad();
        $this->flota();
        $this->actoresViales();
        $this->contratistas();
        $this->objetivos();
        $this->programas();
        $this->infracciones();
        $this->auditoria();
        $this->tabla10();

        $pendiente = collect($this->secciones)->flatMap(fn (array $s) => collect($s['cifras'])
            ->pluck('alerta')->filter()->map(fn (string $a) => ['seccion' => $s['titulo'], 'texto' => $a]))->values();

        return [
            'titulo' => 'Reporte de autogestión del PESV',
            'archivo' => 'reporte-autogestion-pesv-'.Str::slug($tenant->name).'-'.$anio,
            'atencion_titulo' => 'Información pendiente para el reporte',
            'atencion_vacia' => 'El reporte tiene toda la información que exige el paso 20 de la Res. 40595.',
            'observaciones_titulo' => 'Análisis de los indicadores por el comité de seguridad vial',
            'firmas' => [
                ['Elaboró', $plan->lider_nombre ?? '', 'Líder del diseño e implementación del PESV'],
                ['Aprobó', $tenant->representante_legal ?? '', 'Representante legal · '.$tenant->name],
            ],
            'empresa' => [
                'nombre' => $tenant->name,
                'razon_social' => $tenant->legal_name ?: $tenant->name,
                'nit' => $tenant->nit,
                'ciudad' => $tenant->city,
            ],
            'periodo' => [
                'desde' => "{$anio}-01-01",
                'hasta' => "{$anio}-12-31",
                'etiqueta' => "Año {$anio} · corte al 31 de diciembre · se reporta a más tardar el 31 de enero de ".($anio + 1),
            ],
            'generado' => [
                'fecha' => Carbon::now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'por' => $por,
            ],
            'atencion' => $pendiente->all(),
            'secciones' => $this->secciones,
        ];
    }

    // ---- Literales del paso 20 ----------------------------------------------

    private function identificacion(): void
    {
        $t = $this->tenant;
        $this->seccion('a) Identificación de la organización', [
            $this->dato('Razón social', $t->legal_name ?: $t->name),
            $this->dato('NIT', $t->nit, 'Falta el NIT de la empresa en Organización.'),
            $this->dato('Dirección del domicilio principal', trim(($t->address ?? '').($t->city ? ', '.$t->city : ''), ', '), 'Falta la dirección de la empresa en Organización.'),
            $this->dato('Teléfono', $t->phone, 'Falta el teléfono de la empresa en Organización.'),
        ]);
    }

    private function lider(): void
    {
        $this->seccion('b) Líder del diseño e implementación del PESV', [
            $this->dato('Nombre', $this->plan->lider_nombre, 'No hay líder del PESV designado (paso 1).'),
            $this->dato('Cargo', $this->plan->lider_cargo, 'Falta el cargo del líder del PESV.'),
            $this->dato('Correo electrónico institucional', $this->datos->lider_email, 'Falta el correo institucional del líder del PESV.'),
        ]);
    }

    private function auditores(): void
    {
        $auditores = collect($this->datos->auditores ?? [])->filter(fn ($a) => filled($a['nombre'] ?? null));
        $sinCorreo = $auditores->filter(fn ($a) => blank($a['email'] ?? null) || blank($a['cargo'] ?? null))->count();

        $this->seccion('c) Auditores del PESV en el último año', [
            $this->cifra('Auditores registrados', $auditores->count(), $auditores->isEmpty()
                ? 'Registra el nombre, cargo y correo de quien auditó el PESV en '.$this->anio.'.'
                : ($sinCorreo ? "{$sinCorreo} auditor(es) sin cargo o correo." : null)),
        ], [
            $this->tabla('Auditores', ['Nombre', 'Cargo', 'Correo electrónico'],
                $auditores->map(fn ($a) => [$a['nombre'], $a['cargo'] ?? '—', $a['email'] ?? '—']), 'Sin auditores registrados.'),
        ]);
    }

    private function representante(): void
    {
        $this->seccion('d) Representante legal', [
            $this->dato('Nombre', $this->tenant->representante_legal, 'Falta el representante legal en Organización.'),
        ]);
    }

    private function misionalidad(): void
    {
        [$vehiculos, $conductores] = [PesvVehicle::where('is_active', true)->count(), Employee::where('is_active', true)->conductores()->count()];
        $misionalidad = $this->plan->misionalidad;
        $porNorma = $misionalidad ? PesvPlan::nivelPorNorma($misionalidad, $vehiculos, $conductores) : null;

        $this->seccion('e) Misionalidad y tamaño de la organización', [
            $this->dato('Misionalidad', $misionalidad ? PesvPlan::MISIONALIDADES[$misionalidad] : null, 'Falta la misionalidad en el plan PESV.'),
            $this->cifra('Nivel del PESV', self::NIVELES[$this->plan->nivel] ?? $this->plan->nivel,
                $porNorma && $porNorma !== $this->plan->nivel ? 'Según la Tabla 1, con esta flota y conductores el nivel es «'.self::NIVELES[$porNorma].'».' : null),
            $this->dato('Vehículos para desplazamientos laborales', $vehiculos),
            $this->dato('Conductores', $conductores),
        ]);
    }

    private function flota(): void
    {
        $flota = PesvVehicle::where('is_active', true)->get(['tipo', 'propiedad']);
        $propiedades = PesvVehicle::PROPIEDADES;
        $filas = $flota->groupBy('tipo')->sortKeys()->map(fn (Collection $g, string $tipo) => [
            Str::ucfirst($tipo),
            ...array_map(fn ($p) => $g->where('propiedad', $p)->count(), $propiedades),
            $g->count(),
        ])->values();
        $deContratistas = (int) PesvContractor::where('is_active', true)->sum('num_vehiculos');

        $this->seccion('f) Flota de vehículos', [
            $this->cifra('Total de vehículos', $flota->count(), $flota->isEmpty() ? 'No hay vehículos registrados en la caracterización.' : null),
        ], [
            $this->tabla('Vehículos por tipo y propiedad', ['Tipo', ...array_map(fn ($p) => Str::ucfirst($p), $propiedades), 'Total'], $filas, 'Sin vehículos.'),
        ], $deContratistas ? ["Además, los contratistas declaran {$deContratistas} vehículo(s) al servicio de la organización."] : []);
    }

    private function actoresViales(): void
    {
        $encuestas = PesvMobilitySurvey::whereYear('fecha', $this->anio)->get(['respuestas']);
        $roles = ['Conductor', 'Motociclista', 'Ciclista', 'Peatón', 'Pasajero', 'Acompañante'];
        $conteo = collect($roles)->map(fn ($r) => [$r, $encuestas->filter(fn ($e) => in_array($r, (array) ($e->respuestas['rol'] ?? []), true))->count()]);

        $this->seccion('g) Colaboradores por tipo de actor vial', [
            $this->dato('Colaboradores activos', Employee::where('is_active', true)->count()),
            $this->dato('Conductores registrados', Employee::where('is_active', true)->conductores()->count()),
            $this->cifra("Encuestas de movilidad {$this->anio}", $encuestas->count(),
                $encuestas->isEmpty() ? "No hay encuestas de movilidad en {$this->anio}: de ahí sale el número por actor vial." : null),
        ], [
            $this->tabla('Actores viales según la encuesta de movilidad', ['Actor vial', 'Colaboradores'], $conteo, 'Sin encuestas.'),
        ], ['Un colaborador puede identificarse con más de un rol en la vía.']);
    }

    private function contratistas(): void
    {
        $todos = PesvContractor::where('is_active', true)->get(['tipo', 'num_conductores', 'num_vehiculos']);
        $filas = $todos->groupBy('tipo')->map(fn (Collection $g, string $tipo) => [
            Str::ucfirst(str_replace('_', ' ', $tipo)), $g->count(), (int) $g->sum('num_conductores'), (int) $g->sum('num_vehiculos'),
        ])->values();

        $this->seccion('h) Contratistas, subcontratistas y terceros', [
            $this->dato('Contratistas y terceros activos', $todos->count()),
        ], [
            $this->tabla('Por tipo', ['Tipo', 'Cantidad', 'Conductores', 'Vehículos'], $filas, 'Sin contratistas ni terceros registrados.'),
        ]);
    }

    private function objetivos(): void
    {
        $indicadores = $this->indicadoresPesv();
        $metas = IndicatorGoal::whereIn('indicator_id', $indicadores->pluck('id'))->pluck('meta', 'indicator_id');
        $filas = $indicadores->map(fn (Indicator $i) => [
            $i->nombre, $this->numero($metas[$i->id] ?? $i->meta).' '.$i->unidad, $metas->has($i->id) ? 'Propia de la empresa' : 'Por defecto',
        ]);

        $this->seccion('i) Objetivos y metas del PESV', [
            $this->cifra('Metas fijadas por la empresa', $metas->count().' de '.$indicadores->count(),
                $metas->isEmpty() ? 'Ningún indicador PESV tiene meta propia de la empresa (paso 7).' : null),
            $this->dato('Objetivos y metas propuestos para '.($this->anio + 1), $this->datos->objetivos_siguiente,
                'Faltan los objetivos y metas propuestos para '.($this->anio + 1).'.'),
        ], [
            $this->tabla("Metas {$this->anio}", ['Indicador', 'Meta', 'Origen'], $filas, 'Sin indicadores PESV.'),
        ]);
    }

    private function programas(): void
    {
        $catalogo = ManagementProgram::where('categoria', 'pesv')->pluck('id');
        $delAnio = ProgramPlan::with('activities')->whereIn('management_program_id', $catalogo)->where('anio', $this->anio)->orderBy('nombre')->get();
        $siguientes = ProgramPlan::whereIn('management_program_id', $catalogo)->where('anio', $this->anio + 1)->orderBy('nombre')->pluck('nombre');
        $propuestos = collect([$siguientes->implode('; '), $this->datos->programas_siguiente])->filter()->implode(' · ');

        $this->seccion('j) Programas de gestión de riesgos críticos', [
            $this->cifra("Programas ejecutados en {$this->anio}", $delAnio->count(),
                $delAnio->isEmpty() ? "No hay programas PESV activados para {$this->anio} (paso 8)." : null),
            $this->dato('Programas propuestos para '.($this->anio + 1), $propuestos,
                'Faltan los programas propuestos para '.($this->anio + 1).'.'),
        ], [
            $this->tabla("Programas {$this->anio}", ['Programa', 'Responsable', 'Cumplimiento'],
                $delAnio->map(fn (ProgramPlan $p) => [$p->nombre, $p->responsable ?: '—', $this->porcentaje($p->cumplimiento())]),
                'Sin programas.'),
        ]);
    }

    private function infracciones(): void
    {
        $infracciones = PesvInfraction::whereYear('fecha', $this->anio)->get(['codigo', 'descripcion', 'valor']);
        $filas = $infracciones->groupBy(fn ($i) => $i->codigo ?: 'Sin código')->sortKeys()->map(fn (Collection $g, string $codigo) => [
            $codigo, Str::limit((string) $g->first()->descripcion, 80), $g->count(), '$ '.$this->numero($g->sum('valor')),
        ])->values();

        $this->seccion('k) Infracciones de tránsito de los conductores', [
            $this->dato("Infracciones en {$this->anio}", $infracciones->count()),
        ], [
            $this->tabla('Por código de infracción', ['Código', 'Descripción', 'Cantidad', 'Valor'], $filas, "Sin infracciones registradas en {$this->anio}."),
        ], ['Antes de reportar cero infracciones, conviene consultar el SIMIT de cada conductor.']);
    }

    private function auditoria(): void
    {
        $auditoria = self::auditoriasPesv()->where('tipo', 'interna')
            ->where(fn ($q) => $q->whereDate('fecha_fin', '<=', "{$this->anio}-12-31")
                ->orWhere(fn ($q) => $q->whereNull('fecha_fin')->whereDate('fecha_programada', '<=', "{$this->anio}-12-31")))
            ->orderByDesc('fecha_fin')->orderByDesc('fecha_programada')->first();

        $criterios = PesvCriterion::all()->filter(fn (PesvCriterion $c) => $c->aplicaA($this->plan->nivel))->groupBy('pesv_step_id');
        $respuestas = $this->plan->criterios()->pluck('estado', 'pesv_criterion_id');
        $estados = ['cumple' => 'Cumple', 'no_cumple' => 'No cumple', 'en_proceso' => 'En proceso', 'pendiente' => 'Sin verificar', 'no_aplica' => 'No aplica'];
        $pasos = PesvStep::orderBy('numero')->get()->map(function (PesvStep $p) use ($criterios, $respuestas, $estados) {
            if (! $p->aplicaA($this->plan->nivel)) {
                return [$p->numero, $p->titulo, '—', 'No exigido en el nivel'];
            }
            $suyos = collect($criterios->get($p->id, []));
            $resp = $suyos->map(fn ($c) => $respuestas[$c->id] ?? 'no_verificado');

            return [$p->numero, $p->titulo, $resp->filter(fn ($e) => $e === 'cumple')->count().' / '.$resp->reject(fn ($e) => $e === 'no_aplica')->count(),
                $estados[PesvPlan::estadoDelPaso($resp)] ?? '—'];
        });

        $cifras = [$this->dato('Cumplimiento de la lista de verificación (Tabla 16)', $this->porcentaje($this->plan->calcularAvance()))];
        if ($auditoria) {
            $nc = $auditoria->findings()->whereIn('tipo', AuditFinding::NO_CONFORMIDADES)->count();
            array_push($cifras,
                $this->dato('Última auditoría interna', trim($auditoria->codigo.' · '.($auditoria->fecha_fin ?? $auditoria->fecha_programada)?->format('d/m/Y'))),
                $this->dato('Auditor líder', $auditoria->auditor_lider),
                $this->dato('No conformidades', $nc),
                $this->dato('Conclusiones', $auditoria->conclusiones, 'La auditoría no tiene conclusiones registradas.'),
            );
        } else {
            $cifras[] = $this->dato('Última auditoría interna', null, "No hay auditoría interna del PESV registrada hasta {$this->anio}.");
        }

        $this->seccion('l) Auditoría interna y nivel de cumplimiento de los 24 pasos', $cifras, [
            $this->tabla('Estado de los pasos', ['Paso', 'Requisito', 'Preguntas en «cumple»', 'Estado'], $pasos, ''),
        ], ['Se adjunta copia del informe de la última auditoría interna al PESV.']);
    }

    // ---- Tabla 10 -------------------------------------------------------------

    private function tabla10(): void
    {
        $nivel = $this->plan->nivel;
        $siniestros = PesvSiniestro::whereYear('fecha', $this->anio)->get();
        $km = PesvKmPeriodo::where('anio', $this->anio)->pluck('km', 'trimestre')->map(fn ($v) => (float) $v);
        $acumulado = collect(PesvEstadisticaController::trimestres($siniestros, $km))->firstWhere('trimestre', 'año');
        $nombresNivel = collect(PesvSiniestro::NIVELES_PERDIDA)->map(fn ($n) => $n['nombre']);
        $riesgos = PesvRiesgoVialController::indicadores(PesvRoadRisk::all(), $this->anio);

        $indicadores = $this->indicadoresPesv()->keyBy('codigo');
        $metas = IndicatorGoal::whereIn('indicator_id', $indicadores->pluck('id'))->pluck('meta', 'indicator_id');
        $lecturas = IndicatorReading::where('anio', $this->anio)->whereIn('indicator_id', $indicadores->pluck('id'))->get()->groupBy('indicator_id');

        $filas = [];
        $sinMedir = [];
        foreach (self::TABLA_10 as $n => [$codigo, $nombre, $frecuencia, $niveles]) {
            if (! in_array($nivel, $niveles, true)) {
                $filas[] = [$n, $nombre, $frecuencia, 'No aplica al nivel '.mb_strtolower(self::NIVELES[$nivel] ?? $nivel), '—', '—'];

                continue;
            }

            [$resultado, $meta, $estado] = match ($n) {
                1 => [$acumulado['km']
                    ? $nombresNivel->map(fn ($nom, $k) => "{$nom}: ".$this->numero($acumulado['niveles'][$k]['tsv'], 2))->implode(' · ')
                    : null, '—', $acumulado['km'] ? $this->numero($acumulado['km']).' km' : 'Sin km de la flota'],
                2 => [$nombresNivel->map(fn ($nom, $k) => "{$nom}: $ ".$this->numero($acumulado['niveles'][$k]['costo']))->implode(' · '), '—',
                    $siniestros->count().' siniestro(s)'],
                3 => ["RSVI = {$riesgos['rsvi']} ({$riesgos['ri_inicio']} → {$riesgos['ri_fin']}) · GRV = {$riesgos['grv']} ({$riesgos['rva_inicio']} → {$riesgos['rva_fin']})",
                    '—', PesvRoadRisk::count() ? 'Matriz de riesgos viales' : 'Matriz vacía'],
                default => $this->resultadoLecturas($indicadores->get($codigo), $metas, $lecturas),
            };

            if ($resultado === null || ($n === 3 && ! PesvRoadRisk::count())) {
                $sinMedir[] = $n;
            }
            $filas[] = [$n, $nombre, $frecuencia, $resultado ?? 'Sin medición', $meta, $estado];
        }

        $this->seccion('Indicadores de gestión del PESV (Tabla 10)', [
            $this->dato('Indicadores que aplican al nivel', collect(self::TABLA_10)->filter(fn ($i) => in_array($nivel, $i[3], true))->count()),
            $this->cifra('Sin medición en '.$this->anio, count($sinMedir), $sinMedir ? 'Sin medición en '.$this->anio.': indicador(es) '.implode(', ', $sinMedir).'.' : null),
        ], [
            $this->tabla("Resultados {$this->anio}", ['N.°', 'Indicador', 'Frecuencia', "Resultado {$this->anio}", 'Meta', 'Estado'], $filas, ''),
        ], [
            'TSV(n) = siniestros del nivel × 1.000.000 / km recorridos por la flota. Los indicadores 4 a 13 se acumulan en el año como suma de numeradores sobre suma de denominadores.',
            'Cortes trimestrales al 31 de marzo, 30 de junio, 30 de septiembre y 31 de diciembre.',
        ]);
    }

    /** @return array{0: ?string, 1: string, 2: string} [resultado, meta, estado] */
    private function resultadoLecturas(?Indicator $ind, Collection $metas, Collection $lecturas): array
    {
        if (! $ind) {
            return [null, '—', 'Indicador no cargado'];
        }
        $suyas = collect($lecturas->get($ind->id, []));
        $meta = $metas->has($ind->id) ? (float) $metas[$ind->id] : ($ind->meta !== null ? (float) $ind->meta : null);
        $metaTxt = $meta === null ? '—' : $this->numero($meta, 2).' '.$ind->unidad;

        // NCAC sin lecturas: se calcula con los hallazgos de las auditorías del PESV del año.
        if ($suyas->isEmpty() && $ind->codigo === 'PESV-NCAC') {
            $nc = AuditFinding::whereIn('tipo', AuditFinding::NO_CONFORMIDADES)
                ->whereIn('audit_id', self::auditoriasPesv()->whereYear('fecha_programada', $this->anio)->pluck('id'))->get();
            if ($nc->isNotEmpty()) {
                $cerradas = AcpmAction::whereIn('id', $nc->pluck('acpm_action_id')->filter())->where('estado', 'cerrada')->count();
                $valor = $ind->calcular($cerradas, $nc->count());

                return [$this->numero($valor, 2).' '.$ind->unidad." ({$cerradas} de {$nc->count()})", $metaTxt, $this->cumple($ind, $valor, $meta)];
            }
        }
        if ($suyas->isEmpty()) {
            return [null, $metaTxt, '—'];
        }

        $valor = $ind->calcular((float) $suyas->sum('numerador'), (float) $suyas->sum('denominador'));

        return [$valor === null ? null : $this->numero($valor, 2).' '.$ind->unidad, $metaTxt,
            $this->cumple($ind, $valor, $meta).' · '.$suyas->count().' mes(es)'];
    }

    private function cumple(Indicator $ind, ?float $valor, ?float $meta): string
    {
        if ($valor === null || $meta === null) {
            return 'Sin meta';
        }

        return ($ind->sentido === 'asc' ? $valor >= $meta : $valor <= $meta) ? 'Cumple' : 'No cumple';
    }

    // ---- Apoyo ----------------------------------------------------------------

    /** Auditorías cuyo objetivo, alcance o criterios hablan del PESV. */
    public static function auditoriasPesv(): Builder
    {
        return Audit::query()->where(fn ($q) => $q
            ->where('objetivo', 'like', '%PESV%')->orWhere('alcance', 'like', '%PESV%')
            ->orWhere('criterios', 'like', '%40595%')->orWhere('objetivo', 'like', '%seguridad vial%')
            ->orWhere('alcance', 'like', '%seguridad vial%'));
    }

    /** @return Collection<int, Indicator> */
    private function indicadoresPesv(): Collection
    {
        return Indicator::where('categoria', 'PESV')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id))
            ->orderBy('orden')->orderBy('id')->get();
    }

    /**
     * @param  list<array<string, mixed>>  $cifras
     * @param  list<array<string, mixed>>  $tablas
     * @param  list<string>  $notas
     */
    private function seccion(string $titulo, array $cifras, array $tablas = [], array $notas = []): void
    {
        $this->secciones[] = ['clave' => Str::slug(Str::before($titulo, ')')), 'titulo' => $titulo, 'cifras' => $cifras, 'tablas' => $tablas, 'notas' => $notas];
    }

    /** Dato que exige la norma: si viene vacío, queda como pendiente con ese texto. */
    private function dato(string $etiqueta, mixed $valor, ?string $siFalta = null): array
    {
        $vacio = $valor === null || $valor === '';

        return $this->cifra($etiqueta, $valor, $vacio ? $siFalta : null);
    }

    /** Cifra con una alerta ya decidida por quien la llama. */
    private function cifra(string $etiqueta, mixed $valor, ?string $alerta = null): array
    {
        return ['etiqueta' => $etiqueta, 'valor' => $valor === null || $valor === '' ? '—' : (string) $valor, 'alerta' => $alerta];
    }

    private function tabla(string $titulo, array $columnas, iterable $filas, string $vacio): array
    {
        return [
            'titulo' => $titulo,
            'columnas' => $columnas,
            'filas' => collect($filas)->map(fn ($f) => array_map(fn ($v) => (string) $v, array_values($f)))->values()->all(),
            'omitidas' => 0,
            'vacio' => $vacio,
        ];
    }

    private function numero(float|int|string|null $n, int $decimales = 0): string
    {
        return $n === null ? '—' : number_format((float) $n, $decimales, ',', '.');
    }

    private function porcentaje(?float $v): string
    {
        return $v === null ? '—' : $this->numero($v, 1).' %';
    }
}
