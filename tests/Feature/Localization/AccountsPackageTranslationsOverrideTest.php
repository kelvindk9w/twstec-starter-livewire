<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

// =============================================================================
// No starter, o lang/ do APLICATIVO vence o do pacote twstec/kit-accounts — a
// mesma regra do foundation e do auth (Localization\PackageTranslations).
//
// As mensagens da API (chave inválida, escopo negado, projeto, chave
// vinculada) e o assunto do aviso de inatividade vêm do pacote; o corpo do
// aviso continua no lang/ do starter. Quem usa o kit troca qualquer um deles
// só editando o lang/ do aplicativo. A prova usa uma CÓPIA do lang/ do
// starter, para não tocar nos arquivos de verdade.
// =============================================================================

/**
 * @param  array<string, array<string, mixed>>  $overrides  'pt_BR/api_keys.php' => chaves a sobrepor
 */
function starterLangWithAccounts(array $overrides): string
{
    $dir = sys_get_temp_dir().'/starter-accounts-lang-'.uniqid();

    (new Filesystem)->copyDirectory(lang_path(), $dir);

    foreach ($overrides as $relative => $values) {
        $path = $dir.'/'.$relative;
        $current = is_file($path) ? require $path : [];

        file_put_contents($path, '<?php return '.var_export(array_replace_recursive($current, $values), true).';');
    }

    app()->useLangPath($dir);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    return $dir;
}

function accountsPackageLangFile(string $relative): array
{
    return require base_path('vendor/twstec/kit-accounts/lang/'.$relative);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/starter-accounts-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) mensagem que só o pacote tem: texto do pacote', function (string $locale): void {
    starterLangWithAccounts([]);

    expect(__('api_keys.auth.invalid', [], $locale))->toBe(accountsPackageLangFile("{$locale}/api_keys.php")['auth']['invalid'])
        ->and(__('api_keys.projects.account_key_required', [], $locale))->toBe(accountsPackageLangFile("{$locale}/api_keys.php")['projects']['account_key_required'])
        ->and(__('mail.api_key_inactivity.subject', [], $locale))->toBe(accountsPackageLangFile("{$locale}/mail.php")['api_key_inactivity']['subject']);
})->with(['pt_BR', 'en', 'es']);

it('(b) a mesma chave no lang/ do aplicativo: texto do aplicativo — inclusive na resposta da API', function (string $locale): void {
    starterLangWithAccounts([
        "{$locale}/api_keys.php" => [
            'auth' => ['invalid' => "Chave recusada pelo meu produto ({$locale})"],
            'scopes' => ['denied' => "Escopo :scope negado pelo meu produto ({$locale})"],
        ],
        "{$locale}/mail.php" => ['api_key_inactivity' => ['subject' => "Assunto do meu produto ({$locale})"]],
    ]);

    app()->setLocale($locale);

    expect(__('api_keys.auth.invalid', [], $locale))->toBe("Chave recusada pelo meu produto ({$locale})")
        ->and(__('api_keys.scopes.denied', ['scope' => 'a:b'], $locale))->toBe("Escopo a:b negado pelo meu produto ({$locale})")
        ->and(__('mail.api_key_inactivity.subject', [], $locale))->toBe("Assunto do meu produto ({$locale})");

    $this->getJson('/api/v1/projects')
        ->assertUnauthorized()
        ->assertJsonPath('error.message', "Chave recusada pelo meu produto ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) mail.php repartido: assunto do pacote, corpo do aplicativo e rodapé do foundation no mesmo grupo', function (string $locale): void {
    $appMail = require lang_path("{$locale}/mail.php");

    starterLangWithAccounts(["{$locale}/api_keys.php" => ['keys' => ['revoked' => "Revogada no meu produto ({$locale})"]]]);

    expect(__('api_keys.keys.revoked', [], $locale))->toBe("Revogada no meu produto ({$locale})")
        ->and(__('api_keys.keys.rotated', [], $locale))->toBe(accountsPackageLangFile("{$locale}/api_keys.php")['keys']['rotated'])
        ->and(__('mail.api_key_inactivity.subject', [], $locale))->toBe(accountsPackageLangFile("{$locale}/mail.php")['api_key_inactivity']['subject'])
        ->and(__('mail.api_key_inactivity.heading', [], $locale))->toBe($appMail['api_key_inactivity']['heading'])
        ->and(__('mail.footer.transactional', [], $locale))->toBe((require base_path('vendor/twstec/kit-foundation/lang/'.$locale.'/mail.php'))['footer']['transactional']);
})->with(['pt_BR', 'en', 'es']);
