<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asistente al registro de una capacitación (snapshot del empleado o entrada manual).
 */
class TrainingAttendee extends Model
{
    protected $fillable = [
        'training_id',
        'employee_id',
        'nombres',
        'numero_documento',
        'cargo',
        'asistio',
        'nota',
        // `eficaz` NO es asignable: lo deriva el modelo de la nota y del umbral
        // de la capacitacion. Dejarlo asignable permitiria marcar como eficaz a
        // quien no aprobo, y ese numero es el numerador de IND3.
    ];

    protected function casts(): array
    {
        return [
            'asistio' => 'boolean',
            'nota' => 'decimal:2',
            'eficaz' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // La eficacia se deriva de la nota contra el umbral de SU capacitacion.
        // Se recalcula en cada guardado porque el consultor puede cambiar la
        // nota minima despues de haber calificado, y entonces las notas ya
        // registradas tienen que reevaluarse solas.
        static::saving(function (TrainingAttendee $asistente): void {
            if ($asistente->nota === null) {
                // Sin nota no hay veredicto. No es lo mismo que reprobar: el
                // denominador de IND3 son las personas EVALUADAS.
                $asistente->eficaz = null;

                return;
            }

            $minima = $asistente->training?->nota_minima ?? 70;
            $asistente->eficaz = (float) $asistente->nota >= (float) $minima;
        });
    }

    /** @return BelongsTo<Training, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }
}
