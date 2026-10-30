<?php

declare(strict_types=1);

// =============================================================================
// O DOCKER DE DESENVOLVIMENTO e os arquivos que vão para o projeto criado.
// Travas baratas, lidas nos arquivos (a prova de ponta a ponta é o E2E
// tests/e2e/front-assets.spec e a simulação da instalação publicada):
//
// - o Vite recebe a URL do site (VITE_DEV_APP_URL) e libera no CORS SÓ ela —
//   sem isso, com o `server.origin`, o laravel-vite-plugin libera só a origem
//   do próprio Vite e a página do projeto fica em branco;
// - o banco da suíte PostgreSQL é <DB_DATABASE>_test no db-init do compose
//   (o instalador grava o mesmo nome no phpunit.pgsql.xml);
// - o desenvolvimento usa 30 nas rotas sensíveis (a suíte E2E), e a produção
//   continua com 5;
// - o CI base: gatilho manual e semanal, só leitura, ações fixadas por commit,
//   e o job de E2E só no disparo manual, com .env efêmero e sem segredo;
// - o Dependabot modelo: os cinco ecossistemas nos caminhos do projeto e os
//   pacotes do kit (twstec/*) num grupo próprio;
// - a verificação local com selo (opcional): os scripts existem, são
//   executáveis e o pre-push só vale depois do scripts/instalar-hooks.
//
// No repositório do kit, o compose, o CI e o Dependabot ficam em docker/dev/;
// no projeto criado, em compose.yaml, .github/workflows/ci.yml e
// .github/dependabot.yml (a validação contra o schema oficial e o actionlint
// rodam na simulação da instalação publicada, no CI do kit).
// =============================================================================

function developmentFile(string $project, string $kit): string
{
    return (string) file_get_contents(is_file(base_path($project)) ? base_path($project) : base_path($kit));
}

it('o Vite de desenvolvimento libera no CORS só a origem do site', function (): void {
    $compose = developmentFile('compose.yaml', 'docker/dev/compose.yaml');
    $vite = (string) file_get_contents(base_path(is_file(base_path('vite.config.ts')) ? 'vite.config.ts' : 'vite.config.js'));

    expect($compose)->toContain('VITE_DEV_APP_URL: http://${COMPOSE_PROJECT_NAME}.localhost:${DEV_SITE_PORT:-8080}')
        ->and($vite)->toContain('process.env.VITE_DEV_APP_URL')
        ->and($vite)->toContain('cors: { origin: devAppOrigin ? [devAppOrigin] : [] }');
});

it('o banco da suíte PostgreSQL do db-init é <DB_DATABASE>_test', function (): void {
    $compose = developmentFile('compose.yaml', 'docker/dev/compose.yaml');

    expect($compose)->toContain("datname = '\${DB_DATABASE}_test'")
        ->toContain('CREATE DATABASE \"${DB_DATABASE}_test\"');

    preg_match('/<env name="DB_DATABASE" value="([^"]+)"/', (string) file_get_contents(base_path('phpunit.pgsql.xml')), $match);

    expect($match[1] ?? '')->toEndWith('_test');
});

it('rotas sensíveis: 30 no desenvolvimento (o .env.example), 5 na produção', function (): void {
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^RATE_LIMIT_SENSITIVE=30$/m')
        ->and((string) file_get_contents(base_path('.env.prod.example')))->toMatch('/^# RATE_LIMIT_SENSITIVE=5$/m')
        ->and((string) file_get_contents(config_path('security.php')))->toContain("env('RATE_LIMIT_SENSITIVE', 5)");
});

it('o CI base: manual e semanal, só leitura, ações fixadas por commit', function (): void {
    $ci = developmentFile('.github/workflows/ci.yml', 'docker/dev/ci.yml');

    expect($ci)->toMatch("/^on:\n  workflow_dispatch:\n(    .+\n)*  schedule:\n/m")
        ->toContain("permissions:\n  contents: read")
        ->toContain('persist-credentials: false')
        ->not->toMatch('/^\s*(push|pull_request):/m');

    preg_match_all('/uses: ([^\s]+)/', $ci, $uses);

    expect($uses[1])->not->toBe([]);

    foreach ($uses[1] as $action) {
        expect($action)->toMatch('/@[0-9a-f]{40}$/');
    }

    expect(is_executable(base_path('scripts/verificar')))->toBeTrue();
});

