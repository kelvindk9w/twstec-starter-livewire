<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyGenerator;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;

// Geração do par pk_/sk_ e hash da secreta:
// aleatoriedade criptográfica, prefixo por ambiente, só hash no banco.

it('gera par com prefixos do ambiente configurado e parte aleatória segura', function () {
    config()->set('api_keys.environment', 'test');

    $gerador = new ApiKeyGenerator;

    ['public_key' => $pk, 'secret_key' => $sk] = $gerador->generatePair();

    expect($pk)->toMatch('/^pk_test_[A-Za-z0-9]{32}$/')
        ->and($sk)->toMatch('/^sk_test_[A-Za-z0-9]{48}$/');

    // Dois pares nunca se repetem (aleatoriedade criptográfica).
    $outro = $gerador->generatePair();

    expect($outro['public_key'])->not->toBe($pk)
        ->and($outro['secret_key'])->not->toBe($sk);
});

it('prefixa live em ambiente de produção', function () {
    config()->set('api_keys.environment', 'live');

    ['public_key' => $pk, 'secret_key' => $sk] = (new ApiKeyGenerator)->generatePair();

    expect($pk)->toStartWith('pk_live_')
        ->and($sk)->toStartWith('sk_live_');
});

it('o hash da secreta é HMAC com pepper, determinístico e verificável', function () {
    config()->set('api_keys.hash_pepper', 'pimenta-de-teste');

    $hasher = new ApiKeyHasher;
    $segredo = 'sk_test_'.str_repeat('a', 48);

    $hash = $hasher->hash($segredo);

    // HMAC-SHA256 com o pepper — nunca o segredo nem um SHA-256 puro.
    expect($hash)->toBe(hash_hmac('sha256', $segredo, 'pimenta-de-teste'))
        ->and($hash)->not->toBe($segredo)
        ->and($hash)->not->toBe(hash('sha256', $segredo))
        ->and($hasher->verify($segredo, $hash))->toBeTrue()
        ->and($hasher->verify($segredo.'x', $hash))->toBeFalse();

    // Pepper diferente = hash diferente (vazamento só do banco não basta).
    config()->set('api_keys.hash_pepper', 'outra-pimenta');
    expect((new ApiKeyHasher)->hash($segredo))->not->toBe($hash);
});

it('persiste somente o hash da secreta no banco (nunca plaintext)', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $registro = comoSistema(fn () => ApiKey::query()->sole());

    // Nenhuma coluna contém a secreta em claro.
    expect($registro->secret_hash)->not->toBe($secret)
        ->and($registro->secret_hash)->toBe(app(ApiKeyHasher::class)->hash($secret))
        ->and($registro->getAttributes())->not->toContain($secret)
        // O model também esconde o hash na serialização.
        ->and($key->toArray())->not->toHaveKey('secret_hash');
});

it('allows() resolve exatos, wildcards e escopos malformados', function () {
    $user = User::factory()->create();

    $checar = function (array $scopes, string $alvo) use ($user): bool {
        ['api_key' => $key] = criarChave($user, ['scopes' => $scopes]);

        return $key->allows($alvo);
    };

    expect($checar(['*:*'], 'qualquer:coisa'))->toBeTrue()
        ->and($checar(['customers:*'], 'customers:delete'))->toBeTrue()
        ->and($checar(['customers:read'], 'customers:read'))->toBeTrue()
        ->and($checar(['customers:read'], 'customers:write'))->toBeFalse()
        ->and($checar(['customers:read'], 'pix:create'))->toBeFalse()
        ->and($checar(['pix:create', 'customers:read'], 'customers:read'))->toBeTrue()
        ->and($checar([], 'customers:read'))->toBeFalse()
        // Escopo-alvo sem ação = ação curinga implícita (só casa com wildcards).
        ->and($checar(['customers:*'], 'customers'))->toBeTrue()
        ->and($checar(['customers:read'], 'customers'))->toBeFalse();
});
