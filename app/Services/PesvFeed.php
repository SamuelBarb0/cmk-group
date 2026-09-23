<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FormRecord;
use App\Models\GeneratedDocument;
use App\Models\Indicator;
use App\Models\IndicatorGoal;
use App\Models\IndicatorReading;
use App\Models\IpercRow;
use App\Models\ManagementProgram;
use App\Models\PesvContractor;
use App\Models\PesvDriverCheck;
use App\Models\PesvDriverTest;
use App\Models\PesvInfraction;
use App\Models\PesvMobilitySurvey;
use App\Models\PesvRoadRisk;
use App\Models\PesvRoute;
use App\Models\PesvSede;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Models\PesvVehicleCheck;
use App\Models\ProgramPlan;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\WorkPlan;
use App\Support\Pesv\SemaforoDocumentos;

/**
 * Insumos que la plataforma YA tiene para cada paso del PESV.
 *
 * El PESV no se llena en el vacío: casi todos sus pasos piden información que
 * el cliente ya cargó en otro módulo (empleados, IPERC, indicadores, plan de
 * trabajo, capacitaciones, documentos). Este servicio resuelve, paso por paso,
 * qué hay y qué falta, para que la pantalla no vuelva a pedir lo mismo.
 *
 * Cada insumo es {etiqueta, estado, detalle, url, cantidad}:
 *   - ok      -> el dato existe y el paso puede apoyarse en él
 *   - parcial -> existe pero incompleto (p. ej. documento en borrador)
 *   - falta   -> no hay nada todavía; la url dice dónde cargarlo
 *
 * Todo se consulta bajo el TenantScope, así que siempre habla del cliente
 * activo.
 */
class PesvFeed
{
    /** Códigos de plantilla que respaldan cada paso. */
    private const DOCUMENTOS_POR_PASO = [
        3 => ['POL-PESV'],
        4 => ['FT-ACTA-REV-DIR', 'FT-RENDICION'],
        8 => ['PR-FATIGA'],
        11 => ['PR-INFRACTORES', 'PR-IDONEIDAD', 'PR-TERCEROS'],
        12 => ['PL-EMERGENCIAS'],
        13 => ['PR-INV-SINIESTROS'],
        15 => ['PR-PLAN-VIAJES'],
        17 => ['PR-MONIT-VEH'],
        18 => ['PR-GESTION-CAMBIO'],
        19 => ['MAN-CONTROL-DOC'],
        20 => ['FT-AUTOGESTION-PESV'],
        22 => ['PR-AUDITORIA'],
        24 => ['PR-PARTICIPACION'],
    ];

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Insumos del paso indicado.
     *
     * @return array<int, array{etiqueta: string, estado: string, detalle: string, url: ?string, cantidad: ?int}>
     */
    public function paraPaso(int $numero): array
    {
        $insumos = match ($numero) {
            1 => $this->lider(),
            2 => $this->comite(),
            5 => $this->caracterizacion(),
            6 => $this->riesgosViales(),
            7 => $this->objetivos(),
            8 => $this->programasCriticos(),
            9 => $this->planAnual(),
            10 => $this->formacion(),
            14, 15 => $this->rutas(),
            16 => $this->preoperacional(),
            21 => $this->siniestralidad(),
            default => [],
        };

        // Los pasos que se respaldan con un documento suman ese insumo.
        foreach (self::DOCUMENTOS_POR_PASO[$numero] ?? [] as $codigo) {
            $insumos[] = $this->documento($codigo);
        }

        // Casos donde el documento no basta y hay datos vivos que mirar.
        if ($numero === 5) {
            $insumos[] = $this->encuestaMovilidad();
        }

        if ($numero === 6) {
            $insumos[] = $this->matrizRiesgosViales();
        }

        if ($numero === 11) {
            $insumos[] = $this->conductores();
            array_push($insumos, ...$this->paso11());
        }

        if ($numero === 13) {
            $insumos[] = $this->siniestrosSinInvestigar();
        }

        if ($numero === 17) {
            $insumos[] = $this->mantenimiento();
        }

        if ($numero === 18) {
            $insumos[] = $this->contratistas();
        }

        if ($numero === 20) {
            $insumos[] = $this->indicadoresConLectura();
        }

        return $insumos;
    }

