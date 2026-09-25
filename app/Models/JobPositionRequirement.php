<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lo que exige un cargo: educación, formación, experiencia o habilidad.
 *
 * Un requisito de formación con `training_topic_id` se cumple solo con una
 * capacitación de ese tema (ver MatrizCompetencias); los demás se evalúan a
 * mano en `competency_assessments`.
 */
class JobPositionRequirement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['job_position_id', 'tipo', 'descripcion', 'training_topic_id', 'orden'];

    public const TIPOS = [
        'educacion' => 'Educación',
        'formacion' => 'Formación',
        'experiencia' => 'Experiencia',
        'habilidad' => 'Habilidad',
    ];

    /** @return BelongsTo<JobPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    /** @return BelongsTo<TrainingTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(TrainingTopic::class, 'training_topic_id');
    }

    /** @return HasMany<CompetencyAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(CompetencyAssessment::class);
    }
}
