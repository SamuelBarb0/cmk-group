<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento exigido a un contratista (sección 3 de «8.4 SELEC PROVEED»).
 * Sin tenant ni rutas: se guarda a través del contratista.
 */
class ContractorDocument extends Model
{
    protected $fillable = ['tipo', 'nombre', 'estado', 'fecha_expedicion', 'fecha_vencimiento', 'observacion', 'orden'];

    protected function casts(): array
    {
        return [
            'fecha_expedicion' => 'date:Y-m-d',
            'fecha_vencimiento' => 'date:Y-m-d',
        ];
    }

    public const TIPOS = [
        'rut' => 'RUT',
        'camara_comercio' => 'Certificado de existencia y representación legal',
        'cedula_representante' => 'Cédula del representante legal o de la persona natural',
        'certificacion_bancaria' => 'Certificación bancaria',
        'certificaciones_comerciales' => 'Certificaciones comerciales (2)',
        'sgsst_arl' => 'Acreditación del SG-SST emitida por la ARL',
        'certificaciones_calidad' => 'Certificaciones ISO, NTC, BASC vigentes',
        'seguridad_social' => 'Planilla de aportes a seguridad social (EPS, AFP, ARL)',
        'carta_arl' => 'Carta de intención de afiliarse o no a la ARL',
        'poliza' => 'Póliza',
        'otro' => 'Otro',
    ];

    /** Los que pide la hoja de selección, según el tipo de persona. */
    public const REQUERIDOS = [
        'juridica' => ['rut', 'camara_comercio', 'cedula_representante', 'certificacion_bancaria', 'certificaciones_comerciales', 'sgsst_arl', 'certificaciones_calidad', 'seguridad_social'],
        'natural' => ['rut', 'cedula_representante', 'certificacion_bancaria', 'seguridad_social', 'carta_arl'],
    ];

    public const ESTADOS = ['recibido', 'no_entregado', 'no_aplica'];

    /** @return BelongsTo<PesvContractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(PesvContractor::class, 'pesv_contractor_id');
    }

    /**
     * no_entregado / vencido / por_vencer (30 días) o null si está en regla.
     * Un documento que no aplica nunca alerta.
     *
     * @return array{alerta: string}|null
     */
    public function alerta(): ?array
    {
        if ($this->estado === 'no_aplica') {
            return null;
        }
        if ($this->estado === 'no_entregado') {
            return ['alerta' => 'no_entregado'];
        }
        if ($this->fecha_vencimiento === null) {
            return null;
        }
        $hoy = now()->startOfDay();
        if ($this->fecha_vencimiento->lt($hoy)) {
            return ['alerta' => 'vencido'];
        }

        return $this->fecha_vencimiento->lte($hoy->copy()->addDays(30)) ? ['alerta' => 'por_vencer'] : null;
    }
}
