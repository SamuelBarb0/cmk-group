<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Capacitación programada o dictada por una empresa cliente (por tenant).
 */
class Training extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'training_topic_id',
        'titulo',
        'categoria',
        'fecha',
        'instructor',
        'modalidad',
        'duracion_minutos',
        'lugar',
        'objetivo',
        'estado',
        'observaciones',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'duracion_minutos' => 'integer',
        ];
    }

    /** @return BelongsTo<TrainingTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(TrainingTopic::class, 'training_topic_id');
    }

    /** @return HasMany<TrainingAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(TrainingAttendee::class);
    }
}
