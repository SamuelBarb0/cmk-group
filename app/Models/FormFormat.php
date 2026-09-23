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
        'editado_at',
        'editado_por',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'orden' => 'integer',
            'activo' => 'boolean',
            'editado_at' => 'datetime',
        ];
    }

    public const TIPOS_CAMPO = [
        'text' => 'Texto corto',
        'textarea' => 'Texto largo',
        'date' => 'Fecha',
        'number' => 'Número',
        'select' => 'Lista de opciones',
        'checklist' => 'Lista de chequeo (cumple / no cumple / N/A)',
        'firma' => 'Firma (nombre y cédula)',
    ];

    public const GRUPOS = ['inspeccion' => 'Inspección', 'lista' => 'Lista de chequeo', 'acta' => 'Acta', 'general' => 'Otro'];

    public const CATEGORIAS = ['SST', 'PESV', 'HSEQ'];

    /** @return HasMany<FormRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(FormRecord::class);
    }
}
