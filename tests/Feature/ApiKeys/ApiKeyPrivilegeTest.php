<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// Sem escalada de privilégio pela API v1 (twstec/kit-accounts — a regra mora
// no ApiKeyService e é coberta inteira na suíte do pacote): aqui, pela
// requisição de verdade no aplicativo, uma chave não cria nem rotaciona chave
// mais ampla que ela, e `scopes` omitido herda os dela.
// =============================================================================

function privilegioToken(User $user): string
{
    $token = 'token-privilegio-'.bin2hex(random_bytes(16));

    DB::table('sensitive_action_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $token;
}

it('chave com escopos restritos: subconjunto cria; superconjunto e *:* são 403 api_key_scope_exceeded; omitido herda', function (): void {
    $user = User::factory()->withTransactionPassword()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user, ['scopes' => ['api-keys:create', 'api-keys:rotate', 'orders:*']]);
    $headers = fn (): array => ['X-Api-Key' => $key->public_key, 'Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json', 'X-Sensitive-Action-Token' => privilegioToken($user)];

    $this->postJson('/api/v1/api-keys', ['name' => 'Menor', 'scopes' => ['orders:create']], $headers())
        ->assertCreated()
        ->assertJsonPath('data.scopes', ['orders:create']);

    foreach ([['customers:read'], ['*:*'], ['orders:*', 'api-keys:revoke']] as $scopes) {
        $this->postJson('/api/v1/api-keys', ['name' => 'Maior', 'scopes' => $scopes], $headers())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'api_key_scope_exceeded');
    }

    $this->postJson('/api/v1/api-keys', ['name' => 'Herdada'], $headers())
        ->assertCreated()
        ->assertJsonPath('data.scopes', ['api-keys:create', 'api-keys:rotate', 'orders:*']);

    ['api_key' => $ampla] = criarChave($user, ['scopes' => ['*:*']]);

    $this->postJson("/api/v1/api-keys/{$ampla->uuid}/rotate", [], $headers())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'api_key_scope_exceeded')
        ->assertJsonMissingPath('secret_key');

    expect(comoSistema(fn (): int => ApiKey::query()->count()))->toBe(4)
        ->and(AuditEvent::query()->where('action', 'api_key.privilege_exceeded')->where('outcome', 'denied')->count())->toBe(4);
});
