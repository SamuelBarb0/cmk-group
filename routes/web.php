<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\AcpmActionController;
use App\Http\Controllers\AiDocumentController;
use App\Http\Controllers\AmbientalController;
use App\Http\Controllers\AspectoAmbientalController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CalidadController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CommitteeController;
use App\Http\Controllers\ComunicacionController;
use App\Http\Controllers\ContextoController;
use App\Http\Controllers\ContratistaController;
use App\Http\Controllers\ControlDocumentalController;
use App\Http\Controllers\ControlDocumentalVersionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentoEmpresaController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EncuestaPublicaController;
use App\Http\Controllers\EquipoMedicionController;
use App\Http\Controllers\FormatoController;
use App\Http\Controllers\FormFormatController;
use App\Http\Controllers\ImportacionController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\IpercController;
use App\Http\Controllers\LegalRequirementController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\OccupationalHealthController;
use App\Http\Controllers\OrganizacionController;
use App\Http\Controllers\PesvAutogestionController;
use App\Http\Controllers\PesvColaboradorController;
use App\Http\Controllers\PesvConductorController;
use App\Http\Controllers\PesvContractorController;
use App\Http\Controllers\PesvController;
use App\Http\Controllers\PesvDocumentosController;
use App\Http\Controllers\PesvEncuestaController;
use App\Http\Controllers\PesvEstadisticaController;
use App\Http\Controllers\PesvInfraccionController;
use App\Http\Controllers\PesvRiesgoVialController;
use App\Http\Controllers\PesvRouteController;
use App\Http\Controllers\PesvRutaPlanController;
use App\Http\Controllers\PesvSedeController;
use App\Http\Controllers\PesvSiniestroController;
use App\Http\Controllers\PesvVehicleController;
use App\Http\Controllers\PesvVehiculoFichaController;
use App\Http\Controllers\PesvVerificacionController;
use App\Http\Controllers\PesvViaInternaController;
use App\Http\Controllers\PpeController;
use App\Http\Controllers\ProcesoController;
use App\Http\Controllers\ProgramaGestionController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RevisionDireccionController;
use App\Http\Controllers\RiesgoOportunidadController;
use App\Http\Controllers\SafetyReportController;
use App\Http\Controllers\SstDiagnosticController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TrainingTopicController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\WorkAccidentController;
use App\Http\Controllers\WorkPlanController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Encuesta de movilidad del PESV respondida por el trabajador, sin cuenta: el
// token del enlace identifica a la empresa (ver EncuestaPublicaController).
Route::get('encuesta-movilidad/{token}', [EncuestaPublicaController::class, 'show'])
    ->middleware('throttle:60,1')->name('encuesta.movilidad');
