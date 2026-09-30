<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\Finder\Finder;

// =============================================================================
// TRAVAS DO PROJETO — valem no projeto criado a partir deste starter e
// reprovam o build quando:
//
// 1. um PHP de app/, database/ ou routes/ não declara strict_types=1;
// 2. o código chama env() fora de config/ — com `php artisan config:cache`
//    (produção) o .env não é lido, e esse env() devolve o padrão em
//    silêncio (foi assim que as frequências do backup eram ignoradas). O
//    valor vai para um arquivo de config/ e o código lê com config();
// 3. os models (app/Models) e o domínio (app/Domain) usam classe de
//    interface: Filament, Livewire, Inertia. Os namespaces proibidos saem do
//    autoload do Composer, PACOTE A PACOTE — `Filament\` sozinho não é a
//    lista: filament/notifications, filament/forms... declaram cada um o seu
//    prefixo (ver docs/testes.md, "Pacotes divididos").
//
// Leitura por tokens do PHP: comentários e strings não contam.
// =============================================================================

/**
 * Pastas cujo PHP declara strict_types.
 */
const PROJECT_STRICT_DIRECTORIES = ['app', 'database', 'routes'];

/**
 * Pastas onde env() não pode aparecer (config/ é o ÚNICO lugar).
 */
const PROJECT_NO_ENV_DIRECTORIES = ['app', 'bootstrap', 'database', 'routes', 'resources/views'];

/**
 * Pastas que não conhecem interface.
 */
const PROJECT_UI_FREE_DIRECTORIES = ['app/Models', 'app/Domain'];

/**
 * Pacotes (nome no Composer, `*` no fim = o vendor inteiro) cujos
 * namespaces são de interface.
 */
const PROJECT_UI_PACKAGES = ['filament/*', 'livewire/*', 'inertiajs/*'];

/**
 * @return list<SplFileInfo>
 */
function projectPhpFiles(array $directories): array
{
    $existing = array_values(array_filter(array_map(base_path(...), $directories), is_dir(...)));

    if ($existing === []) {
        return [];
    }

    return iterator_to_array((new Finder)->files()->in($existing)->exclude('cache')->name('*.php'), false);
}

function projectRelative(SplFileInfo $file): string
{
    return str_replace(base_path().'/', '', $file->getRealPath());
}

function projectDeclaresStrictTypes(string $code): bool
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));

    $head = implode('', array_map(fn (PhpToken $token): string => strtolower($token->text), array_slice($tokens, 0, 7)));

    return $head === 'declare(strict_types=1);';
}

/**
 * Linhas com chamada à função global env() (não método ->env(), ::env()).
 *
 * @return list<int>
 */
function projectEnvCalls(string $code, bool $blade): array
{
    if ($blade) {
        // Comentário Blade não conta (as quebras de linha ficam, para a linha
        // apontada continuar certa).
        $code = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $comment): string => str_repeat("\n", substr_count($comment[0], "\n")), $code);
        preg_match_all('/(?<![\w>:$.\\\\])\\\\?env\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match): int => substr_count(substr($code, 0, $match[1]), "\n") + 1, $matches[0]);
    }

    $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
    $lines = [];

    foreach ($tokens as $index => $token) {
        $isEnv = ($token->is(T_STRING) && strtolower($token->text) === 'env')
            || ($token->is(T_NAME_FULLY_QUALIFIED) && strtolower($token->text) === '\\env');
        $previous = $tokens[$index - 1] ?? null;

        if ($isEnv && ($tokens[$index + 1] ?? null)?->text === '('
            && ! ($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) ?? false)) {
            $lines[] = $token->line;
        }
    }

    return $lines;
}

/**
 * Prefixos de namespace dos pacotes de interface, lidos do autoload do
 * Composer (vendor/composer/installed.json).
 *
 * @return list<string>
 */
function projectUiPrefixes(): array
{
    $installed = json_decode((string) file_get_contents(base_path('vendor/composer/installed.json')), true);
    $prefixes = [];

    foreach ($installed['packages'] ?? $installed as $package) {
        $name = (string) ($package['name'] ?? '');

        foreach (PROJECT_UI_PACKAGES as $pattern) {
            if (fnmatch($pattern, $name)) {
                foreach (['psr-4', 'psr-0'] as $standard) {
                    foreach (array_keys($package['autoload'][$standard] ?? []) as $prefix) {
                        if ($prefix !== '') {
                            $prefixes[] = rtrim($prefix, '\\').'\\';
                        }
                    }
                }
            }
        }
    }

    $prefixes = array_values(array_unique($prefixes));
    sort($prefixes);

    return $prefixes;
}

