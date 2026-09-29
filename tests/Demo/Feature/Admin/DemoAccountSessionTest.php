<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Twstec\Kit\Demo\Accounts\DemoAccountGuard;
use Twstec\Kit\Demo\Accounts\DemoAccountSession;
use Twstec\Kit\Demo\Accounts\DemoAccountTrigger;

// =============================================================================
// GATILHO DAS CONTAS DEMO SINCRONIZADO COM O MODO DEMO DA APLICAÇÃO
// (DemoAccountSession).
//
// O cenário real: uma base teve o modo demo ligado (o gatilho foi instalado
// pela migration/seed) e passou a rodar com ele desligado. O `migrate` do
// deploy não reinstala nada, então o gatilho continua lá. A aplicação é a
// fonte da verdade: com o modo desligado, cada conexão desliga a proteção na
// própria sessão; com ele ligado, nada muda.
//
// Os testes do banco usam uma SEGUNDA conexão (`demo_probe`), porque o
// RefreshDatabase roda cada teste dentro de uma transação na conexão padrão:
// reconectar a padrão jogaria fora essa transação, e o que precisa ser
// provado é justamente o que acontece quando uma conexão NOVA é aberta. A
// `demo_probe` grava de verdade (commit) e por isso limpa o que fez ao final.
// =============================================================================

const DEMO_PROBE = 'demo_probe';

function probeEmails(): array
{
    return DemoAccountGuard::emails();
}

/**
 * Roda o callback com a `demo_probe` como conexão padrão — o
 * DemoAccountTrigger e o DemoAccountGuard falam com a conexão padrão.
 */
function onProbe(Closure $callback): mixed
{
    return DB::usingConnection(DEMO_PROBE, $callback);
}

/** Abre uma conexão física NOVA na `demo_probe` (como um processo novo faria). */
function freshProbe(): void
{
    DB::purge(DEMO_PROBE);
}

function probeFlag(): ?string
{
    return DB::connection(DEMO_PROBE)->selectOne(
        'SELECT current_setting(?, true) AS valor',
        [DemoAccountGuard::DATABASE_FLAG],
    )->valor;
}

/**
 * O estado "a base já teve o modo demo ligado": gatilho instalado e contas
 * demo gravadas, tudo com commit.
 */
