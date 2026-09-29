<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

// =============================================================================
// No starter, o lang/ do APLICATIVO vence o do pacote twstec/kit-uploads — a
// mesma regra do foundation, do auth e do accounts
// (Localization\PackageTranslations).
//
// As mensagens do upload (enviado, avatar atualizado e cada motivo de recusa)
// vêm do pacote; quem usa o kit troca qualquer uma delas só editando o lang/
// do aplicativo. A prova usa uma CÓPIA do lang/ do starter, para não tocar nos
// arquivos de verdade.
// =============================================================================

/**
 * @param  array<string, array<string, mixed>>  $overrides  'pt_BR/uploads.php' => chaves a sobrepor
 */
function starterLangWithUploads(array $overrides): string
{
    $dir = sys_get_temp_dir().'/starter-uploads-lang-'.uniqid();

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

function uploadsPackageLangFile(string $relative): array
{
    return require base_path('vendor/twstec/kit-uploads/lang/'.$relative);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/starter-uploads-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) mensagem que só o pacote tem: texto do pacote', function (string $locale): void {
    starterLangWithUploads([]);

    $pacote = uploadsPackageLangFile("{$locale}/uploads.php");

    expect(__('uploads.stored', [], $locale))->toBe($pacote['stored'])
        ->and(__('uploads.rejected.extension_mismatch', [], $locale))->toBe($pacote['rejected']['extension_mismatch'])
        ->and(__('uploads.rejected.too_large', ['max' => 7], $locale))->toBe(str_replace(':max', '7', $pacote['rejected']['too_large']));
})->with(['pt_BR', 'en', 'es']);

it('(b) a mesma chave no lang/ do aplicativo: texto do aplicativo — inclusive na resposta da API', function (string $locale): void {
    starterLangWithUploads([
        "{$locale}/uploads.php" => [
            'stored' => "Recebido pelo meu produto ({$locale})",
            'rejected' => ['extension_mismatch' => "Extensão recusada pelo meu produto ({$locale})"],
        ],
    ]);

    app()->setLocale($locale);
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');

    expect(__('uploads.stored', [], $locale))->toBe("Recebido pelo meu produto ({$locale})")
        ->and(__('uploads.rejected.extension_mismatch', [], $locale))->toBe("Extensão recusada pelo meu produto ({$locale})");

    ['api_key' => $apiKey, 'secret_key' => $secret] = criarChave(User::factory()->create(), ['scopes' => ['uploads:create']]);

    $this->postJson('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPng(), 'documento.pdf')], headersApi($apiKey, $secret))
        ->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', "Extensão recusada pelo meu produto ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) grupo repartido: cada chave de uploads resolve do lado certo — app e pacote no mesmo grupo', function (string $locale): void {
    starterLangWithUploads(["{$locale}/uploads.php" => ['rejected' => ['empty' => "Vazio no meu produto ({$locale})"]]]);

    $pacote = uploadsPackageLangFile("{$locale}/uploads.php");

    expect(__('uploads.rejected.empty', [], $locale))->toBe("Vazio no meu produto ({$locale})")
        ->and(__('uploads.rejected.embedded_script', [], $locale))->toBe($pacote['rejected']['embedded_script'])
        ->and(__('uploads.avatar_updated', [], $locale))->toBe($pacote['avatar_updated']);
})->with(['pt_BR', 'en', 'es']);
