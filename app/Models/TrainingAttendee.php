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
    ];

    protected function casts(): array
    {
        return [
            'asistio' => 'boolean',
        ];
    }

    /** @return BelongsTo<Training, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }
}
