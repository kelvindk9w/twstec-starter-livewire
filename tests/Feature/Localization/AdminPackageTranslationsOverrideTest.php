<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;

// =============================================================================
// No starter, o lang/ do APLICATIVO vence o do pacote twstec/kit-admin — a
// mesma regra do foundation, do auth, do accounts e do uploads
// (Localization\PackageTranslations).
//
// Os textos do painel (/admin) vêm do pacote; as telas da demonstração ficam no
// lang/admin.php do aplicativo, no MESMO grupo `admin`. Quem usa o kit troca
// qualquer texto do painel só editando o lang/ do aplicativo. A prova usa uma
// CÓPIA do lang/ do starter, para não tocar nos arquivos de verdade.
// =============================================================================

/**
 * @param  array<string, array<string, mixed>>  $overrides  'pt_BR/admin.php' => chaves a sobrepor
 */
function starterLangWithAdmin(array $overrides): string
{
    $dir = sys_get_temp_dir().'/starter-admin-lang-'.uniqid();

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

function adminPackageLangFile(string $relative): array
{
    return require base_path('vendor/twstec/kit-admin/lang/'.$relative);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/starter-admin-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) texto que só o pacote tem: texto do pacote', function (string $locale): void {
    starterLangWithAdmin([]);

    $pacote = adminPackageLangFile("{$locale}/admin.php");

    expect(__('admin.users.plural', [], $locale))->toBe($pacote['users']['plural'])
        ->and(__('admin.users.avatar_not_owned', [], $locale))->toBe($pacote['users']['avatar_not_owned'])
        ->and(__('admin.audit.plural', [], $locale))->toBe($pacote['audit']['plural']);
})->with(['pt_BR', 'en', 'es']);

it('(b) a mesma chave no lang/ do aplicativo: texto do aplicativo — inclusive na tela do painel', function (string $locale): void {
    starterLangWithAdmin([
        "{$locale}/admin.php" => ['users' => ['create' => "Cadastrar pessoa no meu produto ({$locale})"]],
    ]);

    app()->setLocale($locale);

    expect(__('admin.users.create', [], $locale))->toBe("Cadastrar pessoa no meu produto ({$locale})");

    $this->actingAs(User::factory()->create(['is_admin' => true, 'locale' => $locale]));

    Livewire::test(ListUsers::class)->assertSee("Cadastrar pessoa no meu produto ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) grupo repartido: a demo (lang/ do app) e o produto (pacote) resolvem do lado certo no mesmo grupo admin', function (string $locale): void {
    starterLangWithAdmin(["{$locale}/admin.php" => ['users' => ['blocked' => "Barrada no meu produto ({$locale})"]]]);

    $pacote = adminPackageLangFile("{$locale}/admin.php");
    $app = require base_path("vendor/twstec/kit-demo/lang/{$locale}/admin.php");

    expect(__('admin.users.blocked', [], $locale))->toBe("Barrada no meu produto ({$locale})")
        ->and(__('admin.users.active', [], $locale))->toBe($pacote['users']['active'])
        ->and(__('admin.products.label', [], $locale))->toBe($app['products']['label'])
        ->and(__('admin.submissions.attack_xss', [], $locale))->toBe($pacote['submissions']['attack_xss']);
})->with(['pt_BR', 'en', 'es'])->group('demo');
