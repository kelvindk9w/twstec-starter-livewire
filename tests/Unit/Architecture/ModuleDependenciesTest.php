<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Twstec\Kit\Admin\Compat\LegacyNames;

// =============================================================================
// ARQUITETURA DOS MÓDULOS DE app/Core — a trava da divisão em pacotes.
//
// O kit vai virar pacotes instaláveis (foundation, auth, accounts, uploads;
// o admin fica fora de app/Core). Um pacote só pode depender dos que estão
// ABAIXO dele: foundation não conhece ninguém; auth conhece foundation;
// accounts conhece auth e foundation; uploads conhece os três.
//
// As QUATRO camadas JÁ SAÍRAM de app/Core: são os pacotes
// twstec/kit-foundation (packages/foundation), twstec/kit-auth
// (packages/auth), twstec/kit-accounts (packages/accounts) e
// twstec/kit-uploads (packages/uploads), e a trava de cada uma — não conhecer
// nada do aplicativo nem das camadas de cima — mora na suíte do próprio
// pacote. Desde a extração do pacote uploads (F6), app/Core não existe mais:
// as regras dos módulos de app/Core continuam aqui para reprovar um módulo que
// volte a morar nele. E mais uma: o código do aplicativo usa os nomes NOVOS das
// classes que saíram (Twstec\Kit\Foundation\…, Twstec\Kit\Auth\…,
// Twstec\Kit\Accounts\…, Twstec\Kit\Uploads\…, e App\Models\User para o
// model de usuário, que é do aplicativo); os nomes antigos
// (App\Core\<Módulo>\…) existem só como apelidos de compatibilidade para o
// que está gravado fora do código.
//
// A DEMONSTRAÇÃO do kit é o pacote twstec/kit-demo (packages/demo, namespace
// Twstec\Kit\Demo — antes App\Demo, em app/Demo e demo/), instalado só no
// desenvolvimento (require-dev). Ela fica no topo: pode usar qualquer peça do
// produto, e NENHUMA peça do produto pode usar a demo — nem as telas
// (app/Filament, app/Livewire), nem providers, rotas, config, migrations,
// seeders e views do aplicativo, nem os cinco pacotes do kit. A ligação é só
// pela descoberta automática de pacotes e pelos pontos de extensão; nenhum
// arquivo do produto nomeia a demo (PRODUCT_DEMO_JUNCTIONS vazia).
//
// Este arquivo reprova o build quando:
//
// 1. um módulo novo aparece em app/Core sem camada declarada aqui;
// 2. um módulo usa classe de uma camada ACIMA da dele (import que "sobe");
// 3. surge um CICLO entre módulos que não seja um dos grupos coesos
//    declarados (módulos que andam juntos e vão para o MESMO pacote);
// 4. o backend passa a depender das telas (Livewire/Filament do app);
// 5. qualquer arquivo do produto (aplicativo ou pacote do kit) passa a usar
//    a demonstração (Twstec\Kit\Demo, ou o nome antigo App\Demo), a demo
//    volta a morar no aplicativo, ou o composer.json a declara fora do
//    require-dev.
//
// O que ainda não pôde ser corrigido é EXCEÇÃO EXPLÍCITA, listada abaixo com
// a fase em que sai. Exceção que deixou de existir também reprova — a lista
// não pode envelhecer em silêncio.
//
// A leitura é por tokens do PHP: comentários e strings não contam, só nomes
// de classe de verdade (`use`, `new`, `::class`, tipos, `instanceof`…). Nas
// views Blade, que não são PHP puro, vale o nome escrito em qualquer lugar.
// =============================================================================

/**
 * Camadas, de baixo para cima, e os módulos de cada uma.
 *
 * Vazia desde a extração do pacote uploads (F6): as quatro camadas são os
 * pacotes twstec/kit-foundation, twstec/kit-auth, twstec/kit-accounts e
 * twstec/kit-uploads, e usar classe deles é sempre descer na hierarquia. Um
 * módulo que reaparecer em app/Core sem camada declarada aqui reprova.
 *
 * @var array<string, list<string>>
 */
const CORE_LAYERS = [];

