<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\EditUser;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Foundation\Audit\AuditChanges;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Audit\Support\AuditEventTrigger;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;

// =============================================================================
// Núcleo da trilha de auditoria de ações (Twstec\Kit\Foundation\Audit): append-only nas
// três camadas, redação do resumo de mudanças, escopo que não vaza, falha
// fechada, console (`user:make-admin`) e a poda por idade.
// =============================================================================

function auditEventFor(array $attributes = []): AuditEvent
{
    return app(AuditTrail::class)->within(
        AuditScope::console('teste'),
        fn (): AuditEvent => app(AuditTrail::class)->record($attributes['action'] ?? 'teste.feito', subjectType: 'teste'),
    );
}

// -----------------------------------------------------------------------------
// Append-only
// -----------------------------------------------------------------------------

it('recusa UPDATE e DELETE pela instância', function () {
    $evento = auditEventFor();

    expect(fn () => $evento->forceFill(['action' => 'outra.coisa'])->save())
        ->toThrow(AppendOnlyViolationException::class)
        ->and(fn () => $evento->delete())->toThrow(AppendOnlyViolationException::class);

    expect(AuditEvent::query()->sole()->action)->toBe('teste.feito');
});

it('recusa escrita EM MASSA pelo Eloquent (update, delete, upsert, increment, truncate)', function () {
    auditEventFor();

    $tentativas = [
        fn () => AuditEvent::query()->update(['action' => 'x']),
        fn () => AuditEvent::query()->where('id', '>', 0)->delete(),
        fn () => AuditEvent::query()->forceDelete(),
        fn () => AuditEvent::query()->upsert([['uuid' => 'x', 'action' => 'y']], ['uuid'], ['action']),
        fn () => AuditEvent::query()->increment('id'),
        fn () => AuditEvent::query()->touch(),
        fn () => AuditEvent::truncate(),
        fn () => AuditEvent::query()->updateOrInsert(['action' => 'x'], ['action' => 'y']),
    ];

    foreach ($tentativas as $tentativa) {
        expect($tentativa)->toThrow(AppendOnlyViolationException::class);
    }

    expect(AuditEvent::query()->count())->toBe(1);
});

