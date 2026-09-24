<?php

namespace App\Services;

use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\Norm;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/**
 * Informe y acta de la revisión por la dirección en .docx, con membrete de
 * CMK: datos de la reunión, cada entrada con sus cifras del periodo y el
 * análisis de la dirección, las conclusiones sobre el sistema y las
 * decisiones. Sale de lo congelado en la revisión, no de los módulos de hoy.
 */
class RevisionDireccionExporter
{
    private const NAVY = '16243F';

    private const VALORACION = ['si' => 'Sí', 'parcial' => 'Parcialmente', 'no' => 'No'];

    private const TIPO_DECISION = ['mejora' => 'Mejora', 'cambio' => 'Cambio al sistema', 'recursos' => 'Recursos', 'otro' => 'Otro'];

    private const ESTADO_DECISION = ['pendiente' => 'Pendiente', 'en_proceso' => 'En proceso', 'cumplida' => 'Cumplida', 'cancelada' => 'Cancelada'];

    public function export(ManagementReview $revision): string
    {
        $company = config('cmk.company') ?? [];
        $datos = $revision->datos ?? [];

        // Sin esto, un «&» o un «<» en el análisis deja el .docx corrupto. Es
        // un ajuste global de PhpWord: se restaura al terminar para no
        // cambiarle el comportamiento a los otros exportadores.
        $escapabaAntes = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            return $this->generar($revision, $company, $datos);
        } finally {
            Settings::setOutputEscapingEnabled($escapabaAntes);
        }
    }

    private function generar(ManagementReview $revision, array $company, array $datos): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $sec = $word->addSection(['marginTop' => 1200, 'marginBottom' => 1000]);

        $sec->addHeader()->addText(
            strtoupper($company['legal_name'] ?? 'CMK GROUP S.A.S.').'  ·  NIT '.($company['nit'] ?? ''),
            ['bold' => true, 'size' => 9, 'color' => self::NAVY],
        );
        $sec->addFooter()->addPreserveText('Página {PAGE} de {NUMPAGES}', ['size' => 8, 'color' => '888888'], ['alignment' => 'center']);

        $sec->addText('Revisión por la dirección '.$revision->codigo, ['bold' => true, 'size' => 16, 'color' => self::NAVY]);
        if (! $revision->estaCerrada()) {
            $sec->addText('BORRADOR — la revisión no se ha cerrado.', ['bold' => true, 'color' => 'C0392B']);
        }

        $this->tabla($sec, ['Dato', 'Detalle'], [
            ['Empresa', $revision->tenant->name ?? '—'],
            ['Periodo revisado', $revision->periodo_desde->format('d/m/Y').' al '.$revision->periodo_hasta->format('d/m/Y')],
            ['Fecha de la reunión', $revision->fecha_reunion?->format('d/m/Y') ?? '—'],
            ['Normas', collect($revision->sistemas)->map(fn ($s) => Norm::NOMBRES[$s] ?? $s)->implode(', ')],
            ['Participantes', $revision->participantes ?: '—'],
        ]);

        $this->titulo($sec, 'Entradas de la revisión');
        $n = 0;
        foreach ($revision->entradasAplicables() as $clave => $entrada) {
            $sec->addText((++$n).'. '.$entrada['titulo'], ['bold' => true, 'size' => 11, 'color' => self::NAVY], ['spaceBefore' => 200]);
            $sec->addText($entrada['referencias'], ['size' => 8, 'color' => '888888']);

            if ($clave === 'acciones_previas') {
                $previas = $datos['acciones_previas'] ?? [];
                $previas
                    ? $this->tabla($sec, ['Revisión', 'Decisión', 'Responsable', 'Estado'], array_map(fn ($d) => [
                        $d['revision'] ?? '—', $d['descripcion'], $d['responsable'] ?? '—', self::ESTADO_DECISION[$d['estado']] ?? $d['estado'],
                    ], $previas))
                    : $sec->addText('Sin decisiones de revisiones anteriores.', ['italic' => true, 'color' => '666666']);
            }

            foreach ($entrada['secciones'] as $clave_seccion) {
                $seccion = $datos['secciones'][$clave_seccion] ?? null;
                if (! $seccion || ! $seccion['cifras']) {
                    continue;
                }
                $sec->addText($seccion['titulo'], ['bold' => true, 'size' => 9], ['spaceBefore' => 80]);
                $this->tabla($sec, ['Indicador', 'Valor'], array_map(fn ($c) => [$c['etiqueta'], $c['valor']], $seccion['cifras']));
            }

            $sec->addText('Análisis de la dirección:', ['bold' => true, 'size' => 9], ['spaceBefore' => 80]);
            $this->parrafos($sec, $revision->analisis[$clave] ?? '—');
        }

        $this->titulo($sec, 'Conclusiones sobre el sistema');
        $criterios = $revision->conclusiones_sistema ?? [];
        $this->tabla($sec, ['Criterio', 'Conclusión'], collect(ManagementReview::CRITERIOS)
            ->map(fn ($nombre, $clave) => [$nombre, self::VALORACION[$criterios[$clave] ?? ''] ?? '—'])->values()->all());
        if ($revision->conclusiones) {
            $this->parrafos($sec, $revision->conclusiones);
        }

        $this->titulo($sec, 'Decisiones y acciones');
        $decisiones = $revision->decisions;
        $decisiones->isEmpty()
            ? $sec->addText('No se tomaron decisiones.', ['italic' => true, 'color' => '666666'])
            : $this->tabla($sec, ['Tipo', 'Decisión', 'Responsable', 'Fecha límite', 'Estado'], $decisiones->map(fn (ManagementReviewDecision $d) => [
                self::TIPO_DECISION[$d->tipo] ?? $d->tipo, $d->descripcion, $d->responsable ?? '—',
                $d->fecha_limite?->format('d/m/Y') ?? '—', self::ESTADO_DECISION[$d->estado] ?? $d->estado,
            ])->all());

        if ($revision->estaCerrada()) {
            $sec->addText('Cerrada por '.$revision->cerrada_por.' el '.$revision->cerrada_at->format('d/m/Y H:i').'.', ['size' => 8, 'color' => '666666'], ['spaceBefore' => 200]);
        }
        $sec->addText('Firma de la alta dirección: ______________________________', [], ['spaceBefore' => 400]);

        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $archivo = $dir.'/'.Str::slug('revision-direccion-'.$revision->codigo).'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($archivo);

        return $archivo;
    }

    private function titulo($sec, string $texto): void
    {
        $sec->addText(strtoupper($texto), ['bold' => true, 'size' => 12, 'color' => self::NAVY], ['spaceBefore' => 300, 'spaceAfter' => 80]);
    }

    private function parrafos($sec, string $texto): void
    {
        foreach (preg_split('/\R/', $texto) as $linea) {
            $sec->addText($linea, ['size' => 10]);
        }
    }

    /** @param list<list<string|null>> $filas */
    private function tabla($sec, array $columnas, array $filas): void
    {
        $t = $sec->addTable(['borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct']);
        $t->addRow();
        foreach ($columnas as $c) {
            $t->addCell(null, ['bgColor' => 'F2F4F7'])->addText($c, ['bold' => true, 'size' => 9]);
        }
        foreach ($filas as $fila) {
            $t->addRow();
            foreach ($fila as $v) {
                $t->addCell()->addText((string) ($v ?? '—'), ['size' => 9]);
            }
        }
    }
}
