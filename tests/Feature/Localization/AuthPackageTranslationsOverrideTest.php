<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

// =============================================================================
// No starter, o lang/ do APLICATIVO vence o do pacote twstec/kit-auth — a
// mesma regra do foundation (Localization\PackageTranslations).
//
// As mensagens do domínio de autenticação (recusa de login, bloqueio, código
// do segundo fator, assuntos dos e-mails) vêm do pacote; os textos das telas
// continuam no lang/ do starter. Quem usa o kit troca qualquer um deles só
// editando o lang/ do aplicativo. A prova usa uma CÓPIA do lang/ do starter,
// para não tocar nos arquivos de verdade.
// =============================================================================

/**
 * @param  array<string, array<string, mixed>>  $overrides  'pt_BR/auth.php' => chaves a sobrepor
 */
function starterLangWithAuth(array $overrides): string
{
    $dir = sys_get_temp_dir().'/starter-auth-lang-'.uniqid();

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

function authPackageLangFile(string $relative): array
{
    return require base_path('vendor/twstec/kit-auth/lang/'.$relative);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/starter-auth-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) mensagem que só o pacote tem: texto do pacote', function (string $locale): void {
    starterLangWithAuth([]);

    expect(__('auth.account_inactive', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['account_inactive'])
        ->and(__('auth.two_factor.invalid', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['two_factor']['invalid'])
        ->and(__('mail.login_code.subject', [], $locale))->toBe(authPackageLangFile("{$locale}/mail.php")['login_code']['subject']);
})->with(['pt_BR', 'en', 'es']);

it('(b) a mesma chave no lang/ do aplicativo: texto do aplicativo', function (string $locale): void {
    starterLangWithAuth([
        "{$locale}/auth.php" => [
            'failed' => "Credenciais do meu produto ({$locale})",
            'two_factor' => ['locked' => "Bloqueio do meu produto ({$locale})"],
        ],
        "{$locale}/mail.php" => ['password_reset' => ['subject' => "Assunto do meu produto ({$locale})"]],
    ]);

    expect(__('auth.failed', [], $locale))->toBe("Credenciais do meu produto ({$locale})")
        ->and(__('auth.two_factor.locked', [], $locale))->toBe("Bloqueio do meu produto ({$locale})")
        ->and(__('mail.password_reset.subject', [], $locale))->toBe("Assunto do meu produto ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) auth.php e mail.php repartidos: tela do aplicativo, mensagem do pacote, no mesmo grupo', function (string $locale): void {
    $appAuth = require lang_path("{$locale}/auth.php");
    $appMail = require lang_path("{$locale}/mail.php");

    starterLangWithAuth(["{$locale}/auth.php" => ['two_factor' => ['cancelled' => "Cancelado no meu produto ({$locale})"]]]);

    expect(__('auth.two_factor.cancelled', [], $locale))->toBe("Cancelado no meu produto ({$locale})")
        ->and(__('auth.two_factor.expired', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['two_factor']['expired'])
        ->and(__('auth.two_factor.title', [], $locale))->toBe($appAuth['two_factor']['title'])
        ->and(__('mail.password_reset.subject', [], $locale))->toBe(authPackageLangFile("{$locale}/mail.php")['password_reset']['subject'])
        ->and(__('mail.password_reset.heading', [], $locale))->toBe($appMail['password_reset']['heading']);
})->with(['pt_BR', 'en', 'es']);
