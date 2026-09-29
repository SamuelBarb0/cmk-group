<?php

namespace App\Services\Dashboard;

use App\Models\AcpmAction;
use App\Models\Audit;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestAction;
use App\Models\ChemicalProduct;
use App\Models\Committee;
use App\Models\CommunicationLog;
use App\Models\ContextProfile;
use App\Models\ContractorDocument;
use App\Models\ControlledDocument;
use App\Models\CustomerRequest;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillRecommendation;
use App\Models\EmergencyEquipment;
use App\Models\Employee;
use App\Models\MaintenanceAsset;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\MeasuringEquipment;
use App\Models\MedicalExam;
use App\Models\PesvContractor;
use App\Models\PesvVehicle;
use App\Models\RiskOpportunity;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\User;
use App\Models\WorkAccident;
use App\Support\EvaluacionesContratistas;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Agenda de la empresa activa: todo lo que VENCE o está PROGRAMADO en los
 * módulos, en una sola lista ordenada por fecha.
 *
 * Cada módulo ya sabe cuándo algo suyo está vencido (la ACPM, la calibración,
 * el SOAT…); esto solo junta esas fechas. Por eso cada fuente reutiliza la
 * regla del propio modelo en vez de repetirla: si mañana cambia cuándo vence
 * una calibración, cambia en un sitio y la agenda lo sigue.
 *
 * Una fuente solo aparece si la empresa tiene CONTRATADO el módulo y el
 * usuario puede ABRIRLO: los mismos dos filtros que el menú lateral y el
 * informe de gestión. Así el panel nunca enseña algo a lo que no se puede ir.
 *
 * Lo vencido sale siempre, por viejo que sea: vencido es justo lo que hay que
 * atender. Lo que aún no vence sale hasta HORIZONTE días hacia adelante.
 */
class Agenda
{
    /** Por vencer: dentro de estos días. El mismo aviso que usan los módulos (30). */
    public const DIAS_POR_VENCER = 30;

    /** Hasta dónde mira hacia adelante. */
    public const HORIZONTE = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<array{modulo: string, modulo_titulo: string, tipo: string, titulo: string, detalle: ?string,
     *                    fecha: string, dias: int, estado: string, url: string}>
     */
    public function items(User $user): array
    {
        $tenant = $this->context->get();
        if (! $tenant) {
            return [];
        }

        $hoy = CarbonImmutable::today();
        $hasta = $hoy->addDays(self::HORIZONTE);

        return collect($this->fuentes($tenant))
            ->filter(fn (array $f) => ($f['modulo'] === null || $tenant->moduloHabilitado($f['modulo'])) && $user->can($f['permiso']))
            ->flatMap(fn (array $f) => collect(($f['items'])($hoy, $hasta))
                ->map(fn (array $item) => $item + ['modulo' => $f['modulo'] ?? 'organizacion']))
            ->filter(fn (array $item) => $item['fecha'] !== null && $item['fecha']->lte($hasta))
            ->map(function (array $item) use ($hoy) {
                $dias = (int) $hoy->diffInDays($item['fecha'], false);

                return [
                    'modulo' => $item['modulo'],
                    'modulo_titulo' => self::tituloModulo($item['modulo']),
                    'tipo' => $item['tipo'],
                    'titulo' => $item['titulo'],
                    'detalle' => $item['detalle'] ?? null,
                    'fecha' => $item['fecha']->toDateString(),
                    'dias' => $dias,
                    'estado' => match (true) {
                        $dias < 0 => 'vencido',
                        $dias <= self::DIAS_POR_VENCER => 'por_vencer',
                        default => 'proximo',
                    },
                    'url' => $item['url'],
                ];
            })
            ->sortBy([['fecha', 'asc'], ['modulo', 'asc']])
            ->values()
            ->all();
    }

    /** Conteo por estado, para la vista consolidada y los avisos. */
    public static function resumen(array $items): array
    {
        $c = collect($items)->countBy('estado');

        return [
            'vencido' => $c->get('vencido', 0),
            'por_vencer' => $c->get('por_vencer', 0),
            'proximo' => $c->get('proximo', 0),
        ];
    }

