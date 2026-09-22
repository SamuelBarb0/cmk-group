<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Examen médico ocupacional de un trabajador. Guarda el CONCEPTO y las
 * recomendaciones, nunca el diagnóstico (ver la migración).
 */
class MedicalExam extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'fecha', 'tipo', 'ips', 'examenes_realizados',
        'concepto', 'restricciones', 'recomendaciones_personales',
        'recomendaciones_sst', 'recomendaciones_medicas',
        'carta_entregada', 'fecha_carta', 'pve', 'plan_accion', 'seguimiento',
        'proximo_examen',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_carta' => 'date:Y-m-d',
            'proximo_examen' => 'date:Y-m-d',
            'examenes_realizados' => 'array',
            'carta_entregada' => 'boolean',
        ];
    }

    /** Res. 2346 de 2007, art. 3, más el control post-incapacidad del art. 5. */
    public const TIPOS = ['ingreso', 'periodico', 'retiro', 'post_incapacidad', 'reintegro'];

    public const CONCEPTOS = ['apto', 'apto_con_restricciones', 'no_apto', 'aplazado'];

    /**
     * Un examen de retiro cierra el ciclo: no genera próximo examen. Los
     * demás sí, porque tras ellos el trabajador sigue expuesto.
     */
    public const TIPOS_SIN_PROXIMO = ['retiro'];

    /**
     * Hay algo que entregar por escrito: restricciones o recomendaciones. Si
     * es así y la carta no consta como entregada, el trabajador no se enteró
     * formalmente, que es justo lo que pregunta el auditor.
     */
    public function requiereCarta(): bool
    {
        return $this->concepto === 'apto_con_restricciones'
            || filled($this->restricciones)
            || filled($this->recomendaciones_sst)
            || filled($this->recomendaciones_medicas);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
