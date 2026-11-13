<?php

declare(strict_types=1);

// =============================================================================
// O DOCKER DE PRODUÇÃO (docker-compose.prod.yml, docker/nginx, o entrypoint
// da imagem do app). Travas baratas, lidas nos arquivos; a prova de ponta a
// ponta é a simulação da instalação publicada no CI (as imagens construídas,
// o nginx subindo com os nomes do site e recusando nome inválido).
//
// - o nginx só atende os nomes do site (host desconhecido: 444 / TLS
//   recusado), e o redirect para HTTPS nunca reflete um Host inventado;
// - o public/ é SINCRONIZADO no volume do nginx (o que sobrou da versão
//   anterior sai), e falha ali para a subida;
// - Redis, app e nginx têm healthcheck, e quem depende deles espera o
//   "pronto", não só o "iniciado" (projeto criado sem Redis: sem redis e
//   horizon, com o worker `queue`).
// =============================================================================

function productionFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

it('o nginx de produção só atende os nomes do site: o resto leva 444 ou o TLS recusado, e o redirect não reflete Host inventado', function (): void {
    $conf = productionFile('docker/nginx/prod.conf');
    $dockerfile = productionFile('docker/nginx/Dockerfile');
    $names = productionFile('docker/nginx/server-names.envsh');

    expect($conf)->toMatch('/server \{\s+listen 80 default_server;\s+server_name _;\s+return 444;\s+\}/')
        ->toMatch('/server \{\s+listen 443 ssl default_server;\s+server_name _;\s+ssl_reject_handshake on;\s+\}/')
        ->and(substr_count($conf, 'server_name ${NGINX_SERVER_NAMES};'))->toBe(2);

    // Todo redirect com $host está num server dos nomes do site.
    foreach (preg_split('/^server \{/m', $conf) ?: [] as $block) {
        if (str_contains($block, 'return 301 https://$host')) {
            expect($block)->toContain('server_name ${NGINX_SERVER_NAMES};');
        }
    }

    expect($dockerfile)->toContain('COPY docker/nginx/prod.conf /etc/nginx/templates/default.conf.template')
        ->toContain('COPY --chmod=755 docker/nginx/server-names.envsh /docker-entrypoint.d/05-server-names.envsh')
        ->toContain('ENV NGINX_ENVSUBST_FILTER=^NGINX_SERVER_NAMES$')
        ->not->toContain('COPY docker/nginx/prod.conf /etc/nginx/conf.d/')
        // Nome inválido (ou nenhum) para o container, em vez de entrar como diretiva.
        ->and($names)->toContain('*[!A-Za-z0-9.*\\ -]*)')
        ->toContain('exit 1');
});

it('o public/ é sincronizado no volume do nginx: o que sobrou da versão anterior sai, e a falha para a subida', function (): void {
    $entrypoint = productionFile('docker/php/entrypoint-prod.sh');

    preg_match('/^if \[ -d \/app-public \]; then\n(.*?)^fi$/ms', $entrypoint, $sync);

    expect($sync[1] ?? '')->toContain('cp -a /var/www/html/public/. /app-public/')
        ->toContain('find . -mindepth 1 -depth')
        ->toContain('rm -rf "./$entry"')
        ->not->toContain('|| true')
        ->not->toContain('2>/dev/null')
        ->and($entrypoint)->toMatch('/^set -e$/m');
});

