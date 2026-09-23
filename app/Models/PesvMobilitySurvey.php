<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Respuesta a la encuesta de movilidad del PESV (RE-SST-36). */
class PesvMobilitySurvey extends Model
{
    use BelongsToTenant;

    protected $fillable = ['employee_id', 'fecha', 'nombre', 'documento', 'respuestas', 'origen'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'respuestas' => 'array'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
