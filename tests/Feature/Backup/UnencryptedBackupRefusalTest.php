<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\BackupConfig;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupHasFailed;
use Twstec\Kit\Foundation\Backup\BackupEncryption;
use Twstec\Kit\Foundation\Backup\Console\GuardedBackupCommand;
use Twstec\Kit\Foundation\Backup\Exceptions\UnencryptedBackupRefusedException;

// =============================================================================
// BACKUP SEM CRIPTOGRAFIA EM SILÊNCIO (Twstec\Kit\Foundation\Backup\BackupEncryption).
//
// Com BACKUP_ARCHIVE_PASSWORD vazia o pacote monta o zip em claro e ninguém
// fica sabendo — em produção, é o dump do banco inteiro indo para o R2.
// Agora, em produção, o `backup:run` RECUSA (erro, log e alerta de falha de
// backup); fora dela, avisa e segue. A recusa mora no COMANDO: nada disto
// roda no boot, então instalação e manutenção não são afetadas.
// =============================================================================

/**
 * Backup de ARQUIVOS pequeno e isolado num disco falso — o suficiente para
 * o pacote montar um zip de verdade sem tocar no banco nem no R2.
 */
function backupDeTeste(?string $senha, string $cifra = 'default'): void
{
    $origem = storage_path('framework/testing/backup-origem');
    @mkdir($origem, 0755, true);
    file_put_contents($origem.'/dado.txt', 'conteudo sensivel de teste');

    Storage::fake('local');

    config()->set('backup.backup.password', $senha);
    config()->set('backup.backup.encryption', $cifra);
    config()->set('backup.backup.source.files.include', [$origem]);
    config()->set('backup.backup.source.files.exclude', []);
    config()->set('backup.backup.destination.disks', ['local']);
    config()->set('backup.notifications.webhook.url', '');

    // O DTO de config do pacote é resolvido uma vez; relê a configuração acima.
    app()->forgetInstance(Config::class);
}

function simulaProducaoDeBackup(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

function zipsGerados(): array
{
    return collect(Storage::disk('local')->allFiles())
        ->filter(fn (string $arquivo): bool => str_ends_with($arquivo, '.zip'))
        ->values()
        ->all();
}

// -----------------------------------------------------------------------------
// O contrato
// -----------------------------------------------------------------------------

it('identifica os três jeitos de o zip sair em claro', function (?string $senha, string $cifra, ?string $esperado) {
    $config = BackupConfig::fromArray([
        ...config('backup.backup'),
        'password' => $senha,
        'encryption' => $cifra,
    ]);

    expect(BackupEncryption::problem($config))->toBe($esperado);
})->with([
    'sem senha' => [null, 'default', BackupEncryption::MISSING],
    'senha só com espaços' => ['   ', 'default', BackupEncryption::MISSING],
    'senha de fachada' => ['troque-esta-senha', 'default', BackupEncryption::PLACEHOLDER],
    'senha degenerada' => ['AAAAAAAAAAAAAAAA', 'default', BackupEncryption::PLACEHOLDER],
    'cifra desligada' => ['uma-senha-forte-e-propria-7f3a9c', 'none', BackupEncryption::DISABLED],
    'protegido' => ['uma-senha-forte-e-propria-7f3a9c', 'aes256', null],
]);

it('recusa só em produção e sem opt-out', function (bool $producao, bool $optOut, bool $recusa) {
    expect(BackupEncryption::refusalRequired($producao, $optOut))->toBe($recusa);
})->with([
    'produção' => [true, false, true],
    'produção com opt-out' => [true, true, false],
    'dev' => [false, false, false],
]);

it('o backup:run registrado é a versão com a regra da criptografia', function () {
    expect(Artisan::all()['backup:run'])->toBeInstanceOf(GuardedBackupCommand::class);
});

// -----------------------------------------------------------------------------
// Produção: recusa alta
// -----------------------------------------------------------------------------

it('em produção, sem senha, o backup é RECUSADO com mensagem, log e alerta — e nada é gerado', function () {
    Event::fake([BackupHasFailed::class]);
    Log::spy();
    backupDeTeste(null);
    simulaProducaoDeBackup();

    $this->artisan('backup:run', ['--only-files' => true])
        ->expectsOutputToContain('Backup RECUSADO em APP_ENV=production')
        ->assertExitCode(1);

    expect(zipsGerados())->toBe([]);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'BACKUP_ARCHIVE_PASSWORD'))
        ->once();

    // O mesmo evento de falha que já manda e-mail + webhook quando o dump quebra.
    Event::assertDispatched(
        BackupHasFailed::class,
        fn (BackupHasFailed $evento): bool => $evento->exception instanceof UnencryptedBackupRefusedException,
    );
});