it('Redis, app e nginx de produção têm healthcheck, e quem depende deles espera o pronto', function (): void {
    $compose = productionFile('docker-compose.prod.yml');

    // Cada serviço: as linhas do bloco dele (a indentação do primeiro serviço
    // é a de todos — 2 espaços no Livewire, 4 no React).
    $lines = explode("\n", (string) strstr($compose, "services:\n"));
    preg_match('/^( +)\S/', $lines[1] ?? '', $indent);
    $service = [];
    $current = null;

    foreach (array_slice($lines, 1) as $line) {
        if ($line !== '' && ! str_starts_with($line, ' ') && ! str_starts_with($line, '#')) {
            break;
        }

        if (preg_match('/^'.($indent[1] ?? '  ').'([a-z][\w-]*):$/', $line, $key) === 1) {
            $current = $key[1];
            $service[$current] = '';

            continue;
        }

        if ($current !== null) {
            $service[$current] .= $line."\n";
        }
    }

    // Projeto criado sem Redis: sem os serviços redis e horizon; a fila é o
    // worker `queue` (queue:work), que espera o app pronto.
    if (! array_key_exists('redis', $service)) {
        expect(array_keys($service))->toContain('app', 'nginx', 'queue', 'scheduler')
            ->not->toContain('horizon')
            // Fora dos comentários, nada de Redis nem do Horizon.
            ->and((string) preg_replace('/^\s*#.*$/m', '', $compose))->not->toMatch('/redis|horizon/i')
            ->and($service['queue'])->toContain('php artisan queue:work')
            ->toMatch('/app:\s+condition: service_healthy/');

        foreach (['app', 'nginx', 'postgres'] as $name) {
            expect($service[$name])->toContain('healthcheck:');
        }

        foreach (['app', 'nginx', 'queue', 'scheduler'] as $name) {
            expect($service[$name])->not->toMatch('/^\s+- app$/m')
                ->not->toMatch('/app:\s+condition: service_started/');
        }

        expect($service['nginx'])->toMatch('/app:\s+condition: service_healthy/');

        return;
    }

    expect(array_keys($service))->toContain('app', 'nginx', 'redis', 'horizon', 'scheduler');

    foreach (['app', 'nginx', 'redis', 'postgres'] as $name) {
        expect($service[$name])->toContain('healthcheck:');
    }

    expect($service['redis'])->toContain('REDISCLI_AUTH: ${PROD_REDIS_PASSWORD:?')
        ->toMatch('/redis-cli,\s*ping/')
        ->and($service['nginx'])->toContain('127.0.0.1:8081/nginx-health')
        ->toContain('APP_URL: ${PROD_APP_URL:-https://localhost}')
        ->toContain('NGINX_SERVER_NAMES: ${PROD_SERVER_NAMES:-}')
        ->and($service['app'])->toContain("fsockopen('127.0.0.1', 9000)");

    // Nenhuma dependência de Redis ou do app só por "iniciado".
    foreach (['app', 'nginx', 'horizon', 'scheduler'] as $name) {
        expect($service[$name])->not->toMatch('/^\s+- (app|redis)$/m')
            ->not->toMatch('/(app|redis):\s+condition: service_started/');
    }

    expect($service['nginx'])->toMatch('/app:\s+condition: service_healthy/')
        ->and($service['app'])->toMatch('/redis:\s+condition: service_healthy/')
        ->and($service['horizon'])->toMatch('/redis:\s+condition: service_healthy/');
});

it('o nome do produto em produção é obrigatório, sem padrão do kit, e APP_NAME sai igual ao PLATFORM_NAME', function (): void {
    $compose = productionFile('docker-compose.prod.yml');
    $required = '${PROD_PLATFORM_NAME:?';

    // Um padrão aqui venceria em silêncio o PLATFORM_NAME do .env.prod (o
    // `environment` passa por cima do `env_file`), e sem APP_NAME o cache, o
    // Redis, o Horizon e o backup ficavam com "laravel".
    expect($compose)->toMatch('/^\s+APP_NAME: '.preg_quote($required, '/').'/m')
        ->toMatch('/^\s+PLATFORM_NAME: '.preg_quote($required, '/').'/m')
        ->not->toContain('Starter Kit')
        ->not->toContain('PROD_PLATFORM_NAME:-');

    // A cor da marca tem o mesmo padrão da config/platform.php do kit: nenhum.
    expect($compose)->toContain('PLATFORM_PRIMARY_COLOR: ${PROD_PLATFORM_PRIMARY_COLOR:-}')
        ->and(file_get_contents(kitConfigFile('platform')))->toContain("'primary_color' => env('PLATFORM_PRIMARY_COLOR'),");
});

it('o nginx de produção não desfaz a Referrer-Policy mais restrita de uma rota: repassa a do aplicativo, num cabeçalho só', function (): void {
    $conf = productionFile('docker/nginx/prod.conf');

    // Nada de valor fixo: com dois cabeçalhos, o navegador fica com o último.
    expect($conf)->not->toMatch('/add_header\s+Referrer-Policy\s+"/')
        ->and($conf)->toMatch('/map \$upstream_http_referrer_policy \$kit_referrer_policy \{\s*""\s+"strict-origin-when-cross-origin";\s*default \$upstream_http_referrer_policy;\s*\}/')
        ->and($conf)->toMatch('/add_header Referrer-Policy \$kit_referrer_policy always;/')
        ->and($conf)->toContain('fastcgi_hide_header Referrer-Policy;');
});

it('o PHP das imagens de desenvolvimento e de produção desliga o progresso de upload da sessão (mitigação da CVE-2026-115560)', function (string $ini): void {
    $settings = parse_ini_file(base_path($ini), false, INI_SCANNER_RAW);

    expect($settings)->toBeArray()
        ->and($settings['session.upload_progress.enabled'] ?? null)->toBe('0')
        ->and(productionFile($ini))->toContain('CVE-2026-115560');

    // O arquivo entra mesmo na imagem (o Dockerfile o copia para o conf.d).
    expect(productionFile('docker/php/Dockerfile'))->toContain('COPY '.$ini.' /usr/local/etc/php/conf.d/');
})->with(['docker/php/php-dev.ini', 'docker/php/php-prod.ini']);
