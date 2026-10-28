<?php

declare(strict_types=1);

// =============================================================================
// SÓ DO REPOSITÓRIO DO KIT (monorepo): o docker-compose.yml da RAIZ, o
// ambiente de desenvolvimento de quem clona o kit.
//
// - toda porta publicada só em ${DEV_BIND:-127.0.0.1} (porta publicada pelo
//   Docker passa por cima do firewall da máquina);
// - nenhuma senha padrão: PostgreSQL e Redis exigem DB_PASSWORD e
//   REDIS_PASSWORD (o scripts/preparar-dev gera as duas no .env da raiz), e
//   os containers PHP dos dois starters recebem as mesmas.
//
// Este arquivo NÃO vai para o projeto criado (export-ignore no .gitattributes
// do starter): lá não há o monorepo em volta. No container app do monorepo
// (que monta só starters/livewire) ele pula; no CI, com o checkout inteiro,
// roda.
// =============================================================================

beforeEach(function (): void {
    if (! is_file(base_path('../../docker-compose.yml')) || ! is_dir(base_path('../../packages'))) {
        $this->markTestSkipped('Sem o monorepo em volta (só o checkout inteiro do kit o tem).');
    }
});

function monorepoFile(string $path): string
{
    return (string) file_get_contents(base_path('../../'.$path));
}

it('o compose da raiz publica toda porta só em DEV_BIND (127.0.0.1 por padrão)', function (): void {
    $compose = monorepoFile('docker-compose.yml');

    preg_match_all('/^\s+ports:\n((?:\s+-\s+.*\n)+)/m', $compose, $blocks);

    $mappings = [];
    foreach ($blocks[1] as $block) {
        preg_match_all('/^\s+-\s+"?([^"\s]+)"?/m', $block, $lines);
        $mappings = [...$mappings, ...$lines[1]];
    }

    expect($mappings)->not->toBe([]);

    foreach ($mappings as $mapping) {
        expect($mapping)->toStartWith('${DEV_BIND:-127.0.0.1}:');
    }
});

it('o compose da raiz não tem senha padrão: PostgreSQL e Redis exigem as do .env da raiz', function (): void {
    $compose = monorepoFile('docker-compose.yml');

    expect($compose)->not->toContain('tws_dev_password')
        ->not->toMatch('/\$\{(DB_PASSWORD|REDIS_PASSWORD):-/')
        ->toContain('POSTGRES_PASSWORD: ${DB_PASSWORD:?rode scripts/preparar-dev')
        ->toContain('REDIS_PASSWORD: ${REDIS_PASSWORD:?rode scripts/preparar-dev')
        ->toContain('--requirepass "$$REDIS_PASSWORD"')
        ->toContain('REDISCLI_AUTH: ${REDIS_PASSWORD}');

    // Os seis containers PHP (app, queue, scheduler e os react-*) recebem as
    // mesmas senhas.
    expect(substr_count($compose, 'environment: *dev-secrets'))->toBe(6);

    $script = base_path('../../scripts/preparar-dev');

    expect(is_executable($script))->toBeTrue()
        ->and((string) file_get_contents($script))->toContain('DB_PASSWORD=$db_password')
        ->toContain('REDIS_PASSWORD=$(random_secret)')
        ->toContain('umask 077');
});
