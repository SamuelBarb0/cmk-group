<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Elemento de protección personal del catálogo de la empresa cliente. */
class PpeItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'nombre', 'categoria', 'norma', 'uso',
        'vida_util', 'criterio_reposicion', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /** Las siete familias que agrupa la matriz de EPP de CMK. */
    public const CATEGORIAS = [
        'cabeza', 'visual_facial', 'respiratoria', 'auditiva',
        'manos', 'pies', 'cuerpo',
    ];

    /** @return HasMany<PpeAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(PpeAssignment::class);
    }

    /** @return HasMany<PpeDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(PpeDelivery::class);
    }
}
