<?php

namespace App\Services\Reportes\Secciones;

use App\Models\LegalRequirement;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/** Matriz de requisitos legales: estado a la fecha (no tiene eventos por periodo). */
class RequisitosLegales extends Seccion
{
    public function clave(): string
    {
        return 'requisitos-legales';
    }

    public function titulo(): string
    {
        return 'Requisitos legales';
    }

    public function modulo(): ?string
    {
        return 'requisitos-legales';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $aplicables = LegalRequirement::where('aplica', true)->get(['id', 'norma', 'articulo', 'requisito', 'cumplimiento', 'responsable']);
        if ($aplicables->isEmpty()) {
            $this->nota('La matriz de requisitos legales está vacía.');

            return;
        }
        $noCumple = $aplicables->where('cumplimiento', 'no_cumple');
        // Igual que CUMP-LEG: solo «cumple» cuenta; un parcial no es cumplimiento.
        $porcentaje = self::pct($aplicables->where('cumplimiento', 'cumple')->count(), $aplicables->count());

        $this->cifra('Requisitos aplicables', $aplicables->count());
        $this->cifra('Cumplen', $aplicables->where('cumplimiento', 'cumple')->count());
        $this->cifra('Cumplen parcialmente', $aplicables->where('cumplimiento', 'parcial')->count());
        $this->cifra('No cumplen', $noCumple->count(),
            $noCumple->isNotEmpty() ? $noCumple->count().' requisito(s) legal(es) aplicable(s) sin cumplir.' : null);
        $this->cifra('Cumplimiento legal', self::porcentaje($porcentaje));

        $this->tabla('Requisitos sin cumplir', ['Norma', 'Artículo', 'Requisito', 'Responsable'],
            $noCumple->map(fn ($r) => [self::corto($r->norma, 40), $r->articulo ?: '—', self::corto($r->requisito, 110), $r->responsable ?: '—']),
            'Todos los requisitos aplicables se cumplen al menos parcialmente.');
        $this->nota('Estado de la matriz a la fecha del informe.');
    }
}
