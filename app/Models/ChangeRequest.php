<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un cambio gestionado: solicitud, aprobación, plan de acción y cierre.
 */
class ChangeRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fecha_solicitud', 'solicitante', 'cargo_solicitante', 'area', 'procesos_involucrados',
        'tipo', 'tipo_otro', 'condicion', 'descripcion', 'fecha_limite',
        'analisis', 'costo_presupuestado', 'costo_ejecutado',
        'requiere_actualizar_iperc', 'iperc_actualizada_at', 'requiere_capacitacion',
        'aprobacion_gerencia_nombre', 'aprobacion_gerencia_fecha',
        'aprobacion_sst_nombre', 'aprobacion_sst_fecha',
        'rechazado_at', 'motivo_rechazo',
        'cierre_fecha', 'cierre_implementado', 'cierre_a_tiempo', 'cierre_eficaz', 'cierre_justificacion',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_solicitud' => 'date:Y-m-d',
            'fecha_limite' => 'date:Y-m-d',
            'iperc_actualizada_at' => 'date:Y-m-d',
            'aprobacion_gerencia_fecha' => 'date:Y-m-d',
            'aprobacion_sst_fecha' => 'date:Y-m-d',
            'rechazado_at' => 'date:Y-m-d',
            'cierre_fecha' => 'date:Y-m-d',
            'analisis' => 'array',
            'costo_presupuestado' => 'decimal:2',
            'costo_ejecutado' => 'decimal:2',
            'requiere_actualizar_iperc' => 'boolean',
            'requiere_capacitacion' => 'boolean',
            'cierre_implementado' => 'boolean',
            'cierre_a_tiempo' => 'boolean',
            'cierre_eficaz' => 'boolean',
        ];
    }

    /**
     * Tipos del procedimiento de CMK («Descripción de los tipos de cambios») y
     * sus siglas en la matriz: I, RQ, PR, PER, CONTR, PESV, SG. La matriz trae
     * además «AC», que el procedimiento no define; queda en «otro».
     */
    public const TIPOS = [
        'infraestructura', 'requisito_legal', 'proceso', 'personal',
        'contratista', 'pesv', 'sistema_gestion', 'otro',
    ];

    public const CONDICIONES = ['temporal', 'fijo', 'ciclico', 'emergencia'];

    /** Las seis filas de «2. Análisis para la gestión del cambio». */
    public const ELEMENTOS = [
        'mano_obra' => 'Mano de obra',
        'tecnologico' => 'Tecnológico',
        'metodo' => 'Método de trabajo',
        'medicion' => 'Medición y control',
        'medio_ambiente' => 'Medio ambiente',
        'materia_prima' => 'Materia prima',
    ];

    public const ESTADOS = ['pendiente_aprobacion', 'aprobado', 'rechazado', 'cerrado'];

    protected $appends = ['estado', 'vencido'];

    /**
     * El estado sale de los datos, no se guarda: así no puede decir
     * «aprobado» un cambio al que le falta una de las dos firmas.
     */
    public function getEstadoAttribute(): string
    {
        return match (true) {
            $this->rechazado_at !== null => 'rechazado',
            $this->cierre_fecha !== null => 'cerrado',
            $this->estaAprobado() => 'aprobado',
            default => 'pendiente_aprobacion',
        };
    }

    public function estaAprobado(): bool
    {
        return $this->aprobacion_gerencia_fecha !== null && $this->aprobacion_sst_fecha !== null;
    }

    /** Aprobado, sin cerrar y con la fecha límite ya pasada. */
    public function getVencidoAttribute(): bool
    {
        return $this->estado === 'aprobado'
            && $this->fecha_limite !== null
            && $this->fecha_limite->isBefore(now()->startOfDay());
    }

    /** @return HasMany<ChangeRequestAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ChangeRequestAction::class)->orderBy('orden');
    }
}