function probeWithInstalledTrigger(): void
{
    config()->set('ui.demo_login.enabled', true);
    freshProbe();

    onProbe(function (): void {
        DemoAccountTrigger::install();

        DemoAccountGuard::withoutProtection(function (): void {
            foreach (probeEmails() as $email) {
                DB::table('users')->updateOrInsert(['email' => $email], [
                    'uuid' => (string) Str::uuid(),
                    'name' => 'Demo',
                    'password' => Hash::make('Demo-password1'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    });

    expect(onProbe(fn (): bool => DemoAccountTrigger::installed()))->toBeTrue();
}

describe('conexão PostgreSQL', function () {
    beforeEach(function () {
        config()->set('database.connections.'.DEMO_PROBE, config('database.connections.pgsql'));
        freshProbe();
    });

    afterEach(function () {
        // O Pest roda o afterEach mesmo nos testes pulados (SQLite, ou sem o
        // pacote da demo instalado — tests/TestCase.php).
        if (DB::connection()->getDriverName() !== 'pgsql' || ! TestCase::demoInstalled()) {
            return;
        }

        // Limpa o que a `demo_probe` gravou com commit: gatilho e linhas.
        config()->set('ui.demo_login.enabled', false);
        freshProbe();

        onProbe(function (): void {
            DB::statement('SELECT set_config(?, ?, false)', [DemoAccountGuard::DATABASE_FLAG, 'off']);
            DB::table('users')->whereIn('email', probeEmails())->delete();
            DemoAccountTrigger::drop();
        });

        DB::purge(DEMO_PROBE);
    });

    it('com o modo demo DESLIGADO, a conexão nasce com a proteção desligada na sessão', function () {
        config()->set('ui.demo_login.enabled', false);
        freshProbe();

        expect(probeFlag())->toBe('off');
    });

    it('com o modo demo LIGADO, a conexão não toca na sessão (o gatilho vale)', function () {
        config()->set('ui.demo_login.enabled', true);
        freshProbe();

        expect(probeFlag())->not->toBe('off');
    });

    it('em produção sem opt-out a flag crua ligada não protege — mesma regra do model', function () {
        config()->set('ui.demo_login.enabled', true);
        config()->set('ui.demo.allow_in_production', false);
        app()->detectEnvironment(fn (): string => 'production');

        try {
            freshProbe();

            expect(probeFlag())->toBe('off');
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    });

    it('a base que teve o modo demo ligado passa a aceitar alterar e apagar a demo quando ele é desligado', function () {
        probeWithInstalledTrigger();

        // Processo novo, agora com o modo demo desligado. O gatilho segue
        // instalado no banco — ninguém rodou nada além do deploy.
        config()->set('ui.demo_login.enabled', false);
        freshProbe();

        expect(onProbe(fn (): bool => DemoAccountTrigger::installed()))->toBeTrue();

        $email = probeEmails()[0];

        DB::connection(DEMO_PROBE)->table('users')->where('email', $email)->update(['status' => 'blocked']);
        DB::connection(DEMO_PROBE)->table('users')->where('email', $email)->delete();

        expect(DB::connection(DEMO_PROBE)->table('users')->where('email', $email)->exists())->toBeFalse();
    });

    it('com o modo demo LIGADO, a mesma base continua recusando (nenhuma brecha)', function () {
        probeWithInstalledTrigger();

        config()->set('ui.demo_login.enabled', true);
        freshProbe();

        expect(fn () => DB::connection(DEMO_PROBE)->table('users')->whereIn('email', probeEmails())->delete())
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect(DB::connection(DEMO_PROBE)->table('users')->whereIn('email', probeEmails())->count())
            ->toBe(count(probeEmails()));
    });

    it('sobrevive à reconexão: a sessão nova recebe a flag de novo', function () {
        config()->set('ui.demo_login.enabled', false);
        freshProbe();

        $antes = DB::connection(DEMO_PROBE)->selectOne('SELECT pg_backend_pid() AS pid')->pid;

        DB::reconnect(DEMO_PROBE);

        $depois = DB::connection(DEMO_PROBE)->selectOne('SELECT pg_backend_pid() AS pid')->pid;

        expect($depois)->not->toBe($antes)
            ->and(probeFlag())->toBe('off');
    });

    it('sobrevive a uma conexão perdida (o reconector do Laravel abre outra sessão)', function () {
        config()->set('ui.demo_login.enabled', false);
        freshProbe();

        $pid = DB::connection(DEMO_PROBE)->selectOne('SELECT pg_backend_pid() AS pid')->pid;

        // Derruba a sessão pelo lado do servidor, como um restart do banco ou
        // um pooler reciclando a conexão fariam.
        DB::connection()->statement('SELECT pg_terminate_backend(?)', [$pid]);

        expect(probeFlag())->toBe('off')
            ->and(DB::connection(DEMO_PROBE)->selectOne('SELECT pg_backend_pid() AS pid')->pid)->not->toBe($pid);
    });

    it('withoutProtection devolve a sessão ao estado da aplicação, não a "on"', function () {
        probeWithInstalledTrigger();

        config()->set('ui.demo_login.enabled', false);
        freshProbe();

        onProbe(fn () => DemoAccountGuard::withoutProtection(fn () => null));

        expect(probeFlag())->toBe('off');

        DB::connection(DEMO_PROBE)->table('users')->whereIn('email', probeEmails())->delete();

        expect(DB::connection(DEMO_PROBE)->table('users')->whereIn('email', probeEmails())->exists())->toBeFalse();
    });

    it('withoutProtection com o modo demo ligado religa a proteção ao sair', function () {
        probeWithInstalledTrigger();

        config()->set('ui.demo_login.enabled', true);
        freshProbe();

        onProbe(fn () => DemoAccountGuard::withoutProtection(fn () => null));

        expect(probeFlag())->toBe('on');
    });

    it('não abre conexão só por configurar uma (composer install e config:cache rodam sem banco)', function () {
        config()->set('database.connections.demo_unreachable', array_merge(
            config('database.connections.pgsql'),
            ['host' => '127.0.0.1', 'port' => 1],
        ));

        $conexao = DB::connection('demo_unreachable');

        expect($conexao->getDriverName())->toBe('pgsql')
            ->and($conexao->getRawPdo())->toBeInstanceOf(Closure::class);

        DB::purge('demo_unreachable');
    });
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'O gatilho é do PostgreSQL; rode com -c phpunit.pgsql.xml (o CI roda).',
);

it('fora do PostgreSQL é inerte (SQLite não tem gatilho)', function () {
    config()->set('ui.demo_login.enabled', false);

    $conexao = DB::connection('sqlite');
    $antes = $conexao->getRawPdo();

    DemoAccountSession::prepare($conexao);

    expect($conexao->getRawPdo())->toBe($antes);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'Verifica o caminho do SQLite.',
);

it('a linha de base segue o modo demo', function () {
    config()->set('ui.demo_login.enabled', true);
    expect(DemoAccountSession::baseline())->toBe('on');

    config()->set('ui.demo_login.enabled', false);
    expect(DemoAccountSession::baseline())->toBe('off');
});
