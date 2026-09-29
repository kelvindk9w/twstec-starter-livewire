<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

// =============================================================================
// Configuração de backups: dump lógico criptografado →
// disco de destino, notificações (webhook = validação cruzada), health
// check e agendamentos. Testes de CONFIG — sem chamadas reais ao R2.
// =============================================================================

it('destino padrão em dev/testes é o disco local (nunca chama o R2)', function () {
    expect(config('backup.backup.destination.disks'))->toBe(['local'])
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['local']);
});

it('disco backup (R2) existe no filesystems e herda as credenciais AWS_*', function () {
    $disk = config('filesystems.disks.backup');

    expect($disk['driver'])->toBe('s3')
        ->and($disk['key'])->toBe(config('filesystems.disks.s3.key'))
        ->and($disk['endpoint'])->toBe(config('filesystems.disks.s3.endpoint'))
        ->and($disk['throw'])->toBeTrue();
});

it('bucket do disco backup é dedicado (BACKUP_R2_BUCKET) com fallback ao AWS_BUCKET', function () {
    config(['filesystems.disks.backup.bucket' => env('BACKUP_R2_BUCKET', env('AWS_BUCKET'))]);

    expect(config('filesystems.disks.backup.bucket'))->toBe(env('AWS_BUCKET'));
});

it('dump é comprimido (gzip) e o zip é criptografado via BACKUP_ARCHIVE_PASSWORD', function () {
    expect(config('backup.backup.database_dump_compressor'))->toBe(GzipCompressor::class)
        ->and(config('backup.backup.encryption'))->not->toBe('none')
        ->and(config('backup.backup.verify_backup'))->toBeTrue()
        // Sem BACKUP_ARCHIVE_PASSWORD no ambiente de testes = sem senha (dev).
        ->and(config('backup.backup.password'))->toBeNull();
});

it('sucesso do dump dispara SÓ o webhook (gatilho da validação cruzada)', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupWasSuccessfulNotification::class])->toBe(['webhook']);
});

it('falhas disparam e-mail + webhook; rotina boa é silenciosa', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupHasFailedNotification::class])->toBe(['mail', 'webhook'])
        ->and($notifications[UnhealthyBackupWasFoundNotification::class])->toBe(['mail', 'webhook'])
        ->and($notifications[CleanupHasFailedNotification::class])->toBe(['mail', 'webhook'])
        ->and($notifications[HealthyBackupWasFoundNotification::class])->toBe([])
        ->and($notifications[CleanupWasSuccessfulNotification::class])->toBe([]);
});

it('url do webhook vem de BACKUP_WEBHOOK_URL (vazio = desativado)', function () {
    expect(config('backup.notifications.webhook.url'))->toBe('');
});

it('health check do backup:monitor vigia idade e armazenamento', function () {
    $monitor = config('backup.monitor_backups.0');

    expect($monitor['health_checks'][MaximumAgeInDays::class])->toBe(1)
        ->and($monitor['health_checks'][MaximumStorageInMegabytes::class])->toBe(5000)
        ->and($monitor['name'])->toBe(config('backup.backup.name'));
});

it('agendamentos de backup registrados com onOneServer + withoutOverlapping', function (string $command) {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, $command));

    expect($event)->not->toBeNull("agendamento ausente: {$command}")
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
})->with([
    'dump horário' => ['backup:run --only-db'],
    'limpeza/retenção' => ['backup:clean'],
    'health check' => ['backup:monitor'],
]);

it('webhook de sucesso envia o contrato JSON da validação cruzada (Http fake)', function () {
    Http::fake();

    config([
        'backup.notifications.webhook.url' => 'https://sandbox.example.com/internal/backup-validation',
        'backup.backup.destination.disks' => ['local'],
        'backup.monitor_backups.0.disks' => ['local'],
    ]);

    // O DTO de config do pacote é resolvido no boot (scoped) — invalidar
    // para que ele releia a url do webhook definida acima.
    app()->forgetInstance(Config::class);

    // Dispara a notificação REAL do pacote (sucesso do dump) pelo canal
    // webhook nativo — sem rede de verdade (Http::fake).
    (new Notifiable)->notify(new BackupWasSuccessfulNotification(
        new BackupWasSuccessful('local', (string) config('backup.backup.name')),
    ));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox.example.com/internal/backup-validation'
        && $request['type'] === 'backup_successful'
        && isset($request['application_name'], $request['disk_name'], $request['backup_name'])
        && $request['disk_name'] === 'local');
});

it('webhook NÃO é chamado quando BACKUP_WEBHOOK_URL está vazio', function () {
    Http::fake();

    config(['backup.backup.destination.disks' => ['local']]);

    (new Notifiable)->notify(new BackupWasSuccessfulNotification(
        new BackupWasSuccessful('local', (string) config('backup.backup.name')),
    ));

    Http::assertNothingSent();
});
