<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;

// A CÓPIA DO CONFIG DO APLICATIVO (config/api_keys.php, que prevalece sobre a
// do pacote) também trata o pepper vazio como ausente. O .env.example trazia
// `API_KEYS_HASH_PEPPER=` sem valor — e o container de dev injeta exatamente
// isso —, o que não aciona o fallback do env(): o HMAC rodava com pepper de 0
// caracteres. A regra completa (peppers anteriores, legado, avisos) é provada
// na suíte do pacote twstec/kit-accounts, numa aplicação limpa.

/**
 * Lê o config/api_keys.php do aplicativo com as variáveis dadas no ambiente,
 * como o boot faria, e devolve o ambiente como estava.
 *
 * @param  array<string, string>  $vars
 * @return array<string, mixed>
 */
function appApiKeysConfigWith(array $vars): array
{
    $previous = [];

    foreach ($vars as $name => $value) {
        $previous[$name] = array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null;
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require config_path('api_keys.php');
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}

it('pepper vazio ou só com espaços no .env vira a APP_KEY na cópia do config do aplicativo', function (string $valor) {
    $config = appApiKeysConfigWith(['API_KEYS_HASH_PEPPER' => $valor]);

    expect($config['hash_pepper'])->toBe(env('APP_KEY'))
        ->and($config['hash_pepper'])->not->toBe('');
})->with([
    'vazio' => [''],
    'só espaços' => ['   '],
]);

it('pepper dedicado, peppers anteriores e a flag do legado vêm do .env na cópia do aplicativo', function () {
    $config = appApiKeysConfigWith([
        'API_KEYS_HASH_PEPPER' => 'pepper-dedicado-5e1b',
        'API_KEYS_PREVIOUS_HASH_PEPPERS' => 'anterior-1, ,anterior-2',
        'API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY' => 'true',
    ]);

    expect($config['hash_pepper'])->toBe('pepper-dedicado-5e1b')
        ->and($config['previous_peppers'])->toBe(['anterior-1', 'anterior-2'])
        ->and($config['accept_empty_pepper_legacy'])->toBeTrue();
});

it('com o pepper vazio vindo do ambiente, a chave criada no painel nasce com o hash da APP_KEY', function () {
    config()->set('api_keys.hash_pepper', '');

    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $hash = (string) comoSistema(fn () => ApiKey::query()->whereKey($key->getKey())->value('secret_hash'));

    expect($hash)->toBe(hash_hmac('sha256', $secret, (string) config('app.key')))
        ->and($hash)->not->toBe(hash_hmac('sha256', $secret, ''))
        ->and(app(ApiKeyHasher::class)->verify($secret, $hash))->toBeTrue();
});
