<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FormRecord;
use App\Models\GeneratedDocument;
use App\Models\Indicator;
use App\Models\IpercRow;
use App\Models\PesvContractor;
use App\Models\PesvRoute;
use App\Models\PesvSede;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Models\Tenant;
use App\Models\Training;
use App\Models\WorkPlan;

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
        if ($numero === 11) {
            $insumos[] = $this->conductores();
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
        $indicadores = Indicator::where('categoria', 'PESV')->count();

        return [[
            'etiqueta' => 'Indicadores PESV definidos',
            'estado' => $indicadores > 0 ? 'ok' : 'falta',
            'detalle' => $indicadores > 0
                ? "{$indicadores} indicadores de categoría PESV, con sus metas."
                : 'No hay indicadores de categoría PESV. Los objetivos del paso 7 se miden con ellos.',
            'url' => '/indicadores',
            'cantidad' => $indicadores,
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
        $conLectura = Indicator::where('categoria', 'PESV')->has('readings')->count();
        $total = Indicator::where('categoria', 'PESV')->count();

        return [
            'etiqueta' => 'Indicadores PESV con mediciones',
            'estado' => $total === 0 ? 'falta' : ($conLectura === 0 ? 'parcial' : 'ok'),
            'detalle' => $total === 0
                ? 'No hay indicadores PESV definidos.'
                : "{$conLectura} de {$total} indicadores PESV tienen mediciones cargadas.",
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
