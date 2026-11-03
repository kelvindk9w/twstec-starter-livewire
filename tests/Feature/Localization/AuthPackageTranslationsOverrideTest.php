<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

// =============================================================================
// No starter, o lang/ do APLICATIVO vence o do pacote twstec/kit-auth — a
// mesma regra do foundation (Localization\PackageTranslations).
//
// As mensagens do domínio de autenticação (recusa de login, bloqueio, código
// do segundo fator), os textos das telas de entrada e do painel e os e-mails
// (assunto e corpo) vêm do pacote: o projeto não tem cópia. Quem usa o kit
// troca qualquer um deles escrevendo só a chave no lang/ do aplicativo; um
// projeto antigo, com as cópias completas, continua igual. A prova usa uma
// CÓPIA do lang/ do starter, para não tocar nos arquivos de verdade.
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
    requireLocales($locale);

    starterLangWithAuth([]);

    expect(__('auth.account_inactive', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['account_inactive'])
        ->and(__('auth.two_factor.invalid', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['two_factor']['invalid'])
        ->and(__('mail.login_code.subject', [], $locale))->toBe(authPackageLangFile("{$locale}/mail.php")['login_code']['subject']);
})->with(['pt_BR', 'en', 'es']);

it('(b) a mesma chave no lang/ do aplicativo: texto do aplicativo', function (string $locale): void {
    requireLocales($locale);

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

it('(c) o projeto não tem cópia: telas, mensagens e e-mails do kit vêm do pacote, e uma chave do aplicativo muda só ela', function (string $locale): void {
    requireLocales($locale);

    expect(lang_path("{$locale}/auth.php"))->not->toBeFile()
        ->and(lang_path("{$locale}/panel.php"))->not->toBeFile()
        ->and(lang_path("{$locale}/passwords.php"))->not->toBeFile();

    starterLangWithAuth(["{$locale}/auth.php" => ['two_factor' => ['cancelled' => "Cancelado no meu produto ({$locale})"]]]);

    expect(__('auth.two_factor.cancelled', [], $locale))->toBe("Cancelado no meu produto ({$locale})")
        ->and(__('auth.two_factor.expired', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['two_factor']['expired'])
        ->and(__('auth.two_factor.title', [], $locale))->toBe(authPackageLangFile("{$locale}/auth.php")['two_factor']['title'])
        ->and(__('panel.nav.dashboard', [], $locale))->toBe(authPackageLangFile("{$locale}/panel.php")['nav']['dashboard'])
        ->and(__('passwords.reset', [], $locale))->toBe(authPackageLangFile("{$locale}/passwords.php")['reset'])
        ->and(__('mail.password_reset.subject', [], $locale))->toBe(authPackageLangFile("{$locale}/mail.php")['password_reset']['subject'])
        ->and(__('mail.password_reset.heading', [], $locale))->toBe(authPackageLangFile("{$locale}/mail.php")['password_reset']['heading']);
})->with(['pt_BR', 'en', 'es']);

it('(d) projeto antigo, com as cópias completas: o arquivo do aplicativo continua valendo inteiro', function (string $locale): void {
    requireLocales($locale);

    // A cópia que um projeto criado antes da mudança tem: o arquivo inteiro,
    // com um texto que ele personalizou.
    $copy = authPackageLangFile("{$locale}/panel.php");
    $copy['nav']['dashboard'] = "Início do meu produto ({$locale})";

    starterLangWithAuth(["{$locale}/panel.php" => $copy]);

    expect(__('panel.nav.dashboard', [], $locale))->toBe("Início do meu produto ({$locale})")
        ->and(trans('panel.nav', [], $locale))->toBe($copy['nav'])
        ->and(trans('panel.profile', [], $locale))->toBe($copy['profile']);
})->with(['pt_BR', 'en', 'es']);
