<?php

namespace App\Services\Reportes\Secciones;

use App\Models\Employee;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;
use App\Support\TenantContext;

/** Datos de la empresa y su población trabajadora (base: siempre va). */
class Empresa extends Seccion
{
    public function clave(): string
    {
        return 'empresa';
    }

    public function titulo(): string
    {
        return 'Datos de la empresa';
    }

    public function modulo(): ?string
    {
        return null;
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $t = app(TenantContext::class)->get();
        $activos = Employee::where('is_active', true)->get(['id', 'area']);

        $this->cifra('Trabajadores activos', $activos->count(),
            $activos->isEmpty() ? 'No hay trabajadores activos registrados: los indicadores por trabajador no se pueden calcular.' : null);
        $this->cifra('Nivel de riesgo', $t->nivel_riesgo);
        $this->cifra('ARL', $t->arl);
        $this->cifra('Actividad económica', $t->actividad_economica);
        $this->cifra('Representante legal', $t->representante_legal);
        $this->cifra('Responsable del SG-SST', $t->responsable_sgsst,
            blank($t->responsable_sgsst) ? 'La empresa no tiene responsable del SG-SST registrado.' : null);

        if ($t->licencia_sgsst_vence) {
            $vencida = $t->licencia_sgsst_vence->isPast();
            $this->cifra('Licencia SST del responsable', ($vencida ? 'Vencida el ' : 'Vigente hasta ').self::fecha($t->licencia_sgsst_vence),
                $vencida ? 'La licencia en SST del responsable del SG-SST está vencida.' : null);
        }

        $this->tabla('Trabajadores activos por área', ['Área', 'Trabajadores'],
            $activos->groupBy(fn ($e) => $e->area ?: 'Sin área')->map->count()->sortDesc()->map(fn ($n, $area) => [$area, $n]),
            'Sin trabajadores registrados.');
    }
}
