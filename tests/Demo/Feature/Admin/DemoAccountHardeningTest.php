<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Demo\Accounts\DemoAccountGuard;
use Twstec\Kit\Demo\Accounts\DemoAccountTrigger;
use Twstec\Kit\Demo\Accounts\Exceptions\DemoAccountProtectedException;
use Twstec\Kit\Demo\Database\Seeders\DemoAdminSeeder;
use Twstec\Kit\Demo\Database\Seeders\DemoUserSeeder;

// =============================================================================
// BLINDAGEM DAS CONTAS DEMO (DemoAccountGuard) — docs/demo.md, "Contas
// demo são intocáveis".
//
// Antes, só a UI do Filament recusava. Um `php artisan tinker` com três
// linhas trocava a senha da conta demo e derrubava a demonstração para todo
// mundo que chegasse depois. Agora são três camadas, e cada teste aqui
// prova uma delas:
//
//   1. UI      — a mensagem amigável continua aparecendo (nada regrediu);
//   2. MODEL   — eventos updating/deleting lançam exceção (roda no SQLite);
//   3. BANCO   — o gatilho recusa update/delete EM MASSA (só no PostgreSQL:
//                os testes da camada 3 rodam no CI, com phpunit.pgsql.xml,
//                e ficam como skip no `pest` local em SQLite).
//
// E o corte entre CAMPO SENSÍVEL e CAMPO INOFENSIVO é testado nos dois
// sentidos: a demo tem de continuar funcional para quem a está visitando.
// =============================================================================

/**
 * Roda a operação num SAVEPOINT. No PostgreSQL, um erro dentro de transação
 * aborta a transação inteira — e o RefreshDatabase roda cada teste dentro de
 * uma. Sem o savepoint, a recusa do gatilho envenenaria as consultas
 * seguintes do próprio teste ("current transaction is aborted").
 */
function recusadoPeloBanco(Closure $operacao): mixed
{
    return DB::transaction($operacao);
}

beforeEach(function () {
    // Nos testes o modo demo nasce desligado (APP_ENV=testing). A blindagem
    // só vale com ele ligado — é isso que estes testes exercitam.
    config()->set('ui.demo_login.enabled', true);

    $this->demo = User::factory()->create([
        'email' => config('ui.demo_login.email'),
        'name' => DemoUserSeeder::NOME,
    ]);

    $this->demoAdmin = User::factory()->create([
        'email' => config('ui.demo_admin.email'),
        'name' => DemoAdminSeeder::NOME,
        'is_admin' => true,
    ]);
});

// -----------------------------------------------------------------------------
// Camada 2: eventos do model (tinker, comandos, jobs)
// -----------------------------------------------------------------------------

it('recusa excluir a conta demo por código (o caminho do tinker)', function () {
    $id = $this->demo->getKey();

    expect(fn () => User::query()->find($id)->delete())
        ->toThrow(DemoAccountProtectedException::class);

    expect(User::query()->whereKey($id)->exists())->toBeTrue();
});

it('recusa excluir o super admin demo por código', function () {
    expect(fn () => $this->demoAdmin->delete())
        ->toThrow(DemoAccountProtectedException::class);

    expect($this->demoAdmin->fresh())->not->toBeNull();
});

it('recusa alterar campo sensível da conta demo', function (string $campo, mixed $valor) {
    expect(fn () => $this->demo->forceFill([$campo => $valor])->save())
        ->toThrow(DemoAccountProtectedException::class);

    expect($this->demo->fresh()->{$campo})->not->toBe($valor);
})->with([
    'e-mail' => ['email', 'sequestrado@example.com'],
    'senha de login' => ['password', 'Outra-senha-123'],
    'flag de admin' => ['is_admin', true],
    'situação (bloqueio)' => ['status', UserStatus::Blocked],
    'verificação em duas etapas (trancaria a demo)' => ['two_factor_enabled_at', '2026-01-01 00:00:00'],
]);

it('a exceção diz QUAL campo foi recusado (erro que se entende sem abrir o código)', function () {
    try {
        $this->demo->forceFill(['password' => 'Outra-senha-123'])->save();
    } catch (DemoAccountProtectedException $exception) {
        expect($exception->operation)->toBe('update')
            ->and($exception->attributes)->toBe(['password'])
            ->and($exception->email)->toBe(config('ui.demo_login.email'))
            ->and($exception->getMessage())->toContain('password');

        return;
    }

    $this->fail('A alteração da senha da conta demo deveria ter sido recusada.');
});

it('não deixa a conta demo escapar trocando o próprio e-mail antes', function () {
    // Sem olhar o e-mail ORIGINAL, este seria o furo: a instância já não
    // pareceria demo no momento do evento.
    expect(fn () => $this->demo->forceFill([
        'email' => 'nao-sou-mais-demo@example.com',
        'is_admin' => true,
    ])->save())->toThrow(DemoAccountProtectedException::class);

    expect($this->demo->fresh()->email)->toBe(config('ui.demo_login.email'));
});

