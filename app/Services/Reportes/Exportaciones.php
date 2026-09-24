<?php

namespace App\Services\Reportes;

use App\Models\Absence;
use App\Models\AcpmAction;
use App\Models\AuditFinding;
use App\Models\ChangeRequest;
use App\Models\ContractorEvaluation;
use App\Models\EmergencyDrill;
use App\Models\Employee;
use App\Models\FormRecord;
use App\Models\IndicatorReading;
use App\Models\IpercRow;
use App\Models\LegalRequirement;
use App\Models\MaintenanceRecord;
use App\Models\MedicalExam;
use App\Models\PesvInfraction;
use App\Models\PesvSiniestro;
use App\Models\PesvVehicle;
use App\Models\PpeDelivery;
use App\Models\SafetyReport;
use App\Models\TrainingAttendee;
use App\Models\User;
use App\Models\WorkAccident;
use App\Support\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Catálogo de exportaciones a Excel: los datos de cada módulo, completos,
 * para auditorías, la ARL o el análisis propio del cliente.
 *
 * Cada una declara su módulo y su permiso (los mismos de su pantalla) y, si
 * sus registros son eventos, la columna de fecha por la que se filtra el
 * periodo. Las de estado (matrices, flota, nómina) salen completas.
 */
class Exportaciones
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array<string, mixed>> las que este usuario puede bajar para la empresa activa */
    public function disponibles(User $user): array
    {
        $tenant = $this->context->get();

        return array_values(array_filter($this->definiciones(),
            fn (array $e) => ($e['modulo'] === null || $tenant?->moduloHabilitado($e['modulo'])) && $user->can($e['permiso'])));
    }

    /** @return array<string, mixed>|null */
    public function buscar(User $user, string $clave): ?array
    {
        return collect($this->disponibles($user))->firstWhere('clave', $clave);
    }

    /**
     * Filas de una exportación: [encabezados, filas].
     *
     * @return array{0: list<string>, 1: iterable<list<mixed>>}
     */
    public function filas(array $e, ?Periodo $periodo): array
    {
        /** @var Builder $q */
        $q = $e['consulta']();
        if ($periodo && $e['fecha']) {
            $periodo->filtrar($q, $e['fecha']);
        }

        return [
            array_keys($e['columnas']),
            $q->lazy(500)->map(fn ($m) => array_map(fn (callable $c) => $c($m), array_values($e['columnas']))),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function definiciones(): array
    {
        $persona = fn ($m) => $m->employee ? trim("{$m->employee->nombres} {$m->employee->apellidos}") : null;
        $documento = fn ($m) => $m->employee?->numero_documento;
        $empleado = 'employee:id,nombres,apellidos,numero_documento,cargo';

        return [
            [
                'clave' => 'empleados', 'titulo' => 'Trabajadores', 'grupo' => 'Organización',
                'descripcion' => 'Nómina completa con datos laborales y de seguridad social.',
                'modulo' => null, 'permiso' => 'sst.view', 'fecha' => null,
                'consulta' => fn () => Employee::query()->orderBy('apellidos')->orderBy('nombres'),
                'columnas' => [
                    'Tipo doc.' => fn ($e) => $e->tipo_documento, 'Documento' => fn ($e) => $e->numero_documento,
                    'Apellidos' => fn ($e) => $e->apellidos, 'Nombres' => fn ($e) => $e->nombres,
                    'Cargo' => fn ($e) => $e->cargo, 'Área' => fn ($e) => $e->area, 'Sede' => fn ($e) => $e->sede,
                    'Fecha de ingreso' => fn ($e) => $e->fecha_ingreso, 'Tipo de contrato' => fn ($e) => $e->tipo_contrato,
                    'EPS' => fn ($e) => $e->eps, 'AFP' => fn ($e) => $e->afp, 'ARL' => fn ($e) => $e->arl,
                    'Nivel de riesgo' => fn ($e) => $e->nivel_riesgo, 'Activo' => fn ($e) => $e->is_active,
                    'Conductor' => fn ($e) => $e->es_conductor, 'Licencia vence' => fn ($e) => $e->licencia_vence,
                ],
            ],
            [
                'clave' => 'accidentes', 'titulo' => 'Accidentes e incidentes', 'grupo' => 'Accidentalidad y salud',
                'descripcion' => 'Eventos con su reporte a la ARL e investigación.',
                'modulo' => 'accidentes', 'permiso' => 'incidents.view', 'fecha' => 'fecha',
                'consulta' => fn () => WorkAccident::with([$empleado, 'ausencia:id,dias'])->orderBy('fecha'),
                'columnas' => [
                    'Código' => fn ($a) => $a->codigo, 'Fecha' => fn ($a) => $a->fecha, 'Hora' => fn ($a) => $a->hora,
                    'Clase' => fn ($a) => $a->clase, 'Trabajador' => $persona, 'Documento' => $documento,
                    'Área' => fn ($a) => $a->area, 'Lugar' => fn ($a) => $a->lugar, 'Descripción' => fn ($a) => $a->descripcion,
                    'Tipo de lesión' => fn ($a) => $a->tipo_lesion, 'Parte del cuerpo' => fn ($a) => $a->parte_cuerpo,
                    'Mecanismo' => fn ($a) => $a->mecanismo, 'Agente' => fn ($a) => $a->agente,
                    'Mortal' => fn ($a) => $a->mortal, 'Grave' => fn ($a) => $a->grave, 'Días perdidos' => fn ($a) => $a->ausencia?->dias,
                    'Reportado a ARL' => fn ($a) => $a->reportado_arl, 'Fecha reporte ARL' => fn ($a) => $a->fecha_reporte_arl,
                    'Investigado' => fn ($a) => $a->investigado, 'Fecha investigación' => fn ($a) => $a->fecha_investigacion,
                    'Causa raíz' => fn ($a) => $a->causa_raiz,
                ],
            ],
            [
                'clave' => 'ausentismo', 'titulo' => 'Ausentismo', 'grupo' => 'Accidentalidad y salud',
                'descripcion' => 'Ausencias e incapacidades que inician en el periodo.',
                'modulo' => 'ausentismo', 'permiso' => 'sst.view', 'fecha' => 'fecha_inicio',
                'consulta' => fn () => Absence::with($empleado)->orderBy('fecha_inicio'),
                'columnas' => [
                    'Trabajador' => $persona, 'Documento' => $documento, 'Cargo' => fn ($a) => $a->employee?->cargo,
                    'Tipo' => fn ($a) => $a->tipo, 'Inicio' => fn ($a) => $a->fecha_inicio, 'Fin' => fn ($a) => $a->fecha_fin,
                    'Días' => fn ($a) => $a->dias, 'CIE-10' => fn ($a) => $a->cie10, 'Diagnóstico' => fn ($a) => $a->diagnostico,
                    'Entidad' => fn ($a) => $a->entidad, 'N.º incapacidad' => fn ($a) => $a->incapacidad_numero, 'Prórroga' => fn ($a) => $a->prorroga,
                ],
            ],
            [
                'clave' => 'examenes', 'titulo' => 'Exámenes médicos ocupacionales', 'grupo' => 'Accidentalidad y salud',
                'descripcion' => 'Conceptos de aptitud, restricciones y próximo examen.',
                'modulo' => 'salud-ocupacional', 'permiso' => 'sst.view', 'fecha' => 'fecha',
                'consulta' => fn () => MedicalExam::with($empleado)->orderBy('fecha'),
                'columnas' => [
                    'Trabajador' => $persona, 'Documento' => $documento, 'Cargo' => fn ($e) => $e->employee?->cargo,
                    'Fecha' => fn ($e) => $e->fecha, 'Tipo' => fn ($e) => $e->tipo, 'IPS' => fn ($e) => $e->ips,
                    'Exámenes realizados' => fn ($e) => implode(', ', $e->examenes_realizados ?? []),
                    'Concepto' => fn ($e) => $e->concepto, 'Restricciones' => fn ($e) => $e->restricciones,
                    'Recomendaciones SST' => fn ($e) => $e->recomendaciones_sst, 'Carta entregada' => fn ($e) => $e->carta_entregada,
                    'PVE' => fn ($e) => $e->pve, 'Próximo examen' => fn ($e) => $e->proximo_examen,
                ],
            ],
            [
                'clave' => 'reportes-ac', 'titulo' => 'Actos y condiciones inseguras', 'grupo' => 'Seguimiento',
                'descripcion' => 'Reportes con su severidad e intervención.',
                'modulo' => 'reportes-ac', 'permiso' => 'incidents.view', 'fecha' => 'fecha',
                'consulta' => fn () => SafetyReport::query()->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($r) => $r->fecha, 'Reportado por' => fn ($r) => $r->reportado_por, 'Tipo' => fn ($r) => $r->tipo,
                    'Área' => fn ($r) => $r->area, 'Lugar' => fn ($r) => $r->lugar, 'Descripción' => fn ($r) => $r->descripcion,
                    'Clasificación del peligro' => fn ($r) => $r->clasificacion_peligro, 'Severidad' => fn ($r) => $r->severidad,
                    'Acción inmediata' => fn ($r) => $r->accion_inmediata, 'Estado' => fn ($r) => $r->estado,
                    'Fecha de intervención' => fn ($r) => $r->fecha_intervencion, 'Responsable' => fn ($r) => $r->responsable_intervencion,
                ],
            ],
            [
                'clave' => 'acpm', 'titulo' => 'Acciones correctivas y de mejora', 'grupo' => 'Seguimiento',
                'descripcion' => 'ACPM detectadas en el periodo, con su cierre y verificación.',
                'modulo' => 'acpm', 'permiso' => 'sst.view', 'fecha' => 'fecha_deteccion',
                'consulta' => fn () => AcpmAction::query()->orderBy('fecha_deteccion'),
                'columnas' => [
                    'Código' => fn ($a) => $a->codigo, 'Tipo' => fn ($a) => $a->tipo, 'Origen' => fn ($a) => $a->origen_tipo,
                    'Hallazgo' => fn ($a) => $a->hallazgo, 'Causa' => fn ($a) => $a->causa, 'Acción' => fn ($a) => $a->accion,
                    'Responsable' => fn ($a) => $a->responsable, 'Detección' => fn ($a) => $a->fecha_deteccion,
                    'Fecha límite' => fn ($a) => $a->fecha_limite, 'Estado' => fn ($a) => $a->estado, 'Vencida' => fn ($a) => $a->vencida,
                    'Cierre' => fn ($a) => $a->fecha_cierre, 'Eficaz' => fn ($a) => $a->eficaz, 'Verificación' => fn ($a) => $a->verificacion,
                ],
            ],
            [
                'clave' => 'asistencia', 'titulo' => 'Asistencia a capacitaciones', 'grupo' => 'Gestión',
                'descripcion' => 'Una fila por asistente: tema, fecha, asistencia, nota y eficacia.',
                'modulo' => 'capacitaciones', 'permiso' => 'sst.view', 'fecha' => 'trainings.fecha',
                // TrainingAttendee no tiene tenant: se filtra por su capacitación.
                'consulta' => fn () => TrainingAttendee::query()->select('training_attendees.*')
                    ->join('trainings', 'trainings.id', '=', 'training_attendees.training_id')
                    ->where('trainings.tenant_id', $this->context->id())
                    ->with('training:id,titulo,fecha,estado,instructor,duracion_minutos')
                    ->orderBy('trainings.fecha')->orderBy('training_attendees.id'),
                'columnas' => [
                    'Capacitación' => fn ($a) => $a->training?->titulo, 'Fecha' => fn ($a) => $a->training?->fecha,
                    'Estado' => fn ($a) => $a->training?->estado, 'Instructor' => fn ($a) => $a->training?->instructor,
                    'Duración (min)' => fn ($a) => $a->training?->duracion_minutos, 'Nombre' => fn ($a) => $a->nombres,
                    'Documento' => fn ($a) => $a->numero_documento, 'Cargo' => fn ($a) => $a->cargo, 'Asistió' => fn ($a) => $a->asistio,
                    'Nota' => fn ($a) => $a->nota, 'Eficaz' => fn ($a) => $a->eficaz,
                ],
            ],
            [
                'clave' => 'formatos', 'titulo' => 'Inspecciones y formatos diligenciados', 'grupo' => 'Gestión',
                'descripcion' => 'Listado de registros (el detalle de cada uno se baja en Word desde Formatos).',
                'modulo' => 'inspecciones', 'permiso' => 'inspections.view', 'fecha' => 'fecha',
                'consulta' => fn () => FormRecord::query()->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($r) => $r->fecha, 'Código' => fn ($r) => $r->codigo, 'Consecutivo' => fn ($r) => $r->consecutivo, 'Formato' => fn ($r) => $r->titulo,
                    'Tipo' => fn ($r) => $r->grupo, 'Estado' => fn ($r) => $r->estado, 'Responsable' => fn ($r) => $r->responsable,
                    'Diligenciado por' => fn ($r) => $r->generado_por,
                ],
            ],
            [
                'clave' => 'indicadores', 'titulo' => 'Lecturas de indicadores', 'grupo' => 'Gestión',
                'descripcion' => 'Numerador, denominador y resultado de cada mes medido.',
                'modulo' => 'indicadores', 'permiso' => 'sst.view', 'fecha' => null,
                'consulta' => fn () => IndicatorReading::with('indicator')->orderBy('anio')->orderBy('mes')->orderBy('indicator_id'),
                'columnas' => [
                    'Año' => fn ($l) => $l->anio, 'Mes' => fn ($l) => $l->mes, 'Código' => fn ($l) => $l->indicator?->codigo,
                    'Indicador' => fn ($l) => $l->indicator?->nombre, 'Numerador' => fn ($l) => (float) $l->numerador,
                    'Denominador' => fn ($l) => (float) $l->denominador,
                    'Resultado' => fn ($l) => $l->indicator?->calcular((float) $l->numerador, (float) $l->denominador),
                ],
            ],
            [
                'clave' => 'epp', 'titulo' => 'Entregas de EPP', 'grupo' => 'Gestión',
                'descripcion' => 'Entregas con talla, cantidad y firma de recibido.',
                'modulo' => 'epp', 'permiso' => 'sst.view', 'fecha' => 'fecha_entrega',
                'consulta' => fn () => PpeDelivery::with([$empleado, 'item:id,nombre,categoria'])->orderBy('fecha_entrega'),
                'columnas' => [
                    'Fecha' => fn ($d) => $d->fecha_entrega, 'Trabajador' => $persona, 'Documento' => $documento,
                    'Cargo' => fn ($d) => $d->employee?->cargo, 'Elemento' => fn ($d) => $d->item?->nombre,
                    'Categoría' => fn ($d) => $d->item?->categoria, 'Cantidad' => fn ($d) => $d->cantidad, 'Talla' => fn ($d) => $d->talla,
                    'Motivo' => fn ($d) => $d->motivo, 'Entregado por' => fn ($d) => $d->entregado_por,
                    'Firmado' => fn ($d) => $d->firmada, 'Fecha de firma' => fn ($d) => $d->fecha_firma,
                ],
            ],
            [
                'clave' => 'simulacros', 'titulo' => 'Simulacros', 'grupo' => 'Gestión',
                'descripcion' => 'Simulacros con tiempos de evacuación y participación.',
                'modulo' => 'emergencias', 'permiso' => 'sst.view', 'fecha' => 'fecha',
                'consulta' => fn () => EmergencyDrill::query()->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($s) => $s->fecha, 'Tipo' => fn ($s) => $s->tipo, 'Escenario' => fn ($s) => $s->escenario,
                    'Sede' => fn ($s) => $s->sede, 'Estado' => fn ($s) => $s->estado,
                    'Evacuación (s)' => fn ($s) => $s->tiempo_evacuacion_segundos, 'Convocados' => fn ($s) => $s->convocados,
                    'Participantes' => fn ($s) => $s->participantes, 'Evacuados' => fn ($s) => $s->evacuados,
                    'Entidades de apoyo' => fn ($s) => $s->entidades_apoyo,
                ],
            ],
            [
                'clave' => 'mantenimientos', 'titulo' => 'Mantenimientos', 'grupo' => 'Gestión',
                'descripcion' => 'Preventivos y correctivos con costo y proveedor.',
                'modulo' => 'mantenimiento', 'permiso' => 'sst.view', 'fecha' => 'fecha',
                'consulta' => fn () => MaintenanceRecord::with(['asset:id,codigo,nombre,tipo', 'planItem:id,actividad'])->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($r) => $r->fecha, 'Activo' => fn ($r) => $r->asset?->nombre, 'Código activo' => fn ($r) => $r->asset?->codigo,
                    'Tipo de activo' => fn ($r) => $r->asset?->tipo, 'Tipo' => fn ($r) => $r->tipo,
                    'Actividad del plan' => fn ($r) => $r->planItem?->actividad, 'Descripción' => fn ($r) => $r->descripcion,
                    'Lectura' => fn ($r) => $r->lectura, 'Realizado por' => fn ($r) => $r->realizado_por,
                    'Proveedor idóneo' => fn ($r) => $r->proveedor_idoneo, 'Factura' => fn ($r) => $r->factura,
                    'Valor' => fn ($r) => $r->valor !== null ? (float) $r->valor : null, 'Estado' => fn ($r) => $r->estado,
                ],
            ],
            [
                'clave' => 'evaluaciones-contratistas', 'titulo' => 'Evaluaciones de contratistas', 'grupo' => 'Gestión',
                'descripcion' => 'Selección, desempeño y requisitos SST de cada contratista.',
                'modulo' => 'contratistas', 'permiso' => 'sst.view', 'fecha' => 'fecha',
                'consulta' => fn () => ContractorEvaluation::with('contractor:id,nombre,nit')->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($e) => $e->fecha, 'Contratista' => fn ($e) => $e->contractor?->nombre, 'NIT' => fn ($e) => $e->contractor?->nit,
                    'Uso' => fn ($e) => $e->uso, 'Puntaje' => fn ($e) => $e->puntaje, 'Porcentaje' => fn ($e) => $e->porcentaje !== null ? (float) $e->porcentaje : null,
                    'Resultado' => fn ($e) => $e->resultado,
                    'Incumplimientos' => fn ($e) => is_array($e->incumplimientos) ? implode('; ', $e->incumplimientos) : $e->incumplimientos,
                ],
            ],
            [
                'clave' => 'gestion-cambio', 'titulo' => 'Gestión del cambio', 'grupo' => 'Seguimiento',
                'descripcion' => 'Solicitudes con aprobaciones y cierre.',
                'modulo' => 'gestion-cambio', 'permiso' => 'sst.view', 'fecha' => 'fecha_solicitud',
                'consulta' => fn () => ChangeRequest::query()->orderBy('fecha_solicitud'),
                'columnas' => [
                    'Fecha' => fn ($c) => $c->fecha_solicitud, 'Solicitante' => fn ($c) => $c->solicitante, 'Área' => fn ($c) => $c->area,
                    'Tipo' => fn ($c) => $c->tipo, 'Condición' => fn ($c) => $c->condicion, 'Descripción' => fn ($c) => $c->descripcion,
                    'Fecha límite' => fn ($c) => $c->fecha_limite, 'Estado' => fn ($c) => $c->estado,
                    'Aprobó gerencia' => fn ($c) => $c->aprobacion_gerencia_fecha, 'Aprobó SST' => fn ($c) => $c->aprobacion_sst_fecha,
                    'Cierre' => fn ($c) => $c->cierre_fecha, 'Eficaz' => fn ($c) => $c->cierre_eficaz,
                ],
            ],
            [
                'clave' => 'hallazgos-auditoria', 'titulo' => 'Hallazgos de auditoría', 'grupo' => 'Seguimiento',
                'descripcion' => 'Una fila por hallazgo de las auditorías programadas en el periodo.',
                'modulo' => 'auditoria', 'permiso' => 'audit.view', 'fecha' => 'audits.fecha_programada',
                // AuditFinding no tiene tenant: se filtra por su auditoría.
                'consulta' => fn () => AuditFinding::query()->select('audit_findings.*')
                    ->join('audits', 'audits.id', '=', 'audit_findings.audit_id')
                    ->where('audits.tenant_id', $this->context->id())
                    ->with(['audit:id,codigo,tipo,fecha_programada', 'accion:id,codigo'])
                    ->orderBy('audits.fecha_programada')->orderBy('audit_findings.id'),
                'columnas' => [
                    'Auditoría' => fn ($h) => $h->audit?->codigo, 'Fecha' => fn ($h) => $h->audit?->fecha_programada,
                    'Tipo de auditoría' => fn ($h) => $h->audit?->tipo, 'Tipo de hallazgo' => fn ($h) => $h->tipo,
                    'Proceso' => fn ($h) => $h->proceso, 'Requisito' => fn ($h) => $h->requisito, 'Descripción' => fn ($h) => $h->descripcion,
                    'ACPM' => fn ($h) => $h->accion?->codigo,
                ],
            ],
            [
                'clave' => 'requisitos-legales', 'titulo' => 'Matriz de requisitos legales', 'grupo' => 'Matrices (estado actual)',
                'descripcion' => 'Matriz completa con su evaluación de cumplimiento.',
                'modulo' => 'requisitos-legales', 'permiso' => 'sst.view', 'fecha' => null,
                'consulta' => fn () => LegalRequirement::query()->orderBy('id'),
                'columnas' => [
                    'Norma' => fn ($r) => $r->norma, 'Año' => fn ($r) => $r->anio, 'Artículo' => fn ($r) => $r->articulo,
                    'Tema' => fn ($r) => $r->tema, 'Entidad' => fn ($r) => $r->entidad, 'Requisito' => fn ($r) => $r->requisito,
                    'Aplica' => fn ($r) => $r->aplica, 'Cumplimiento' => fn ($r) => $r->cumplimiento,
                    'Forma de cumplimiento' => fn ($r) => $r->forma_cumplimiento, 'Evidencia' => fn ($r) => $r->evidencia,
                    'Responsable' => fn ($r) => $r->responsable, 'Verificado' => fn ($r) => $r->fecha_verificacion,
                ],
            ],
            [
                'clave' => 'iperc', 'titulo' => 'Matriz IPERC', 'grupo' => 'Matrices (estado actual)',
                'descripcion' => 'Peligros, valoración GTC 45 y medidas de intervención.',
                'modulo' => 'iperc', 'permiso' => 'sst.view', 'fecha' => null,
                'consulta' => fn () => IpercRow::query()->orderBy('proceso')->orderBy('id'),
                'columnas' => [
                    'Proceso' => fn ($r) => $r->proceso, 'Zona' => fn ($r) => $r->zona, 'Actividad' => fn ($r) => $r->actividad,
                    'Tarea' => fn ($r) => $r->tarea, 'Rutinaria' => fn ($r) => $r->rutinaria, 'Clasificación' => fn ($r) => $r->clasificacion,
                    'Peligro' => fn ($r) => $r->peligro, 'Efectos' => fn ($r) => $r->efectos, 'ND' => fn ($r) => $r->nd, 'NE' => fn ($r) => $r->ne,
                    'NP' => fn ($r) => $r->np, 'NC' => fn ($r) => $r->nc, 'NR' => fn ($r) => $r->nr, 'Nivel de riesgo' => fn ($r) => $r->nivel_riesgo,
                    'Aceptabilidad' => fn ($r) => $r->aceptabilidad, 'Expuestos' => fn ($r) => $r->expuestos,
                    'Eliminación' => fn ($r) => $r->med_eliminacion, 'Sustitución' => fn ($r) => $r->med_sustitucion,
                    'Ingeniería' => fn ($r) => $r->med_ingenieria, 'Administrativos' => fn ($r) => $r->med_administrativos, 'EPP' => fn ($r) => $r->med_epp,
                ],
            ],
            [
                'clave' => 'siniestros-viales', 'titulo' => 'Siniestros viales', 'grupo' => 'Seguridad vial',
                'descripcion' => 'Siniestros con vehículo, conductor y consecuencias.',
                'modulo' => 'pesv', 'permiso' => 'pesv.view', 'fecha' => 'fecha',
                'consulta' => fn () => PesvSiniestro::with(['vehiculo:id,placa', 'conductor:id,nombres,apellidos,numero_documento'])->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($s) => $s->fecha, 'Hora' => fn ($s) => $s->hora, 'Lugar' => fn ($s) => $s->lugar, 'Tipo' => fn ($s) => $s->tipo,
                    'Gravedad' => fn ($s) => $s->gravedad, 'Vehículo' => fn ($s) => $s->vehiculo?->placa,
                    'Conductor' => fn ($s) => $s->conductor ? trim("{$s->conductor->nombres} {$s->conductor->apellidos}") : null,
                    'Lesionados' => fn ($s) => $s->lesionados, 'Fallecidos' => fn ($s) => $s->fallecidos,
                    'Días de incapacidad' => fn ($s) => $s->dias_incapacidad, 'Costo' => fn ($s) => $s->costo !== null ? (float) $s->costo : null,
                    'Causa probable' => fn ($s) => $s->causa_probable, 'Investigado' => fn ($s) => $s->investigado,
                ],
            ],
            [
                'clave' => 'infracciones', 'titulo' => 'Infracciones de tránsito', 'grupo' => 'Seguridad vial',
                'descripcion' => 'Comparendos de los conductores, con código, valor y estado.',
                'modulo' => 'pesv', 'permiso' => 'pesv.view', 'fecha' => 'fecha',
                'consulta' => fn () => PesvInfraction::with([$empleado, 'vehiculo:id,placa'])->orderBy('fecha'),
                'columnas' => [
                    'Fecha' => fn ($i) => $i->fecha, 'Conductor' => $persona, 'Documento' => $documento,
                    'Código' => fn ($i) => $i->codigo, 'Descripción' => fn ($i) => $i->descripcion, 'Vehículo' => fn ($i) => $i->vehiculo?->placa,
                    'Valor' => fn ($i) => $i->valor !== null ? (float) $i->valor : null, 'Estado' => fn ($i) => $i->estado,
                    'Registrada en SIMIT' => fn ($i) => $i->registrada_simit, 'Acciones' => fn ($i) => $i->acciones,
                ],
            ],
            [
                'clave' => 'vehiculos', 'titulo' => 'Flota de vehículos', 'grupo' => 'Seguridad vial',
                'descripcion' => 'Vehículos con vencimientos de SOAT, tecnomecánica y póliza.',
                'modulo' => 'pesv', 'permiso' => 'pesv.view', 'fecha' => null,
                'consulta' => fn () => PesvVehicle::query()->orderBy('placa'),
                'columnas' => [
                    'Placa' => fn ($v) => $v->placa, 'Tipo' => fn ($v) => $v->tipo, 'Marca' => fn ($v) => $v->marca, 'Línea' => fn ($v) => $v->linea,
                    'Modelo' => fn ($v) => $v->modelo, 'Propiedad' => fn ($v) => $v->propiedad, 'Propietario' => fn ($v) => $v->propietario,
                    'SOAT vence' => fn ($v) => $v->soat_vence, 'Tecnomecánica vence' => fn ($v) => $v->tecnomecanica_vence,
                    'Póliza vence' => fn ($v) => $v->poliza_vence, 'Kilometraje' => fn ($v) => $v->kilometraje,
                    'Próximo mantenimiento' => fn ($v) => $v->proximo_mantenimiento, 'Activo' => fn ($v) => $v->is_active,
                ],
            ],
        ];
    }
}