it('em produção, senha de fachada também é recusada', function () {
    Event::fake([BackupHasFailed::class]);
    backupDeTeste('troque-esta-senha');
    simulaProducaoDeBackup();

    $this->artisan('backup:run', ['--only-files' => true])
        ->expectsOutputToContain('placeholder')
        ->assertExitCode(1);

    expect(zipsGerados())->toBe([]);
});

it('em produção, cifra desligada é recusada mesmo com senha', function () {
    Event::fake([BackupHasFailed::class]);
    backupDeTeste('uma-senha-forte-e-propria-7f3a9c', 'none');
    simulaProducaoDeBackup();

    $this->artisan('backup:run', ['--only-files' => true])->assertExitCode(1);

    expect(zipsGerados())->toBe([]);
});

it('--disable-notifications recusa igual, só sem o alerta', function () {
    Event::fake([BackupHasFailed::class]);
    backupDeTeste(null);
    simulaProducaoDeBackup();

    $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true])
        ->assertExitCode(1);

    Event::assertNotDispatched(BackupHasFailed::class);
});

it('em produção, COM senha própria, o backup roda e o zip sai criptografado', function () {
    backupDeTeste('uma-senha-forte-e-propria-7f3a9c', 'aes256');
    simulaProducaoDeBackup();

    $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true])
        ->assertExitCode(0);

    $zips = zipsGerados();
    expect($zips)->toHaveCount(1);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($zips[0])))->toBeTrue();

    $cifrados = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $cifrados += ($zip->statIndex($i)['encryption_method'] ?? 0) !== ZipArchive::EM_NONE ? 1 : 0;
    }
    $zip->close();

    expect($cifrados)->toBeGreaterThan(0);
});

it('em produção com opt-out explícito, roda sem criptografia e deixa aviso no log', function () {
    Log::spy();
    backupDeTeste(null);
    simulaProducaoDeBackup();
    config()->set('security.backup.allow_unencrypted_in_production', true);

    $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true])
        ->assertExitCode(0);

    expect(zipsGerados())->toHaveCount(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'BACKUP_ALLOW_UNENCRYPTED_IN_PRODUCTION'))
        ->once();
});

// -----------------------------------------------------------------------------
// Fora de produção: aviso, não recusa
// -----------------------------------------------------------------------------

it('em dev, sem senha, o backup roda com aviso', function () {
    backupDeTeste(null);

    $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true])
        ->expectsOutputToContain('Permitido fora de produção')
        ->assertExitCode(0);

    expect(zipsGerados())->toHaveCount(1);
});

it('--config= alternativo é avaliado pela configuração que será usada de fato', function () {
    Event::fake([BackupHasFailed::class]);
    backupDeTeste('uma-senha-forte-e-propria-7f3a9c', 'aes256');
    simulaProducaoDeBackup();

    // A config padrão está protegida; a alternativa, não.
    config()->set('backup_sem_senha', [...config('backup'), 'backup' => [...config('backup.backup'), 'password' => null]]);

    $this->artisan('backup:run', ['--only-files' => true, '--config' => 'backup_sem_senha'])
        ->assertExitCode(1);

    expect(zipsGerados())->toBe([]);
});

// -----------------------------------------------------------------------------
// Processo real, como o agendamento o dispara
// -----------------------------------------------------------------------------

it('o processo `artisan backup:run` real recusa em produção sem senha — o caminho do agendamento', function (): void {
    $resultado = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'BACKUP_ARCHIVE_PASSWORD' => ''])
        ->run('php artisan backup:run --only-db --disable-notifications --no-ansi');

    expect($resultado->exitCode())->toBe(1);
    expect($resultado->output().$resultado->errorOutput())
        ->toContain('Backup RECUSADO em APP_ENV=production')
        ->not->toContain('Starting backup');
});

it('a regra não toca o boot: `package:discover` em produção sem senha de backup segue funcionando', function (): void {
    $resultado = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'BACKUP_ARCHIVE_PASSWORD' => ''])
        ->run('php artisan package:discover --no-ansi');

    expect($resultado->exitCode())->toBe(0);
});
