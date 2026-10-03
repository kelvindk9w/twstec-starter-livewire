<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;

// =============================================================================
// Idempotency-Key na API v1 do starter (twstec/kit-accounts + o middleware
// `idempotent` do twstec/kit-foundation) — ver docs/api.md, "Idempotência".
//
// Os critérios de aceite da issue #20 pela requisição de verdade: dois POST
// com a mesma chave criam UM projeto e recebem respostas idênticas; corpo
// diferente é recusado com código estável; a mesma chave em duas contas cria
// dois; a chave vencida executa de novo. A concorrência real (dois processos
// no PostgreSQL) está em IdempotencyConcurrencyTest.
// =============================================================================

it('dois POST com a mesma chave: um projeto e duas respostas idênticas', function (): void {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $headers = [...headersApi($key, $secret), 'Idempotency-Key' => 'pedido-2026-0001-'.str_repeat('a', 8)];

    $first = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers);
    $second = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers);

    $first->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $second->assertStatus($first->status())
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('X-Original-Correlation-Id', (string) $first->headers->get('X-Correlation-Id'));

    expect($second->getContent())->toBe($first->getContent())
        ->and(comoSistema(fn (): int => Project::query()->count()))->toBe(1);
});

it('mesma chave com corpo diferente: 422 idempotency_key_reused, sem criar', function (): void {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $headers = [...headersApi($key, $secret), 'Idempotency-Key' => 'pedido-2026-0002-'.str_repeat('b', 8)];

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)->assertCreated();

    assertErroApi($this->postJson('/api/v1/projects', ['name' => 'Faturas'], $headers), 422, 'idempotency_key_reused');

    expect(comoSistema(fn (): int => Project::query()->count()))->toBe(1);
});

it('a mesma chave em duas contas: duas escritas independentes', function (): void {
    $chave = 'pedido-2026-0003-'.str_repeat('c', 8);
    ['api_key' => $keyA, 'secret_key' => $secretA] = criarChave(User::factory()->create());
    ['api_key' => $keyB, 'secret_key' => $secretB] = criarChave(User::factory()->create());

    $a = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...headersApi($keyA, $secretA), 'Idempotency-Key' => $chave]);
    $b = $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], [...headersApi($keyB, $secretB), 'Idempotency-Key' => $chave]);

    $a->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $b->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect($a->json('data.uuid'))->not->toBe($b->json('data.uuid'))
        ->and(comoSistema(fn (): int => Project::query()->count()))->toBe(2);
});

it('chave vencida executa de novo', function (): void {
    $this->freezeSecond();
    $inicio = now()->toImmutable();

    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $headers = [...headersApi($key, $secret), 'Idempotency-Key' => 'pedido-2026-0004-'.str_repeat('d', 8)];

    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)->assertCreated();

    $this->travelTo($inicio->addHours((int) config('idempotency.ttl_hours'))->subSecond());
    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)->assertHeader('Idempotent-Replayed', 'true');

    $this->travelTo($inicio->addHours((int) config('idempotency.ttl_hours')));
    $this->postJson('/api/v1/projects', ['name' => 'Pedidos'], $headers)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(comoSistema(fn (): int => Project::query()->count()))->toBe(2);
});

it('criar chave de API pela API: a repetição não cria outra nem reexibe a secreta', function (): void {
    Mail::fake();
    config()->set('security.rate_limit.sensitive', 100);
    config()->set('auth.verification.resend_cooldown_seconds', 0);

    $user = User::factory()->withTransactionPassword()->create();
    ['api_key' => $boot, 'secret_key' => $bootSecret] = criarChave($user);
    $headers = [...headersApi($boot, $bootSecret), 'Idempotency-Key' => 'chave-2026-0005-'.str_repeat('e', 8)];

    $first = $this->postJson('/api/v1/api-keys', ['name' => 'Integração de faturas'], [...$headers, 'X-Sensitive-Action-Token' => tokenAcaoSensivel($user)]);
    $first->assertCreated();
    $secreta = (string) $first->json('secret_key');

    $replay = $this->postJson('/api/v1/api-keys', ['name' => 'Integração de faturas'], $headers);

    $replay->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.uuid', $first->json('data.uuid'))
        ->assertJsonPath('idempotency.body_withheld', true)
        ->assertJsonMissingPath('secret_key');

    expect($secreta)->not->toBe('')
        ->and($replay->getContent())->not->toContain($secreta)
        ->and(json_encode(DB::table(IdempotencyStore::TABLE)->get()))->not->toContain($secreta)
        ->and(comoSistema(fn (): int => ApiKey::query()->count()))->toBe(2);
});
