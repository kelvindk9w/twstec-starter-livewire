<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;

// Endpoints do motor de chaves da API v1: criar (ação
// sensível), listar, revogar, rotacionar (ação sensível + grace period)
// e vínculo N:N com projetos. Isolamento de tenant em tudo.

beforeEach(function () {
    Mail::fake();

    // As chamadas aos endpoints web do fluxo de ação sensível passam pelo
    // throttle HTTP (5/min); o que está sob teste aqui é o motor de chaves.
    config()->set('security.rate_limit.sensitive', 100);
});

/**
 * Usuário com senha de transação + chave bootstrap (full access) autenticável.
 *
 * @return array{user: User, api_key: ApiKey, secret_key: string}
 */
function tenantBootstrap(): array
{
    $user = User::factory()->withTransactionPassword()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    return ['user' => $user, 'api_key' => $key, 'secret_key' => $secret];
}

it('cria chave exigindo ação sensível: sem o token, 403', function () {
    ['api_key' => $key, 'secret_key' => $secret] = tenantBootstrap();

    assertErroApi(
        $this->postJson('/api/v1/api-keys', ['name' => 'Minha chave'], headersApi($key, $secret)),
        403,
        'forbidden',
    )->assertJsonPath('error.message', __('auth.sensitive_action.invalid_token'));

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(1); // só a bootstrap
});

it('cria chave com token de ação sensível e exibe a secreta UMA única vez', function () {
    ['user' => $user, 'api_key' => $bootKey, 'secret_key' => $bootSecret] = tenantBootstrap();

    $token = tokenAcaoSensivel($user);

    $response = $this->postJson('/api/v1/api-keys', [
        'name' => 'Integração ERP',
    ], [...headersApi($bootKey, $bootSecret), 'X-Sensitive-Action-Token' => $token]);

    $response->assertCreated()
        ->assertJsonPath('message', __('api_keys.keys.created'))
        ->assertJsonPath('data.name', 'Integração ERP')
        ->assertJsonPath('data.status', ApiKeyStatus::Active->value)
        ->assertJsonPath('data.scopes', ['*:*']) // padrão: tudo habilitado
        ->assertJsonPath('data.expires_at', null)
        ->assertJsonStructure(['data' => ['uuid', 'codigo_publico', 'public_key'], 'secret_key']);

    $publicKey = $response->json('data.public_key');
    $secretKey = $response->json('secret_key');
    $ambiente = config('api_keys.environment');

    expect($publicKey)->toStartWith("pk_{$ambiente}_")
        ->and($secretKey)->toStartWith("sk_{$ambiente}_")
        ->and($response->json('data.codigo_publico'))->toStartWith('KEY-')
        // A secreta NUNCA vai para o banco — só o hash HMAC.
        ->and(app(ApiKeyHasher::class)->verify($secretKey, comoSistema(fn () => ApiKey::query()->where('public_key', $publicKey)->sole())->secret_hash))->toBeTrue();

    expect(comoSistema(fn () => ApiKey::query()->where('public_key', $publicKey)->sole())->secret_hash)->not->toBe($secretKey);

    // O token de ação sensível é de USO ÚNICO: segunda criação com o mesmo
    // token é negada.
    $this->postJson('/api/v1/api-keys', ['name' => 'Outra'], [...headersApi($bootKey, $bootSecret), 'X-Sensitive-Action-Token' => $token])
        ->assertForbidden();
});

it('cria chave com scopes restritos e validade definida pelo usuário', function () {
    ['user' => $user, 'api_key' => $bootKey, 'secret_key' => $bootSecret] = tenantBootstrap();

    $token = tokenAcaoSensivel($user);
    $expira = now()->addDays(30)->startOfSecond();

    $response = $this->postJson('/api/v1/api-keys', [
        'name' => 'Só leitura de clientes',
        'scopes' => ['customers:read'],
        'expires_at' => $expira->toIso8601String(),
    ], [...headersApi($bootKey, $bootSecret), 'X-Sensitive-Action-Token' => $token]);

    $response->assertCreated()
        ->assertJsonPath('data.scopes', ['customers:read']);

    $chave = comoSistema(fn () => ApiKey::query()->where('public_key', $response->json('data.public_key'))->sole());

    expect($chave->allows('customers:read'))->toBeTrue()
        ->and($chave->allows('customers:create'))->toBeFalse()
        ->and($chave->allows('pix:create'))->toBeFalse()
        ->and($chave->expires_at->equalTo($expira))->toBeTrue();
});

it('rejeita scopes em formato inválido na criação', function () {
    ['user' => $user, 'api_key' => $bootKey, 'secret_key' => $bootSecret] = tenantBootstrap();

    assertErroDeValidacaoApi(
        $this->postJson('/api/v1/api-keys', [
            'name' => 'X',
            'scopes' => ['sem-dois-pontos'],
        ], [...headersApi($bootKey, $bootSecret), 'X-Sensitive-Action-Token' => tokenAcaoSensivel($user)]),
        'scopes.0',
    );
});