    public static function tituloModulo(string $modulo): string
    {
        return $modulo === 'organizacion'
            ? 'Organización'
            : Str::before(config("cmk.modulos_contratables.$modulo") ?? Str::headline($modulo), ' (');
    }

    /**
     * Cada fuente: módulo contratable (null = base), permiso para verlo y una
     * función que devuelve sus fechas.
     *
     * @return list<array{modulo: ?string, permiso: string, items: Closure(CarbonImmutable, CarbonImmutable): iterable}>
     */
    private function fuentes(Tenant $tenant): array
    {
        return [
            $this->fuente('acpm', 'sst.view', fn ($hoy, $hasta) => AcpmAction::pendientes()
                ->whereNotNull('fecha_limite')->whereDate('fecha_limite', '<=', $hasta)->get()
                ->map(fn (AcpmAction $a) => [
                    'tipo' => 'Acción '.$a->tipo,
                    'titulo' => trim($a->codigo.' · '.Str::limit($a->hallazgo, 90)),
                    'detalle' => $a->responsable ? 'Responsable: '.$a->responsable : null,
                    'fecha' => $a->fecha_limite,
                    'url' => route('acpm.index'),
                ])),

            $this->fuente('auditoria', 'audit.view', fn ($hoy, $hasta) => Audit::where('estado', 'programada')
                ->whereNotNull('fecha_programada')->whereDate('fecha_programada', '<=', $hasta)->get()
                ->map(fn (Audit $a) => [
                    'tipo' => 'Auditoría programada',
                    'titulo' => trim($a->codigo.' · '.Str::headline((string) $a->tipo)),
                    'detalle' => $a->auditor_lider ? 'Auditor líder: '.$a->auditor_lider : null,
                    'fecha' => $a->fecha_programada,
                    'url' => route('auditoria.show', $a),
                ])),

            $this->fuente('gestion-cambio', 'sst.view', fn ($hoy, $hasta) => ChangeRequest::whereNotNull('fecha_limite')
                ->whereDate('fecha_limite', '<=', $hasta)->get()
                // Solo el cambio aprobado y sin cerrar corre contra su fecha (la misma regla que `vencido`).
                ->filter(fn (ChangeRequest $c) => $c->estado === 'aprobado')
                ->map(fn (ChangeRequest $c) => [
                    'tipo' => 'Cambio aprobado sin cerrar',
                    'titulo' => Str::limit($c->descripcion, 100),
                    'detalle' => $c->area ? 'Área: '.$c->area : null,
                    'fecha' => $c->fecha_limite,
                    'url' => route('gestion-cambio.index'),
                ])
                ->concat(ChangeRequestAction::whereHas('changeRequest')->where('ejecutada', false)
                    ->whereNotNull('fecha_compromiso')->whereDate('fecha_compromiso', '<=', $hasta)->get()
                    ->map(fn (ChangeRequestAction $a) => [
                        'tipo' => 'Acción de un cambio',
                        'titulo' => Str::limit($a->descripcion, 100),
                        'detalle' => $a->responsable ? 'Responsable: '.$a->responsable : null,
                        'fecha' => $a->fecha_compromiso,
                        'url' => route('gestion-cambio.index'),
                    ]))),

            // Solo el comité vigente de cada tipo: los periodos anteriores ya
            // se renovaron y avisar de ellos sería ruido perpetuo.
            $this->fuente('comites', 'sst.view', fn ($hoy, $hasta) => Committee::whereNotNull('fecha_vencimiento')
                ->orderByDesc('fecha_conformacion')->get()->unique('tipo')
                ->filter(fn (Committee $c) => $c->fecha_vencimiento->lte($hasta))
                ->map(fn (Committee $c) => [
                    'tipo' => 'Vigencia de comité',
                    'titulo' => 'Renovar el '.strtoupper($c->tipo).($c->periodo ? ' (periodo '.$c->periodo.')' : ''),
                    'detalle' => 'Vigencia legal de '.Committee::ANIOS_VIGENCIA.' años.',
                    'fecha' => $c->fecha_vencimiento,
                    'url' => route('comites.index'),
                ])),

            $this->fuente('comunicaciones', 'sst.view', fn ($hoy, $hasta) => CommunicationLog::pendientes()
                ->whereNotNull('fecha_limite_respuesta')->whereDate('fecha_limite_respuesta', '<=', $hasta)->get()
                ->map(fn (CommunicationLog $c) => [
                    'tipo' => 'Respuesta pendiente',
                    'titulo' => Str::limit($c->asunto, 100),
                    'detalle' => $c->parte_interesada ? 'A: '.$c->parte_interesada : null,
                    'fecha' => $c->fecha_limite_respuesta,
                    'url' => route('comunicaciones.index'),
                ])),

            $this->fuente('contexto', 'sst.view', fn ($hoy, $hasta) => ContextProfile::whereNotNull('revisado_at')->get()
                ->map(fn (ContextProfile $p) => [
                    'tipo' => 'Revisión del contexto',
                    'titulo' => 'Revisar el contexto de la organización',
                    'detalle' => 'Se revisa cada '.ContextProfile::REVISION_MESES.' meses.',
                    'fecha' => $p->revisado_at->copy()->addMonths(ContextProfile::REVISION_MESES),
                    'url' => route('contexto.index'),
                ])),

            $this->fuente('contratistas', 'sst.view', fn ($hoy, $hasta) => ContractorDocument::with('contractor')
                ->whereHas('contractor', fn ($q) => $q->where('is_active', true))
                ->where('estado', 'recibido')->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '<=', $hasta)->get()
                ->map(fn (ContractorDocument $d) => [
                    'tipo' => 'Documento de contratista',
                    'titulo' => $d->contractor->nombre.' · '.($d->nombre ?: (ContractorDocument::TIPOS[$d->tipo] ?? $d->tipo)),
                    'detalle' => null,
                    'fecha' => $d->fecha_vencimiento,
                    'url' => route('contratistas.show', $d->contractor),
                ])
                ->concat(PesvContractor::where('is_active', true)->with(['evaluations', 'documents'])->get()
                    ->map(fn (PesvContractor $c) => [$c, $c->situacion($hoy->year)['proxima_evaluacion']])
                    ->filter(fn ($par) => $par[1] !== null)
                    ->map(fn ($par) => [
                        'tipo' => 'Evaluación de contratista',
                        'titulo' => 'Evaluar a '.$par[0]->nombre,
                        'detalle' => 'Cada '.EvaluacionesContratistas::MESES_ENTRE_EVALUACIONES.' meses.',
                        'fecha' => CarbonImmutable::parse($par[1]),
                        'url' => route('contratistas.show', $par[0]),
                    ]))),

            $this->fuente('control-documental', 'documents.view', fn ($hoy, $hasta) => ControlledDocument::where('estado', 'vigente')
                ->whereNotNull('proxima_revision')->whereDate('proxima_revision', '<=', $hasta)->get()
                ->map(fn (ControlledDocument $d) => [
                    'tipo' => 'Revisión de documento',
                    'titulo' => $d->codigo.' · '.Str::limit($d->titulo, 90),
                    'detalle' => null,
                    'fecha' => $d->proxima_revision,
                    'url' => route('control-documental.show', $d),
                ])),

            $this->fuente('calidad', 'sst.view', fn ($hoy, $hasta) => CustomerRequest::where('estado', '!=', 'cerrada')
                ->whereNotNull('fecha_limite')->whereDate('fecha_limite', '<=', $hasta)->get()
                ->map(fn (CustomerRequest $r) => [
                    'tipo' => 'PQRS por responder',
                    'titulo' => Str::headline((string) $r->tipo).' · '.($r->cliente ?: 'Cliente').' · '.Str::limit($r->descripcion, 70),
                    'detalle' => $r->responsable ? 'Responsable: '.$r->responsable : null,
                    'fecha' => $r->fecha_limite,
                    'url' => route('calidad.index'),
                ])),

            $this->fuente('emergencias', 'sst.view', fn ($hoy, $hasta) => EmergencyDrill::where('estado', 'programado')
                ->whereNotNull('fecha')->whereDate('fecha', '<=', $hasta)->get()
                ->map(fn (EmergencyDrill $s) => [
                    'tipo' => 'Simulacro programado',
                    'titulo' => 'Simulacro'.($s->escenario ? ': '.Str::limit($s->escenario, 80) : ''),
                    'detalle' => $s->sede ? 'Sede: '.$s->sede : null,
                    'fecha' => $s->fecha,
                    'url' => route('emergencias.index'),
                ])
                ->concat(EmergencyDrillRecommendation::whereHas('drill')->where('implementada', false)
                    ->whereNotNull('fecha_limite')->whereDate('fecha_limite', '<=', $hasta)->get()
                    ->map(fn (EmergencyDrillRecommendation $r) => [
                        'tipo' => 'Recomendación de simulacro',
                        'titulo' => Str::limit($r->descripcion, 100),
                        'detalle' => $r->responsable ? 'Responsable: '.$r->responsable : null,
                        'fecha' => $r->fecha_limite,
                        'url' => route('emergencias.index'),
                    ]))
                ->concat(EmergencyEquipment::whereNotNull('fecha_vencimiento')
                    ->whereDate('fecha_vencimiento', '<=', $hasta)->get()
                    ->map(fn (EmergencyEquipment $e) => [
                        'tipo' => 'Equipo de emergencia',
                        'titulo' => $e->elemento.($e->ubicacion ? ' · '.$e->ubicacion : ''),
                        'detalle' => $e->ubicacion_exacta,
                        'fecha' => $e->fecha_vencimiento,
                        'url' => route('emergencias.index'),
                    ]))),

            // El último examen de cada trabajador activo manda (uno más nuevo
            // reemplaza al anterior); el de retiro no programa el siguiente.
            $this->fuente('salud-ocupacional', 'sst.view', fn ($hoy, $hasta) => MedicalExam::with('employee')
                ->whereHas('employee', fn ($q) => $q->where('is_active', true))
                ->whereNotIn('tipo', MedicalExam::TIPOS_SIN_PROXIMO)
                ->orderByDesc('fecha')->orderByDesc('id')->get()
                ->unique('employee_id')
                ->filter(fn (MedicalExam $e) => $e->proximo_examen !== null && $e->proximo_examen->lte($hasta))
                ->map(fn (MedicalExam $e) => [
                    'tipo' => 'Examen médico periódico',
                    'titulo' => $e->employee->nombre_completo,
                    'detalle' => $e->employee->cargo,
                    'fecha' => $e->proximo_examen,
                    'url' => route('salud-ocupacional.index'),
                ])),

            $this->fuente('equipos-medicion', 'sst.view', fn ($hoy, $hasta) => MeasuringEquipment::where('estado', 'en_uso')
                ->with('calibrations')->get()
                ->map(fn (MeasuringEquipment $e) => [$e, $e->situacion()])
                ->filter(fn ($par) => $par[1]['proxima'] !== null)
                ->map(fn ($par) => [
                    'tipo' => 'Calibración',
                    'titulo' => trim($par[0]->codigo.' · '.$par[0]->nombre),
                    'detalle' => $par[0]->ubicacion,
                    'fecha' => CarbonImmutable::parse($par[1]['proxima']),
                    'url' => route('equipos-medicion.index'),
                ])),

            // Por fecha, la próxima fecha. Por uso (km u horas) no hay fecha:
            // si ya está vencido o por vencer entra HOY, con lo que falta.
            $this->fuente('mantenimiento', 'sst.view', fn ($hoy, $hasta) => MaintenanceAsset::where('activo', true)
                ->with(['planItems', 'records'])->get()
                ->flatMap(fn (MaintenanceAsset $a) => collect($a->estadoDelPlan())->map(fn (array $p) => [
                    'tipo' => 'Mantenimiento',
                    'titulo' => $a->nombre.' · '.$p['item']->actividad,
                    'detalle' => $p['proxima_fecha'] === null && $p['proxima_lectura'] !== null
                        ? 'A las '.number_format($p['proxima_lectura'], 0, ',', '.').' '.($a->unidad_lectura ?? '').' (faltan '.number_format(max(0, $p['restante']), 0, ',', '.').')'
                        : null,
                    'fecha' => match (true) {
                        $p['proxima_fecha'] !== null => CarbonImmutable::parse($p['proxima_fecha']),
                        in_array($p['estado'], ['vencido', 'por_vencer'], true) => $hoy,
                        default => null,
                    },
                    'url' => route('mantenimiento.index'),
                ]))),

            $this->fuente('pesv', 'pesv.view', fn ($hoy, $hasta) => PesvVehicle::where('is_active', true)->get()
                ->flatMap(fn (PesvVehicle $v) => collect([
                    'soat_vence' => 'SOAT',
                    'tecnomecanica_vence' => 'Revisión técnico-mecánica',
                    'poliza_vence' => 'Póliza',
                ] + ($tenant->moduloHabilitado('mantenimiento') ? [] : ['proximo_mantenimiento' => 'Mantenimiento']))
                    // Con el módulo de mantenimiento contratado, el mantenimiento
                    // del vehículo ya sale de ahí: no se duplica.
                    ->filter(fn ($etiqueta, $campo) => $v->{$campo} !== null)
                    ->map(fn ($etiqueta, $campo) => [
                        'tipo' => $etiqueta,
                        'titulo' => $v->placa.($v->marca ? ' · '.$v->marca.' '.$v->linea : ''),
                        'detalle' => null,
                        'fecha' => CarbonImmutable::parse($v->{$campo}),
                        'url' => route('pesv.vehiculos.show', $v),
                    ])->values())
                ->concat(Employee::conductores()->where('is_active', true)->get()
                    ->flatMap(fn (Employee $e) => collect([
                        'licencia_vence' => 'Licencia de conducción',
                        'examen_psicosensometrico_vence' => 'Examen psicosensométrico',
                    ])->filter(fn ($etiqueta, $campo) => $e->{$campo} !== null)
                        ->map(fn ($etiqueta, $campo) => [
                            'tipo' => $etiqueta,
                            'titulo' => $e->nombre_completo,
                            'detalle' => $e->cargo,
                            'fecha' => CarbonImmutable::parse($e->{$campo}),
                            'url' => route('pesv.conductores.show', $e),
                        ])->values()))),

            $this->fuente('capacitaciones', 'sst.view', fn ($hoy, $hasta) => Training::where('estado', 'programada')
                ->whereNotNull('fecha')->whereDate('fecha', '<=', $hasta)->get()
                ->map(fn (Training $t) => [
                    'tipo' => 'Capacitación programada',
                    'titulo' => Str::limit($t->titulo, 100),
                    'detalle' => $t->instructor ? 'Instructor: '.$t->instructor : null,
                    'fecha' => $t->fecha,
                    'url' => route('capacitaciones.show', $t),
                ])),

            $this->fuente('revision-direccion', 'reports.view', fn ($hoy, $hasta) => ManagementReview::where('estado', '!=', 'cerrada')
                ->whereNotNull('fecha_reunion')->whereDate('fecha_reunion', '<=', $hasta)->get()
                ->map(fn (ManagementReview $r) => [
                    'tipo' => 'Revisión por la dirección',
                    'titulo' => trim($r->codigo.' · Reunión de revisión'),
                    'detalle' => null,
                    'fecha' => $r->fecha_reunion,
                    'url' => route('revision-direccion.show', $r),
                ])
                ->concat(ManagementReviewDecision::with('review')->whereHas('review')
                    ->whereIn('estado', ['pendiente', 'en_proceso'])
                    ->whereNotNull('fecha_limite')->whereDate('fecha_limite', '<=', $hasta)->get()
                    ->map(fn (ManagementReviewDecision $d) => [
                        'tipo' => 'Decisión de la revisión',
                        'titulo' => Str::limit($d->descripcion, 100),
                        'detalle' => $d->responsable ? 'Responsable: '.$d->responsable : null,
                        'fecha' => $d->fecha_limite,
                        'url' => route('revision-direccion.show', $d->review),
                    ]))),

            $this->fuente('riesgos-oportunidades', 'sst.view', fn ($hoy, $hasta) => RiskOpportunity::where('estado', '!=', 'cerrado')
                ->whereNotNull('fecha_limite')->whereDate('fecha_limite', '<=', $hasta)->get()
                ->map(fn (RiskOpportunity $r) => [
                    'tipo' => $r->tipo === 'oportunidad' ? 'Oportunidad en tratamiento' : 'Riesgo en tratamiento',
                    'titulo' => Str::limit($r->descripcion, 100),
                    'detalle' => $r->responsable ? 'Responsable: '.$r->responsable : null,
                    'fecha' => $r->fecha_limite,
                    'url' => route('riesgos-oportunidades.index'),
                ])),

            // Plazos legales que corren desde el accidente: reporte a la ARL e
            // investigación (Res. 1401 de 2007).
            $this->fuente('accidentes', 'incidents.view', fn ($hoy, $hasta) => WorkAccident::with('employee')
                ->where(fn ($q) => $q->where('investigado', false)->orWhere('reportado_arl', false))->get()
                ->flatMap(fn (WorkAccident $a) => collect([
                    ! $a->reportado_arl ? [
                        'tipo' => 'Reporte a la ARL',
                        'titulo' => trim($a->codigo.' · '.($a->employee?->nombre_completo ?? 'Accidente')),
                        'detalle' => 'Plazo de '.WorkAccident::DIAS_REPORTE_ARL.' días desde el accidente.',
                        'fecha' => $a->fecha->copy()->addDays(WorkAccident::DIAS_REPORTE_ARL),
                        'url' => route('accidentes.index'),
                    ] : null,
                    ! $a->investigado ? [
                        'tipo' => 'Investigación de accidente',
                        'titulo' => trim($a->codigo.' · '.($a->employee?->nombre_completo ?? 'Accidente')),
                        'detalle' => 'Plazo de '.WorkAccident::DIAS_INVESTIGACION.' días desde el accidente.',
                        'fecha' => $a->fecha->copy()->addDays(WorkAccident::DIAS_INVESTIGACION),
                        'url' => route('accidentes.index'),
                    ] : null,
                ])->filter()->values())),

            $this->fuente('ambiental', 'sst.view', fn ($hoy, $hasta) => ChemicalProduct::where('activo', true)
                ->whereNotNull('hds_fecha')->get()
                ->map(fn (ChemicalProduct $p) => [
                    'tipo' => 'Hoja de seguridad (HDS)',
                    'titulo' => 'Actualizar la HDS de '.$p->nombre,
                    'detalle' => 'Una HDS se considera desactualizada a los '.ChemicalProduct::HDS_ANIOS.' años.',
                    'fecha' => $p->hds_fecha->copy()->addYears(ChemicalProduct::HDS_ANIOS),
                    'url' => route('ambiental.index'),
                ])),

            $this->fuente(null, 'sst.view', fn ($hoy, $hasta) => $tenant->licencia_sgsst_vence ? [[
                'tipo' => 'Licencia SG-SST',
                'titulo' => 'Licencia del responsable del SG-SST',
                'detalle' => null,
                'fecha' => CarbonImmutable::parse($tenant->licencia_sgsst_vence),
                'url' => route('organizacion.show'),
            ]] : []),
        ];
    }

    private function fuente(?string $modulo, string $permiso, Closure $items): array
    {
        return [
            'modulo' => $modulo,
            'permiso' => $permiso,
            'items' => function (CarbonImmutable $hoy, CarbonImmutable $hasta) use ($items): Collection {
                return collect($items($hoy, $hasta))->map(function (array $item) {
                    $f = $item['fecha'];
                    $item['fecha'] = $f instanceof CarbonInterface ? CarbonImmutable::instance($f)->startOfDay() : null;

                    return $item;
                });
            },
        ];
    }
}
