<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Foundation\Logging\CorrelationId;

// =============================================================================
// Bug de QA #4 — envelope padronizado de ERRO da API (`api/*`).
//
// Contrato (docs/api.md, "Contrato de resposta da API"):
//   {"error": {"code": "…", "message": "…", "correlation_id": "…"}}
//   422 acrescenta "errors" (campo → lista de mensagens).
//
// Nunca stack trace, nunca caminho de servidor — nem com APP_DEBUG=true.
// =============================================================================

/**
 * Asserções comuns a QUALQUER erro da API.
 */
function assertEnvelopeDeErro(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']])
        ->assertJsonPath('error.code', $code);

    $body = $response->getContent();

    expect($body)->not->toContain('trace')
        ->and($body)->not->toContain('/var/www')
        ->and($body)->not->toContain(base_path())
        ->and($body)->not->toContain('vendor/laravel');

    expect(Str::isUuid($response->json('error.correlation_id')))->toBeTrue();
    expect($response->headers->get(CorrelationId::HEADER))
        ->toBe($response->json('error.correlation_id'));
}

it('401 sem credenciais de API', function () {
    assertEnvelopeDeErro($this->getJson('/api/v1/projects'), 401, 'unauthorized');
});

it('403 quando a chave não tem o scope exigido', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user, ['scopes' => ['projects:read']]);

    $response = $this->postJson('/api/v1/projects', ['name' => 'X'], headersApi($key, $secret));

    assertEnvelopeDeErro($response, 403, 'forbidden');
});

it('404 em recurso inexistente (uuid uniforme, sem vazar existência)', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $response = $this->getJson('/api/v1/projects/'.Str::uuid7(), headersApi($key, $secret));

    assertEnvelopeDeErro($response, 404, 'not_found');
});

it('404 em endpoint inexistente da API', function () {
    assertEnvelopeDeErro($this->getJson('/api/v1/nao-existe'), 404, 'not_found');
});

it('422 traz os erros por campo dentro do envelope', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $response = $this->postJson('/api/v1/projects', ['name' => ''], headersApi($key, $secret));

    assertEnvelopeDeErro($response, 422, 'validation_failed');

    $response->assertJsonStructure(['error' => ['errors' => ['name']]]);

    expect($response->json('error.errors.name'))->toBeArray()->not->toBeEmpty();
});

it('429 quando o rate limit da API estoura', function () {
    config()->set('security.rate_limit.api', 2);

    $this->getJson('/api/health');
    $this->getJson('/api/health');

    assertEnvelopeDeErro($this->getJson('/api/health'), 429, 'too_many_requests');
});

it('500 devolve mensagem genérica e NÃO vaza a exceção, mesmo com APP_DEBUG=true', function () {
    config()->set('app.debug', true);

    Route::get('/api/explode-teste', function (): never {
        throw new RuntimeException('SEGREDO: senha do banco em /var/www/html/.env');
    });

    $this->getJson('/api/explode-teste')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonPath('error.message', __('api.errors.server_error'))
        ->assertDontSee('SEGREDO', escape: false)
        ->assertDontSee('RuntimeException', escape: false);
});

it('o envelope de sucesso continua sendo {"data": …}', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonStructure(['data' => ['status', 'version', 'correlation_id']]);
});
