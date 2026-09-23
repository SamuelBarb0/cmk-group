<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Requisitos del operador (RE-SST-51) de un conductor. Uno por conductor. */
class PesvDriverCheck extends Model
{
    use BelongsToTenant;

    protected $fillable = ['employee_id', 'fecha', 'placa_asignada', 'respuestas', 'resultado', 'verificado_por', 'observaciones'];

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
