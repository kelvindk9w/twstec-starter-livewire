<?php

declare(strict_types=1);

// =============================================================================
// Hash de senhas.
//
// Driver padrão: Argon2id — o mais forte disponível no PHP (vencedor do
// Password Hashing Competition; resistente a GPU/ASIC por consumo de memória).
// Aplicado à senha de LOGIN e à senha de TRANSAÇÃO (hashes separados).
// `rehash_on_login` permite upgrade gradual de hashes antigos (bcrypt → argon2id).
//
// Todos os parâmetros são ajustáveis por .env — NUNCA hardcodar.
// Em testes, o phpunit.xml reduz ARGON_MEMORY/ARGON_TIME para velocidade,
// mantendo o MESMO algoritmo.
// =============================================================================

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    // Parâmetros Argon2id — mínimos OWASP: memory >= 19 MiB, time >= 2.
    // Os defaults abaixo (64 MiB / 4 iterações / 1 thread) seguem o RFC 9106.
    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => true,

];
