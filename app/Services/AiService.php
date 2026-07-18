<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de Anthropic Claude (Messages API) vía HTTP.
 *
 * Sin SDK: usa el Http facade de Laravel contra POST /v1/messages.
 * Base para la generación de documentos SST/PESV/HSEQ con IA.
 *
 * Modelos: config('ai.anthropic.model') — por defecto claude-opus-4-8.
 * OJO en modelos nuevos: NO enviar temperature/top_p/top_k ni budget_tokens
 * (devuelven 400). Se controla la profundidad con el prompt.
 */
class AiService
{
    /**
     * Completa un mensaje con Claude y devuelve el texto generado.
     *
     * @param  string       $userPrompt    Instrucción/contenido del usuario.
     * @param  string|null  $systemPrompt  Rol/contexto del sistema (opcional).
     * @param  int|null     $maxTokens     Límite de salida (opcional).
     */
    public function complete(string $userPrompt, ?string $systemPrompt = null, ?int $maxTokens = null): string
    {
        $cfg = config('ai.anthropic');

        if (empty($cfg['api_key'])) {
            throw new RuntimeException('ANTHROPIC_API_KEY no configurada.');
        }

        $payload = [
            'model' => $cfg['model'],
            'max_tokens' => $maxTokens ?? $cfg['max_tokens'],
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $response = Http::withHeaders([
            'x-api-key' => $cfg['api_key'],
            'anthropic-version' => $cfg['version'],
            'content-type' => 'application/json',
        ])
            ->timeout($cfg['timeout'])
            ->post(rtrim($cfg['base_url'], '/').'/v1/messages', $payload);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Error de la API de Claude ({$response->status()}): {$error}");
        }

        $data = $response->json();

        // Claude puede rechazar por seguridad; hay que revisarlo antes de leer content.
        if (($data['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('Claude rechazó la solicitud por políticas de seguridad.');
        }

        // Concatena todos los bloques de texto de la respuesta.
        $text = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            throw new RuntimeException('Respuesta vacía de Claude (stop_reason: '.($data['stop_reason'] ?? 'desconocido').').');
        }

        return $text;
    }

    /**
     * Verifica conectividad y credenciales con una llamada mínima.
     * Devuelve el texto de saludo o lanza excepción.
     */
    public function ping(): string
    {
        return $this->complete('Responde solo con la palabra: OK', maxTokens: 16);
    }

    /** Timeout configurado (segundos) para las llamadas a la API. */
    public function timeout(): int
    {
        return (int) config('ai.anthropic.timeout', 120);
    }
}
