<?php

use Composer\InstalledVersions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Twstec\Kit\Foundation\Kit;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =============================================================================
// Assets do Filament nos scripts do Composer (composer.json → scripts).
//
// O Filament vem com o painel /admin (twstec/kit-admin), que é OPCIONAL: sem
// ele, `filament:upgrade` e `filament:assets` não existem, e um script do
// Composer que chama comando inexistente derruba o `composer install` (e o
// build da imagem de produção). Os scripts chamam este comando, que só
// repassa ao Filament quando ele está instalado.
// =============================================================================
Artisan::command('tws:filament-assets {--upgrade : filament:upgrade (depois do dump do autoload e do update)}', function (): int {
    if (! InstalledVersions::isInstalled('filament/filament')) {
        $this->components->info(__('ui.console.filament_skipped'));

        return 0;
    }

    return $this->call($this->option('upgrade') ? 'filament:upgrade' : 'filament:assets', ['--ansi' => true]);
})->purpose(__('ui.console.filament_assets'));

// =============================================================================
// Expiração de chaves de API por inatividade: diário, em UTC.
// Aviso prévio por e-mail + desativação — ver ProcessApiKeyInactivity e
// config/api_keys.php (API_KEYS_INACTIVITY_*). onOneServer/withoutOverlapping
// evitam execução dupla em deploys com múltiplos schedulers. O comando é do
// pacote twstec/kit-accounts: sem ele, não há chaves nem o agendamento.
// =============================================================================
if (Kit::has('accounts')) {
    Schedule::command('api-keys:process-inactivity')
        ->daily()
        ->withoutOverlapping()
        ->onOneServer();
}

// =============================================================================
// Backups: dump lógico do PostgreSQL (pg_dump) em zip
// criptografado → disco de destino (R2 em produção). O sucesso dispara o
// webhook da validação cruzada produção→sandbox (BACKUP_WEBHOOK_URL —
// endpoint receptor no sandbox; contrato em docs/backup.md). Frequências em cron
// (UTC) via .env; backup:clean aplica a retenção e backup:monitor é o
// health check de idade/tamanho. onOneServer/withoutOverlapping evitam
// execução dupla em deploys com múltiplos schedulers.
// =============================================================================
// Poda da tabela `failed_jobs`: job falho guarda o payload e o texto da
// exceção, e sem poda isso fica para sempre. Janela em
// queue.failed.retention_hours (QUEUE_FAILED_RETENTION_HOURS, padrão 7 dias).
Schedule::command('queue:prune-failed', ['--hours' => (int) config('queue.failed.retention_hours', 168)])
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Poda da trilha de auditoria de ações (`audit_events`): a ÚNICA remoção
// que a tabela append-only aceita (ver PruneAuditEvents). Janela em
// audit.retention_days (AUDIT_RETENTION_DAYS, padrão 365 dias; 0 = não poda).
Schedule::command('audit:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// Limpeza dos uploads sem dono (`uploads:prune-orphans`): agendada pelo
// PRÓPRIO pacote twstec/kit-uploads (UPLOADS_PRUNE_SCHEDULE, cron; vazio
// desliga, com aviso no log) — não precisa de linha aqui. Ver docs/uploads.md.

Schedule::command('backup:run --only-db')
    ->cron((string) env('BACKUP_RUN_CRON', '0 * * * *'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:clean')
    ->cron((string) env('BACKUP_CLEAN_CRON', '30 2 * * *'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:monitor')
    ->cron((string) env('BACKUP_MONITOR_CRON', '0 3 * * *'))
    ->withoutOverlapping()
    ->onOneServer();
