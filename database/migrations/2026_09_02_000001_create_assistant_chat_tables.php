<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistente conversacional (widget flotante).
 *
 * - assistant_conversations: un hilo por usuario y empresa cliente. tenant_id
 *   es nullable porque el personal de CMK puede chatear sin cliente activo
 *   (ahí el asistente solo orienta, no genera documentos).
 * - assistant_messages: los mensajes TAL CUAL los espera la Messages API. En
 *   `content` va el array de bloques (text, thinking, tool_use, tool_result)
 *   serializado, porque hay que devolvérselo a Claude sin alterar para que el
 *   bucle de herramientas y los bloques de razonamiento sigan siendo válidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('titulo')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tenant_id']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 20);   // user | assistant
            $table->longText('content');  // JSON: array de bloques de contenido
            $table->timestamps();

            $table->index(['assistant_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
