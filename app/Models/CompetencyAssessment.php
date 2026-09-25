<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evaluación a mano de un trabajador contra un requisito de su cargo. */
class CompetencyAssessment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['employee_id', 'job_position_requirement_id', 'cumple', 'evidencia', 'evaluado_at', 'evaluado_por'];

    protected function casts(): array
    {
        return [
            'cumple' => 'boolean',
            'evaluado_at' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<JobPositionRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(JobPositionRequirement::class, 'job_position_requirement_id');
    }
}
