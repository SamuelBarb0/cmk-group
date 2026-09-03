<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un turno de la conversación, guardado con la forma EXACTA que espera la
 * Messages API: `content` es el array de bloques (text, thinking con su
 * signature, tool_use, tool_result). No se aplana a texto porque Claude
 * necesita recibirlos de vuelta intactos en la siguiente vuelta.
 *
 * No lleva BelongsToTenant: cuelga de la conversación, que ya está segregada.
 */
class AssistantMessage extends Model
{
    protected $fillable = ['assistant_conversation_id', 'role', 'content'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    /** @return BelongsTo<AssistantConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
