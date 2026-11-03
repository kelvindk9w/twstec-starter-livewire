<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tests\Support\ProjectLocales;

// A paridade de idiomas segue o que o projeto TEM em lang/: projeto de um
// idioma só (que apagou en/es) passa; idioma presente e incompleto reprova.

/**
 * Pasta de idiomas temporária: `$files` é 'pt_BR/ui.php' => conteúdo.
 *
 * @param  array<string, array<string, mixed>>  $files
 */
function temporaryLangPath(array $files): string
{
    $dir = sys_get_temp_dir().'/lang-parity-'.uniqid();
    $filesystem = new Filesystem;

    foreach ($files as $relative => $contents) {
        $filesystem->ensureDirectoryExists(dirname("{$dir}/{$relative}"));
        file_put_contents("{$dir}/{$relative}", '<?php return '.var_export($contents, true).';');
    }

    return $dir;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/lang-parity-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('os idiomas do projeto têm os mesmos arquivos e chaves do idioma padrão', function () {
    expect(ProjectLocales::present(lang_path()))->toContain(platform()->locale)
        ->and(ProjectLocales::divergences(lang_path(), platform()->locale))->toBe([]);
});

it('projeto só com o idioma padrão passa na paridade (en/es não são exigidos)', function () {
    $lang = temporaryLangPath(['pt_BR/ui.php' => ['save' => 'Salvar', 'nested' => ['a' => 'A']]]);

    expect(ProjectLocales::present($lang))->toBe(['pt_BR'])
        ->and(ProjectLocales::divergences($lang, 'pt_BR', ['ui']))->toBe([]);
});

it('idioma presente com chave faltando, sobrando ou arquivo ausente reprova', function () {
    $lang = temporaryLangPath([
        'pt_BR/ui.php' => ['save' => 'Salvar', 'nested' => ['a' => 'A']],
        'pt_BR/mail.php' => ['hello' => 'Olá'],
        'en/ui.php' => ['save' => 'Save', 'extra' => 'Extra'],
    ]);

    expect(ProjectLocales::divergences($lang, 'pt_BR', ['ui', 'mail']))->toEqualCanonicalizing([
        'lang/en/ui.php: falta nested.a',
        'lang/en/ui.php: sobra extra',
        'lang/en/mail.php não existe',
    ]);
});
