<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuración de IA (Anthropic Claude)
    |--------------------------------------------------------------------------
    |
    | Motor de generación de documentos SST/PESV/HSEQ con Claude.
    | El modelo por defecto es Opus 4.8 (el más capaz para texto legal largo);
    | para alto volumen se puede cambiar a claude-sonnet-5 (costo-eficiente).
    */

    'provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-4-8'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 8000),
        // Timeout amplio: la generación de documentos largos puede tardar.
        'timeout' => (int) env('AI_TIMEOUT', 120),
    ],

];