it('LIBERA os campos inofensivos: a demo continua funcional para quem a visita', function () {
    $this->demo->forceFill([
        'name' => 'Nome escolhido pelo visitante',
        'locale' => 'en',
        'theme' => 'dark',
        'notification_preferences' => ['product_updates' => false],
    ])->save();

    $fresco = $this->demo->fresh();

    expect($fresco->name)->toBe('Nome escolhido pelo visitante')
        ->and($fresco->locale)->toBe('en')
        ->and($fresco->theme)->toBe('dark');
});

it('não atrapalha usuário normal: exclusão e troca de senha seguem funcionando', function () {
    $user = User::factory()->create(['email' => 'pessoa@example.com']);

    $user->forceFill(['password' => 'Senha-normal-123', 'status' => UserStatus::Blocked])->save();
    expect($user->fresh()->status)->toBe(UserStatus::Blocked);

    $user->delete();
    expect(User::query()->where('email', 'pessoa@example.com')->exists())->toBeFalse();
});

it('com o modo demo DESLIGADO nada é bloqueado (produção precisa poder limpar a conta)', function () {
    config()->set('ui.demo_login.enabled', false);

    $this->demo->forceFill(['status' => UserStatus::Blocked])->save();
    expect($this->demo->fresh()->status)->toBe(UserStatus::Blocked);

    $this->demo->delete();
    expect(User::query()->where('email', config('ui.demo_login.email'))->exists())->toBeFalse();
});

// -----------------------------------------------------------------------------
// A porta de serviço: só a semeadura passa
// -----------------------------------------------------------------------------

it('withoutProtection é a única porta: os seeders demo continuam rodando', function () {
    DemoAccountGuard::withoutProtection(function (): void {
        $this->demo->forceFill(['password' => 'Nova-senha-do-seeder1'])->save();
    });

    expect(Hash::check('Nova-senha-do-seeder1', (string) $this->demo->fresh()->password))->toBeTrue();

    // E a porta fecha sozinha ao sair.
    expect(fn () => $this->demo->forceFill(['status' => UserStatus::Blocked])->save())
        ->toThrow(DemoAccountProtectedException::class);
});

it('o seeder do cliente demo é idempotente mesmo com a blindagem ligada', function () {
    $this->seed(DemoUserSeeder::class);
    $this->seed(DemoUserSeeder::class);

    $demo = User::query()->where('email', config('ui.demo_login.email'))->sole();

    expect($demo->name)->toBe('Cliente Demo');
});

it('o seeder do admin demo também roda com a blindagem ligada', function () {
    $this->seed(DemoAdminSeeder::class);

    $admin = User::query()->where('email', config('ui.demo_admin.email'))->sole();

    expect($admin->name)->toBe('Admin Demo')
        ->and($admin->is_admin)->toBeTrue();
});

// -----------------------------------------------------------------------------
// Camada 1: comandos artisan e a UI do Filament
// -----------------------------------------------------------------------------

it('user:make-admin recusa promover a conta demo, com erro legível', function () {
    $this->artisan('user:make-admin', ['email' => config('ui.demo_login.email')])
        ->expectsOutputToContain(__('admin.command.account_protected', ['email' => config('ui.demo_login.email')]))
        ->assertExitCode(1);

    expect($this->demo->fresh()->is_admin)->toBeFalse();
});

it('user:make-admin recusa REBAIXAR o admin demo (o painel ficaria sem dono na demo)', function () {
    $this->artisan('user:make-admin', ['email' => config('ui.demo_admin.email'), '--remove' => true])
        ->assertExitCode(1);

    expect($this->demoAdmin->fresh()->is_admin)->toBeTrue();
});

