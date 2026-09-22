<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Integrante de un COPASST o de un Comité de Convivencia.
 *
 * `representa` es lo que sostiene la paridad que exige la norma: el empleador
 * designa a los suyos y los trabajadores eligen a los suyos por votación.
 */
class CommitteeMember extends Model
{
    protected $fillable = [
        'committee_id', 'employee_id', 'nombres', 'numero_documento',
        'cargo', 'rol', 'representa', 'votos',
    ];

    protected function casts(): array
    {
        return ['votos' => 'integer'];
    }

    public const ROLES = ['presidente', 'secretario', 'principal', 'suplente'];

    public const REPRESENTA = ['empleador', 'trabajadores'];

    /** @return BelongsTo<Committee, $this> */
    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
