<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro diligenciado de un formato para una empresa cliente (por tenant).
 *
 * `schema` es un SNAPSHOT tomado del FormFormat al crear el registro: si el
 * catálogo cambia después, los registros ya diligenciados conservan su
 * estructura original. `data` guarda los valores por `key` de campo.
 */
class FormRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'form_format_id',
        'codigo',
        'titulo',
        'categoria',
        'grupo',
        'schema',
        'data',
        'estado',
        'fecha',
        'responsable',
        'generado_por',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'data' => 'array',
            'fecha' => 'date',
        ];
    }

    /** @return BelongsTo<FormFormat, $this> */
    public function format(): BelongsTo
    {
        return $this->belongsTo(FormFormat::class, 'form_format_id');
    }
}
