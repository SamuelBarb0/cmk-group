<?php

namespace App\Services\Presentaciones;

use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as FondoColor;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * Arma el .pptx de una presentación a partir de las diapositivas que devolvió
 * la IA, con la marca de CMK (config('cmk.brand')). Lienzo 16:9 de 960 × 540
 * px (la unidad de PhpPresentation).
 *
 * Tipos de diapositiva: seccion, vinetas, cifras, tabla, cierre. La portada
 * sale del título y el subtítulo.
 */
class ConstructorPptx
{
    private const W = 960;

    private const H = 540;

    private const MARGEN = 48;

    private const TEXTO = 'FF2B2F36';

    /** @var array<string, string> */
    private array $marca;

    private string $pie = '';

    private int $numero = 0;

    /**
     * @param  array{titulo: string, subtitulo?: string, diapositivas: list<array<string, mixed>>}  $contenido
     */
    public function guardar(array $contenido, string $cliente, string $ruta): void
    {
        $this->marca = collect(config('cmk.brand'))->map(fn ($hex) => 'FF'.strtoupper(ltrim((string) $hex, '#')))->all();
        $this->pie = config('cmk.company.name').' · '.$cliente;

        $ppt = new PhpPresentation;
        $ppt->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);
        $ppt->getDocumentProperties()->setCreator(config('cmk.company.name'))->setTitle($this->limpio($contenido['titulo']));

        $this->portada($ppt->getActiveSlide(), $contenido['titulo'], $contenido['subtitulo'] ?? '', $cliente);
        foreach ($contenido['diapositivas'] as $d) {
            $slide = $ppt->createSlide();
            match ($d['tipo'] ?? 'vinetas') {
                'seccion' => $this->seccion($slide, $d),
                'cierre' => $this->cierre($slide, $d),
                'cifras' => $this->cifras($slide, $d),
                'tabla' => $this->tabla($slide, $d),
                default => $this->vinetas($slide, $d),
            };
            if (filled($d['notas'] ?? null)) {
                $this->caja($slide->getNote()->createRichTextShape(), 0, 0, 600, 400)
                    ->createTextRun($this->limpio($d['notas']));
            }
        }