it('lista somente as chaves do tenant autenticado (isolamento)', function () {
    ['user' => $userA, 'api_key' => $keyA, 'secret_key' => $secretA] = tenantBootstrap();
    $userB = User::factory()->create();
    criarChave($userA, ['name' => 'Segunda de A']);
    criarChave($userB, ['name' => 'Chave de B']);

    $response = $this->getJson('/api/v1/api-keys', headersApi($keyA, $secretA));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $nomes = collect($response->json('data'))->pluck('name');

    expect($nomes)->toContain('Segunda de A')
        ->and($nomes)->not->toContain('Chave de B');
});

it('exige scope api-keys:read para listar', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user, ['scopes' => ['projects:read']]);

    assertErroApi($this->getJson('/api/v1/api-keys', headersApi($key, $secret)), 403, 'forbidden')
        ->assertJsonPath('error.message', __('api_keys.scopes.denied', ['scope' => 'api-keys:read']));
});

it('revoga a própria chave e ela para de autenticar imediatamente', function () {
    ['api_key' => $bootKey, 'secret_key' => $bootSecret] = tenantBootstrap();

    // Alvo da revogação: a própria bootstrap (chave revoga a si mesma).
    $this->deleteJson("/api/v1/api-keys/{$bootKey->uuid}", [], headersApi($bootKey, $bootSecret))
        ->assertOk()
        ->assertJsonPath('message', __('api_keys.keys.revoked'))
        ->assertJsonPath('data.status', ApiKeyStatus::Revoked->value);

    expect($bootKey->refresh()->status)->toBe(ApiKeyStatus::Revoked);

    $this->getJson('/api/v1/api-keys', headersApi($bootKey, $bootSecret))->assertUnauthorized();
});

