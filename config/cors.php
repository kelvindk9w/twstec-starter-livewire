<?php

declare(strict_types=1);

// =============================================================================
// CORS restritivo.
//
// Padrão seguro: NENHUMA origem permitida (CORS_ALLOWED_ORIGINS vazio) —
// a API é consumida server-to-server com chave no header. Liberar apenas as
// origens dos painéis quando eles existirem (ex.: https://app.exemplo.com).
// supports_credentials desligado: a API pública usa chave, não cookie.
// =============================================================================

return [

    'paths' => ['api/*'],

    'allowed_methods' => array_filter(explode(',', (string) env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'))),

    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Correlation-Id'],

    'exposed_headers' => ['X-Correlation-Id'],

    'max_age' => (int) env('CORS_MAX_AGE', 600),

    'supports_credentials' => false,

];
