<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuración de IA (Anthropic Claude)
    |--------------------------------------------------------------------------
    |
    | Motor de generación de documentos SST/PESV/HSEQ con Claude y cerebro del
    | asistente conversacional (widget flotante).
    |
    | El modelo por defecto es Opus 5 (el más capaz para texto legal largo);
    | para alto volumen se puede cambiar a claude-sonnet-5 (costo-eficiente).
    */

    'provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-5'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 8000),
        // Timeout amplio: la generación de documentos largos puede tardar.
        'timeout' => (int) env('AI_TIMEOUT', 120),
    ],

    /*
    | Asistente conversacional (chat con herramientas).
    |
    | max_tokens es más alto que el de generación porque una sola respuesta del
    | chat puede incluir el documento completo redactado. Al ir en streaming no
    | hay riesgo de timeout HTTP con valores grandes.
    |
    | max_turnos limita el bucle de tool use: cada vuelta es una llamada a la
    | API, así que acota el costo de una pregunta que se descontrole.
    */
    'chat' => [
        'max_tokens' => (int) env('AI_CHAT_MAX_TOKENS', 32000),
        // Profundidad de razonamiento: low | medium | high | xhigh | max.
        'effort' => env('AI_CHAT_EFFORT', 'high'),
        'max_turnos' => (int) env('AI_CHAT_MAX_TURNOS', 10),
        // Segundos: una redacción larga con varias vueltas de herramientas.
        'timeout' => (int) env('AI_CHAT_TIMEOUT', 600),
        // Mensajes de historial que se reenvían en cada petición.
        'historial' => (int) env('AI_CHAT_HISTORIAL', 40),
    ],

];