it('user:make-admin continua promovendo usuário normal', function () {
    $user = User::factory()->create(['email' => 'promovido@example.com']);

    $this->artisan('user:make-admin', ['email' => 'promovido@example.com'])
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('a UI do Filament continua recusando com mensagem amigável, não com stack trace', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->callTableAction('block', $this->demo)
        ->assertNotified(__('admin.users.account_protected'));

    expect($this->demo->fresh()->status)->toBe(UserStatus::Active);
});

// -----------------------------------------------------------------------------
// Camada 3: o gatilho do PostgreSQL (update/delete EM MASSA não passa por
// evento nenhum). Só roda quando a suíte aponta para o pgsql.
// -----------------------------------------------------------------------------

describe('gatilho do PostgreSQL', function () {
    it('está instalado quando o modo demo está ligado', function () {
        DemoAccountTrigger::install();

        expect(DemoAccountTrigger::installed())->toBeTrue();
    });

    it('User::where(...)->delete() NÃO remove a conta demo', function () {
        DemoAccountTrigger::install();

        expect(fn () => recusadoPeloBanco(fn () => User::query()->where('email', config('ui.demo_login.email'))->delete()))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect(User::query()->where('email', config('ui.demo_login.email'))->exists())->toBeTrue();
    });

    it('update em massa não muda campo sensível da conta demo', function () {
        DemoAccountTrigger::install();

        expect(fn () => recusadoPeloBanco(fn () => User::query()->whereKey($this->demo->getKey())->update(['status' => 'blocked'])))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect($this->demo->fresh()->status)->toBe(UserStatus::Active);
    });

    it('update em massa não liga a verificação em duas etapas da conta demo', function () {
        DemoAccountTrigger::install();

        expect(fn () => recusadoPeloBanco(fn () => User::query()->whereKey($this->demo->getKey())->update(['two_factor_enabled_at' => now()])))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect($this->demo->fresh()->two_factor_enabled_at)->toBeNull();
    });

    it('update em massa de campo inofensivo passa (a demo continua funcional)', function () {
        DemoAccountTrigger::install();

        User::query()->whereKey($this->demo->getKey())->update(['locale' => 'es']);

        expect($this->demo->fresh()->locale)->toBe('es');
    });

    it('um DELETE em massa que pega toda a base derruba os outros, mas não a demo', function () {
        DemoAccountTrigger::install();

        User::factory()->count(3)->create();

        expect(fn () => recusadoPeloBanco(fn () => User::query()->delete()))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect(User::query()->where('email', config('ui.demo_login.email'))->exists())->toBeTrue();
    });

    // --- TRUNCATE: statement-level, nunca chama o gatilho de linha -----------

    it('o gatilho de TRUNCATE está instalado junto com o de linha', function () {
        DemoAccountTrigger::install();

        expect(DemoAccountTrigger::truncateGuardInstalled())->toBeTrue();

        $gatilho = DB::selectOne(
            'SELECT tgtype FROM pg_trigger WHERE tgname = ?',
            [DemoAccountTrigger::TRUNCATE_TRIGGER],
        );

        // tgtype: bit 1 = FOR EACH ROW (tem de estar DESLIGADO — TRUNCATE só
        // existe no nível da sentença), bit 2 = BEFORE, bit 32 = TRUNCATE.
        expect($gatilho->tgtype & 1)->toBe(0)
            ->and($gatilho->tgtype & 2)->toBe(2)
            ->and($gatilho->tgtype & 32)->toBe(32);
    });

    it('TRUNCATE users NÃO apaga as contas demo', function () {
        DemoAccountTrigger::install();

        User::factory()->count(2)->create();

        expect(fn () => recusadoPeloBanco(fn () => DB::statement('TRUNCATE users CASCADE')))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect(User::query()->whereIn('email', DemoAccountGuard::emails())->count())->toBe(2)
            ->and(User::query()->count())->toBe(4);
    });

    it('User::truncate() (o atalho do Eloquent) também é recusado', function () {
        DemoAccountTrigger::install();

        expect(fn () => recusadoPeloBanco(fn () => DB::statement('TRUNCATE users RESTART IDENTITY CASCADE')))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect(fn () => recusadoPeloBanco(fn () => User::query()->truncate()))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect($this->demo->fresh())->not->toBeNull();
    });

    it('TRUNCATE ... CASCADE de outra tabela que arrasta users também é recusado', function () {
        DemoAccountTrigger::install();

        // users.avatar_upload_id → uploads: truncar uploads em cascata trunca users.
        expect(fn () => recusadoPeloBanco(fn () => DB::statement('TRUNCATE uploads CASCADE')))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');

        expect($this->demoAdmin->fresh())->not->toBeNull();
    });

    it('withoutProtection é a única porta: com ela o TRUNCATE passa', function () {
        DemoAccountTrigger::install();

        DemoAccountGuard::withoutProtection(fn () => DB::statement('TRUNCATE users CASCADE'));

        expect(User::query()->count())->toBe(0);

        // E a porta fecha ao sair: com demo de volta na tabela, recusa de novo.
        User::factory()->create(['email' => config('ui.demo_login.email')]);

        expect(fn () => recusadoPeloBanco(fn () => DB::statement('TRUNCATE users CASCADE')))
            ->toThrow(QueryException::class, 'TWS_DEMO_ACCOUNT_PROTECTED');
    });

    it('sem conta demo na tabela o TRUNCATE passa (não há o que proteger)', function () {
        DemoAccountTrigger::install();

        DemoAccountGuard::withoutProtection(function (): void {
            User::query()->whereIn('email', DemoAccountGuard::emails())->delete();
        });

        User::factory()->count(2)->create();

        DB::statement('TRUNCATE users CASCADE');

        expect(User::query()->count())->toBe(0);
    });

    it('com o modo demo DESLIGADO o gatilho de TRUNCATE sai junto', function () {
        DemoAccountTrigger::install();
        config()->set('ui.demo_login.enabled', false);

        DemoAccountTrigger::install();

        expect(DemoAccountTrigger::truncateGuardInstalled())->toBeFalse()
            ->and(DemoAccountTrigger::installed())->toBeFalse();
    });
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'O gatilho é do PostgreSQL; rode com -c phpunit.pgsql.xml (o CI roda). No SQLite a camada de model cobre este cenário.',
);
