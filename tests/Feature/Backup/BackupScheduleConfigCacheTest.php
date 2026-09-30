<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// =============================================================================
// Frequências do backup com a config em CACHE (como em produção).
//
// Com `php artisan config:cache`, o .env deixa de ser lido: um env() fora de
// config/ devolve o padrão em silêncio. As frequências vêm de
// config/backup.php (schedule.run/clean/monitor) e a rota lê com config().
//
// A prova roda o artisan de verdade, em dois processos: o primeiro gera o
// cache com BACKUP_*_CRON fora do padrão; o segundo lista o agendamento SEM
// essas variáveis no ambiente — só o cache sabe delas. O cache vai para um
// arquivo temporário (APP_CONFIG_CACHE); o bootstrap/cache do projeto não é
// tocado.
// =============================================================================

/**
 * @param  list<string>  $command
 * @param  array<string, string|false>  $env
 */
function backupScheduleArtisan(array $command, array $env): Process
{
    $process = new Process([PHP_BINARY, 'artisan', ...$command, '--no-ansi'], base_path(), $env, null, 120);
    $process->run();

    return $process;
}

it('com config:cache, schedule:list mostra as frequências configuradas, não o padrão', function (): void {
    $cache = sys_get_temp_dir().'/kit-backup-schedule-'.bin2hex(random_bytes(6)).'.php';
    $configured = [
        'BACKUP_RUN_CRON' => '*/5 * * * *',
        'BACKUP_CLEAN_CRON' => '15 4 * * *',
        'BACKUP_MONITOR_CRON' => '45 5 * * *',
    ];

    try {
        $cached = backupScheduleArtisan(['config:cache'], ['APP_CONFIG_CACHE' => $cache, ...$configured]);

        expect($cached->getExitCode())->toBe(0, $cached->getErrorOutput().$cached->getOutput())
            ->and(is_file($cache))->toBeTrue();

        // Sem as variáveis no ambiente (false = remove a herdada): um env()
        // na rota cairia no padrão; config() lê o cache.
        $list = backupScheduleArtisan(['schedule:list'], [
            'APP_CONFIG_CACHE' => $cache,
            ...array_fill_keys(array_keys($configured), false),
        ]);

        expect($list->getExitCode())->toBe(0, $list->getErrorOutput().$list->getOutput());

        $lines = explode("\n", $list->getOutput());
        // schedule:list alinha as colunas do cron com espaços extras.
        $lineOf = fn (string $command): string => (string) preg_replace('/\s+/', ' ', (string) collect($lines)->first(fn (string $line): bool => str_contains($line, $command)));

        expect($lineOf('backup:run'))->toContain('*/5 * * * *')
            ->and($lineOf('backup:clean'))->toContain('15 4 * * *')
            ->and($lineOf('backup:monitor'))->toContain('45 5 * * *');
    } finally {
        @unlink($cache);
    }
});

it('sem cache, as frequências vêm da config (padrões do kit)', function (): void {
    expect(config('backup.schedule'))->toBe([
        'run' => env('BACKUP_RUN_CRON', '0 * * * *'),
        'clean' => env('BACKUP_CLEAN_CRON', '30 2 * * *'),
        'monitor' => env('BACKUP_MONITOR_CRON', '0 3 * * *'),
    ]);
});
