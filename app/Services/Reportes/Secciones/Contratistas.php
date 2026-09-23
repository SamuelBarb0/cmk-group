<?php

namespace App\Services\Reportes\Secciones;

use App\Models\ContractorEvaluation;
use App\Models\PesvContractor;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Contratistas y proveedores: evaluaciones del periodo y situación a la fecha. */
class Contratistas extends Seccion
{
    public function clave(): string
    {
        return 'contratistas';
    }

    public function titulo(): string
    {
        return 'Contratistas y proveedores';
    }

    public function modulo(): ?string
    {
        return 'contratistas';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $evaluaciones = $periodo->filtrar(ContractorEvaluation::query(), 'fecha')->get(['id', 'uso', 'resultado']);
        $activos = PesvContractor::where('is_active', true)->with(['evaluations', 'documents'])->orderBy('nombre')->get();
        $situacion = $activos->map(fn ($c) => ['c' => $c, 's' => $c->situacion($periodo->hasta->year)]);

        $noConfiables = $situacion->filter(fn ($x) => ($x['s']['evaluacion']['resultado'] ?? null) === 'no_confiable');
        $docsVencidos = $situacion->sum(fn ($x) => $x['s']['documentos_vencidos']);
        $evalVencida = $situacion->filter(fn ($x) => $x['s']['evaluacion_vencida'])->count();
        $sinEvaluar = $situacion->filter(fn ($x) => $x['s']['evaluacion'] === null && $x['s']['seleccion'] === null)->count();

        $this->cifra('Contratistas activos', $activos->count());
        $this->cifra('Evaluaciones en el periodo', $evaluaciones->count());
        $this->cifra('Sin evaluar', $sinEvaluar);
        $this->cifra('Evaluación vencida', $evalVencida, $evalVencida ? "{$evalVencida} contratista(s) con la evaluación de desempeño vencida." : null);
        $this->cifra('No confiables', $noConfiables->count(),
            $noConfiables->isNotEmpty() ? $noConfiables->count().' contratista(s) activo(s) calificado(s) como no confiable(s).' : null);
        $this->cifra('Documentos vencidos', $docsVencidos, $docsVencidos ? "{$docsVencidos} documento(s) de contratistas vencido(s) (ARL, seguridad social, pólizas…)." : null);

        $this->tabla('Situación de los contratistas', ['Contratista', 'Última evaluación', 'Resultado', 'Documentos vencidos', 'Pendientes'],
            $situacion->map(fn ($x) => [
                self::corto($x['c']->nombre, 50),
                isset($x['s']['evaluacion']['porcentaje']) ? self::porcentaje((float) $x['s']['evaluacion']['porcentaje']) : '—',
                self::etiqueta($x['s']['evaluacion']['resultado'] ?? null),
                $x['s']['documentos_vencidos'], $x['s']['documentos_pendientes'],
            ]),
            'Sin contratistas activos.');
    }
}
