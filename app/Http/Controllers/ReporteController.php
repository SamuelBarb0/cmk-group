<?php

namespace App\Http\Controllers;

use App\Services\Reportes\ExcelExporter;
use App\Services\Reportes\Exportaciones;
use App\Services\Reportes\InformeGestion;
use App\Services\Reportes\InformePdf;
use App\Services\Reportes\InformeWord;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes de la empresa activa:
 *  - Informe de gestión del SG-SST por periodo (vista previa en pantalla,
 *    Word editable y PDF final), armado por InformeGestion.
 *  - Exportaciones a Excel de los datos de cada módulo.
 *
 * Ver -> reports.view · Descargar -> reports.generate. Además cada sección y
 * cada exportación exige el permiso de su propio módulo.
 */
class ReporteController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InformeGestion $informes,
        private readonly Exportaciones $exportaciones,
    ) {}

    public function index(Request $request): Response
    {
        if (! $this->context->has()) {
            return Inertia::render('reportes/index', ['needsClient' => true]);
        }

        $datos = $this->validarPeriodo($request);
        $periodo = Periodo::desde($datos['desde'] ?? null, $datos['hasta'] ?? null);
        $user = $request->user();
        $disponibles = $this->informes->disponibles($user);
        $claves = $datos['secciones'] ?? null;

        return Inertia::render('reportes/index', [
            'needsClient' => false,
            'periodo' => ['desde' => $periodo->desde->toDateString(), 'hasta' => $periodo->hasta->toDateString()],
            'secciones' => array_map(fn (Seccion $s) => ['clave' => $s->clave(), 'titulo' => $s->titulo()], $disponibles),
            'seleccion' => $claves ?? array_map(fn (Seccion $s) => $s->clave(), $disponibles),
            'informe' => $this->informes->generar($user, $periodo, $claves),
            'exportaciones' => array_map(fn (array $e) => [
                'clave' => $e['clave'], 'titulo' => $e['titulo'], 'descripcion' => $e['descripcion'],
                'grupo' => $e['grupo'], 'porPeriodo' => $e['fecha'] !== null,
            ], $this->exportaciones->disponibles($user)),
            'canGenerate' => $user->can('reports.generate'),
        ]);
    }

    /** Descarga el informe en Word o PDF. POST: lleva el análisis del consultor, que puede ser largo. */
    public function informe(Request $request): BinaryFileResponse
    {
        abort_unless($this->context->has(), 404);

        $datos = $this->validarPeriodo($request, [
            'formato' => ['required', Rule::in(['word', 'pdf'])],
            'observaciones' => ['nullable', 'string', 'max:20000'],
        ]);
        $periodo = Periodo::desde($datos['desde'] ?? null, $datos['hasta'] ?? null);
        $informe = $this->informes->generar($request->user(), $periodo, $datos['secciones'] ?? null);

        $ruta = $datos['formato'] === 'pdf'
            ? app(InformePdf::class)->exportar($informe, $datos['observaciones'] ?? null)
            : app(InformeWord::class)->exportar($informe, $datos['observaciones'] ?? null);

        return response()->download($ruta, basename($ruta))->deleteFileAfterSend();
    }

    /** Exportación a Excel de un módulo (del periodo, o completa con ?todo=1). */
    public function exportar(Request $request, string $clave, ExcelExporter $excel): BinaryFileResponse
    {
        abort_unless($this->context->has(), 404);
        $e = $this->exportaciones->buscar($request->user(), $clave);
        abort_if($e === null, 404);

        $datos = $this->validarPeriodo($request, ['todo' => ['nullable', 'boolean']]);
        $periodo = ($e['fecha'] && ! ($datos['todo'] ?? false)) ? Periodo::desde($datos['desde'] ?? null, $datos['hasta'] ?? null) : null;
        [$encabezados, $filas] = $this->exportaciones->filas($e, $periodo);

        $empresa = $this->context->get();
        $alcance = $periodo ? 'Periodo: '.$periodo->etiqueta() : ($e['fecha'] ? 'Todos los registros' : 'Estado al '.now()->format('d/m/Y'));
        $nombre = Str::slug($e['titulo']).'-'.Str::slug($empresa->name).($periodo ? '-'.$periodo->desde->toDateString().'-a-'.$periodo->hasta->toDateString() : '');
        $ruta = $excel->exportar($e['titulo'], "{$empresa->name} · NIT {$empresa->nit} · {$alcance}", $encabezados, $filas, $nombre);

        return response()->download($ruta, basename($ruta))->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function validarPeriodo(Request $request, array $extra = []): array
    {
        return $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'secciones' => ['nullable', 'array'],
            'secciones.*' => ['string', Rule::in(array_map(fn (string $c) => app($c)->clave(), InformeGestion::SECCIONES))],
            ...$extra,
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);
    }
}