        IOFactory::createWriter($ppt, 'PowerPoint2007')->save($ruta);
    }

    private function portada(Slide $s, string $titulo, string $subtitulo, string $cliente): void
    {
        $this->fondo($s, $this->marca['paper']);
        $this->rect($s, 0, 0, 560, self::H, $this->marca['navy']);

        $t = $this->caja($s->createRichTextShape(), self::MARGEN, 150, 470, 180);
        $this->run($t, $titulo, 34, 'FFFFFFFF', true);
        if (filled($subtitulo)) {
            $t->createParagraph();
            $this->run($t, $subtitulo, 16, 'FFD9DEE7');
        }

        $c = $this->caja($s->createRichTextShape(), self::MARGEN, 420, 470, 60);
        $this->run($c, $cliente, 14, 'FFFFFFFF', true);
        $c->createParagraph();
        $this->run($c, now()->locale('es')->translatedFormat('F \d\e Y'), 11, 'FFD9DEE7');

        $this->logo($s, 640, 210, 70);
        $m = $this->caja($s->createRichTextShape(), 600, 300, 320, 40);
        $m->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->run($m, 'SST · HSEQ · PESV', 11, $this->marca['gray']);
    }

    /** @param  array<string, mixed>  $d */
    private function seccion(Slide $s, array $d): void
    {
        $this->fondo($s, $this->marca['navy']);
        $t = $this->caja($s->createRichTextShape(), self::MARGEN, 190, self::W - 2 * self::MARGEN, 160);
        $this->run($t, $d['titulo'] ?? '', 32, 'FFFFFFFF', true);
        foreach (array_slice($d['puntos'] ?? [], 0, 2) as $p) {
            $t->createParagraph();
            $this->run($t, $p, 16, 'FFD9DEE7');
        }
        $this->numerar($s, true);
    }

    /** @param  array<string, mixed>  $d */
    private function cierre(Slide $s, array $d): void
    {
        $this->fondo($s, $this->marca['navy_deep']);
        $t = $this->caja($s->createRichTextShape(), self::MARGEN, 70, self::W - 2 * self::MARGEN, 70);
        $this->run($t, $d['titulo'] ?? 'Conclusiones', 28, 'FFFFFFFF', true);
        $this->lista($this->caja($s->createRichTextShape(), self::MARGEN, 160, self::W - 2 * self::MARGEN, 320), $d['puntos'] ?? [], 18, 'FFFFFFFF');
        $this->numerar($s, true);
    }

    /** @param  array<string, mixed>  $d */
    private function vinetas(Slide $s, array $d): void
    {
        $y = $this->base($s, $d['titulo'] ?? '');
        $this->lista($this->caja($s->createRichTextShape(), self::MARGEN, $y, self::W - 2 * self::MARGEN, 475 - $y), $d['puntos'] ?? [], 18, self::TEXTO);
    }

    /** @param  array<string, mixed>  $d */
    private function cifras(Slide $s, array $d): void
    {
        $y = $this->base($s, $d['titulo'] ?? '') + 5;
        $cifras = array_slice($d['cifras'] ?? [], 0, 4);
        $n = max(1, count($cifras));
        $gap = 20;
        $ancho = (self::W - 2 * self::MARGEN - ($n - 1) * $gap) / $n;
        foreach (array_values($cifras) as $i => $c) {
            $x = self::MARGEN + $i * ($ancho + $gap);
            $this->rect($s, $x, $y, $ancho, 150, $this->marca['paper']);
            $v = $this->caja($s->createRichTextShape(), $x + 12, $y + 15, $ancho - 24, 70);
            $v->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->run($v, (string) ($c['valor'] ?? ''), 34, $this->marca['navy'], true);
            $e = $this->caja($s->createRichTextShape(), $x + 12, $y + 85, $ancho - 24, 60);
            $e->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->run($e, (string) ($c['etiqueta'] ?? ''), 12, $this->marca['gray']);
        }
        if (! empty($d['puntos'])) {
            $this->lista($this->caja($s->createRichTextShape(), self::MARGEN, $y + 175, self::W - 2 * self::MARGEN, 300 - $y), $d['puntos'], 15, self::TEXTO);
        }
    }

    /** @param  array<string, mixed>  $d */
    private function tabla(Slide $s, array $d): void
    {
        $y = $this->base($s, $d['titulo'] ?? '');
        $columnas = array_slice($d['tabla']['columnas'] ?? [], 0, 6);
        $filas = array_slice($d['tabla']['filas'] ?? [], 0, 8);
        if ($columnas === []) {
            $this->lista($this->caja($s->createRichTextShape(), self::MARGEN, $y, self::W - 2 * self::MARGEN, 475 - $y), $d['puntos'] ?? [], 18, self::TEXTO);

            return;
        }

        $n = count($columnas);
        $tabla = $s->createTableShape($n);
        $tabla->setWidth(self::W - 2 * self::MARGEN)->setOffsetX(self::MARGEN)->setOffsetY($y);
        $anchoCol = (int) ((self::W - 2 * self::MARGEN) / $n);

        $fila = $tabla->createRow()->setHeight(30);
        $fila->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color($this->marca['navy']))->setEndColor(new Color($this->marca['navy']));
        foreach ($columnas as $col) {
            $celda = $fila->nextCell()->setWidth($anchoCol);
            $celda->createTextRun($this->limpio((string) $col))->getFont()->setBold(true)->setSize(11)->setColor(new Color('FFFFFFFF'));
        }
        foreach ($filas as $i => $f) {
            $fila = $tabla->createRow()->setHeight(28);
            $fondo = $i % 2 ? 'FFFFFFFF' : $this->marca['paper'];
            $fila->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color($fondo))->setEndColor(new Color($fondo));
            for ($c = 0; $c < $n; $c++) {
                $fila->nextCell()->setWidth($anchoCol)->createTextRun($this->limpio((string) ($f[$c] ?? '')))->getFont()->setSize(11)->setColor(new Color(self::TEXTO));
            }
        }
    }

    /**
     * Fondo blanco, título, filete y pie: la base de las diapositivas de
     * contenido. Devuelve dónde empieza el cuerpo: un título largo ocupa dos
     * líneas, baja de tamaño y empuja el filete y el cuerpo hacia abajo (el
     * .pptx no reacomoda el texto solo hasta que alguien lo edita).
     */
    private function base(Slide $s, string $titulo): int
    {
        $largo = mb_strlen($titulo) > 52;
        $this->rect($s, 0, 0, 8, self::H, $this->marca['navy']);
        $t = $this->caja($s->createRichTextShape(), self::MARGEN, 26, self::W - 2 * self::MARGEN - 110, $largo ? 76 : 60);
        $this->run($t, $titulo, $largo ? 20 : 24, $this->marca['navy'], true);
        $this->rect($s, self::MARGEN, $largo ? 106 : 92, 80, 3, $this->marca['navy']);
        $this->logo($s, self::W - self::MARGEN - 90, 30, 30);
        $this->numerar($s, false);

        return $largo ? 128 : 115;
    }

    private function numerar(Slide $s, bool $oscuro): void
    {
        $this->numero++;
        $color = $oscuro ? 'FFD9DEE7' : $this->marca['gray_soft'];
        $p = $this->caja($s->createRichTextShape(), self::MARGEN, self::H - 34, 600, 22);
        $this->run($p, $this->pie, 9, $color);
        $n = $this->caja($s->createRichTextShape(), self::W - self::MARGEN - 60, self::H - 34, 60, 22);
        $n->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->run($n, (string) ($this->numero + 1), 9, $color);
    }

    /** @param  list<string>  $puntos */
    private function lista(RichText $caja, array $puntos, int $tam, string $color): void
    {
        foreach (array_values(array_slice($puntos, 0, 7)) as $i => $p) {
            $par = $i === 0 ? $caja->getActiveParagraph() : $caja->createParagraph();
            $par->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET)->setBulletChar('•')->setBulletColor(new Color($this->marca['gray']));
            $par->getAlignment()->setMarginLeft(22)->setIndent(-22);
            $par->setLineSpacing(115);
            $par->createTextRun($this->limpio($p))->getFont()->setSize($tam)->setColor(new Color($color));
        }
    }

    private function caja(RichText $r, float $x, float $y, float $w, float $h): RichText
    {
        $r->setOffsetX((int) $x)->setOffsetY((int) $y)->setWidth((int) $w)->setHeight((int) $h);
        $r->setAutoFit(RichText::AUTOFIT_NORMAL);

        return $r;
    }

    private function run(RichText $caja, string $texto, int $tam, string $color, bool $negrita = false): void
    {
        $caja->createTextRun($this->limpio($texto))->getFont()->setSize($tam)->setBold($negrita)->setColor(new Color($color))->setName('Calibri');
    }

    private function rect(Slide $s, float $x, float $y, float $w, float $h, string $color): void
    {
        $r = $s->createRichTextShape()->setOffsetX((int) $x)->setOffsetY((int) $y)->setWidth((int) $w)->setHeight((int) $h);
        $r->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color($color))->setEndColor(new Color($color));
    }

    private function fondo(Slide $s, string $color): void
    {
        $s->setBackground((new FondoColor)->setColor(new Color($color)));
    }

    private function logo(Slide $s, int $x, int $y, int $alto): void
    {
        $ruta = resource_path('reportes/logo-cmk.png');
        if (is_file($ruta)) {
            $s->createDrawingShape()->setPath($ruta)->setHeight($alto)->setOffsetX($x)->setOffsetY($y);
        }
    }

    /** Sin caracteres de control: rompen el XML del .pptx. */
    private function limpio(string $texto): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto));
    }
}