/**
 * Grupos coesos: ciclos aceitos porque os módulos vão juntos para o MESMO
 * pacote. Qualquer outro ciclo reprova.
 *
 * Vazia desde a extração do pacote accounts (F5): o grupo projetos ↔ chaves de
 * API foi junto para o twstec/kit-accounts, como o grupo segurança ↔ trilha ↔
 * HTTP ↔ idioma tinha ido para o twstec/kit-foundation; a suíte de cada
 * pacote confere o dele.
 *
 * @var list<list<string>>
 */
const CORE_COHESIVE_CYCLES = [];

/**
 * Módulos que saíram de app/Core para o pacote twstec/kit-foundation. O nome
 * antigo App\Core\<Módulo>\… de qualquer um deles não pode voltar ao código.
 */
const FOUNDATION_MOVED_MODULES = ['Identifiers', 'Money', 'Http', 'Security', 'Logging', 'Localization', 'Settings', 'Mail', 'Support', 'Backup', 'Audit'];

/**
 * Módulo que saiu de app/Core para o pacote twstec/kit-auth. O nome antigo
 * App\Core\Auth\… não pode voltar ao código (o model de usuário virou
 * App\Models\User, do aplicativo; o resto é Twstec\Kit\Auth\…).
 */
const AUTH_MOVED_MODULES = ['Auth'];

/**
 * Módulos que saíram de app/Core para o pacote twstec/kit-accounts. O nome
 * antigo App\Core\Tenancy\… ou App\Core\ApiKeys\… não pode voltar ao
 * código (o novo é Twstec\Kit\Accounts\Tenancy\… e
 * Twstec\Kit\Accounts\ApiKeys\…).
 */
const ACCOUNTS_MOVED_MODULES = ['Tenancy', 'ApiKeys'];

/**
 * Módulo que saiu de app/Core para o pacote twstec/kit-uploads. O nome antigo
 * App\Core\Uploads\… não pode voltar ao código (o novo é
 * Twstec\Kit\Uploads\…).
 */
const UPLOADS_MOVED_MODULES = ['Uploads'];

/**
 * Imports que sobem na hierarquia e ainda não puderam sair: arquivo => classes.
 *
 * Vazia desde a extração do pacote auth (F4): o model de usuário saiu de
 * app/Core e virou App\Models\User, do aplicativo, que compõe as traits dos
 * pacotes instalados (a de autenticação e a de avatar de uploads) — o pacote
 * auth não conhece uploads.
 *
 * @var array<string, list<string>>
 */
const CORE_UPWARD_EXCEPTIONS = [];

/**
 * Prefixos de código de TELA que o backend não pode usar.
 */
const CORE_FORBIDDEN_UI_PREFIXES = ['App\Livewire\\', 'App\Filament\\'];

/**
 * Diretórios do PRODUTO — tudo o que não é a demonstração — e os arquivos
 * dentro deles que podem nomear a demo (o ponto de ligação).
 */
const PRODUCT_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'routes', 'resources/views'];

/**
 * PONTOS DE LIGAÇÃO da demo: lugares do produto que poderiam nomeá-la.
 *
 * Vazia desde que a demonstração virou o pacote twstec/kit-demo (F9): o
 * provider dela entra pela descoberta automática de pacotes, e o registro do
 * e-mail de contato na galeria vem do autoload do próprio pacote. Nenhum
 * arquivo do produto precisa citá-la.
 *
 * @var array<string, list<string>>
 */
const PRODUCT_DEMO_JUNCTIONS = [];

/**
 * Pacotes do kit que são PRODUTO (a demo não pode aparecer em nenhum deles).
 * O instalador conhece a demo só pelo NOME DO PACOTE (para tirá-la), nunca
 * pelo namespace. Pacote opcional não instalado fica de fora da leitura.
 */
const PRODUCT_PACKAGES = ['kit-foundation', 'kit-auth', 'kit-accounts', 'kit-uploads', 'kit-admin', 'kit-installer'];

/**
 * Prefixos de nome da demonstração: o atual e o antigo (F1b).
 */
const DEMO_PREFIXES = ['Twstec\\Kit\\Demo\\', 'App\\Demo\\'];

