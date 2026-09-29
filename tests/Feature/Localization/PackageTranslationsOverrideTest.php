<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

// =============================================================================
// No starter, o lang/ do APLICATIVO vence o do pacote twstec/kit-foundation.
//
// Quem usa o kit troca qualquer texto — inclusive os que vêm do pacote, como
// o rodapé dos e-mails — só editando lang/ do aplicativo. O pacote preenche o
// que o aplicativo não define. A prova usa uma CÓPIA do lang/ do starter com
// uma sobreposição, para não tocar nos arquivos de verdade.
// =============================================================================

/**
 * Copia o lang/ do starter para uma pasta temporária, aplica as mudanças e
 * refaz o carregador de traduções.
 *
 * @param  array<string, array<string, mixed>>  $overrides  'pt_BR/mail.php' => chaves a sobrepor
 */
function starterLangWith(array $overrides): string
{
    $dir = sys_get_temp_dir().'/starter-lang-'.uniqid();

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

function foundationPackageLang(string $relative): array
{
    return require base_path('vendor/twstec/kit-foundation/lang/'.$relative);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/starter-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) chave que só o pacote tem: texto do pacote', function (string $locale): void {
    starterLangWith([]);

    expect(__('security.blocked', [], $locale))->toBe(foundationPackageLang("{$locale}/security.php")['blocked'])
        ->and(__('api.errors.server_error', [], $locale))->toBe(foundationPackageLang("{$locale}/api.php")['errors']['server_error']);
})->with(['pt_BR', 'en', 'es']);

it('(b) a mesma chave no lang/ do aplicativo: texto do aplicativo', function (string $locale): void {
    starterLangWith([
        "{$locale}/mail.php" => ['footer' => ['transactional' => "Rodapé do meu produto ({$locale})"]],
        "{$locale}/security.php" => ['blocked' => "Recusado pelo meu produto ({$locale})"],
    ]);

    expect(__('mail.footer.transactional', [], $locale))->toBe("Rodapé do meu produto ({$locale})")
        ->and(__('security.blocked', [], $locale))->toBe("Recusado pelo meu produto ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) mail.php repartido: chaves do aplicativo e do pacote no mesmo grupo', function (string $locale): void {
    $appMail = require lang_path("{$locale}/mail.php");

    starterLangWith(["{$locale}/mail.php" => ['footer' => ['cnpj' => "CNPJ do meu produto ({$locale})"]]]);

    expect(__('mail.footer.cnpj', [], $locale))->toBe("CNPJ do meu produto ({$locale})")
        ->and(__('mail.footer.transactional', [], $locale))->toBe(foundationPackageLang("{$locale}/mail.php")['footer']['transactional'])
        ->and(__('mail.password_reset.heading', [], $locale))->toBe($appMail['password_reset']['heading']);
})->with(['pt_BR', 'en', 'es']);
