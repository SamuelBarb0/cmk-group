<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\AcpmActionController;
use App\Http\Controllers\AiDocumentController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CommitteeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentoEmpresaController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FormatoController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\IpercController;
use App\Http\Controllers\LegalRequirementController;
use App\Http\Controllers\OccupationalHealthController;
use App\Http\Controllers\OrganizacionController;
use App\Http\Controllers\PesvColaboradorController;
use App\Http\Controllers\PesvContractorController;
use App\Http\Controllers\PesvController;
use App\Http\Controllers\PesvRouteController;
use App\Http\Controllers\PesvSedeController;
use App\Http\Controllers\PesvSiniestroController;
use App\Http\Controllers\PesvVehicleController;
use App\Http\Controllers\PpeController;
use App\Http\Controllers\SafetyReportController;
use App\Http\Controllers\SstDiagnosticController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\WorkAccidentController;
use App\Http\Controllers\WorkPlanController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    /*
    | Clientes (F2): primer módulo funcional — CRUD de empresas cliente (tenants).
    | Ver -> clients.view | Crear/editar/eliminar -> clients.manage
    */
    Route::get('clientes', [ClienteController::class, 'index'])
        ->middleware('permission:clients.view')->name('clientes.index');
    Route::post('clientes', [ClienteController::class, 'store'])
        ->middleware('permission:clients.manage')->name('clientes.store');
    Route::put('clientes/{cliente}', [ClienteController::class, 'update'])
        ->middleware('permission:clients.manage')->name('clientes.update');
    Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])
        ->middleware('permission:clients.manage')->name('clientes.destroy');

    /*
    | Selector de cliente activo (solo consultores CMK).
    | Fija/limpia active_tenant_id en sesión para segregar los módulos.
    */
    Route::post('clientes/{cliente}/seleccionar', [ClienteController::class, 'select'])
        ->middleware('permission:clients.view')->name('clientes.select');
    Route::post('clientes/seleccion/salir', [ClienteController::class, 'clearSelection'])
        ->middleware('permission:clients.view')->name('clientes.clear');

    /*
    | Usuarios: gestión de usuarios de la plataforma (equipo CMK y usuarios cliente).
    | Ver -> users.view | Crear/editar/eliminar -> users.manage
    */
    Route::get('usuarios', [UsuarioController::class, 'index'])
        ->middleware('permission:users.view')->name('usuarios.index');
    Route::post('usuarios', [UsuarioController::class, 'store'])
        ->middleware('permission:users.manage')->name('usuarios.store');
    Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])
        ->middleware('permission:users.manage')->name('usuarios.update');
    Route::delete('usuarios/{usuario}', [UsuarioController::class, 'destroy'])
        ->middleware('permission:users.manage')->name('usuarios.destroy');

    /*
    | Empleados (Tier 1): nómina base del SGI, segregada por cliente activo.
    | Ver -> sst.view | Crear/editar/eliminar -> sst.manage
    */
    Route::get('empleados', [EmployeeController::class, 'index'])
        ->middleware('permission:sst.view')->name('empleados.index');
    Route::post('empleados', [EmployeeController::class, 'store'])
        ->middleware('permission:sst.manage')->name('empleados.store');
    Route::put('empleados/{empleado}', [EmployeeController::class, 'update'])
        ->middleware('permission:sst.manage')->name('empleados.update');
    Route::delete('empleados/{empleado}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:sst.manage')->name('empleados.destroy');

    /*
    | Información de la Organización (Tier 1): contexto SGI del cliente activo.
    | Ver -> sst.view | Editar -> sst.manage
    */
    Route::get('organizacion', [OrganizacionController::class, 'show'])
        ->middleware('permission:sst.view')->name('organizacion.show');
    Route::put('organizacion', [OrganizacionController::class, 'update'])
        ->middleware('permission:sst.manage')->name('organizacion.update');

    /*
    | Diagnóstico Estándares Mínimos SG-SST (Res. 0312) del cliente activo.
    | Ver -> sst.view | Diligenciar -> sst.manage
    */
    Route::get('diagnostico', [SstDiagnosticController::class, 'show'])
        ->middleware(['permission:sst.view', 'module:diagnostico'])->name('diagnostico.show');
    Route::post('diagnostico', [SstDiagnosticController::class, 'save'])
        ->middleware(['permission:sst.manage', 'module:diagnostico'])->name('diagnostico.save');

    /*
    | PESV — Plan Estratégico de Seguridad Vial (Res. 40595 de 2022).
    |
    | El plan son 24 pasos en 4 fases. Además del plan en sí, el módulo trae la
    | caracterización que exige el Paso 5 (sedes, colaboradores/conductores,
    | contratistas, vehículos y rutas) y el registro de siniestros viales que
    | alimenta los pasos 13 y 21.
    |
    | Ver -> pesv.view | Gestionar -> pesv.manage
    */
    Route::middleware(['permission:pesv.view', 'module:pesv'])->group(function () {
        Route::get('pesv', [PesvController::class, 'show'])->name('pesv.show');
        Route::get('pesv/paso/{numero}', [PesvController::class, 'paso'])
            ->whereNumber('numero')->name('pesv.paso');
        Route::get('pesv/sedes', [PesvSedeController::class, 'index'])->name('pesv.sedes.index');
        Route::get('pesv/colaboradores', [PesvColaboradorController::class, 'index'])->name('pesv.colaboradores.index');
        Route::get('pesv/contratistas', [PesvContractorController::class, 'index'])->name('pesv.contratistas.index');
        Route::get('pesv/vehiculos', [PesvVehicleController::class, 'index'])->name('pesv.vehiculos.index');
        Route::get('pesv/rutas', [PesvRouteController::class, 'index'])->name('pesv.rutas.index');
        Route::get('pesv/siniestros', [PesvSiniestroController::class, 'index'])->name('pesv.siniestros.index');
    });

    Route::middleware(['permission:pesv.manage', 'module:pesv'])->group(function () {
        Route::put('pesv', [PesvController::class, 'savePlan'])->name('pesv.plan.save');
        Route::post('pesv/paso/{numero}', [PesvController::class, 'saveStep'])
            ->whereNumber('numero')->name('pesv.paso.save');
        Route::post('pesv/comite', [PesvController::class, 'storeMiembro'])->name('pesv.comite.store');
        Route::delete('pesv/comite/{miembro}', [PesvController::class, 'destroyMiembro'])->name('pesv.comite.destroy');

        Route::post('pesv/sedes', [PesvSedeController::class, 'store'])->name('pesv.sedes.store');
        Route::put('pesv/sedes/{sede}', [PesvSedeController::class, 'update'])->name('pesv.sedes.update');
        Route::delete('pesv/sedes/{sede}', [PesvSedeController::class, 'destroy'])->name('pesv.sedes.destroy');

        Route::put('pesv/colaboradores/{colaborador}', [PesvColaboradorController::class, 'update'])
            ->name('pesv.colaboradores.update');

        Route::post('pesv/contratistas', [PesvContractorController::class, 'store'])->name('pesv.contratistas.store');
        Route::put('pesv/contratistas/{contratista}', [PesvContractorController::class, 'update'])->name('pesv.contratistas.update');
        Route::delete('pesv/contratistas/{contratista}', [PesvContractorController::class, 'destroy'])->name('pesv.contratistas.destroy');

        Route::post('pesv/vehiculos', [PesvVehicleController::class, 'store'])->name('pesv.vehiculos.store');
        Route::put('pesv/vehiculos/{vehiculo}', [PesvVehicleController::class, 'update'])->name('pesv.vehiculos.update');
        Route::delete('pesv/vehiculos/{vehiculo}', [PesvVehicleController::class, 'destroy'])->name('pesv.vehiculos.destroy');

        Route::post('pesv/rutas', [PesvRouteController::class, 'store'])->name('pesv.rutas.store');
        Route::put('pesv/rutas/{ruta}', [PesvRouteController::class, 'update'])->name('pesv.rutas.update');
        Route::delete('pesv/rutas/{ruta}', [PesvRouteController::class, 'destroy'])->name('pesv.rutas.destroy');

        Route::post('pesv/siniestros', [PesvSiniestroController::class, 'store'])->name('pesv.siniestros.store');
        Route::put('pesv/siniestros/{siniestro}', [PesvSiniestroController::class, 'update'])->name('pesv.siniestros.update');
        Route::delete('pesv/siniestros/{siniestro}', [PesvSiniestroController::class, 'destroy'])->name('pesv.siniestros.destroy');
    });

    /*
    | Matriz IPERC (GTC 45) del cliente activo.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('iperc', [IpercController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:iperc'])->name('iperc.index');
    Route::post('iperc', [IpercController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:iperc'])->name('iperc.store');
    Route::put('iperc/{peligro}', [IpercController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:iperc'])->name('iperc.update');
    Route::delete('iperc/{peligro}', [IpercController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:iperc'])->name('iperc.destroy');

    /*
    | Comites: COPASST y Comite de Convivencia Laboral. Es la fuente de los
    | indicadores CUMP-COPASST y CUMP-COCOLAB.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('comites', [CommitteeController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:comites'])->name('comites.index');
    Route::post('comites', [CommitteeController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:comites'])->name('comites.store');
    Route::put('comites/{comite}', [CommitteeController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:comites'])->name('comites.update');
    Route::delete('comites/{comite}', [CommitteeController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:comites'])->name('comites.destroy');

    /*
    | Auditorias del sistema de gestion. La barra lateral ya apuntaba a
    | /auditoria desde antes, pero la ruta NO existia: era un enlace muerto.
    | Ver -> audit.view | Gestionar -> sst.manage
    */
    Route::get('auditoria', [AuditController::class, 'index'])
        ->middleware(['permission:audit.view', 'module:auditoria'])->name('auditoria.index');
    Route::post('auditoria', [AuditController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:auditoria'])->name('auditoria.store');
    Route::put('auditoria/{auditoria}', [AuditController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:auditoria'])->name('auditoria.update');
    Route::delete('auditoria/{auditoria}', [AuditController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:auditoria'])->name('auditoria.destroy');

    /*
    | EPP: catalogo, matriz por cargo y entregas firmadas. Cierra el bucle que
    | abrio la jerarquia de controles del IPERC.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('epp', [PpeController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:epp'])->name('epp.index');
    Route::post('epp/items', [PpeController::class, 'storeItem'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.items.store');
    Route::put('epp/items/{item}', [PpeController::class, 'updateItem'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.items.update');
    Route::delete('epp/items/{item}', [PpeController::class, 'destroyItem'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.items.destroy');
    Route::post('epp/matriz', [PpeController::class, 'storeAssignment'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.matriz.store');
    Route::delete('epp/matriz/{asignacion}', [PpeController::class, 'destroyAssignment'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.matriz.destroy');
    Route::post('epp/entregas', [PpeController::class, 'storeDelivery'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.entregas.store');
    Route::put('epp/entregas/{entrega}', [PpeController::class, 'updateDelivery'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.entregas.update');
    Route::delete('epp/entregas/{entrega}', [PpeController::class, 'destroyDelivery'])
        ->middleware(['permission:sst.manage', 'module:epp'])->name('epp.entregas.destroy');

    /*
    | Plan de emergencias: brigada, simulacros, equipos y directorio MEDEVAC
    | (estandares 5.1.1 y 5.1.2 de la Res. 0312). Los simulacros alimentan
    | CUMP-SIM, REC-SIM y PART-EMERG.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('emergencias', [EmergencyController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:emergencias'])->name('emergencias.index');
    Route::post('emergencias/brigada', [EmergencyController::class, 'storeBrigadista'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.brigada.store');
    Route::put('emergencias/brigada/{brigadista}', [EmergencyController::class, 'updateBrigadista'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.brigada.update');
    Route::delete('emergencias/brigada/{brigadista}', [EmergencyController::class, 'destroyBrigadista'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.brigada.destroy');
    Route::post('emergencias/simulacros', [EmergencyController::class, 'storeSimulacro'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.simulacros.store');
    Route::put('emergencias/simulacros/{simulacro}', [EmergencyController::class, 'updateSimulacro'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.simulacros.update');
    Route::delete('emergencias/simulacros/{simulacro}', [EmergencyController::class, 'destroySimulacro'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.simulacros.destroy');
    Route::post('emergencias/equipos', [EmergencyController::class, 'storeEquipo'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.equipos.store');
    Route::put('emergencias/equipos/{equipo}', [EmergencyController::class, 'updateEquipo'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.equipos.update');
    Route::delete('emergencias/equipos/{equipo}', [EmergencyController::class, 'destroyEquipo'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.equipos.destroy');
    // Antes que la ruta con {contacto}: si no, «nacionales» se tomaria por un id.
    Route::post('emergencias/directorio/nacionales', [EmergencyController::class, 'cargarLineasNacionales'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.directorio.nacionales');
    Route::post('emergencias/directorio', [EmergencyController::class, 'storeContacto'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.directorio.store');
    Route::put('emergencias/directorio/{contacto}', [EmergencyController::class, 'updateContacto'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.directorio.update');
    Route::delete('emergencias/directorio/{contacto}', [EmergencyController::class, 'destroyContacto'])
        ->middleware(['permission:sst.manage', 'module:emergencias'])->name('emergencias.directorio.destroy');

    /*
    | Salud ocupacional: profesiograma por cargo y examenes medicos
    | ocupacionales (Res. 2346 de 2007, estandar 3.1.4). Alimenta COB-EMO.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('salud-ocupacional', [OccupationalHealthController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:salud-ocupacional'])->name('salud-ocupacional.index');
    Route::post('salud-ocupacional/examenes', [OccupationalHealthController::class, 'storeExamen'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.examenes.store');
    Route::put('salud-ocupacional/examenes/{examen}', [OccupationalHealthController::class, 'updateExamen'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.examenes.update');
    Route::delete('salud-ocupacional/examenes/{examen}', [OccupationalHealthController::class, 'destroyExamen'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.examenes.destroy');
    // Antes que la ruta con {perfil}, igual que las lineas nacionales de emergencias.
    Route::post('salud-ocupacional/profesiograma/desde-nomina', [OccupationalHealthController::class, 'perfilesDesdeNomina'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.perfiles.nomina');
    Route::post('salud-ocupacional/profesiograma', [OccupationalHealthController::class, 'storePerfil'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.perfiles.store');
    Route::put('salud-ocupacional/profesiograma/{perfil}', [OccupationalHealthController::class, 'updatePerfil'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.perfiles.update');
    Route::delete('salud-ocupacional/profesiograma/{perfil}', [OccupationalHealthController::class, 'destroyPerfil'])
        ->middleware(['permission:sst.manage', 'module:salud-ocupacional'])->name('salud-ocupacional.perfiles.destroy');

    /*
    | Requisitos legales (matriz) del cliente activo. Alimenta el indicador
    | CUMP-LEG. Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('requisitos-legales', [LegalRequirementController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:requisitos-legales'])->name('requisitos-legales.index');
    Route::post('requisitos-legales', [LegalRequirementController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:requisitos-legales'])->name('requisitos-legales.store');
    Route::put('requisitos-legales/{requisito}', [LegalRequirementController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:requisitos-legales'])->name('requisitos-legales.update');
    Route::delete('requisitos-legales/{requisito}', [LegalRequirementController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:requisitos-legales'])->name('requisitos-legales.destroy');

    /*
    | ACPM: acciones correctivas, preventivas y de mejora. Registro común al que
    | llegan los hallazgos de todos los módulos. Alimenta GEST-PA.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('acpm', [AcpmActionController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:acpm'])->name('acpm.index');
    Route::post('acpm', [AcpmActionController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:acpm'])->name('acpm.store');
    Route::put('acpm/{accion}', [AcpmActionController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:acpm'])->name('acpm.update');
    Route::delete('acpm/{accion}', [AcpmActionController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:acpm'])->name('acpm.destroy');

    /*
    | Reportes de actos y condiciones inseguras. Es el canal por el que cualquier
    | trabajador levanta la mano. Alimenta RED-AC.
    | Ver -> incidents.view | Gestionar -> incidents.manage
    */
    Route::get('reportes-ac', [SafetyReportController::class, 'index'])
        ->middleware(['permission:incidents.view', 'module:reportes-ac'])->name('reportes-ac.index');
    Route::post('reportes-ac', [SafetyReportController::class, 'store'])
        ->middleware(['permission:incidents.manage', 'module:reportes-ac'])->name('reportes-ac.store');
    Route::put('reportes-ac/{reporte}', [SafetyReportController::class, 'update'])
        ->middleware(['permission:incidents.manage', 'module:reportes-ac'])->name('reportes-ac.update');
    Route::delete('reportes-ac/{reporte}', [SafetyReportController::class, 'destroy'])
        ->middleware(['permission:incidents.manage', 'module:reportes-ac'])->name('reportes-ac.destroy');

    /*
    | Ausentismo laboral. Fuente de AUS-CM y de los días perdidos del índice de
    | severidad. Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('ausentismo', [AbsenceController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:ausentismo'])->name('ausentismo.index');
    Route::post('ausentismo', [AbsenceController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:ausentismo'])->name('ausentismo.store');
    Route::put('ausentismo/{ausencia}', [AbsenceController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:ausentismo'])->name('ausentismo.update');
    Route::delete('ausentismo/{ausencia}', [AbsenceController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:ausentismo'])->name('ausentismo.destroy');

    /*
    | Accidentes e incidentes de trabajo CON su investigación (Res. 1401 de 2007).
    | Fuente de los índices de frecuencia, severidad y letalidad.
    | Ver -> incidents.view | Gestionar -> incidents.manage
    */
    Route::get('accidentes', [WorkAccidentController::class, 'index'])
        ->middleware(['permission:incidents.view', 'module:accidentes'])->name('accidentes.index');
    Route::post('accidentes', [WorkAccidentController::class, 'store'])
        ->middleware(['permission:incidents.manage', 'module:accidentes'])->name('accidentes.store');
    Route::put('accidentes/{accidente}', [WorkAccidentController::class, 'update'])
        ->middleware(['permission:incidents.manage', 'module:accidentes'])->name('accidentes.update');
    Route::delete('accidentes/{accidente}', [WorkAccidentController::class, 'destroy'])
        ->middleware(['permission:incidents.manage', 'module:accidentes'])->name('accidentes.destroy');

    /*
    | Plan de Trabajo Anual del SGI (cronograma por cláusulas ISO) del cliente activo.
    | Ver -> sst.view | Diligenciar -> sst.manage
    */
    Route::get('plan-trabajo', [WorkPlanController::class, 'show'])
        ->middleware(['permission:sst.view', 'module:plan-trabajo'])->name('plan-trabajo.show');
    Route::post('plan-trabajo', [WorkPlanController::class, 'save'])
        ->middleware(['permission:sst.manage', 'module:plan-trabajo'])->name('plan-trabajo.save');
    Route::post('plan-trabajo/firmar', [WorkPlanController::class, 'firmar'])
        ->middleware(['permission:sst.manage', 'module:plan-trabajo'])->name('plan-trabajo.firmar');
    Route::post('plan-trabajo/firma/quitar', [WorkPlanController::class, 'quitarFirma'])
        ->middleware(['permission:sst.manage', 'module:plan-trabajo'])->name('plan-trabajo.quitar-firma');

    /*
    | Dashboard de Indicadores del SGI (Res. 0312 / Dec. 1072) del cliente activo.
    | Ver -> sst.view | Registrar/gestionar -> sst.manage
    */
    Route::get('indicadores', [IndicatorController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:indicadores'])->name('indicadores.index');
    Route::post('indicadores/lecturas', [IndicatorController::class, 'save'])
        ->middleware(['permission:sst.manage', 'module:indicadores'])->name('indicadores.save');
    Route::put('indicadores/meta', [IndicatorController::class, 'goal'])
        ->middleware(['permission:sst.manage', 'module:indicadores'])->name('indicadores.meta');
    Route::post('indicadores', [IndicatorController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:indicadores'])->name('indicadores.store');
    Route::delete('indicadores/{indicator}', [IndicatorController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:indicadores'])->name('indicadores.destroy');

    /*
    | Documentos de la empresa: repositorio documental por cliente — exports
    | archivados de Documentos IA + archivos subidos (firmados, evidencias).
    | Ver/descargar -> documents.view | Subir/eliminar -> documents.manage
    */
    Route::get('documentos', [DocumentoEmpresaController::class, 'index'])
        ->middleware(['permission:documents.view', 'module:documentos'])->name('documentos.index');
    Route::post('documentos', [DocumentoEmpresaController::class, 'store'])
        ->middleware(['permission:documents.manage', 'module:documentos'])->name('documentos.store');
    Route::get('documentos/{documento}/descargar', [DocumentoEmpresaController::class, 'download'])
        ->middleware(['permission:documents.view', 'module:documentos'])->name('documentos.download');
    Route::delete('documentos/{documento}', [DocumentoEmpresaController::class, 'destroy'])
        ->middleware(['permission:documents.manage', 'module:documentos'])->name('documentos.destroy');

    /*
    | Capacitaciones del SGI del cliente activo: biblioteca de temas (material
    | descargable) + capacitaciones con registro de asistencia exportable.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('capacitaciones', [TrainingController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:capacitaciones'])->name('capacitaciones.index');
    Route::get('capacitaciones/tema/{tema}/material', [TrainingController::class, 'material'])
        ->middleware(['permission:sst.view', 'module:capacitaciones'])->name('capacitaciones.material');
    Route::get('capacitaciones/{capacitacion}', [TrainingController::class, 'show'])
        ->middleware(['permission:sst.view', 'module:capacitaciones'])->name('capacitaciones.show');
    Route::post('capacitaciones', [TrainingController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:capacitaciones'])->name('capacitaciones.store');
    Route::put('capacitaciones/{capacitacion}', [TrainingController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:capacitaciones'])->name('capacitaciones.update');
    Route::delete('capacitaciones/{capacitacion}', [TrainingController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:capacitaciones'])->name('capacitaciones.destroy');
    Route::get('capacitaciones/{capacitacion}/export', [TrainingController::class, 'export'])
        ->middleware(['permission:sst.view', 'module:capacitaciones'])->name('capacitaciones.export');

    /*
    | Motor de formatos (Tier 4): inspecciones, actas y listas de chequeo del
    | cliente activo. Un solo módulo genérico para toda la cola larga de formatos.
    | Ver -> inspections.view | Diligenciar -> inspections.perform
    */
    Route::get('formatos', [FormatoController::class, 'index'])
        ->middleware(['permission:inspections.view', 'module:inspecciones'])->name('formatos.index');
    Route::get('formatos/{formato}', [FormatoController::class, 'show'])
        ->middleware(['permission:inspections.view', 'module:inspecciones'])->name('formatos.show');
    Route::post('formatos', [FormatoController::class, 'store'])
        ->middleware(['permission:inspections.perform', 'module:inspecciones'])->name('formatos.store');
    Route::put('formatos/{formato}', [FormatoController::class, 'update'])
        ->middleware(['permission:inspections.perform', 'module:inspecciones'])->name('formatos.update');
    Route::delete('formatos/{formato}', [FormatoController::class, 'destroy'])
        ->middleware(['permission:inspections.perform', 'module:inspecciones'])->name('formatos.destroy');
    Route::get('formatos/{formato}/export', [FormatoController::class, 'export'])
        ->middleware(['permission:inspections.view', 'module:inspecciones'])->name('formatos.export');

    /*
    | Generación de documentos SGI con IA (Claude) para el cliente activo.
    | Ver -> documents.view | Generar/editar -> documents.manage
    */
    Route::get('documentos-ia/plantillas', [DocumentTemplateController::class, 'index'])
        ->middleware(['permission:documents.view', 'module:documentos-ia'])->name('plantillas.index');
    Route::post('documentos-ia/plantillas', [DocumentTemplateController::class, 'store'])
        ->middleware(['permission:documents.manage', 'module:documentos-ia'])->name('plantillas.store');
    Route::post('documentos-ia/plantillas/{plantilla}', [DocumentTemplateController::class, 'update'])
        ->middleware(['permission:documents.manage', 'module:documentos-ia'])->name('plantillas.update');
    Route::delete('documentos-ia/plantillas/{plantilla}', [DocumentTemplateController::class, 'destroy'])
        ->middleware(['permission:documents.manage', 'module:documentos-ia'])->name('plantillas.destroy');

    Route::get('documentos-ia', [AiDocumentController::class, 'index'])
        ->middleware(['permission:documents.view', 'module:documentos-ia'])->name('documentos-ia.index');
    Route::post('documentos-ia/generar', [AiDocumentController::class, 'generate'])
        ->middleware(['permission:documents.manage', 'module:documentos-ia'])->name('documentos-ia.generate');
    Route::put('documentos-ia/{documento}', [AiDocumentController::class, 'update'])
        ->middleware(['permission:documents.manage', 'module:documentos-ia'])->name('documentos-ia.update');
    Route::delete('documentos-ia/{documento}', [AiDocumentController::class, 'destroy'])
        ->middleware(['permission:documents.manage', 'module:documentos-ia'])->name('documentos-ia.destroy');
    Route::get('documentos-ia/{documento}/export', [AiDocumentController::class, 'export'])
        ->middleware(['permission:documents.view', 'module:documentos-ia'])->name('documentos-ia.export');

    /*
    | Asistente conversacional (widget flotante). Chatea con Claude, que consulta
    | los datos del cliente y genera documentos con herramientas.
    | Consultar -> documents.view | crear/editar documentos -> documents.manage
    | (lo verifica AssistantToolbox: el chat funciona igual, solo sin escritura).
    */
    Route::get('asistente/historial', [AssistantController::class, 'history'])
        ->middleware(['permission:documents.view', 'module:documentos-ia'])->name('asistente.history');
    Route::post('asistente/mensaje', [AssistantController::class, 'stream'])
        ->middleware(['permission:documents.view', 'module:documentos-ia'])->name('asistente.stream');
    Route::delete('asistente', [AssistantController::class, 'clear'])
        ->middleware(['permission:documents.view', 'module:documentos-ia'])->name('asistente.clear');

    /*
    | Módulos de la plataforma (Fase 1: shells navegables protegidos por permiso).
    | El contenido de cada módulo se desarrolla en las fases F2–F5.
    */
    $modules = [
        ['reportes', 'Reportes', 'Informes PDF auditables, indicadores y exportaciones.', 'reports.view'],
        // 'auditoria' salio de aqui: ya tiene modulo real mas arriba. Ojo, este
        // bucle se ejecuta DESPUES, y con la misma URI Laravel se queda con la
        // ultima ruta registrada, asi que el shell tapaba al modulo entero.
    ];

    foreach ($modules as [$slug, $title, $desc, $permission]) {
        Route::get($slug, fn () => Inertia::render('module-placeholder', [
            'title' => $title,
            'description' => $desc,
            'permission' => $permission,
        ]))->middleware(["permission:{$permission}", "module:{$slug}"])->name("modules.{$slug}");
    }

    // Configuración: administración de la plataforma (no depende de contrato).
    Route::get('configuracion', fn () => Inertia::render('module-placeholder', [
        'title' => 'Configuración',
        'description' => 'Parámetros de la plataforma e integraciones.',
        'permission' => 'settings.manage',
    ]))->middleware('permission:settings.manage')->name('modules.configuracion');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
