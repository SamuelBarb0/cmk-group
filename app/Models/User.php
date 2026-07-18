<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Empresa cliente a la que pertenece el usuario (null = personal de CMK).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** ¿Es personal de la consultora CMK (acceso multi-cliente)? */
    public function belongsToCmk(): bool
    {
        return $this->hasAnyRole(['consultor_admin', 'consultor_operativo']);
    }

    /** ¿Es usuario de una empresa cliente? */
    public function belongsToClient(): bool
    {
        return $this->tenant_id !== null;
    }

    /** Nombre técnico del primer rol asignado (para UI). */
    public function primaryRole(): ?string
    {
        return $this->getRoleNames()->first();
    }

    /** Etiqueta legible del rol principal, según config/cmk.php. */
    public function primaryRoleLabel(): ?string
    {
        $role = $this->primaryRole();

        return $role ? config("cmk.roles.{$role}.label", $role) : null;
    }
}
