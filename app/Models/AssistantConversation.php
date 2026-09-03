<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hilo de conversación del asistente: un hilo por usuario y empresa cliente.
 *
 * Además del TenantScope se filtra SIEMPRE por user_id (ver scopeDelUsuario):
 * el scope por tenant no restringe cuando un consultor de CMK trabaja sin
 * cliente activo, y ahí sí haría falta separar los hilos de cada persona.
 */
class AssistantConversation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'titulo'];

    /** @return HasMany<AssistantMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
