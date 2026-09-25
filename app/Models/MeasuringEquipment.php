<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Equipo de seguimiento y medición (ISO 9001 7.1.5, ISO 45001/14001 9.1.1).
 * Segregado por tenant.
 */
class MeasuringEquipment extends Model
{
    use BelongsToTenant;

    protected $table = 'measuring_equipment';

    protected $fillable = [
        'codigo', 'nombre', 'marca', 'modelo', 'serie', 'magnitud', 'unidad', 'rango',
        'resolucion', 'error_maximo', 'uso', 'ubicacion', 'responsable', 'control',
        'frecuencia_meses', 'estado', 'observaciones',
    ];

    protected function casts(): array
    {
        return ['frecuencia_meses' => 'integer'];
    }

    public const CONTROLES = ['calibracion' => 'Calibración', 'verificacion' => 'Verificación'];

    public const ESTADOS = ['en_uso' => 'En uso', 'fuera_servicio' => 'Fuera de servicio', 'baja' => 'Dado de baja'];

    /** Días antes del vencimiento en que la calibración se marca «por vencer». */
    public const AVISO_DIAS = 30;

    /** Estados del control que exigen atención. */
    public const ALERTA = ['vencido', 'sin_calibrar', 'no_conforme'];

    /** @return HasMany<EquipmentCalibration, $this> */
    public function calibrations(): HasMany
    {
        return $this->hasMany(EquipmentCalibration::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    /**
     * Situación del control metrológico hoy. Espera `calibrations` cargadas
     * (ordenadas de la más reciente a la más vieja).
     *
     * @return array{estado: string, ultima: ?string, proxima: ?string, dias: ?int}
     */
    public function situacion(?Carbon $hoy = null): array
    {
        $hoy ??= Carbon::today();
        $ultima = $this->calibrations->first();
        $proxima = $ultima ? $ultima->fecha->copy()->addMonths($this->frecuencia_meses) : null;
        $base = [
            'ultima' => $ultima?->fecha->toDateString(),
            'proxima' => $proxima?->toDateString(),
            'dias' => $proxima ? (int) $hoy->diffInDays($proxima, false) : null,
        ];

        // Un equipo que no se usa no está vencido: no mide nada.
        if ($this->estado !== 'en_uso') {
            return ['estado' => $this->estado] + $base;
        }
        if (! $ultima) {
            return ['estado' => 'sin_calibrar'] + $base;
        }
        if ($ultima->resultado === 'no_conforme') {
            return ['estado' => 'no_conforme'] + $base;
        }
        if ($proxima->lt($hoy)) {
            return ['estado' => 'vencido'] + $base;
        }

        return ['estado' => $base['dias'] <= self::AVISO_DIAS ? 'por_vencer' : 'vigente'] + $base;
    }
}