/**
 * Nomes de classe citados no código que começam por um dos prefixos.
 *
 * @param  list<string>  $prefixes
 * @return list<string>
 */
function projectForbiddenNames(string $code, array $prefixes): array
{
    $found = [];

    foreach (PhpToken::tokenize($code) as $token) {
        if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\').'\\';

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $found[] = rtrim($name, '\\');

                break;
            }
        }
    }

    return array_values(array_unique($found));
}

it('todo PHP de app/, database/ e routes/ declara strict_types=1', function (): void {
    $files = projectPhpFiles(PROJECT_STRICT_DIRECTORIES);
    $missing = [];

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php') && ! projectDeclaresStrictTypes($file->getContents())) {
            $missing[] = projectRelative($file).' — comece com declare(strict_types=1);';
        }
    }

    expect($files)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('env() só em config/: fora dele, o config:cache o ignora em silêncio', function (): void {
    $violations = [];

    foreach (projectPhpFiles(PROJECT_NO_ENV_DIRECTORIES) as $file) {
        foreach (projectEnvCalls($file->getContents(), str_ends_with($file->getFilename(), '.blade.php')) as $line) {
            $violations[] = projectRelative($file).":{$line} — leve o valor para config/ e leia com config()";
        }
    }

    expect($violations)->toBe([]);
});

it('models e domínio não usam classe de interface (prefixos do autoload do Composer)', function (): void {
    $prefixes = projectUiPrefixes();
    $violations = [];

    foreach (projectPhpFiles(PROJECT_UI_FREE_DIRECTORIES) as $file) {
        foreach (projectForbiddenNames($file->getContents(), $prefixes) as $name) {
            $violations[] = projectRelative($file)." usa {$name}";
        }
    }

    expect($prefixes)->not->toBeEmpty()
        ->and($violations)->toBe([]);
});

it('as travas não são cegas', function (): void {
    // strict_types: primeira instrução, depois de comentários.
    expect(projectDeclaresStrictTypes("<?php\n\ndeclare(strict_types=1);\n"))->toBeTrue()
        ->and(projectDeclaresStrictTypes("<?php\n// c\n/** d */\ndeclare(strict_types = 1);"))->toBeTrue()
        ->and(projectDeclaresStrictTypes("<?php\n\nnamespace App;\n"))->toBeFalse()
        ->and(projectDeclaresStrictTypes("<?php\n\ndeclare(strict_types=0);\n"))->toBeFalse()
        ->and(projectDeclaresStrictTypes("<?php\n// declare(strict_types=1);\n"))->toBeFalse();

    // env(): a função global, não método nem texto.
    expect(projectEnvCalls("<?php\n\$a = env('X');", false))->toBe([2])
        ->and(projectEnvCalls("<?php\n\$a = \\env('X');", false))->toBe([2])
        ->and(projectEnvCalls("<?php\n\$a = \$app->env('X'); \$b = App::env(); // env('X')\n\$c = 'env(1)';", false))->toBe([])
        ->and(projectEnvCalls("<p>{{ env('APP_NAME') }}</p>\n@if(env('X'))", true))->toBe([1, 2])
        ->and(projectEnvCalls("<p>{{ app()->env('x') }} {{ \$env('y') }}</p>", true))->toBe([])
        ->and(projectEnvCalls("{{-- vem do .env (config/x.php)\n env('X') --}}\n{{ env('Y') }}", true))->toBe([3]);

    // Interface: o prefixo de cada PACOTE, não só a raiz do vendor. Com
    // `Filament\` apenas, Filament\Notifications (outro pacote) escaparia
    // de uma trava por diretório de autoload, como a do arch() do Pest.
    $prefixes = projectUiPrefixes();

    foreach ($prefixes as $prefix) {
        expect(projectForbiddenNames("<?php\nuse {$prefix}Qualquer\\Coisa;", $prefixes))->toBe([$prefix.'Qualquer\\Coisa']);
    }

    expect(projectForbiddenNames("<?php\nuse Filament\\Notifications\\Notification;\n\$x = new \\Livewire\\Component;", ['Filament\\Notifications\\', 'Livewire\\']))
        ->toBe(['Filament\\Notifications\\Notification', 'Livewire\\Component'])
        ->and(projectForbiddenNames("<?php\n// Filament\\Notifications\\Notification\n\$a = 'Livewire\\\\Component';", ['Filament\\Notifications\\', 'Livewire\\']))->toBe([]);

    if (InstalledVersions::isInstalled('filament/notifications')) {
        expect($prefixes)->toContain('Filament\\Notifications\\');
    }
});