/**
 * Nomes de classe do próprio app (App\…) em código PHP, lidos dos tokens.
 *
 * @return list<string>
 */
function appReferencesIn(string $contents): array
{
    $names = [];

    foreach (PhpToken::tokenize($contents) as $token) {
        if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\');

        if (str_starts_with($name, 'App\\')) {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

/**
 * Nomes de classe do próprio app (App\…) referenciados por cada arquivo de
 * app/Core, lidos dos tokens do PHP.
 *
 * @return array<string, list<string>> caminho relativo => nomes totalmente qualificados
 */
function coreAppReferences(): array
{
    static $references = null;

    if ($references !== null) {
        return $references;
    }

    $references = [];

    // Sem app/Core (desde a F6), não há módulo nenhum para ler.
    if (! is_dir(base_path('app/Core'))) {
        return $references;
    }

    foreach ((new Finder)->files()->in(base_path('app/Core'))->name('*.php') as $file) {
        $references[str_replace(base_path().'/', '', $file->getRealPath())] = appReferencesIn($file->getContents());
    }

    ksort($references);

    return $references;
}

/**
 * Nomes da demonstração (Twstec\Kit\Demo\… ou o antigo App\Demo\…) num
 * arquivo. PHP é lido por tokens (comentário e string não contam). Blade não
 * é PHP puro (o `@php(...)` e o `{{ }}` não tokenizam como código): ali vale o
 * nome escrito em qualquer lugar, inclusive em comentário — a view do produto
 * não deve nem citar a demo.
 *
 * @return list<string>
 */
function demoReferencesIn(string $path, string $contents): array
{
    if (str_ends_with($path, '.blade.php')) {
        preg_match_all('/(?:Twstec\\\\+Kit\\\\+Demo|App\\\\+Demo)(?:\\\\+[A-Za-z0-9_]+)*/', $contents, $matches);

        return array_values(array_unique(array_map(
            fn (string $name): string => (string) preg_replace('/\\\\+/', '\\', $name),
            $matches[0],
        )));
    }

    $names = [];

    foreach (PhpToken::tokenize($contents) as $token) {
        if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\');

        foreach (DEMO_PREFIXES as $prefix) {
            if (str_starts_with($name.'\\', $prefix)) {
                $names[$name] = true;
            }
        }
    }

    return array_keys($names);
}

/**
 * Referências à demonstração em cada arquivo do produto: o aplicativo
 * (PRODUCT_DIRECTORIES) e os cinco pacotes do kit (lidos pelo vendor/, como o
 * aplicativo instalado os vê).
 *
 * @return array<string, list<string>> caminho relativo => nomes
 */
function productDemoReferences(): array
{
    $directories = PRODUCT_DIRECTORIES;

    foreach (PRODUCT_PACKAGES as $package) {
        foreach (['src', 'config', 'database', 'lang', 'resources', 'routes'] as $directory) {
            if (is_dir(base_path("vendor/twstec/{$package}/{$directory}"))) {
                $directories[] = "vendor/twstec/{$package}/{$directory}";
            }
        }
    }

    $references = [];

    foreach ($directories as $directory) {
        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            // Caminho pelo vendor (não pelo link do monorepo).
            $path = str_replace(base_path().'/', '', $file->getPath().'/'.$file->getFilename());
            $names = demoReferencesIn($path, $file->getContents());

            if ($names !== []) {
                $references[$path] = $names;
            }
        }
    }

    ksort($references);

    return $references;
}

/**
 * Módulo de app/Core de um caminho de arquivo (`app/Core/<Módulo>/…`) ou de
 * um nome de classe (`App\Core\<Módulo>\…`); null fora de app/Core.
 */
function coreModuleOf(string $pathOrClass): ?string
{
    if (preg_match('#^app/Core/([^/]+)/#', $pathOrClass, $match) === 1) {
        return $match[1];
    }

    if (preg_match('/^App\\\\Core\\\\([^\\\\]+)\\\\/', $pathOrClass, $match) === 1) {
        return $match[1];
    }

    return null;
}

/**
 * Posição da camada do módulo (0 = foundation); null se não declarado.
 */
function coreLayerRank(string $module): ?int
{
    foreach (array_keys(CORE_LAYERS) as $rank => $layer) {
        if (in_array($module, CORE_LAYERS[$layer], true)) {
            return $rank;
        }
    }

    return null;
}

/**
 * Grafo de dependências entre módulos: módulo => módulos que ele usa.
 *
 * @return array<string, list<string>>
 */
function coreModuleGraph(): array
{
    $graph = [];

    foreach (coreAppReferences() as $path => $names) {
        $from = coreModuleOf($path);

        if ($from === null) {
            continue;
        }

        $graph[$from] ??= [];

        foreach ($names as $name) {
            $to = coreModuleOf($name);

            if ($to !== null && $to !== $from && ! in_array($to, $graph[$from], true)) {
                $graph[$from][] = $to;
            }
        }
    }

    ksort($graph);

    return $graph;
}

/**
 * Componentes fortemente conexos com mais de um módulo (os ciclos), pelo
 * algoritmo de Tarjan. Cada grupo vem ordenado, e a lista também.
 *
 * @param  array<string, list<string>>  $graph
 * @return list<list<string>>
 */
function coreCycles(array $graph): array
{
    $index = 0;
    $indices = [];
    $lowlinks = [];
    $stack = [];
    $onStack = [];
    $components = [];

    $connect = function (string $node) use (&$connect, &$index, &$indices, &$lowlinks, &$stack, &$onStack, &$components, $graph): void {
        $indices[$node] = $lowlinks[$node] = $index++;
        $stack[] = $node;
        $onStack[$node] = true;

        foreach ($graph[$node] ?? [] as $next) {
            if (! isset($indices[$next])) {
                $connect($next);
                $lowlinks[$node] = min($lowlinks[$node], $lowlinks[$next]);
            } elseif ($onStack[$next] ?? false) {
                $lowlinks[$node] = min($lowlinks[$node], $indices[$next]);
            }
        }

        if ($lowlinks[$node] === $indices[$node]) {
            $component = [];

            do {
                $member = array_pop($stack);
                $onStack[$member] = false;
                $component[] = $member;
            } while ($member !== $node);

            if (count($component) > 1) {
                sort($component);
                $components[] = $component;
            }
        }
    };

    foreach (array_keys($graph) as $node) {
        if (! isset($indices[$node])) {
            $connect($node);
        }
    }

    usort($components, fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

    return $components;
}

it('declara a camada de todo módulo de app/Core, uma vez só', function (): void {
    $declared = array_merge(...array_values(CORE_LAYERS));

    expect($declared)->toHaveCount(count(array_unique($declared)));

    $modules = [];

    foreach (is_dir(base_path('app/Core')) ? (new Finder)->directories()->in(base_path('app/Core'))->depth(0) : [] as $directory) {
        $modules[] = $directory->getFilename();
    }

    sort($modules);
    sort($declared);

    expect($modules)->toBe($declared);
});

it('não deixa módulo usar classe de uma camada acima da dele', function (): void {
    $violations = [];
    $usedExceptions = [];

    foreach (coreAppReferences() as $path => $names) {
        $from = coreModuleOf($path);
        $fromRank = $from === null ? null : coreLayerRank($from);

        if ($fromRank === null) {
            continue;
        }

        foreach ($names as $name) {
            $to = coreModuleOf($name);
            $toRank = $to === null ? null : coreLayerRank($to);

            if ($toRank === null || $toRank <= $fromRank) {
                continue;
            }

            if (in_array($name, CORE_UPWARD_EXCEPTIONS[$path] ?? [], true)) {
                $usedExceptions[$path][] = $name;

                continue;
            }

            $violations[] = sprintf('%s (%s) usa %s (%s)', $path, array_keys(CORE_LAYERS)[$fromRank], $name, array_keys(CORE_LAYERS)[$toRank]);
        }
    }

    expect($violations)->toBe([]);

    // Exceção que não é mais usada sai da lista.
    foreach (CORE_UPWARD_EXCEPTIONS as $path => $names) {
        foreach ($names as $name) {
            expect($usedExceptions[$path] ?? [])->toContain($name);
        }
    }
});

it('só aceita ciclos entre módulos do mesmo grupo coeso', function (): void {
    $expected = array_map(function (array $group): array {
        sort($group);

        return $group;
    }, CORE_COHESIVE_CYCLES);

    usort($expected, fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

    expect(coreCycles(coreModuleGraph()))->toBe($expected);

    // E cada grupo coeso cabe numa camada só — nenhum ciclo atravessa pacotes.
    foreach (CORE_COHESIVE_CYCLES as $group) {
        expect(array_unique(array_map(coreLayerRank(...), $group)))->toHaveCount(1);
    }
});

it('mantém o backend sem dependência das telas (Livewire/Filament do app)', function (): void {
    $violations = [];

    foreach (coreAppReferences() as $path => $names) {
        foreach ($names as $name) {
            foreach (CORE_FORBIDDEN_UI_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('mantém o produto sem dependência da demonstração (Twstec\\Kit\\Demo)', function (): void {
    $violations = [];

    foreach (productDemoReferences() as $path => $names) {
        foreach ($names as $name) {
            if (! in_array($name, PRODUCT_DEMO_JUNCTIONS[$path] ?? [], true)) {
                $violations[] = "{$path} usa {$name}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('a leitura da demonstração pega o que deve pegar (a trava não é cega)', function (): void {
    $php = <<<'PHP'
        <?php
        use Twstec\Kit\Demo\Support\DemoSurface;
        // Twstec\Kit\Demo\Em\Comentario não conta
        $a = \App\Demo\Support\DemoSurface::allowed();
        $b = 'Twstec\\Kit\\Demo\\Em\\String';
        $c = Twstec\Kit\Admin\AdminPlugin::class;
        PHP;

    expect(demoReferencesIn('x.php', $php))->toBe(['Twstec\Kit\Demo\Support\DemoSurface', 'App\Demo\Support\DemoSurface'])
        ->and(demoReferencesIn('x.blade.php', '{{-- Twstec\\Kit\\Demo\\X --}}'))->toBe(['Twstec\Kit\Demo\X']);
});

it('mantém a demonstração fora do aplicativo', function (): void {
    // A demo é o pacote twstec/kit-demo. Ela não volta a morar no aplicativo:
    // nem as pastas de antes do pacote (app/Demo, demo/), nem classe dela em
    // app/ (inclusive app/Core, onde seria a fronteira voltando por dentro do
    // produto).
    $violations = [];

    foreach (['app/Demo', 'demo'] as $directory) {
        if (file_exists(base_path($directory))) {
            $violations[] = "{$directory} existe de novo (a demo é o pacote twstec/kit-demo)";
        }
    }

    foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
        if (preg_match('/^namespace\s+(?:App\\\\Demo|Twstec\\\\Kit\\\\Demo)\b/m', $file->getContents()) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file->getRealPath());
        }
    }

    expect($violations)->toBe([]);
});

it('declara a demonstração só como dependência de desenvolvimento', function (): void {
    // require-dev: está no ambiente de quem clona o kit e NUNCA numa
    // instalação `composer install --no-dev` (a imagem de produção). Na
    // seção `require`, a demo — contas de credencial pública, vitrine, dado
    // fictício — viajaria para produção.
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    // (Tirada com `composer remove --dev twstec/kit-demo`, ela não aparece em
    // lugar nenhum — o que também vale.)
    expect(array_keys($composer['require']))->not->toContain('twstec/kit-demo');

    if (TestCase::demoInstalled()) {
        expect(array_keys($composer['require-dev'] ?? []))->toContain('twstec/kit-demo');
    }

    // E nenhum pacote do produto depende dela.
    foreach (PRODUCT_PACKAGES as $package) {
        // Módulo opcional não instalado (ver tws:install): nada a conferir.
        if (! is_dir(base_path("vendor/twstec/{$package}"))) {
            continue;
        }

        $manifesto = base_path("vendor/twstec/{$package}/composer.json");
        $dados = json_decode((string) file_get_contents($manifesto), true);

        expect(array_keys($dados['require'] ?? []))->not->toContain('twstec/kit-demo');
    }
});

it('usa os nomes novos das classes da base, nunca os apelidos App\\Core\\<Módulo da base>', function (): void {
    // Os apelidos de compatibilidade (packages/foundation/src/Compat) existem
    // para o que está gravado FORA do código — payload de fila antigo, config
    // publicada por quem ainda não atualizou. Código novo que usa o nome
    // antigo prenderia o kit a eles e impediria de removê-los na 3.0.
    $pattern = '/^App\\\\Core\\\\('.implode('|', FOUNDATION_MOVED_MODULES).')\\\\/';
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'demo', 'tests'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());

            foreach (appReferencesIn($file->getContents()) as $name) {
                if (preg_match($pattern, $name) === 1) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }
    }

    // E nenhum módulo da base voltou a morar em app/Core.
    foreach (FOUNDATION_MOVED_MODULES as $module) {
        if (is_dir(base_path("app/Core/{$module}"))) {
            $violations[] = "app/Core/{$module} existe de novo (o módulo é do pacote twstec/kit-foundation)";
        }
    }

    expect($violations)->toBe([]);
});

it('usa os nomes novos das classes de autenticação, nunca os apelidos App\\Core\\Auth', function (): void {
    // Mesma regra, para o que saiu com o pacote twstec/kit-auth: os apelidos
    // (packages/auth/src/Compat e, para o model de usuário e o comando
    // user:make-admin, que ficaram no aplicativo, app/Support/legacy-aliases.php)
    // existem só para o que está gravado fora do código. A leitura é por
    // tokens: o nome antigo escrito como TEXTO no arquivo de apelidos não conta.
    $pattern = '/^App\\\\Core\\\\('.implode('|', AUTH_MOVED_MODULES).')\\\\/';
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'demo', 'tests'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());

            foreach (appReferencesIn($file->getContents()) as $name) {
                if (preg_match($pattern, $name) === 1) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }
    }

    // Nas views Blade, que não são PHP puro, vale o nome escrito.
    foreach ((new Finder)->files()->in(base_path('resources/views'))->name('*.blade.php') as $file) {
        if (preg_match('/App\\\\+Core\\\\+Auth\\\\+/', $file->getContents()) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file->getRealPath()).' usa App\\Core\\Auth';
        }
    }

    // E o módulo não voltou a morar em app/Core.
    foreach (AUTH_MOVED_MODULES as $module) {
        if (is_dir(base_path("app/Core/{$module}"))) {
            $violations[] = "app/Core/{$module} existe de novo (o módulo é do pacote twstec/kit-auth)";
        }
    }

    expect($violations)->toBe([]);
});

it('usa os nomes novos das classes de contas e API, nunca os apelidos App\\Core\\Tenancy e App\\Core\\ApiKeys', function (): void {
    // Mesma regra, para o que saiu com o pacote twstec/kit-accounts: os
    // apelidos (packages/accounts/src/Compat) existem só para o que está
    // gravado fora do código — payload de fila antigo, snapshot do Livewire,
    // rota em cache. A leitura é por tokens: o nome antigo escrito como TEXTO
    // (string, nowdoc, comentário) não conta.
    $pattern = '/^App\\\\Core\\\\('.implode('|', ACCOUNTS_MOVED_MODULES).')\\\\/';
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'demo', 'tests'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());

            foreach (appReferencesIn($file->getContents()) as $name) {
                if (preg_match($pattern, $name) === 1) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }
    }

    // Nas views Blade, que não são PHP puro, vale o nome escrito.
    foreach ((new Finder)->files()->in(base_path('resources/views'))->name('*.blade.php') as $file) {
        if (preg_match('/App\\\\+Core\\\\+('.implode('|', ACCOUNTS_MOVED_MODULES).')\\\\+/', $file->getContents()) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file->getRealPath()).' usa App\\Core\\Tenancy ou App\\Core\\ApiKeys';
        }
    }

    // E nenhum dos módulos voltou a morar em app/Core.
    foreach (ACCOUNTS_MOVED_MODULES as $module) {
        if (is_dir(base_path("app/Core/{$module}"))) {
            $violations[] = "app/Core/{$module} existe de novo (o módulo é do pacote twstec/kit-accounts)";
        }
    }

    expect($violations)->toBe([]);
});

it('usa os nomes novos das classes de uploads, nunca os apelidos App\\Core\\Uploads', function (): void {
    // Mesma regra, para o que saiu com o pacote twstec/kit-uploads: os
    // apelidos (packages/uploads/src/Compat) existem só para o que está
    // gravado fora do código — rota em cache, snapshot do Livewire, payload de
    // fila. A leitura é por tokens: o nome antigo escrito como TEXTO (string,
    // nowdoc, comentário) não conta.
    $pattern = '/^App\\\\Core\\\\('.implode('|', UPLOADS_MOVED_MODULES).')\\\\/';
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'demo', 'tests'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());

            foreach (appReferencesIn($file->getContents()) as $name) {
                if (preg_match($pattern, $name) === 1) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }
    }

    // Nas views Blade, que não são PHP puro, vale o nome escrito.
    foreach ((new Finder)->files()->in(base_path('resources/views'))->name('*.blade.php') as $file) {
        if (preg_match('/App\\\\+Core\\\\+('.implode('|', UPLOADS_MOVED_MODULES).')\\\\+/', $file->getContents()) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file->getRealPath()).' usa App\\Core\\Uploads';
        }
    }

    // E o módulo não voltou a morar em app/Core.
    foreach (UPLOADS_MOVED_MODULES as $module) {
        if (is_dir(base_path("app/Core/{$module}"))) {
            $violations[] = "app/Core/{$module} existe de novo (o módulo é do pacote twstec/kit-uploads)";
        }
    }

    expect($violations)->toBe([]);
});