it('no PostgreSQL, o gatilho recusa UPDATE/DELETE/TRUNCATE por SQL cru — fora da poda', function () {
    auditEventFor();

    expect(AuditEventTrigger::installed())->toBeTrue();

    foreach ([
        fn () => DB::table('audit_events')->update(['action' => 'x']),
        fn () => DB::table('audit_events')->delete(),
        fn () => DB::statement('TRUNCATE audit_events'),
    ] as $tentativa) {
        try {
            DB::transaction(fn () => $tentativa());
            $this->fail('o banco aceitou uma escrita na trilha append-only');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('TWS_AUDIT_APPEND_ONLY');
        }
    }

    expect(AuditEvent::query()->count())->toBe(1);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'gatilho só existe no PostgreSQL');

// -----------------------------------------------------------------------------
// Redação do resumo de mudanças (AuditChanges)
// -----------------------------------------------------------------------------

it('redige segredo, dado pessoal cifrado, e-mail, CPF e cartão; nulo continua nulo', function () {
    $changes = app(AuditChanges::class)->sanitize([
        'password' => ['before' => 'antiga-123', 'after' => 'nova-456'],
        'transaction_password' => ['before' => null, 'after' => 'Trans4cao!'],
        'remember_token' => ['before' => 'tok', 'after' => 'tok2'],
        'secret_hash' => ['before' => null, 'after' => '$argon2id$abc'],
        'client_secret' => ['before' => null, 'after' => 's3cr3t'],
        'code' => ['before' => null, 'after' => '123456'],
        'webhook_token' => ['before' => null, 'after' => 'whk'],
        'otp_seed' => ['before' => null, 'after' => 'seed'],
        'bio' => ['before' => 'fale com ana@example.com', 'after' => 'CPF 123.456.789-09 cartão 4111 1111 1111 1111'],
        'status' => ['before' => 'active', 'after' => 'blocked'],
    ]);

    $json = json_encode($changes);

    expect($changes['password'])->toBe(['before' => '[REDACTED]', 'after' => '[REDACTED]'])
        ->and($changes['transaction_password'])->toBe(['before' => null, 'after' => '[REDACTED]'])
        ->and($changes['remember_token']['after'])->toBe('[REDACTED]')
        ->and($changes['secret_hash']['after'])->toBe('[REDACTED]')
        ->and($changes['client_secret']['after'])->toBe('[REDACTED]')
        ->and($changes['code']['after'])->toBe('[REDACTED]')
        ->and($changes['webhook_token']['after'])->toBe('[REDACTED]')
        ->and($changes['otp_seed']['after'])->toBe('[REDACTED]')
        ->and($changes['bio']['before'])->toBe('fale com a***@example.com')
        ->and($changes['bio']['after'])->toBe('CPF 123.***.***-09 cartão **** **** **** 1111')
        ->and($changes['status'])->toBe(['before' => 'active', 'after' => 'blocked']);

    foreach (['antiga-123', 'nova-456', 'Trans4cao!', 'argon2id', 's3cr3t', '123456', 'ana@example.com', '456.789', '4111 1111 1111 1111'] as $segredo) {
        expect($json)->not->toContain($segredo);
    }
});

it('usa o que o model declara: atributo $hidden vira [REDACTED], cast encrypted vira iniciais', function () {
    $user = User::factory()->make(['name' => 'Joana Dark Silva']);

    $changes = app(AuditChanges::class)->sanitize([
        'name' => ['before' => null, 'after' => 'Joana Dark Silva'],
        'password' => ['before' => null, 'after' => 'qualquer'],
    ], $user);

    expect($changes['name']['after'])->toBe('J*** D*** S***')
        ->and($changes['password']['after'])->toBe('[REDACTED]')
        ->and(app(AuditChanges::class)->isSecret('remember_token', $user))->toBeTrue()
        ->and(AuditChanges::maskPersonal('  Ana   Maria '))->toBe('A*** M***');
});

it('um save() que só regrava nulo sobre nulo (atributo não carregado) não vira mudança', function () {
    $user = User::factory()->create();

    app(AuditTrail::class)->within(AuditScope::console('teste'), function () use ($user): void {
        $user->forceFill(['avatar_upload_id' => null])->save();
    });

    expect(AuditEvent::query()->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// Escopo: sem ponto de entrada auditado, nada é gravado; o escopo não vaza
// -----------------------------------------------------------------------------

it('fora de um escopo aberto, gravação de model não gera linha', function () {
    User::factory()->create();
    Product::factory()->create();

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(app(AuditTrail::class)->current())->toBeNull();
})->group('demo');

it('o escopo do /admin fecha ao fim da chamada Livewire', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $alvo = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm(['name' => 'Novo Nome'])
        ->call('save');

    expect(app(AuditTrail::class)->current())->toBeNull();

    // Depois da chamada, gravação solta não é mais atribuída ao admin.
    $alvo->forceFill(['name' => 'Fora do Painel'])->save();

    expect(AuditEvent::query()->where('subject_uuid', $alvo->uuid)->count())->toBe(1);
})->group('admin');

// -----------------------------------------------------------------------------
// Falha fechada
// -----------------------------------------------------------------------------

it('FALHA FECHADA: se a linha da trilha não pode ser gravada, a mudança do admin é desfeita', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);
    $alvo = User::factory()->create(['name' => 'Nome Original']);

    Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
        throw new RuntimeException('banco da trilha indisponível');
    });

    $linhas = new ArrayObject;
    $canal = Mockery::mock();
    $canal->shouldIgnoreMissing();
    $canal->shouldReceive('error')->andReturnUsing(function (string $mensagem, array $contexto = []) use ($linhas): void {
        $linhas[] = ['message' => $mensagem, 'context' => $contexto];
    });
    Log::shouldReceive('channel')->with('request_log')->andReturn($canal);
    Log::getFacadeRoot()->shouldIgnoreMissing();

    expect(fn () => Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm(['name' => 'Nome Alterado'])
        ->call('save'))->toThrow(RuntimeException::class, 'banco da trilha indisponível');

    $falha = collect($linhas)->firstWhere('message', AuditTrail::PERSIST_FAILED_MESSAGE);

    expect($alvo->fresh()->name)->toBe('Nome Original')
        ->and(AuditEvent::query()->count())->toBe(0)
        // A segunda camada (arquivo) registra a ação que não pôde ser gravada.
        ->and($falha['context']['action'] ?? null)->toBe('user.updated')
        ->and($falha['context']['subject_uuid'] ?? null)->toBe($alvo->uuid);
})->group('admin');

// -----------------------------------------------------------------------------
// Console: user:make-admin
// -----------------------------------------------------------------------------

