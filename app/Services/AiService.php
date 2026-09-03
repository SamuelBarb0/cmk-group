<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de Anthropic Claude (Messages API) vía HTTP.
 *
 * Sin SDK: usa el Http facade de Laravel contra POST /v1/messages.
 * Base para la generación de documentos SST/PESV/HSEQ con IA y para el
 * asistente conversacional (que además usa stream() con herramientas).
 *
 * Modelos: config('ai.anthropic.model') — por defecto claude-opus-5.
 * OJO en modelos nuevos: NO enviar temperature/top_p/top_k ni budget_tokens
 * (devuelven 400). La profundidad se controla con output_config.effort.
 */
class AiService
{
    /**
     * Completa un mensaje con Claude y devuelve el texto generado.
     *
     * @param  string  $userPrompt  Instrucción/contenido del usuario.
     * @param  string|null  $systemPrompt  Rol/contexto del sistema (opcional).
     * @param  int|null  $maxTokens  Límite de salida (opcional).
     */
    public function complete(string $userPrompt, ?string $systemPrompt = null, ?int $maxTokens = null): string
    {
        $cfg = config('ai.anthropic');

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

        $response = $this->request()->post($this->endpoint(), $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->mensajeDeError(
                $response->status(),
                $response->json('error.message') ?? $response->body()
            ));
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
     * Llamada en STREAMING a la Messages API.
     *
     * Invoca $onEvent con cada evento SSE ya decodificado (para ir empujando
     * texto al navegador en vivo) y devuelve el mensaje completo ensamblado:
     * ['content' => [bloques], 'stop_reason' => string, 'usage' => array].
     *
     * Los bloques se reconstruyen TAL CUAL los envía la API (texto, thinking
     * con su signature, tool_use con su input) porque hay que devolvérselos a
     * Claude sin tocar en la siguiente vuelta del bucle de herramientas.
     *
     * @param  array<string,mixed>  $payload  Cuerpo de la petición (sin 'stream').
     * @param  callable(array<string,mixed>):void  $onEvent  Recibe cada evento SSE.
     * @return array{content:array<int,array<string,mixed>>,stop_reason:string|null,usage:array<string,mixed>}
     */
    public function stream(array $payload, callable $onEvent): array
    {
        $payload['stream'] = true;

        if (isset($payload['messages'])) {
            $payload['messages'] = $this->normalizarMensajes($payload['messages']);
        }

        $response = $this->request((int) config('ai.chat.timeout', 600))
            ->withOptions(['stream' => true])
            ->post($this->endpoint(), $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->mensajeDeError(
                $response->status(),
                $response->json('error.message') ?? $response->body()
            ));
        }

        $body = $response->toPsrResponse()->getBody();

        $bloques = [];   // índice => bloque en construcción
        $parciales = []; // índice => JSON parcial del input de un tool_use
        $stopReason = null;
        $usage = [];
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            // Los eventos SSE se separan por una línea en blanco.
            while (($corte = strpos($buffer, "\n\n")) !== false) {
                $bruto = substr($buffer, 0, $corte);
                $buffer = substr($buffer, $corte + 2);

                $json = '';
                foreach (explode("\n", $bruto) as $linea) {
                    if (str_starts_with($linea, 'data:')) {
                        $json .= trim(substr($linea, 5));
                    }
                }

                if ($json === '') {
                    continue;
                }

                $evento = json_decode($json, true);

                if (! is_array($evento)) {
                    continue;
                }

                $this->acumular($evento, $bloques, $parciales, $stopReason, $usage);

                $onEvent($evento);
            }
        }

        ksort($bloques);