Route::post('encuesta-movilidad/{token}', [EncuestaPublicaController::class, 'store'])
    ->middleware('throttle:10,1')->name('encuesta.movilidad.store');

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
        Route::get('pesv/evidencias/{evidencia}', [PesvVerificacionController::class, 'descargar'])->name('pesv.evidencias.descargar');
        // Paso 11: fichas de conductor y vehículo, semáforo de documentos, comparendos.
        Route::get('pesv/conductores/{empleado}', [PesvConductorController::class, 'show'])->name('pesv.conductores.show');
        Route::get('pesv/vehiculos/{vehiculo}', [PesvVehiculoFichaController::class, 'show'])->name('pesv.vehiculos.show');
        Route::get('pesv/documentos', [PesvDocumentosController::class, 'index'])->name('pesv.documentos.index');
        // Pasos 5 y 6: encuesta de movilidad y matriz de riesgos viales.
        Route::get('pesv/encuesta', [PesvEncuestaController::class, 'index'])->name('pesv.encuesta.index');
        Route::get('pesv/riesgos-viales', [PesvRiesgoVialController::class, 'index'])->name('pesv.riesgos.index');
        // Pasos 13, 14, 15 y 21.
        Route::get('pesv/estadistica', [PesvEstadisticaController::class, 'index'])->name('pesv.estadistica.index');
        Route::get('pesv/vias-internas', [PesvViaInternaController::class, 'index'])->name('pesv.vias.index');
        Route::get('pesv/rutas/{ruta}/plan', [PesvRutaPlanController::class, 'show'])->name('pesv.rutas.plan');
        Route::get('pesv/infracciones', [PesvInfraccionController::class, 'index'])->name('pesv.infracciones.index');
        // Paso 20: reporte de autogestión anual.
        Route::get('pesv/autogestion', [PesvAutogestionController::class, 'index'])->name('pesv.autogestion.index');
        Route::get('pesv/autogestion/descargar', [PesvAutogestionController::class, 'descargar'])->name('pesv.autogestion.descargar');
    });

    Route::middleware(['permission:pesv.manage', 'module:pesv'])->group(function () {
        Route::put('pesv', [PesvController::class, 'savePlan'])->name('pesv.plan.save');
        Route::post('pesv/paso/{numero}', [PesvController::class, 'saveStep'])
            ->whereNumber('numero')->name('pesv.paso.save');
        Route::post('pesv/comite', [PesvController::class, 'storeMiembro'])->name('pesv.comite.store');
        Route::delete('pesv/comite/{miembro}', [PesvController::class, 'destroyMiembro'])->name('pesv.comite.destroy');
        Route::put('pesv/comite/{miembro}', [PesvController::class, 'updateMiembro'])->name('pesv.comite.update');
        // Lista de verificación (Tabla 16): respuesta y evidencias por pregunta.
        Route::post('pesv/criterio/{criterio}', [PesvVerificacionController::class, 'responder'])->name('pesv.criterio.responder');
        Route::post('pesv/criterio/{criterio}/evidencias', [PesvVerificacionController::class, 'subir'])->name('pesv.evidencias.subir');
        Route::delete('pesv/evidencias/{evidencia}', [PesvVerificacionController::class, 'borrar'])->name('pesv.evidencias.borrar');
        Route::put('pesv/conductores/{empleado}/requisitos', [PesvConductorController::class, 'requisitos'])->name('pesv.conductores.requisitos');
        Route::post('pesv/conductores/{empleado}/pruebas', [PesvConductorController::class, 'guardarPrueba'])->name('pesv.conductores.pruebas');
        Route::delete('pesv/pruebas/{prueba}', [PesvConductorController::class, 'borrarPrueba'])->name('pesv.pruebas.destroy');
        Route::put('pesv/vehiculos/{vehiculo}/requisitos', [PesvVehiculoFichaController::class, 'requisitos'])->name('pesv.vehiculos.requisitos');
        Route::post('pesv/infracciones', [PesvInfraccionController::class, 'store'])->name('pesv.infracciones.store');
        Route::put('pesv/infracciones/{infraccion}', [PesvInfraccionController::class, 'update'])->name('pesv.infracciones.update');
        Route::delete('pesv/infracciones/{infraccion}', [PesvInfraccionController::class, 'destroy'])->name('pesv.infracciones.destroy');
        Route::post('pesv/encuesta/enlace', [PesvEncuestaController::class, 'enlace'])->name('pesv.encuesta.enlace');
        Route::patch('pesv/encuesta/enlace', [PesvEncuestaController::class, 'alternarEnlace'])->name('pesv.encuesta.alternar');
        Route::post('pesv/encuesta/respuestas', [PesvEncuestaController::class, 'responder'])->name('pesv.encuesta.responder');
        Route::delete('pesv/encuesta/respuestas/{respuesta}', [PesvEncuestaController::class, 'borrar'])->name('pesv.encuesta.borrar');
        Route::put('pesv/encuesta/analisis', [PesvEncuestaController::class, 'analisis'])->name('pesv.encuesta.analisis');
        Route::post('pesv/riesgos-viales', [PesvRiesgoVialController::class, 'store'])->name('pesv.riesgos.store');
        Route::put('pesv/riesgos-viales/{riesgo}', [PesvRiesgoVialController::class, 'update'])->name('pesv.riesgos.update');
        Route::delete('pesv/riesgos-viales/{riesgo}', [PesvRiesgoVialController::class, 'destroy'])->name('pesv.riesgos.destroy');
        Route::put('pesv/estadistica/km', [PesvEstadisticaController::class, 'guardarKm'])->name('pesv.estadistica.km');
        Route::put('pesv/autogestion', [PesvAutogestionController::class, 'update'])->name('pesv.autogestion.update');
        Route::post('pesv/vias-internas', [PesvViaInternaController::class, 'store'])->name('pesv.vias.store');
        Route::put('pesv/vias-internas/{via}', [PesvViaInternaController::class, 'update'])->name('pesv.vias.update');
        Route::delete('pesv/vias-internas/{via}', [PesvViaInternaController::class, 'destroy'])->name('pesv.vias.destroy');
        Route::put('pesv/rutas/{ruta}/plan', [PesvRutaPlanController::class, 'update'])->name('pesv.rutas.plan.update');
        Route::post('pesv/siniestros/{siniestro}/acpm', [PesvSiniestroController::class, 'crearAccion'])->name('pesv.siniestros.acpm');

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
    Route::get('auditoria/{auditoria}', [AuditController::class, 'show'])
        ->middleware(['permission:audit.view', 'module:auditoria'])->name('auditoria.show');
    Route::put('auditoria/{auditoria}/verificacion', [AuditController::class, 'verificacion'])
        ->middleware(['permission:sst.manage', 'module:auditoria'])->name('auditoria.verificacion');
    Route::post('auditoria/{auditoria}/hallazgos', [AuditController::class, 'hallazgo'])
        ->middleware(['permission:sst.manage', 'module:auditoria'])->name('auditoria.hallazgo');
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
    | Programas de gestion: PVE, alcohol y SPA, fatiga, velocidad, distraccion,
    | actores viales y ambiental (estandar 4.2.1 de la Res. 0312). Las
    | actividades e indicadores cuelgan del programa y no tienen rutas propias.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('programas', [ProgramaGestionController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:programas'])->name('programas.index');
    Route::post('programas', [ProgramaGestionController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:programas'])->name('programas.store');
    Route::get('programas/{programa}', [ProgramaGestionController::class, 'show'])
        ->middleware(['permission:sst.view', 'module:programas'])->name('programas.show');
    Route::put('programas/{programa}', [ProgramaGestionController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:programas'])->name('programas.update');
    Route::delete('programas/{programa}', [ProgramaGestionController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:programas'])->name('programas.destroy');
    Route::post('programas/{programa}/renovar', [ProgramaGestionController::class, 'renovar'])
        ->middleware(['permission:sst.manage', 'module:programas'])->name('programas.renovar');

    /*
    | Mantenimiento de activos: inventario, plan por activo (cada N dias, km u
    | horas) y registro de lo realizado (PASO 17 del PESV, estandar 4.2.5). Los
    | items del plan cuelgan del activo y no tienen rutas propias.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('mantenimiento', [MaintenanceController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:mantenimiento'])->name('mantenimiento.index');
    // Antes que la ruta con {activo}: si no, «importar-vehiculos» se tomaria por un id.
    Route::post('mantenimiento/activos/importar-vehiculos', [MaintenanceController::class, 'importarVehiculos'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.activos.importar');
    Route::post('mantenimiento/activos', [MaintenanceController::class, 'storeAsset'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.activos.store');
    Route::put('mantenimiento/activos/{activo}', [MaintenanceController::class, 'updateAsset'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.activos.update');
    Route::delete('mantenimiento/activos/{activo}', [MaintenanceController::class, 'destroyAsset'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.activos.destroy');
    Route::post('mantenimiento/registros', [MaintenanceController::class, 'storeRecord'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.registros.store');
    Route::put('mantenimiento/registros/{registro}', [MaintenanceController::class, 'updateRecord'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.registros.update');
    Route::delete('mantenimiento/registros/{registro}', [MaintenanceController::class, 'destroyRecord'])
        ->middleware(['permission:sst.manage', 'module:mantenimiento'])->name('mantenimiento.registros.destroy');

    /*
    | Contratistas y proveedores (estandar 2.10.1 de la Res. 0312 e ISO 8.4):
    | hoja de vida, documentos, seleccion, requisitos SST y evaluacion. Usa el
    | mismo registro que el PESV (pesv_contractors).
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('contratistas', [ContratistaController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:contratistas'])->name('contratistas.index');
    Route::post('contratistas', [ContratistaController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:contratistas'])->name('contratistas.store');
    Route::put('contratistas/evaluaciones/{evaluacion}', [ContratistaController::class, 'updateEvaluacion'])
        ->middleware(['permission:sst.manage', 'module:contratistas'])->name('contratistas.evaluaciones.update');
    Route::delete('contratistas/evaluaciones/{evaluacion}', [ContratistaController::class, 'destroyEvaluacion'])
        ->middleware(['permission:sst.manage', 'module:contratistas'])->name('contratistas.evaluaciones.destroy');
    Route::get('contratistas/{contratista}', [ContratistaController::class, 'show'])
        ->middleware(['permission:sst.view', 'module:contratistas'])->name('contratistas.show');
    Route::put('contratistas/{contratista}', [ContratistaController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:contratistas'])->name('contratistas.update');
    Route::delete('contratistas/{contratista}', [ContratistaController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:contratistas'])->name('contratistas.destroy');
    Route::post('contratistas/{contratista}/evaluaciones', [ContratistaController::class, 'storeEvaluacion'])
        ->middleware(['permission:sst.manage', 'module:contratistas'])->name('contratistas.evaluaciones.store');

    /*
    | Importacion asistida por IA: un Excel del cliente a un modulo. La IA
    | propone el mapeo de columnas; los datos los transforma y valida el
    | Aplicador con las reglas de cada modulo. Todo es carga masiva: sst.manage.
    */
    Route::get('importar', [ImportacionController::class, 'index'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.index');
    Route::post('importar', [ImportacionController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.store');
    Route::get('importar/{importacion}', [ImportacionController::class, 'show'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.show');
    Route::post('importar/{importacion}/mapear', [ImportacionController::class, 'mapear'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.mapear');
    Route::put('importar/{importacion}/mapeo', [ImportacionController::class, 'actualizar'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.mapeo');
    Route::post('importar/{importacion}/aplicar', [ImportacionController::class, 'aplicar'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.aplicar');
    Route::post('importar/{importacion}/deshacer', [ImportacionController::class, 'deshacer'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.deshacer');
    Route::delete('importar/{importacion}', [ImportacionController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:importar'])->name('importar.destroy');

    /*
    | Gestion del cambio (estandar 2.11.1, ISO 45001 8.1.3): solicitud, doble
    | aprobacion (Gerencia y SG-SST), plan de accion y cierre.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::get('gestion-cambio', [ChangeRequestController::class, 'index'])
        ->middleware(['permission:sst.view', 'module:gestion-cambio'])->name('gestion-cambio.index');
    Route::post('gestion-cambio', [ChangeRequestController::class, 'store'])
        ->middleware(['permission:sst.manage', 'module:gestion-cambio'])->name('gestion-cambio.store');
    Route::put('gestion-cambio/{cambio}', [ChangeRequestController::class, 'update'])
        ->middleware(['permission:sst.manage', 'module:gestion-cambio'])->name('gestion-cambio.update');
    Route::delete('gestion-cambio/{cambio}', [ChangeRequestController::class, 'destroy'])
        ->middleware(['permission:sst.manage', 'module:gestion-cambio'])->name('gestion-cambio.destroy');

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
    | M01 — Control documental: listado maestro, ciclo de vida de versiones,
    | mapa de procesos y cobertura de requisitos por norma.
    | Ver -> documents.view | Gestionar -> documents.manage
    */
    Route::middleware('module:control-documental')->prefix('control-documental')->name('control-documental.')->group(function () {
        Route::get('/', [ControlDocumentalController::class, 'index'])
            ->middleware('permission:documents.view')->name('index');
        Route::get('requisitos', [ControlDocumentalController::class, 'cobertura'])
            ->middleware('permission:documents.view')->name('requisitos');

        Route::middleware('permission:documents.manage')->group(function () {
            Route::post('/', [ControlDocumentalController::class, 'store'])->name('store');
            Route::post('catalogo', [ControlDocumentalController::class, 'inicializar'])->name('catalogo');
            Route::post('desde-ia/{generado}', [ControlDocumentalController::class, 'desdeIa'])->whereNumber('generado')->name('desde-ia');
            Route::post('procesos', [ProcesoController::class, 'store'])->name('procesos.store');
            Route::put('procesos/{proceso}', [ProcesoController::class, 'update'])->name('procesos.update');
            Route::delete('procesos/{proceso}', [ProcesoController::class, 'destroy'])->name('procesos.destroy');
        });

        Route::whereNumber(['documento', 'version'])->group(function () {
            Route::get('{documento}', [ControlDocumentalController::class, 'show'])
                ->middleware('permission:documents.view')->name('show');
            Route::post('{documento}/leido', [ControlDocumentalController::class, 'leido'])
                ->middleware('permission:documents.view')->name('leido');
            Route::get('{documento}/versiones/{version}/archivo', [ControlDocumentalVersionController::class, 'archivo'])
                ->middleware('permission:documents.view')->scopeBindings()->name('versiones.archivo');

            Route::middleware('permission:documents.manage')->group(function () {
                Route::put('{documento}', [ControlDocumentalController::class, 'update'])->name('update');
                Route::delete('{documento}', [ControlDocumentalController::class, 'destroy'])->name('destroy');
                Route::post('{documento}/retirar', [ControlDocumentalController::class, 'retirar'])->name('retirar');
                Route::put('{documento}/requisitos', [ControlDocumentalController::class, 'requisitos'])->name('vincular');
                Route::post('{documento}/versiones', [ControlDocumentalVersionController::class, 'store'])->name('versiones.store');
                Route::scopeBindings()->group(function () {
                    Route::post('{documento}/versiones/{version}', [ControlDocumentalVersionController::class, 'update'])->name('versiones.update');
                    Route::delete('{documento}/versiones/{version}', [ControlDocumentalVersionController::class, 'destroy'])->name('versiones.destroy');
                    Route::post('{documento}/versiones/{version}/transicion', [ControlDocumentalVersionController::class, 'transicion'])->name('versiones.transicion');
                });
            });
        });
    });

    /*
    | M02 — Contexto de la organización: alcance y cambio climático, DOFA /
    | PESTEL, partes interesadas y caracterización de procesos; lo trabajado
    | se manda al control documental como borrador.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:contexto')->prefix('contexto')->name('contexto.')->group(function () {
        Route::get('/', [ContextoController::class, 'index'])->middleware('permission:sst.view')->name('index');
        Route::middleware('permission:sst.manage')->group(function () {
            Route::put('perfil', [ContextoController::class, 'updatePerfil'])->name('perfil');
            Route::post('revisado', [ContextoController::class, 'revisado'])->name('revisado');
            Route::post('cuestiones', [ContextoController::class, 'storeCuestion'])->name('cuestiones.store');
            Route::put('cuestiones/{cuestion}', [ContextoController::class, 'updateCuestion'])->name('cuestiones.update');
            Route::delete('cuestiones/{cuestion}', [ContextoController::class, 'destroyCuestion'])->name('cuestiones.destroy');
            Route::post('partes/base', [ContextoController::class, 'baseParts'])->name('partes.base');
            Route::post('partes', [ContextoController::class, 'storeParte'])->name('partes.store');
            Route::put('partes/{parte}', [ContextoController::class, 'updateParte'])->name('partes.update');
            Route::delete('partes/{parte}', [ContextoController::class, 'destroyParte'])->name('partes.destroy');
            Route::put('procesos/{proceso}', [ContextoController::class, 'updateProceso'])->name('procesos.update');
            Route::post('enviar', [ContextoController::class, 'enviar'])->name('enviar');
        });
    });

    /*
    | M04 — Riesgos y oportunidades de los procesos (se alimenta de la DOFA
    | del contexto) y matriz de aspectos e impactos ambientales. Lo que sale
    | alto o significativo se trata con acciones en ACPM.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:riesgos-oportunidades')->prefix('riesgos-oportunidades')->name('riesgos-oportunidades.')->group(function () {
        Route::get('/', [RiesgoOportunidadController::class, 'index'])->middleware('permission:sst.view')->name('index');
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('/', [RiesgoOportunidadController::class, 'store'])->name('store');
            Route::post('desde-dofa', [RiesgoOportunidadController::class, 'desdeDofa'])->name('desde-dofa');
            Route::post('enviar', [RiesgoOportunidadController::class, 'enviar'])->name('enviar');
            Route::put('{fila}', [RiesgoOportunidadController::class, 'update'])->name('update');
            Route::delete('{fila}', [RiesgoOportunidadController::class, 'destroy'])->name('destroy');
            Route::post('{fila}/accion', [RiesgoOportunidadController::class, 'crearAccion'])->name('accion');
        });
    });

    Route::middleware('module:aspectos-ambientales')->prefix('aspectos-ambientales')->name('aspectos-ambientales.')->group(function () {
        Route::get('/', [AspectoAmbientalController::class, 'index'])->middleware('permission:sst.view')->name('index');
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('/', [AspectoAmbientalController::class, 'store'])->name('store');
            Route::post('base', [AspectoAmbientalController::class, 'base'])->name('base');
            Route::post('enviar', [AspectoAmbientalController::class, 'enviar'])->name('enviar');
            Route::put('{aspecto}', [AspectoAmbientalController::class, 'update'])->name('update');
            Route::delete('{aspecto}', [AspectoAmbientalController::class, 'destroy'])->name('destroy');
            Route::post('{aspecto}/accion', [AspectoAmbientalController::class, 'crearAccion'])->name('accion');
        });
    });

    /*
    | M07 — Perfiles de cargo y matriz de competencias (ISO 5.3 y 7.2,
    | Dec. 1072 2.2.4.6.8 y 2.2.4.6.11). Las brechas de formación se programan
    | como capacitaciones.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:cargos')->prefix('cargos')->name('cargos.')->group(function () {
        Route::get('/', [CargoController::class, 'index'])->middleware('permission:sst.view')->name('index');
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('/', [CargoController::class, 'store'])->name('store');
            Route::post('desde-nomina', [CargoController::class, 'desdeNomina'])->name('desde-nomina');
            Route::post('enviar', [CargoController::class, 'enviar'])->name('enviar');
            Route::put('{cargo}', [CargoController::class, 'update'])->name('update');
            Route::delete('{cargo}', [CargoController::class, 'destroy'])->name('destroy');
            Route::post('{cargo}/evaluar', [CargoController::class, 'evaluar'])->name('evaluar');
            Route::post('{cargo}/requisitos', [CargoController::class, 'storeRequisito'])->name('requisitos.store');
            Route::put('{cargo}/requisitos/{requisito}', [CargoController::class, 'updateRequisito'])->name('requisitos.update');
            Route::delete('{cargo}/requisitos/{requisito}', [CargoController::class, 'destroyRequisito'])->name('requisitos.destroy');
            Route::post('{cargo}/requisitos/{requisito}/programar', [CargoController::class, 'programar'])->name('requisitos.programar');
        });
    });

    /*
    | M09 — Equipos de seguimiento y medición (ISO 9001 7.1.5, ISO 45001/14001
    | 9.1.1): hoja de vida, calibraciones con certificado y equipos no conformes.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:equipos-medicion')->prefix('equipos-medicion')->name('equipos-medicion.')->group(function () {
        Route::middleware('permission:sst.view')->group(function () {
            Route::get('/', [EquipoMedicionController::class, 'index'])->name('index');
            Route::get('{equipo}/calibraciones/{calibracion}/certificado', [EquipoMedicionController::class, 'certificado'])->name('certificado');
        });
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('/', [EquipoMedicionController::class, 'store'])->name('store');
            Route::post('enviar', [EquipoMedicionController::class, 'enviar'])->name('enviar');
            Route::put('{equipo}', [EquipoMedicionController::class, 'update'])->name('update');
            Route::delete('{equipo}', [EquipoMedicionController::class, 'destroy'])->name('destroy');
            Route::post('{equipo}/calibraciones', [EquipoMedicionController::class, 'storeCalibracion'])->name('calibraciones.store');
            Route::delete('{equipo}/calibraciones/{calibracion}', [EquipoMedicionController::class, 'destroyCalibracion'])->name('calibraciones.destroy');
            Route::post('{equipo}/calibraciones/{calibracion}/accion', [EquipoMedicionController::class, 'crearAccion'])->name('calibraciones.accion');
        });
    });

    /*
    | M17 — Calidad (ISO 9001): PQRS (8.2.1), salidas no conformes (8.7) y
    | satisfacción del cliente (9.1.2).
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:calidad')->prefix('calidad')->name('calidad.')->group(function () {
        Route::get('/', [CalidadController::class, 'index'])->middleware('permission:sst.view')->name('index');
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('enviar', [CalidadController::class, 'enviar'])->name('enviar');
            Route::post('pqrs', [CalidadController::class, 'storePqrs'])->name('pqrs.store');
            Route::put('pqrs/{pqrs}', [CalidadController::class, 'updatePqrs'])->name('pqrs.update');
            Route::delete('pqrs/{pqrs}', [CalidadController::class, 'destroyPqrs'])->name('pqrs.destroy');
            Route::post('pqrs/{pqrs}/accion', [CalidadController::class, 'accionPqrs'])->name('pqrs.accion');
            Route::post('salidas', [CalidadController::class, 'storeSalida'])->name('salidas.store');
            Route::put('salidas/{salida}', [CalidadController::class, 'updateSalida'])->name('salidas.update');
            Route::delete('salidas/{salida}', [CalidadController::class, 'destroySalida'])->name('salidas.destroy');
            Route::post('salidas/{salida}/accion', [CalidadController::class, 'accionSalida'])->name('salidas.accion');
            Route::post('encuestas', [CalidadController::class, 'storeEncuesta'])->name('encuestas.store');
            Route::put('encuestas/{encuesta}', [CalidadController::class, 'updateEncuesta'])->name('encuestas.update');
            Route::delete('encuestas/{encuesta}', [CalidadController::class, 'destroyEncuesta'])->name('encuestas.destroy');
        });
    });

    /*
    | M18 — Gestión ambiental (ISO 14001): residuos y RESPEL, consumos de agua
    | y energía, e inventario de productos químicos.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:ambiental')->prefix('ambiental')->name('ambiental.')->group(function () {
        Route::middleware('permission:sst.view')->group(function () {
            Route::get('/', [AmbientalController::class, 'index'])->name('index');
            Route::get('residuos/{residuo}/certificado', [AmbientalController::class, 'certificado'])->name('residuos.certificado');
        });
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('enviar', [AmbientalController::class, 'enviar'])->name('enviar');
            Route::post('residuos', [AmbientalController::class, 'storeResiduo'])->name('residuos.store');
            Route::post('residuos/{residuo}', [AmbientalController::class, 'updateResiduo'])->name('residuos.update');
            Route::delete('residuos/{residuo}', [AmbientalController::class, 'destroyResiduo'])->name('residuos.destroy');
            Route::post('lecturas', [AmbientalController::class, 'storeLectura'])->name('lecturas.store');
            Route::delete('lecturas/{lectura}', [AmbientalController::class, 'destroyLectura'])->name('lecturas.destroy');
            Route::post('quimicos', [AmbientalController::class, 'storeQuimico'])->name('quimicos.store');
            Route::put('quimicos/{quimico}', [AmbientalController::class, 'updateQuimico'])->name('quimicos.update');
            Route::delete('quimicos/{quimico}', [AmbientalController::class, 'destroyQuimico'])->name('quimicos.destroy');
        });
    });

    /*
    | M19 — Comunicaciones: matriz (qué, cuándo, a quién, cómo, quién) y
    | registro de comunicaciones enviadas y recibidas.
    | Ver -> sst.view | Gestionar -> sst.manage
    */
    Route::middleware('module:comunicaciones')->prefix('comunicaciones')->name('comunicaciones.')->group(function () {
        Route::get('/', [ComunicacionController::class, 'index'])->middleware('permission:sst.view')->name('index');
        Route::middleware('permission:sst.manage')->group(function () {
            Route::post('matriz/base', [ComunicacionController::class, 'base'])->name('base');
            Route::post('matriz', [ComunicacionController::class, 'storeItem'])->name('matriz.store');
            Route::put('matriz/{item}', [ComunicacionController::class, 'updateItem'])->name('matriz.update');
            Route::delete('matriz/{item}', [ComunicacionController::class, 'destroyItem'])->name('matriz.destroy');
            Route::post('registro', [ComunicacionController::class, 'storeLog'])->name('registro.store');
            Route::put('registro/{registro}', [ComunicacionController::class, 'updateLog'])->name('registro.update');
            Route::delete('registro/{registro}', [ComunicacionController::class, 'destroyLog'])->name('registro.destroy');
        });
    });

    /*
    | M16 — Revisión por la dirección: entradas recopiladas de los módulos,
    | análisis, conclusiones sobre el sistema y decisiones con seguimiento.
    | Ver -> reports.view | Gestionar -> reports.generate
    */
    Route::middleware('module:revision-direccion')->prefix('revision-direccion')->name('revision-direccion.')
        ->whereNumber(['revision', 'decision'])->group(function () {
            Route::get('/', [RevisionDireccionController::class, 'index'])->middleware('permission:reports.view')->name('index');
            Route::get('{revision}', [RevisionDireccionController::class, 'show'])->middleware('permission:reports.view')->name('show');
            Route::get('{revision}/word', [RevisionDireccionController::class, 'export'])->middleware('permission:reports.view')->name('export');

            Route::middleware('permission:reports.generate')->group(function () {
                Route::post('/', [RevisionDireccionController::class, 'store'])->name('store');
                Route::put('{revision}', [RevisionDireccionController::class, 'update'])->name('update');
                Route::delete('{revision}', [RevisionDireccionController::class, 'destroy'])->name('destroy');
                Route::post('{revision}/recopilar', [RevisionDireccionController::class, 'recopilar'])->name('recopilar');
                Route::post('{revision}/cerrar', [RevisionDireccionController::class, 'cerrar'])->name('cerrar');
                Route::scopeBindings()->group(function () {
                    Route::post('{revision}/decisiones', [RevisionDireccionController::class, 'storeDecision'])->name('decisiones.store');
                    Route::put('{revision}/decisiones/{decision}', [RevisionDireccionController::class, 'updateDecision'])->name('decisiones.update');
                    Route::delete('{revision}/decisiones/{decision}', [RevisionDireccionController::class, 'destroyDecision'])->name('decisiones.destroy');
                    Route::post('{revision}/decisiones/{decision}/acpm', [RevisionDireccionController::class, 'acpm'])->name('decisiones.acpm');
                });
            });
        });

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
    // Biblioteca global de temas y su material (solo el administrador de CMK;
    // el rol se valida en el controlador). ANTES de capacitaciones/{capacitacion}.
    Route::prefix('capacitaciones/temas')->name('capacitaciones.temas.')
        ->middleware('permission:sst.view')
        ->controller(TrainingTopicController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('{tema}', 'update')->name('update');
            Route::patch('{tema}/activo', 'toggle')->name('toggle');
        });
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
    // Catálogo global de formatos (solo el administrador de CMK; el rol se
    // valida en el controlador). ANTES de formatos/{formato}: si no, «catalogo»
    // se leería como el id de un registro.
    Route::prefix('formatos/catalogo')->name('formatos.catalogo.')
        ->middleware('permission:inspections.view')
        ->controller(FormFormatController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('nuevo', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{formFormat}/editar', 'edit')->name('edit');
            Route::put('{formFormat}', 'update')->name('update');
            Route::patch('{formFormat}/activo', 'toggle')->name('toggle');
            Route::delete('{formFormat}', 'destroy')->name('destroy');
        });
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
    Route::post('formatos/{formato}/anular', [FormatoController::class, 'anular'])
        ->middleware(['permission:inspections.perform', 'module:inspecciones'])->name('formatos.anular');
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
    | Reportes de la empresa activa: informe de gestión del SG-SST por periodo
    | (Word y PDF) y exportaciones a Excel de cada módulo.
    | Ver -> reports.view | Descargar -> reports.generate (y cada sección o
    | exportación pide además el permiso de su propio módulo).
    */
    Route::get('reportes', [ReporteController::class, 'index'])
        ->middleware(['permission:reports.view', 'module:reportes'])->name('reportes.index');
    Route::post('reportes/informe', [ReporteController::class, 'informe'])
        ->middleware(['permission:reports.generate', 'module:reportes'])->name('reportes.informe');
    Route::get('reportes/exportar/{clave}', [ReporteController::class, 'exportar'])
        ->middleware(['permission:reports.generate', 'module:reportes'])->name('reportes.exportar');

    /*
    | Módulos de la plataforma (Fase 1: shells navegables protegidos por permiso).
    | El contenido de cada módulo se desarrolla en las fases F2–F5.
    */
    $modules = [
        // 'auditoria' y 'reportes' salieron de aqui: ya tienen modulo real mas
        // arriba. Ojo, este bucle se ejecuta DESPUES, y con la misma URI Laravel
        // se queda con la ultima ruta registrada: el shell tapaba al modulo.
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