it('o E2E do CI base: só no disparo manual, com .env efêmero e nenhum segredo do repositório', function (): void {
    $ci = developmentFile('.github/workflows/ci.yml', 'docker/dev/ci.yml');

    expect($ci)->toContain("    inputs:\n      e2e:\n")
        ->toContain("type: boolean\n        default: false")
        ->toContain("if: github.event_name == 'workflow_dispatch' && inputs.e2e")
        ->toContain('cp .env.example .env')
        ->toContain('openssl rand')
        ->toContain('run: scripts/e2e')
        ->not->toContain('secrets.')
        ->and(substr_count($ci, 'actions/checkout@'))->toBe(substr_count($ci, 'persist-credentials: false'));

    // O .env efêmero grava as chaves que o compose.yaml exige.
    $compose = developmentFile('compose.yaml', 'docker/dev/compose.yaml');
    preg_match_all('/\$\{([A-Z_]+):\?/', $compose, $required);

    foreach (array_unique($required[1]) as $variable) {
        expect(preg_match("/^{$variable}=\S/m", (string) file_get_contents(base_path('.env.example'))) === 1 || str_contains($ci, "set_env {$variable} "))
            ->toBeTrue("{$variable}: o compose.yaml exige e o .env efêmero do E2E não grava");
    }
});

it('o Dependabot modelo: os cinco ecossistemas nos caminhos do projeto, twstec/* num grupo próprio', function (): void {
    $dependabot = developmentFile('.github/dependabot.yml', 'docker/dev/dependabot.yml');

    expect($dependabot)->toContain("version: 2\n")
        ->toMatch("/^\\s+twstec:\\n\\s+patterns: \\[[\"']twstec\\/\\*[\"']\\]/m")
        ->not->toMatch('/starters\/|packages\//');

    preg_match_all('/package-ecosystem: (\S+)/', $dependabot, $ecosystems);

    expect($ecosystems[1])->toBe(['composer', 'npm', 'github-actions', 'docker', 'docker-compose']);

    // Cada diretório apontado existe no projeto (a raiz e as pastas dos
    // Dockerfiles).
    preg_match_all('/director(?:y|ies): (.+)/', $dependabot, $directories);

    foreach ($directories[1] as $list) {
        preg_match_all("#/[\w/.-]*#", $list, $paths);

        foreach ($paths[0] as $path) {
            expect(is_dir(base_path(ltrim($path, '/'))))->toBeTrue("{$path} não existe no projeto");
        }
    }

    expect(is_file(base_path('docker/php/Dockerfile')))->toBeTrue()
        ->and(is_file(base_path('docker/nginx/Dockerfile')))->toBeTrue();
});

it('a verificação local com selo: scripts executáveis, sh válido e o pre-push só depois do instalar-hooks', function (): void {
    $scripts = ['scripts/verificar', 'scripts/e2e', 'scripts/promover', 'scripts/instalar-hooks', 'scripts/hooks/pre-push'];

    foreach ($scripts as $script) {
        expect(is_executable(base_path($script)))->toBeTrue("{$script} não é executável");

        exec('sh -n '.escapeshellarg(base_path($script)).' 2>&1', $output, $code);
        expect($code)->toBe(0, "{$script}: ".implode("\n", $output));
    }

    exec('sh -n '.escapeshellarg(base_path('scripts/lib/selo.sh')).' 2>&1', $output, $code);
    expect($code)->toBe(0);

    $verificar = (string) file_get_contents(base_path('scripts/verificar'));
    $instalar = (string) file_get_contents(base_path('scripts/instalar-hooks'));
    $promover = (string) file_get_contents(base_path('scripts/promover'));

    // Desligado por padrão: sem --selo, nada é publicado; o hook só vale
    // pelo core.hooksPath que o instalar-hooks grava.
    expect($verificar)->toContain('SELO=0')
        ->toContain('--selo) SELO=1')
        ->toContain('--e2e) E2E=1')
        ->and($instalar)->toContain('git config --local core.hooksPath scripts/hooks')
        ->and($promover)->toContain('git merge-base --is-ancestor')
        ->not->toMatch('/push[^\n]*(--force|\s-f\s)/')
        ->not->toContain('--no-verify')
        ->and((string) file_get_contents(base_path('scripts/lib/selo.sh')))->not->toContain('--no-verify');
});

