<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Rate limiting: global na API + estrito em rotas
// sensíveis. Valores via config/security.php (ajustáveis por .env).

it('aplica o limite global da API e responde 429 após o limite', function () {
    config()->set('security.rate_limit.api', 3);

    for ($i = 0; $i < 3; $i++) {
        $this->get('/api/health')->assertOk();
    }

    $response = $this->get('/api/health');

    $response->assertTooManyRequests();
});

it('aplica limite mais estrito em rotas sensíveis (login, códigos 2FA)', function () {
    config()->set('security.rate_limit.sensitive', 2);

    Route::post('/api/_test/sensivel', fn () => response()->json(['ok' => true]))
        ->middleware('throttle:sensitive');

    $this->postJson('/api/_test/sensivel', [])->assertOk();
    $this->postJson('/api/_test/sensivel', [])->assertOk();

    $response = $this->postJson('/api/_test/sensivel', []);

    $response->assertTooManyRequests();
});

it('isola o limite por IP (a chave do limiter inclui o IP)', function () {
    config()->set('security.rate_limit.api', 1);

    $this->get('/api/health')->assertOk();
    $this->get('/api/health')->assertTooManyRequests();

    // Outro "cliente" (IP diferente) não é afetado.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('/api/health')
        ->assertOk();
});