it('user:make-admin grava user.admin_granted e user.admin_revoked no contexto console', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->artisan('user:make-admin', ['email' => $user->email])->assertSuccessful();
    $this->artisan('user:make-admin', ['email' => $user->email, '--remove' => true])->assertSuccessful();

    $concedido = AuditEvent::query()->where('action', 'user.admin_granted')->sole();
    $revogado = AuditEvent::query()->where('action', 'user.admin_revoked')->sole();

    expect($concedido->context)->toBe(AuditContext::Console)
        ->and($concedido->outcome)->toBe(AuditOutcome::Success)
        ->and($concedido->actor_uuid)->toBeNull()
        ->and($concedido->correlation_id)->toBeNull()
        ->and($concedido->ip)->toBeNull()
        ->and($concedido->user_agent)->toStartWith('console: user:make-admin (os-user: ')
        ->and($concedido->subject_uuid)->toBe($user->uuid)
        ->and($concedido->changes['is_admin'])->toBe(['before' => false, 'after' => true])
        ->and($revogado->changes['is_admin'])->toBe(['before' => true, 'after' => false])
        ->and($revogado->user_agent)->toContain('--remove');
})->group('admin');

it('user:make-admin: conta demo e e-mail inexistente ficam como denied, sem o e-mail digitado na trilha', function () {
    config()->set('ui.demo_login.enabled', true);
    $demo = User::factory()->create(['email' => config('ui.demo_login.email'), 'is_admin' => false]);

    $this->artisan('user:make-admin', ['email' => $demo->email])->assertFailed();
    $this->artisan('user:make-admin', ['email' => 'ninguem-aqui@example.com'])->assertFailed();

    $recusas = AuditEvent::query()->where('outcome', AuditOutcome::Denied->value)->orderBy('id')->get();

    expect($recusas)->toHaveCount(2)
        ->and($recusas[0]->action)->toBe('user.admin_granted')
        ->and($recusas[0]->subject_uuid)->toBe($demo->uuid)
        ->and($recusas[1]->subject_uuid)->toBeNull()
        ->and($recusas[1]->reason)->toBe(__('admin.command.user_not_found'))
        ->and(json_encode($recusas->map->getAttributes()))->not->toContain('ninguem-aqui@example.com')
        ->and(json_encode($recusas->map->getAttributes()))->not->toContain((string) $demo->email)
        ->and($demo->fresh()->is_admin)->toBeFalse();
})->group('demo')->group('admin');

// -----------------------------------------------------------------------------
// Retenção
// -----------------------------------------------------------------------------

it('audit:prune apaga só o que passou da janela e deixa rastro da poda', function () {
    $this->travelTo(now()->subDays(400));
    auditEventFor(['action' => 'velho.um']);
    auditEventFor(['action' => 'velho.dois']);
    $this->travelBack();

    $this->travelTo(now()->subDays(10));
    auditEventFor(['action' => 'recente.um']);
    $this->travelBack();

    $this->artisan('audit:prune')->assertSuccessful();

    $acoes = AuditEvent::query()->orderBy('id')->pluck('action')->all();
    $rastro = AuditEvent::query()->where('action', 'audit_event.pruned')->sole();

    expect($acoes)->toBe(['recente.um', 'audit_event.pruned'])
        ->and($rastro->context)->toBe(AuditContext::Console)
        ->and($rastro->changes['deleted']['after'])->toBe(2);

    // A porta da poda fecha de novo: a escrita crua continua recusada.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction(fn () => DB::table('audit_events')->delete()))
            ->toThrow(QueryException::class);
    }

    expect(fn () => AuditEvent::query()->delete())->toThrow(AppendOnlyViolationException::class);
});

it('audit:prune respeita AUDIT_RETENTION_DAYS e 0 desliga a poda', function () {
    $this->travelTo(now()->subDays(40));
    auditEventFor(['action' => 'mes.passado']);
    $this->travelBack();

    config()->set('audit.retention_days', 0);
    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditEvent::query()->count())->toBe(1);

    config()->set('audit.retention_days', 30);
    $this->artisan('audit:prune')->assertSuccessful();
    expect(AuditEvent::query()->where('action', 'mes.passado')->exists())->toBeFalse();
});

it('a poda é agendada diariamente, em um servidor só, e a janela padrão é 365 dias', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($evento): bool => str_contains((string) $evento->command, 'audit:prune'));

    expect($evento)->not->toBeNull('poda da trilha de auditoria não agendada')
        ->and($evento->expression)->toBe('0 0 * * *')
        ->and($evento->onOneServer)->toBeTrue()
        ->and(require base_path('config/audit.php'))->toHaveKey('retention_days');

    // Instalação nova, sem .env: o padrão vale.
    $semEnv = (function (): int {
        $anterior = $_ENV['AUDIT_RETENTION_DAYS'] ?? null;
        unset($_ENV['AUDIT_RETENTION_DAYS'], $_SERVER['AUDIT_RETENTION_DAYS']);
        putenv('AUDIT_RETENTION_DAYS');

        try {
            return (require base_path('config/audit.php'))['retention_days'];
        } finally {
            if ($anterior !== null) {
                $_ENV['AUDIT_RETENTION_DAYS'] = $anterior;
            }
        }
    })();

    expect($semEnv)->toBe(365);
});
