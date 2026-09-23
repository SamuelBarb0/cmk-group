<?php

namespace App\Services\Reportes\Secciones;

use App\Http\Controllers\OccupationalHealthController;
use App\Models\Employee;
use App\Models\MedicalExam;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Exámenes médicos ocupacionales. Solo cifras: ni nombres ni conceptos por
 * persona, porque el informe lo lee la gerencia (Res. 2346).
 *
 * El estado por trabajador sigue la regla de la pantalla
 * (OccupationalHealthController::estadoPorTrabajador): cuenta el último
 * examen que no sea de retiro.
 */
class SaludOcupacional extends Seccion
{
    public function clave(): string
    {
        return 'salud-ocupacional';
    }

    public function titulo(): string
    {
        return 'Salud ocupacional';
    }

    public function modulo(): ?string
    {
        return 'salud-ocupacional';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $delPeriodo = $periodo->filtrar(MedicalExam::query(), 'fecha')->get(['id', 'tipo', 'concepto', 'restricciones', 'carta_entregada']);

        $this->cifra('Exámenes en el periodo', $delPeriodo->count());
        $this->cifra('Con restricciones o no aptos', $delPeriodo->whereIn('concepto', ['apto_con_restricciones', 'no_apto'])->count());

        // Estado HOY de la población activa.
        $activos = Employee::where('is_active', true)->pluck('id');
        $ultimos = MedicalExam::whereIn('employee_id', $activos)
            ->whereNotIn('tipo', MedicalExam::TIPOS_SIN_PROXIMO)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get(['id', 'employee_id', 'proximo_examen'])
            ->unique('employee_id');
        $hoy = now()->startOfDay();
        $aviso = $hoy->copy()->addDays(OccupationalHealthController::DIAS_AVISO);
        $vencidos = $ultimos->filter(fn ($e) => $e->proximo_examen?->lt($hoy))->count();
        $porVencer = $ultimos->filter(fn ($e) => $e->proximo_examen && ! $e->proximo_examen->lt($hoy) && $e->proximo_examen->lte($aviso))->count();
        $sinExamen = $activos->count() - $ultimos->count();

        $this->cifra('Trabajadores con examen vencido', $vencidos,
            $vencidos ? "{$vencidos} trabajador(es) con el examen médico periódico vencido." : null);
        $this->cifra('Por vencer en '.OccupationalHealthController::DIAS_AVISO.' días', $porVencer);
        $this->cifra('Trabajadores sin examen', $sinExamen,
            $sinExamen > 0 ? "{$sinExamen} trabajador(es) activo(s) sin examen médico ocupacional registrado." : null);

        $this->tabla('Exámenes del periodo por tipo', ['Tipo', 'Exámenes', 'Aptos', 'Con restricciones', 'No aptos / aplazados'],
            collect(MedicalExam::TIPOS)->map(fn ($t) => [
                self::etiqueta($t),
                $delPeriodo->where('tipo', $t)->count(),
                $delPeriodo->where('tipo', $t)->where('concepto', 'apto')->count(),
                $delPeriodo->where('tipo', $t)->where('concepto', 'apto_con_restricciones')->count(),
                $delPeriodo->where('tipo', $t)->whereIn('concepto', ['no_apto', 'aplazado'])->count(),
            ])->filter(fn ($f) => $f[1] > 0),
            'Sin exámenes en el periodo.');
        $this->nota('Vencidos, por vencer y sin examen son el estado a la fecha del informe. No incluye diagnósticos: la historia clínica la custodia la IPS.');
    }
}