it('rotaciona SEM grace period: a antiga morre na hora e a nova funciona', function () {
    ['user' => $user, 'api_key' => $antiga, 'secret_key' => $segredoAntigo] = tenantBootstrap();

    $response = $this->postJson("/api/v1/api-keys/{$antiga->uuid}/rotate", [], [
        ...headersApi($antiga, $segredoAntigo),
        'X-Sensitive-Action-Token' => tokenAcaoSensivel($user),
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', __('api_keys.keys.rotated'))
        ->assertJsonStructure(['secret_key']);

    $novaSegredo = $response->json('secret_key');
    $nova = comoSistema(fn () => ApiKey::query()->where('public_key', $response->json('data.public_key'))->sole());

    // Encadeamento da rotação + morte imediata da antiga.
    expect($antiga->refresh()->status)->toBe(ApiKeyStatus::Rotated)
        ->and($antiga->rotated_to_id)->toBe($nova->id)
        ->and($nova->rotated_from_id)->toBe($antiga->id)
        ->and($antiga->grace_ends_at)->not->toBeNull()
        ->and($novaSegredo)->not->toBe($segredoAntigo);

    // Antiga NÃO autentica mais; nova autentica.
    $this->getJson('/api/v1/api-keys', headersApi($antiga, $segredoAntigo))->assertUnauthorized();
    $this->getJson('/api/v1/api-keys', headersApi($nova, $novaSegredo))->assertOk();
});

it('rotaciona COM grace period: antiga convive até o fim da janela escolhida', function () {
    ['user' => $user, 'api_key' => $antiga, 'secret_key' => $segredoAntigo] = tenantBootstrap();

    $response = $this->postJson("/api/v1/api-keys/{$antiga->uuid}/rotate", [
        'grace_period_minutes' => 60,
    ], [
        ...headersApi($antiga, $segredoAntigo),
        'X-Sensitive-Action-Token' => tokenAcaoSensivel($user),
    ]);

    $response->assertCreated();

    $novaSegredo = $response->json('secret_key');
    $nova = comoSistema(fn () => ApiKey::query()->where('public_key', $response->json('data.public_key'))->sole());

    // Antiga segue ATIVA durante o grace (sem downtime na troca).
    expect($antiga->refresh()->status)->toBe(ApiKeyStatus::Active)
        ->and($antiga->grace_ends_at->isFuture())->toBeTrue();

    $this->getJson('/api/v1/api-keys', headersApi($antiga, $segredoAntigo))->assertOk();
    $this->getJson('/api/v1/api-keys', headersApi($nova, $novaSegredo))->assertOk();

    // Grace encerrado: a antiga morre, a nova segue.
    $this->travel(61)->minutes();

    $this->getJson('/api/v1/api-keys', headersApi($antiga, $segredoAntigo))->assertUnauthorized();
    $this->getJson('/api/v1/api-keys', headersApi($nova, $novaSegredo))->assertOk();
});

it('exige ação sensível para rotacionar', function () {
    ['api_key' => $key, 'secret_key' => $secret] = tenantBootstrap();

    $this->postJson("/api/v1/api-keys/{$key->uuid}/rotate", [], headersApi($key, $secret))
        ->assertForbidden();

    expect(comoSistema(fn () => ApiKey::query()->count()))->toBe(1);
});

it('rotação herda scopes e projetos da chave antiga', function () {
    ['user' => $user] = tenantBootstrap();

    $projeto = projetoDe($user, 'Loja A');

    // A chave restrita precisa do scope api-keys:rotate para se autorrotacionar
    // (rotacionar a SI MESMA é permitido à chave vinculada; rotacionar outra
    // chave, não — ApiKeyProjectBindingTest).
    ['api_key' => $antiga, 'secret_key' => $segredoAntigo] = criarChave($user, [
        'scopes' => ['pix:create', 'api-keys:rotate'],
        'project_uuids' => [$projeto->uuid],
    ]);

    $response = $this->postJson("/api/v1/api-keys/{$antiga->uuid}/rotate", [], [
        ...headersApi($antiga, $segredoAntigo),
        'X-Sensitive-Action-Token' => tokenAcaoSensivel($user),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.scopes', ['pix:create', 'api-keys:rotate']);

    $nova = comoSistema(fn () => ApiKey::query()->where('public_key', $response->json('data.public_key'))->sole());

    expect(comoSistema(fn () => $nova->projects()->pluck('projects.id')->all()))->toBe([$projeto->id])
        ->and($nova->isRestrictedToProjects())->toBeTrue();
});

it('vincula e desvincula projetos da chave (N:N) somente dentro do tenant', function () {
    // Quem vincula é a chave de CONTA ($gestora); a vinculada é outra ($key).
    // (Uma chave vinculada não gerencia chaves — ApiKeyProjectBindingTest.)
    ['user' => $user, 'api_key' => $gestora, 'secret_key' => $secret] = tenantBootstrap();
    $key = criarChave($user)['api_key'];

    $projetoA = projetoDe($user, 'Loja A');
    $projetoB = projetoDe($user, 'Loja B');
    $projetoAlheio = projetoDe(User::factory()->create(), 'Alheio');

    // Vincula dois projetos do tenant.
    $this->putJson("/api/v1/api-keys/{$key->uuid}/projects", [
        'project_uuids' => [$projetoA->uuid, $projetoB->uuid],
    ], headersApi($gestora, $secret))
        ->assertOk()
        ->assertJsonPath('message', __('api_keys.keys.projects_synced'));

    expect(comoSistema(fn () => $key->refresh()->projects()->pluck('projects.id')->all()))
        ->toEqualCanonicalizing([$projetoA->id, $projetoB->id]);

    // Projeto de OUTRO tenant: 422, sem vazar existência.
    assertErroDeValidacaoApi(
        $this->putJson("/api/v1/api-keys/{$key->uuid}/projects", [
            'project_uuids' => [$projetoAlheio->uuid],
        ], headersApi($gestora, $secret)),
        'project_uuids',
    );

    // Lista vazia = sem vínculo (a chave volta a enxergar a conta toda).
    $this->putJson("/api/v1/api-keys/{$key->uuid}/projects", [
        'project_uuids' => [],
    ], headersApi($gestora, $secret))->assertOk();

    expect(comoSistema(fn () => $key->refresh()->projects()->count()))->toBe(0)
        ->and($key->isRestrictedToProjects())->toBeFalse();
});

it('não encontra chave de outro tenant para revogar/rotacionar (404 uniforme)', function () {
    ['user' => $userA, 'api_key' => $keyA, 'secret_key' => $secretA] = tenantBootstrap();
    $userB = User::factory()->create();
    ['api_key' => $keyB] = criarChave($userB);

    $this->deleteJson("/api/v1/api-keys/{$keyB->uuid}", [], headersApi($keyA, $secretA))
        ->assertNotFound();

    $this->postJson("/api/v1/api-keys/{$keyB->uuid}/rotate", [], [
        ...headersApi($keyA, $secretA),
        'X-Sensitive-Action-Token' => tokenAcaoSensivel($userA),
    ])->assertNotFound();

    // A chave de B segue intacta.
    expect($keyB->refresh()->status)->toBe(ApiKeyStatus::Active);
});

it('não rotaciona chave já revogada', function () {
    ['user' => $user, 'api_key' => $alvo, 'secret_key' => $segredoAlvo] = tenantBootstrap();
    ['api_key' => $boot, 'secret_key' => $segredoBoot] = criarChave($user);

    $alvo->forceFill(['status' => ApiKeyStatus::Revoked])->save();

    $this->postJson("/api/v1/api-keys/{$alvo->uuid}/rotate", [], [
        ...headersApi($boot, $segredoBoot),
        'X-Sensitive-Action-Token' => tokenAcaoSensivel($user),
    ])->assertStatus(422)
        ->assertJsonPath('error.message', __('api_keys.keys.not_rotatable'));
});
