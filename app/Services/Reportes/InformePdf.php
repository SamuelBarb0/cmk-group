<?php

namespace App\Services\Reportes;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Pinta el informe de gestión en PDF: la copia final, la que se archiva como
 * evidencia. Mismo contenido que el Word, desde la vista reportes.informe.
 */
class InformePdf
{
    /** @param  array<string, mixed>  $informe  salida de InformeGestion::generar() */
    public function exportar(array $informe, ?string $observaciones): string
    {
        $html = view('reportes.informe', [
            'informe' => $informe,
            'observaciones' => $observaciones,
            'company' => config('cmk.company'),
            // Embebido: dompdf no tiene que salir a buscar nada, y el logo ya
            // viene aplanado sobre blanco (la transparencia da problemas).
            'logo' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('reportes/logo-cmk.png'))),
        ])->render();

        foreach ([storage_path('app/temp'), storage_path('fonts')] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $opciones = new Options;
        $opciones->setIsRemoteEnabled(false);
        $opciones->setDefaultFont('DejaVu Sans');
        $opciones->setChroot([resource_path('reportes')]);
        $opciones->setTempDir(storage_path('app/temp'));
        $opciones->setFontCache(storage_path('fonts'));

        $pdf = new Dompdf($opciones);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();

        // Numeración de página: el pie fijo de la vista no conoce el total.
        $pdf->getCanvas()->page_text(520, 752, 'Página {PAGE_NUM} de {PAGE_COUNT}',
            $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.53, 0.53, 0.53]);

        $ruta = storage_path('app/temp').'/'.InformeWord::nombre($informe).'.pdf';
        file_put_contents($ruta, $pdf->output());

        return $ruta;
    }
}
