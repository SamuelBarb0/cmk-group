<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calibración o verificación de un equipo de medición, con su certificado.
 */
class EquipmentCalibration extends Model
{
    use BelongsToTenant;

    // `archivo`, `archivo_nombre` y `acpm_action_id` NO son asignables: los pone
    // el controlador (el archivo lo guarda él; la acción la crea ACPM).
    protected $fillable = [
        'measuring_equipment_id', 'fecha', 'tipo', 'realizado_por', 'acreditado_onac', 'certificado',
        'error_encontrado', 'incertidumbre', 'resultado', 'impacto_mediciones', 'observaciones',
    ];

    protected $hidden = ['archivo'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'acreditado_onac' => 'boolean',
        ];
    }

    protected $appends = ['tiene_archivo'];

    public const RESULTADOS = ['conforme' => 'Conforme', 'no_conforme' => 'No conforme'];

    public function getTieneArchivoAttribute(): bool
    {
        return filled($this->archivo);
    }

    /** @return BelongsTo<MeasuringEquipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(MeasuringEquipment::class, 'measuring_equipment_id');
    }

    /** @return BelongsTo<AcpmAction, $this> */
    public function acpmAction(): BelongsTo
    {
        return $this->belongsTo(AcpmAction::class);
    }
}