it('usa os nomes novos das classes do painel /admin, nunca os apelidos App\\Filament\\… e App\\Console\\Commands\\MakeAdminUser', function (): void {
    // Mesma regra, para o que saiu com o pacote twstec/kit-admin: os apelidos
    // (packages/admin/src/Compat) existem só para o que está gravado fora do
    // código — snapshot do Livewire, config de dashboards publicada, estado na
    // sessão. A lista de nomes antigos é a do próprio pacote (fechada): uma
    // tela que o aplicativo escreve em App\Filament\ continua permitida. A
    // leitura é por tokens: o nome antigo escrito como TEXTO não conta.
    $antigos = array_keys(LegacyNames::MAP);
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'demo', 'tests'] as $directory) {
        if (! is_dir(base_path($directory))) {
            continue;
        }

        foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());

            foreach (appReferencesIn($file->getContents()) as $name) {
                if (in_array($name, $antigos, true)) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }
    }

    // Nas views Blade, que não são PHP puro, vale o nome escrito.
    foreach ((new Finder)->files()->in(base_path('resources/views'))->name('*.blade.php') as $file) {
        foreach ($antigos as $antigo) {
            if (str_contains(str_replace('\\\\', '\\', $file->getContents()), $antigo)) {
                $violations[] = str_replace(base_path().'/', '', $file->getRealPath()).' usa '.$antigo;
            }
        }
    }

    // E o painel não voltou a morar no aplicativo: nenhum arquivo de
    // app/Filament com o nome de uma classe que é do pacote.
    foreach ($antigos as $antigo) {
        if (str_starts_with($antigo, 'App\\Filament\\')) {
            $arquivo = app_path(str_replace('\\', '/', substr($antigo, strlen('App\\'))).'.php');

            if (is_file($arquivo)) {
                $violations[] = str_replace(base_path().'/', '', $arquivo).' existe de novo (a classe é do pacote twstec/kit-admin)';
            }
        }
    }

    expect($antigos)->not->toBeEmpty()
        ->and($violations)->toBe([]);
})->group('admin');

it('deixa app/Core vazio: todo o backend do kit mora nos pacotes', function (): void {
    // Desde a extração do pacote uploads (F6), nenhum arquivo do aplicativo
    // mora em app/Core. Backend novo do kit vai para o pacote da camada dele;
    // código do produto de quem usa o kit mora fora de app/Core (app/Domain,
    // app/Models…).
    $restantes = [];

    if (is_dir(base_path('app/Core'))) {
        foreach ((new Finder)->files()->in(base_path('app/Core')) as $file) {
            $restantes[] = str_replace(base_path().'/', '', $file->getRealPath());
        }
    }

    expect($restantes)->toBe([]);
});
