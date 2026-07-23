<?php

use App\Http\Controllers\AiDocumentController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentoEmpresaController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FormatoController;
use App\Http\Controllers\IpercController;
use App\Http\Controllers\OrganizacionController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\SstDiagnosticController;
use App\Http\Controllers\UsuarioController;
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
    | Módulos de la plataforma (Fase 1: shells navegables protegidos por permiso).
    | El contenido de cada módulo se desarrolla en las fases F2–F5.
    */
    $modules = [
        ['reportes', 'Reportes', 'Informes PDF auditables, indicadores y exportaciones.', 'reports.view'],
        ['auditoria', 'Auditoría', 'Consulta y evidencia de información auditable del cliente.', 'audit.view'],
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
