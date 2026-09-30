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
// - o CI base: gatilho manual e semanal, só leitura, ações fixadas por commit.
//
// No repositório do kit, o compose e o CI ficam em docker/dev/; no projeto
// criado, em compose.yaml e .github/workflows/ci.yml.
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

    expect($ci)->toContain("on:\n  workflow_dispatch:\n  schedule:")
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
