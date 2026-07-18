<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un Tenant representa una empresa CLIENTE gestionada por CMK GROUP.
 * La información de cada tenant queda segregada mediante tenant_id
 * (Row Level Security a nivel de aplicación; en PostgreSQL se refuerza
 * con políticas RLS nativas en producción).
 */
class Tenant extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'nit',
        'email',
        'phone',
        'city',
        'address',
        'logo_path',
        'is_active',
        // Información de la Organización (contexto SGI)
        'actividad_economica',
        'codigo_ciiu',
        'sector',
        'nivel_riesgo',
        'arl',
        'tamano_empresa',
        'num_trabajadores',
        'representante_legal',
        'representante_cc',
        'responsable_sgsst',
        'licencia_sgsst',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'num_trabajadores' => 'integer',
        ];
    }

    /**
     * Usuarios (del cliente) que pertenecen a este tenant.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