    // ---- Insumos individuales ---------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function lider(): array
    {
        $responsable = $this->tenant->responsable_sgsst;

        return [[
            'etiqueta' => 'Responsable del SG-SST registrado',
            'estado' => $responsable ? 'ok' : 'falta',
            'detalle' => $responsable
                ? "En Organización figura {$responsable}. Suele ser el candidato natural a líder del PESV."
                : 'La empresa no tiene responsable del SG-SST registrado en Organización.',
            'url' => '/organizacion',
            'cantidad' => null,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function comite(): array
    {
        $empleados = Employee::where('is_active', true)->count();

        return [[
            'etiqueta' => 'Colaboradores disponibles para integrar el comité',
            'estado' => $empleados > 0 ? 'ok' : 'falta',
            'detalle' => $empleados > 0
                ? "{$empleados} colaboradores activos; puedes armar el comité desde esa lista sin volver a escribirlos."
                : 'No hay empleados registrados todavía.',
            'url' => '/empleados',
            'cantidad' => $empleados,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function caracterizacion(): array
    {
        $empleados = Employee::where('is_active', true)->count();
        $conductores = Employee::where('is_active', true)->conductores()->count();

        return [
            [
                'etiqueta' => 'Sedes',
                'estado' => PesvSede::count() > 0 ? 'ok' : 'falta',
                'detalle' => PesvSede::count() > 0
                    ? PesvSede::count().' sede(s) registradas.'
                    : 'Sin sedes. La dirección de la empresa en Organización sirve de punto de partida.',
                'url' => '/pesv/sedes',
                'cantidad' => PesvSede::count(),
            ],
            [
                'etiqueta' => 'Colaboradores y conductores',
                'estado' => $empleados === 0 ? 'falta' : ($conductores === 0 ? 'parcial' : 'ok'),
                'detalle' => $empleados === 0
                    ? 'No hay empleados cargados.'
                    : "{$empleados} colaboradores cargados, {$conductores} marcados como conductores.",
                'url' => '/pesv/colaboradores',
                'cantidad' => $conductores,
            ],
            [
                'etiqueta' => 'Contratistas y terceros',
                'estado' => PesvContractor::count() > 0 ? 'ok' : 'falta',
                'detalle' => PesvContractor::count().' registrados.',
                'url' => '/pesv/contratistas',
                'cantidad' => PesvContractor::count(),
            ],
            [
                'etiqueta' => 'Vehículos',
                'estado' => PesvVehicle::count() > 0 ? 'ok' : 'falta',
                'detalle' => PesvVehicle::count().' en la flota.',
                'url' => '/pesv/vehiculos',
                'cantidad' => PesvVehicle::count(),
            ],
            [
                'etiqueta' => 'Rutas',
                'estado' => PesvRoute::count() > 0 ? 'ok' : 'falta',
                'detalle' => PesvRoute::count().' rutas caracterizadas.',
                'url' => '/pesv/rutas',
                'cantidad' => PesvRoute::count(),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function riesgosViales(): array
    {
        $peligros = IpercRow::count();
        $noAceptables = IpercRow::whereIn('aceptabilidad', ['No Aceptable', 'No Aceptable o Aceptable con control específico'])->count();
        $rutasRiesgo = PesvRoute::whereIn('nivel_riesgo', ['alto', 'critico'])->count();

        return [
            [
                'etiqueta' => 'Matriz IPERC',
                'estado' => $peligros > 0 ? 'ok' : 'falta',
                'detalle' => $peligros > 0
                    ? "{$peligros} peligros valorados, {$noAceptables} no aceptables. Los de origen vial se traen de aquí."
                    : 'La matriz IPERC está vacía.',
                'url' => '/iperc',
                'cantidad' => $peligros,
            ],
            [
                'etiqueta' => 'Rutas de riesgo alto o crítico',
                'estado' => $rutasRiesgo > 0 ? 'ok' : 'parcial',
                'detalle' => "{$rutasRiesgo} rutas clasificadas en riesgo alto o crítico.",
                'url' => '/pesv/rutas',
                'cantidad' => $rutasRiesgo,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function objetivos(): array
    {
        // Los mínimos de la Res. 40595 ya vienen cargados como indicadores
        // PESV; lo que el paso 7 pide es que la empresa FIJE sus metas.
        $pesv = Indicator::where('categoria', 'PESV')->pluck('id');
        $metasPropias = IndicatorGoal::whereIn('indicator_id', $pesv)->count();

        return [[
            'etiqueta' => 'Metas propias de los indicadores PESV',
            'estado' => $pesv->isEmpty() ? 'falta' : ($metasPropias > 0 ? 'ok' : 'parcial'),
            'detalle' => $pesv->isEmpty()
                ? 'No hay indicadores PESV cargados.'
                : ($metasPropias > 0
                    ? "La empresa fijó meta propia en {$metasPropias} de {$pesv->count()} indicadores PESV."
                    : "Hay {$pesv->count()} indicadores PESV (los mínimos de la Res. 40595), todos con la meta por defecto. Fija las metas de la empresa."),
            'url' => '/indicadores',
            'cantidad' => $metasPropias,
        ]];
    }

    /**
     * Programas de los riesgos críticos (alcohol, fatiga, velocidad,
     * distracción, actores viales): viven en Programas de gestión.
     *
     * @return array<int, array<string, mixed>>
     */
    private function programasCriticos(): array
    {
        $catalogo = ManagementProgram::where('categoria', 'pesv')->orderBy('orden')->get(['id', 'nombre']);
        $anio = (int) now()->year;
        $planes = ProgramPlan::with('activities')->where('anio', $anio)->whereIn('management_program_id', $catalogo->pluck('id'))->get();
        $faltan = $catalogo->reject(fn ($p) => $planes->contains('management_program_id', $p->id))->pluck('nombre');
        $cumplimiento = $planes->map(fn (ProgramPlan $p) => $p->cumplimiento())->filter(fn ($v) => $v !== null);

        return [[
            'etiqueta' => "Programas de riesgos críticos {$anio}",
            'estado' => $planes->isEmpty() ? 'falta' : ($faltan->isEmpty() ? 'ok' : 'parcial'),
            'detalle' => ($planes->isEmpty()
                ? "Ninguno de los {$catalogo->count()} programas PESV está activado para {$anio}."
                : "{$planes->count()} de {$catalogo->count()} programas activados"
                    .($cumplimiento->isNotEmpty() ? ', cumplimiento promedio '.round($cumplimiento->avg(), 1).' %' : '').'.')
                .($faltan->isNotEmpty() ? ' Faltan: '.$faltan->implode('; ').'.' : ''),
            'url' => '/programas',
            'cantidad' => $planes->count(),
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function planAnual(): array
    {
        $plan = WorkPlan::latest('id')->first();

        return [[
            'etiqueta' => 'Plan de Trabajo Anual',
            'estado' => $plan ? 'ok' : 'falta',
            'detalle' => $plan
                ? 'Existe plan de trabajo; las actividades de seguridad vial se programan ahí.'
                : 'Sin plan de trabajo anual cargado.',
            'url' => '/plan-trabajo',
            'cantidad' => null,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function formacion(): array
    {
        $pesv = Training::where('categoria', 'PESV')->count();
        $realizadas = Training::where('categoria', 'PESV')->where('estado', 'realizada')->count();

        return [[
            'etiqueta' => 'Capacitaciones de seguridad vial',
            'estado' => $pesv > 0 ? ($realizadas > 0 ? 'ok' : 'parcial') : 'falta',
            'detalle' => $pesv > 0
                ? "{$pesv} capacitaciones PESV programadas, {$realizadas} realizadas."
                : 'La biblioteca trae temas PESV (comité de seguridad vial, señalización, PONS) sin programar.',
            'url' => '/capacitaciones',
            'cantidad' => $pesv,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function rutas(): array
    {
        $rutas = PesvRoute::where('is_active', true)->count();

        return [[
            'etiqueta' => 'Rutas caracterizadas',
            'estado' => $rutas > 0 ? 'ok' : 'falta',
            'detalle' => "{$rutas} rutas activas con origen, destino, peligros y controles.",
            'url' => '/pesv/rutas',
            'cantidad' => $rutas,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function preoperacional(): array
    {
        $registros = FormRecord::where('categoria', 'PESV')->count();

        return [[
            'etiqueta' => 'Inspecciones registradas en Formatos',
            'estado' => $registros > 0 ? 'ok' : 'falta',
            'detalle' => $registros > 0
                ? "{$registros} registros de inspección de categoría PESV."
                : 'El motor de Formatos ya soporta la inspección preoperacional; falta crear el formato y diligenciarlo.',
            'url' => '/formatos',
            'cantidad' => $registros,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function siniestralidad(): array
    {
        $total = PesvSiniestro::count();
        $fatales = PesvSiniestro::where('gravedad', 'fatal')->count();

        return [[
            'etiqueta' => 'Siniestros viales registrados',
            'estado' => $total > 0 ? 'ok' : 'falta',
            'detalle' => $total > 0
                ? "{$total} siniestros registrados, {$fatales} fatales."
                : 'Sin siniestros registrados. Un histórico vacío también es un dato, pero debe ser real.',
            'url' => '/pesv/siniestros',
            'cantidad' => $total,
        ]];
    }

    /** @return array<string, mixed> */
    private function conductores(): array
    {
        $conductores = Employee::where('is_active', true)->conductores()->count();
        $sinLicencia = Employee::where('is_active', true)->conductores()
            ->whereNull('licencia_numero')->count();

        return [
            'etiqueta' => 'Conductores con licencia registrada',
            'estado' => $conductores === 0 ? 'falta' : ($sinLicencia > 0 ? 'parcial' : 'ok'),
            'detalle' => $conductores === 0
                ? 'No hay colaboradores marcados como conductores.'
                : "{$conductores} conductores, {$sinLicencia} sin número de licencia.",
            'url' => '/pesv/colaboradores',
            'cantidad' => $conductores,
        ];
    }

    /** @return array<string, mixed> */
    private function encuestaMovilidad(): array
    {
        $anio = (int) now()->year;
        $respuestas = PesvMobilitySurvey::whereYear('fecha', $anio)->count();
        $colaboradores = Employee::where('is_active', true)->count();
        $cobertura = $colaboradores ? (int) round($respuestas * 100 / $colaboradores) : 0;

        return [
            'etiqueta' => "Encuesta de movilidad {$anio}",
            'estado' => $respuestas === 0 ? 'falta' : ($cobertura >= 80 ? 'ok' : 'parcial'),
            'detalle' => $respuestas === 0
                ? 'Nadie ha respondido la encuesta de movilidad este año. Genera el enlace y compártelo.'
                : "{$respuestas} respuesta(s) de {$colaboradores} colaboradores ({$cobertura} %).",
            'url' => '/pesv/encuesta',
            'cantidad' => $respuestas,
        ];
    }

    /** @return array<string, mixed> */
    private function matrizRiesgosViales(): array
    {
        $abiertos = PesvRoadRisk::whereNull('fecha_cierre')->get(['id', 'nivel', 'eficaz']);
        $criticos = $abiertos->where('nivel', 'critico')->count();

        return [
            'etiqueta' => 'Matriz de riesgos viales',
            'estado' => $abiertos->isEmpty() ? 'falta' : ($criticos > 0 ? 'parcial' : 'ok'),
            'detalle' => $abiertos->isEmpty()
                ? 'La matriz de riesgos viales está vacía (RE-SST-45).'
                : $abiertos->count().' riesgo(s) abierto(s), '.$criticos.' crítico(s).',
            'url' => '/pesv/riesgos-viales',
            'cantidad' => $abiertos->count(),
        ];
    }

    /**
     * Paso 11 con los registros propios del paso: requisitos del operador y
     * del vehículo, pruebas de idoneidad, comparendos y el semáforo.
     *
     * @return list<array<string, mixed>>
     */
    private function paso11(): array
    {
        $conductores = Employee::where('is_active', true)->conductores()->pluck('id');
        $total = $conductores->count();
        $checks = PesvDriverCheck::whereIn('employee_id', $conductores)->pluck('resultado');
        $aptos = PesvDriverTest::whereIn('employee_id', $conductores)->where('resultado', 'apto')
            ->whereIn('tipo', ['teorica', 'practica'])->get(['employee_id', 'tipo'])
            ->groupBy('employee_id')->filter(fn ($g) => $g->pluck('tipo')->unique()->count() === 2)->count();
        $vehiculos = PesvVehicle::where('is_active', true)->count();
        $vehiculosOk = PesvVehicleCheck::where('resultado', 'cumple')
            ->whereIn('pesv_vehicle_id', PesvVehicle::where('is_active', true)->pluck('id'))->count();
        $abiertas = PesvInfraction::whereNotIn('estado', PesvInfraction::CERRADAS)->count();
        $semaforo = SemaforoDocumentos::resumen(SemaforoDocumentos::filas());

        return [
            [
                'etiqueta' => 'Requisitos del operador',
                'estado' => $total === 0 ? 'falta' : ($checks->filter(fn ($r) => $r === 'cumple')->count() === $total ? 'ok' : ($checks->isEmpty() ? 'falta' : 'parcial')),
                'detalle' => $total === 0 ? 'No hay conductores activos.'
                    : $checks->filter(fn ($r) => $r === 'cumple')->count()." de {$total} conductores cumplen la lista RE-SST-51"
                        .($checks->contains('no_cumple') ? ' ('.$checks->filter(fn ($r) => $r === 'no_cumple')->count().' con requisitos sin cumplir)' : '').'.',
                'url' => '/pesv/colaboradores',
                'cantidad' => $checks->count(),
            ],
            [
                'etiqueta' => 'Pruebas de idoneidad',
                'estado' => $total === 0 ? 'falta' : ($aptos === $total ? 'ok' : ($aptos > 0 ? 'parcial' : 'falta')),
                'detalle' => $total === 0 ? 'No hay conductores activos.' : "{$aptos} de {$total} conductores aprobaron la prueba teórica y la práctica.",
                'url' => '/pesv/colaboradores',
                'cantidad' => $aptos,
            ],
            [
                'etiqueta' => 'Requisitos de los vehículos',
                'estado' => $vehiculos === 0 ? 'falta' : ($vehiculosOk === $vehiculos ? 'ok' : ($vehiculosOk > 0 ? 'parcial' : 'falta')),
                'detalle' => $vehiculos === 0 ? 'No hay vehículos activos.' : "{$vehiculosOk} de {$vehiculos} vehículos aprobados con la lista RE-SST-50.",
                'url' => '/pesv/vehiculos',
                'cantidad' => $vehiculosOk,
            ],
            [
                'etiqueta' => 'Semáforo de documentos',
                'estado' => $semaforo['cumplimiento'] === null ? 'falta' : ($semaforo['E'] === 0 ? 'ok' : 'parcial'),
                'detalle' => $semaforo['cumplimiento'] === null ? 'No hay fechas de vencimiento registradas.'
                    : "Cumplimiento {$semaforo['cumplimiento']} %: {$semaforo['E']} documento(s) vencido(s) o a menos de 8 días, {$semaforo['P']} por vencer.",
                'url' => '/pesv/documentos',
                'cantidad' => $semaforo['E'],
            ],
            [
                'etiqueta' => 'Infracciones de tránsito sin cerrar',
                'estado' => $abiertas === 0 ? 'ok' : 'parcial',
                'detalle' => $abiertas === 0 ? 'Sin comparendos pendientes.' : "{$abiertas} comparendo(s) pendiente(s) de pago o de curso.",
                'url' => '/pesv/infracciones',
                'cantidad' => $abiertas,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function siniestrosSinInvestigar(): array
    {
        $pendientes = PesvSiniestro::where('investigado', false)->count();

        return [
            'etiqueta' => 'Siniestros pendientes de investigar',
            'estado' => $pendientes === 0 ? 'ok' : 'parcial',
            'detalle' => $pendientes === 0
                ? 'No quedan siniestros sin investigar.'
                : "{$pendientes} siniestros registrados sin investigación cerrada.",
            'url' => '/pesv/siniestros',
            'cantidad' => $pendientes,
        ];
    }

    /** @return array<string, mixed> */
    private function mantenimiento(): array
    {
        $flota = PesvVehicle::where('is_active', true)->count();
        $vencidos = PesvVehicle::where('is_active', true)
            ->whereNotNull('proximo_mantenimiento')
            ->whereDate('proximo_mantenimiento', '<', now())
            ->count();

        return [
            'etiqueta' => 'Flota con mantenimiento programado',
            'estado' => $flota === 0 ? 'falta' : ($vencidos > 0 ? 'parcial' : 'ok'),
            'detalle' => $flota === 0
                ? 'Sin vehículos registrados.'
                : "{$flota} vehículos activos, {$vencidos} con el próximo mantenimiento vencido.",
            'url' => '/pesv/vehiculos',
            'cantidad' => $flota,
        ];
    }

    /** @return array<string, mixed> */
    private function contratistas(): array
    {
        $total = PesvContractor::where('is_active', true)->count();
        $sinEvaluar = PesvContractor::where('is_active', true)->whereNull('evaluado_at')->count();

        return [
            'etiqueta' => 'Contratistas evaluados',
            'estado' => $total === 0 ? 'falta' : ($sinEvaluar > 0 ? 'parcial' : 'ok'),
            'detalle' => $total === 0
                ? 'Sin contratistas registrados.'
                : "{$total} contratistas activos, {$sinEvaluar} sin fecha de evaluación.",
            'url' => '/pesv/contratistas',
            'cantidad' => $total,
        ];
    }

    /** @return array<string, mixed> */
    private function indicadoresConLectura(): array
    {
        $pesv = Indicator::where('categoria', 'PESV')->pluck('id');
        $anio = (int) now()->year;
        $conLectura = IndicatorReading::where('anio', $anio)->whereIn('indicator_id', $pesv)->distinct()->count('indicator_id');

        return [
            'etiqueta' => "Indicadores PESV medidos en {$anio}",
            'estado' => $pesv->isEmpty() ? 'falta' : ($conLectura === 0 ? 'falta' : ($conLectura < $pesv->count() ? 'parcial' : 'ok')),
            'detalle' => $pesv->isEmpty()
                ? 'No hay indicadores PESV cargados.'
                : "{$conLectura} de {$pesv->count()} indicadores PESV tienen mediciones este año. El reporte de autogestión se hace con corte al 31 de diciembre.",
            'url' => '/indicadores',
            'cantidad' => $conLectura,
        ];
    }

    /**
     * Estado del documento que respalda un paso.
     *
     * @return array<string, mixed>
     */
    private function documento(string $codigo): array
    {
        $doc = GeneratedDocument::whereHas('template', fn ($q) => $q->where('codigo', $codigo))
            ->latest('id')
            ->first();

        $titulo = $doc?->titulo ?? $codigo;

        return [
            'etiqueta' => "Documento {$codigo}",
            'estado' => $doc === null ? 'falta' : ($doc->estado === 'aprobado' ? 'ok' : 'parcial'),
            'detalle' => $doc === null
                ? 'No se ha generado. Está disponible como plantilla en Documentos IA.'
                : "«{$titulo}» en estado {$doc->estado} (v{$doc->version}).",
            'url' => '/documentos-ia',
            'cantidad' => null,
        ];
    }
}
