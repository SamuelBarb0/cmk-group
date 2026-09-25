<?php

namespace App\Services\Reportes\Secciones;

use App\Models\EquipmentCalibration;
use App\Models\MeasuringEquipment;
use App\Services\Calibracion\DocumentosCalibracion;
use App\Services\Reportes\Periodo;
use App\Services\Reportes\Seccion;

/**
 * Equipos de seguimiento y medición: foto del control metrológico hoy y lo
 * que pasó en el periodo (calibraciones hechas y equipos no conformes).
 */
class EquiposMedicion extends Seccion
{
    public function clave(): string
    {
        return 'equipos-medicion';
    }

    public function titulo(): string
    {
        return 'Equipos de medición';
    }

    public function modulo(): ?string
    {
        return 'equipos-medicion';
    }

    public function permiso(): string
    {
        return 'sst.view';
    }

    protected function construir(Periodo $periodo): void
    {
        $equipos = MeasuringEquipment::query()->with('calibrations')->orderBy('codigo')->get();
        $enUso = $equipos->where('estado', 'en_uso');
        $situacion = $equipos->mapWithKeys(fn (MeasuringEquipment $e) => [$e->id => $e->situacion()]);
        $vencidos = $enUso->filter(fn ($e) => in_array($situacion[$e->id]['estado'], ['vencido', 'sin_calibrar'], true));
        $porVencer = $enUso->filter(fn ($e) => $situacion[$e->id]['estado'] === 'por_vencer');
        $delPeriodo = EquipmentCalibration::query()
            ->whereBetween('fecha', [$periodo->desde->toDateString(), $periodo->hasta->toDateString()])->get();
        $noConformes = $delPeriodo->where('resultado', 'no_conforme');

        $this->cifra('Equipos en uso', $enUso->count());
        $this->cifra('Vencidos o sin calibrar', $vencidos->count(),
            $vencidos->count() ? 'Hay equipos en uso sin calibración vigente: sus mediciones no son confiables (ISO 9001 7.1.5).' : null);
        $this->cifra('Por vencer en '.MeasuringEquipment::AVISO_DIAS.' días', $porVencer->count());
        $this->cifra('Fuera de servicio', $equipos->where('estado', 'fuera_servicio')->count());
        $this->cifra('Calibraciones y verificaciones en el periodo', $delPeriodo->count());
        $this->cifra('Resultados no conformes en el periodo', $noConformes->count(),
            $noConformes->whereNull('acpm_action_id')->count() ? 'Hay resultados no conformes sin acción correctiva en ACPM.' : null);

        $this->tabla('Equipos que requieren atención', ['Código', 'Equipo', 'Última', 'Próxima', 'Estado'],
            $equipos->filter(fn ($e) => in_array($situacion[$e->id]['estado'], ['vencido', 'sin_calibrar', 'no_conforme', 'por_vencer'], true))
                ->map(fn (MeasuringEquipment $e) => [
                    $e->codigo, self::corto($e->nombre, 60), $situacion[$e->id]['ultima'] ?? '—', $situacion[$e->id]['proxima'] ?? '—',
                    DocumentosCalibracion::SITUACIONES[$situacion[$e->id]['estado']],
                ]),
            'Todos los equipos en uso tienen su calibración vigente.');
    }
}
