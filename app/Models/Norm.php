<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Norma del SIG en una edición concreta (catálogo GLOBAL).
 *
 * La clave identifica el sistema (sst, pesv, iso45001, iso9001, iso14001) y la
 * edición distingue versiones: ISO 9001:2015 y 2026 conviven mientras dura la
 * transición, así que (clave, edicion) es la llave y no la clave sola.
 */
class Norm extends Model
{
    protected $fillable = ['clave', 'nombre', 'edicion', 'descripcion', 'vigente', 'orden'];

    protected function casts(): array
    {
        return ['vigente' => 'boolean'];
    }

    /** Claves de sistema, en el orden en que se muestran. */
    public const SISTEMAS = ['sst', 'pesv', 'iso45001', 'iso9001', 'iso14001'];

    public const NOMBRES = [
        'sst' => 'SG-SST',
        'pesv' => 'PESV',
        'iso45001' => 'ISO 45001',
        'iso9001' => 'ISO 9001',
        'iso14001' => 'ISO 14001',
    ];

    /** @return HasMany<NormRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(NormRequirement::class)->orderBy('orden');
    }
}