it('a análise estática roda no CI base e no scripts/verificar, com o mapa dos módulos opcionais', function (): void {
    $ci = developmentFile('.github/workflows/ci.yml', 'docker/dev/ci.yml');
    $verificar = (string) file_get_contents(base_path('scripts/verificar'));
    $phpstan = (string) file_get_contents(base_path('phpstan.neon.dist'));

    $scripts = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['scripts'];

    expect($ci)->toContain('./vendor/bin/phpstan analyse')
        ->and($verificar)->toContain('./vendor/bin/phpstan analyse')
        ->and($scripts['analyse'] ?? null)->toBe(['phpstan analyse --memory-limit=2G'])
        ->and($phpstan)->toMatch('/^includes:(\n\s+- .+)*\n\s+- phpstan\.modules\.php$/m');

    // Com todos os módulos instalados (a suíte do kit), o mapa não exclui
    // nem ignora nada: a análise cobre o app/ inteiro. Sem algum módulo, o
    // que sai da análise é só dele — provado pelo Larastan na simulação de
    // instalação do CI do kit.
    $installed = array_filter(
        ['webhooks', 'uploads', 'admin', 'accounts'],
        static fn (string $module): bool => is_dir(base_path('vendor/twstec/kit-'.$module)),
    );

    if (count($installed) === 4) {
        $modules = require base_path('phpstan.modules.php');

        expect($modules['parameters']['excludePaths']['analyse'])->toBe([])
            ->and($modules['parameters']['ignoreErrors'])->toBe([]);
    }
});

it('a análise estática enxerga as colunas e os casts dos models do kit pelas extensões dos pacotes', function (): void {
    // Cada pacote do kit com tabelas traz o extension.neon que aponta o
    // Larastan para as migrations DELE (no vendor/) e liga a leitura do
    // casts(); o phpstan.modules.php inclui a de cada pacote instalado — sem
    // ela, o projeto teria de listar vendor/twstec/kit-*/database/migrations
    // à mão. A prova de ponta a ponta (a análise acusando um erro de
    // nulidade numa coluna do kit) está na simulação de instalação do CI.
    $packages = glob(base_path('vendor/twstec/kit-*/database/migrations'), GLOB_ONLYDIR) ?: [];
    $modules = require base_path('phpstan.modules.php');
    $phpstan = (string) file_get_contents(base_path('phpstan.neon.dist'));

    expect($packages)->not->toBeEmpty();

    foreach ($packages as $migrations) {
        $extension = dirname($migrations, 2).'/extension.neon';

        expect(is_file($extension))->toBeTrue("sem extension.neon: {$extension}");

        $neon = (string) file_get_contents($extension);

        expect($neon)->toMatch("/^expandRelativePaths:\n\s+- '\[parameters\]\[databaseMigrationsPath\]\[\]'$/m")
            ->and($neon)->toMatch('/^\s+databaseMigrationsPath:\n\s+- database\/migrations$/m')
            ->and($neon)->toMatch('/^\s+parseModelCastsMethod: true$/m')
            ->and($modules['includes'])->toContain($extension);
    }

    // Com as extensões, o Larastan não lê mais sozinho as migrations do
    // projeto: elas ficam listadas no phpstan.neon.dist.
    expect($phpstan)->toMatch('/^\s+databaseMigrationsPath:\n\s+- database\/migrations$/m');
});
