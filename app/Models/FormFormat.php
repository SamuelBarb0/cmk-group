<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formato del catálogo GLOBAL (motor genérico Tier 4).
 *
 * `schema` = { secciones: [ { titulo, campos: [ { key, label, tipo, ... } ] } ] }
 * Tipos de campo: text | textarea | date | number | select | checklist | firma.
 */
class FormFormat extends Model
{
    protected $fillable = [
        'codigo',
        'nombre',
        'categoria',
        'grupo',
        'descripcion',
        'schema',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<FormRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(FormRecord::class);
    }
}