        return [
            'content' => array_values($bloques),
            'stop_reason' => $stopReason,
            'usage' => $usage,
        ];
    }

    /**
     * Deja los mensajes en la forma que exige la API antes de enviarlos.
     *
     * Una herramienta sin argumentos llega como `{}` y json_decode(.., true)
     * la convierte en array PHP vacío, que al reserializar sale como `[]` y
     * la API lo rechaza con «input: Input should be an object». Pasa igual al
     * releer el historial de la base, así que se corrige aquí, en el único
     * punto por el que pasan todos los mensajes.
     *
     * @param  array<int,array<string,mixed>>  $mensajes
     * @return array<int,array<string,mixed>>
     */
    private function normalizarMensajes(array $mensajes): array
    {
        foreach ($mensajes as $m => $mensaje) {
            if (! is_array($mensaje['content'] ?? null)) {
                continue;
            }

            foreach ($mensaje['content'] as $b => $bloque) {
                if (($bloque['type'] ?? '') === 'tool_use' && ($bloque['input'] ?? null) === []) {
                    $mensajes[$m]['content'][$b]['input'] = new \stdClass;
                }
            }
        }

        return $mensajes;
    }

    /**
     * Aplica un evento SSE al mensaje que se está ensamblando.
     *
     * @param  array<string,mixed>  $evento
     * @param  array<int,array<string,mixed>>  $bloques
     * @param  array<int,string>  $parciales
     * @param  array<string,mixed>  $usage
     */
    private function acumular(array $evento, array &$bloques, array &$parciales, ?string &$stopReason, array &$usage): void
    {
        $tipo = $evento['type'] ?? '';
        $i = $evento['index'] ?? 0;

        switch ($tipo) {
            case 'error':
                throw new RuntimeException('Error de la API de Claude: '.($evento['error']['message'] ?? 'desconocido'));
            case 'message_start':
                $usage = $evento['message']['usage'] ?? [];
                break;

            case 'content_block_start':
                $bloques[$i] = $evento['content_block'] ?? [];
                if (($bloques[$i]['type'] ?? '') === 'tool_use') {
                    $parciales[$i] = '';
                }
                break;

            case 'content_block_delta':
                $delta = $evento['delta'] ?? [];
                match ($delta['type'] ?? '') {
                    'text_delta' => $bloques[$i]['text'] = ($bloques[$i]['text'] ?? '').$delta['text'],
                    'thinking_delta' => $bloques[$i]['thinking'] = ($bloques[$i]['thinking'] ?? '').$delta['thinking'],
                    'signature_delta' => $bloques[$i]['signature'] = ($bloques[$i]['signature'] ?? '').$delta['signature'],
                    'input_json_delta' => $parciales[$i] = ($parciales[$i] ?? '').$delta['partial_json'],
                    default => null,
                };
                break;

            case 'content_block_stop':
                if (($bloques[$i]['type'] ?? '') === 'tool_use') {
                    // El input llega troceado como JSON en texto: hay que
                    // decodificarlo, nunca compararlo como cadena.
                    $bloques[$i]['input'] = json_decode($parciales[$i] ?: '{}', true) ?: [];
                }
                break;

            case 'message_delta':
                $stopReason = $evento['delta']['stop_reason'] ?? $stopReason;
                $usage = array_merge($usage, $evento['usage'] ?? []);
                break;
        }
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

    /** Petición HTTP preconfigurada contra la API de Anthropic. */
    private function request(?int $timeout = null): PendingRequest
    {
        $cfg = config('ai.anthropic');

        if (empty($cfg['api_key'])) {
            throw new RuntimeException('ANTHROPIC_API_KEY no configurada.');
        }

        return Http::withHeaders([
            'x-api-key' => $cfg['api_key'],
            'anthropic-version' => $cfg['version'],
            'content-type' => 'application/json',
        ])->timeout($timeout ?? $cfg['timeout']);
    }

    private function endpoint(): string
    {
        return rtrim((string) config('ai.anthropic.base_url'), '/').'/v1/messages';
    }

    /** Traduce los errores más comunes de la API a algo accionable. */
    private function mensajeDeError(int $status, string $detalle): string
    {
        return match ($status) {
            401 => 'La API key de Claude no es válida (revisa ANTHROPIC_API_KEY).',
            429 => 'Se alcanzó el límite de peticiones a Claude; intenta de nuevo en un momento.',
            529 => 'La API de Claude está sobrecargada; intenta de nuevo en un momento.',
            default => "Error de la API de Claude ({$status}): {$detalle}",
        };
    }
}
