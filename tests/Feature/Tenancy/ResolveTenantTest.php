<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// Middleware ResolveTenant: par pk_/sk_ no header → resolve o
// tenant (dono da chave), vincula o request log, atualiza last_used_at
// throttled. Credencial inválida = 401 + log SEM tenant (sinal de ataque).

beforeEach(function () {
    // Rota protegida somente pela tenancy (escopo livre).
    Route::get('/api/v1/_test/tenant', fn () => response()->json([
        'tenant_uuid' => tenant()?->uuid,
        'key_uuid' => tenantKey()?->uuid,
    ]))->middleware('resolve.tenant');

    // Rota protegida por tenancy + scope granular.
    Route::get('/api/v1/_test/customers', fn () => response()->json(['ok' => true]))
        ->middleware(['resolve.tenant', 'scope:customers:read']);
});

it('autentica com par pk_/sk_ válido e resolve o tenant no contexto', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))
        ->assertOk()
        ->assertJsonPath('tenant_uuid', $user->uuid)
        ->assertJsonPath('key_uuid', $key->uuid);
});

it('vincula o request log ao tenant quando a credencial é válida', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $response = $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret));

    $response->assertOk();

    $log = RequestLog::query()
        ->where('correlation_id', $response->headers->get('X-Correlation-Id'))
        ->sole();

    expect($log->tenant_uuid)->toBe($user->uuid)
        ->and($log->status)->toBe(RequestLogStatus::Concluida);
});

it('rejeita credenciais ausentes, secreta errada ou pk_ inexistente com 401 e log SEM tenant', function (array $headers) {
    $response = $this->getJson('/api/v1/_test/tenant', $headers);

    $response->assertUnauthorized()
        ->assertJsonPath('error.message', __('api_keys.auth.invalid'));

    // Log SEM tenant = sinal de possível ataque/tentativa de burla.
    $log = RequestLog::query()
        ->where('correlation_id', $response->headers->get('X-Correlation-Id'))
        ->sole();

    expect($log->tenant_uuid)->toBeNull()
        ->and($log->http_status_response)->toBe(401);
})->with([
    'sem header algum' => [fn (): array => []],
    'só a pública' => [function (): array {
        return ['X-Api-Key' => criarChave(User::factory()->create())['api_key']->public_key];
    }],
    'secreta errada' => [function (): array {
        ['api_key' => $key] = criarChave(User::factory()->create());

        return ['X-Api-Key' => $key->public_key, 'Authorization' => 'Bearer sk_test_secretaerrada'];
    }],
    'pk_ inexistente' => [['X-Api-Key' => 'pk_test_inexistente', 'Authorization' => 'Bearer sk_test_qualquer']],
]);

it('rejeita chave revogada, expirada por data ou com usuário bloqueado/pendente', function (string $cenario) {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    match ($cenario) {
        'revogada' => $key->forceFill(['status' => ApiKeyStatus::Revoked])->save(),
        'expirada' => $key->forceFill(['expires_at' => now()->subMinute()])->save(),
        'usuario bloqueado' => $user->forceFill(['status' => UserStatus::Blocked])->save(),
        // Pendente = bloqueada (deny-by-default: só Active opera, igual ao
        // login e ao painel web — ver EnsureAccountIsActive).
        'usuario pendente' => $user->forceFill(['status' => UserStatus::Pending])->save(),
    };

    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))
        ->assertUnauthorized()
        ->assertJsonPath('error.message', __('api_keys.auth.invalid'));
})->with(['revogada', 'expirada', 'usuario bloqueado', 'usuario pendente']);

it('rejeita chave inativa além do limite configurado (middleware checa inatividade)', function () {
    config()->set('api_keys.inactivity.months', 3);

    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    // Simula chave criada há 4 meses, nunca usada.
    comoSistema(fn () => ApiKey::query()->where('id', $key->id)->update(['created_at' => now()->subMonthsNoOverflow(4)]));

    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))->assertUnauthorized();

    // Dentro do limite: 2 meses → autentica normalmente.
    comoSistema(fn () => ApiKey::query()->where('id', $key->id)->update(['created_at' => now()->subMonthsNoOverflow(2)]));

    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))->assertOk();
});

it('autoriza com scope exato e com wildcards', function (string $scopes, int $status) {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user, ['scopes' => [$scopes]]);

    $response = $this->getJson('/api/v1/_test/customers', headersApi($key, $secret));

    expect($response->status())->toBe($status);
})->with([
    'scope exato' => ['customers:read', 200],
    'wildcard do recurso' => ['customers:*', 200],
    'wildcard total (padrão)' => ['*:*', 200],
    'ação diferente' => ['customers:create', 403],
    'recurso diferente' => ['pix:create', 403],
]);

it('nega scope ausente com 403 e mensagem indicando o scope exigido', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user, ['scopes' => ['pix:create']]);

    $this->getJson('/api/v1/_test/customers', headersApi($key, $secret))
        ->assertForbidden()
        ->assertJsonPath('error.message', __('api_keys.scopes.denied', ['scope' => 'customers:read']));
});

it('atualiza o last_used_at de forma throttled (no máximo 1x por janela)', function () {
    config()->set('api_keys.last_used_throttle_seconds', 60);

    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    expect($key->last_used_at)->toBeNull();

    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))->assertOk();

    $primeiroUso = $key->refresh()->last_used_at;
    expect($primeiroUso)->not->toBeNull();

    // Segunda chamada DENTRO da janela: nenhuma nova escrita.
    $this->travel(30)->seconds();
    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))->assertOk();

    expect($key->refresh()->last_used_at->equalTo($primeiroUso))->toBeTrue();

    // Depois da janela: atualiza de novo.
    $this->travel(31)->seconds();
    $this->getJson('/api/v1/_test/tenant', headersApi($key, $secret))->assertOk();

    expect($key->refresh()->last_used_at->gt($primeiroUso))->toBeTrue();
});

it('a verificação da secreta usa comparação timing-safe (hash_equals) — estrutural', function () {
    // Garantia estrutural: a verificação da sk_ passa por
    // hash_equals (nunca ===), e o fallback de pk_ inexistente também compara
    // (não vaza por tempo se a chave pública existe).
    $hasher = file_get_contents(base_path('vendor/twstec/kit-accounts/src/ApiKeys/Support/ApiKeyHasher.php'));
    $middleware = file_get_contents(base_path('vendor/twstec/kit-accounts/src/Tenancy/Middleware/ResolveTenant.php'));

    expect($hasher)->toContain('hash_equals')
        ->and($middleware)->toContain('hash_hmac'); // hash fictício p/ pk_ inválida
});

it('isola tenants: a chave de um usuário nunca resolve outro usuário', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    ['api_key' => $keyA, 'secret_key' => $secretA] = criarChave($userA);
    ['api_key' => $keyB] = criarChave($userB);

    // pk_ de A com sk_ de B (troca cruzada) → 401.
    $this->getJson('/api/v1/_test/tenant', [
        'X-Api-Key' => $keyA->public_key,
        'Authorization' => 'Bearer '.criarChave($userB)['secret_key'],
    ])->assertUnauthorized();

    // A chave de A resolve A, nunca B.
    $this->getJson('/api/v1/_test/tenant', headersApi($keyA, $secretA))
        ->assertOk()
        ->assertJsonPath('tenant_uuid', $userA->uuid)
        ->assertJsonMissing(['tenant_uuid' => $userB->uuid]);
});
