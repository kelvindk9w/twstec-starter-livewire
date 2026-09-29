<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Admin\Pages\Settings;
use Twstec\Kit\Admin\Resources\ApiKeys\Pages\ListApiKeys;
use Twstec\Kit\Admin\Resources\Users\Pages\CreateUser;
use Twstec\Kit\Admin\Resources\Users\Pages\EditUser;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Admin\Support\ViewModeToggle;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\CreateProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\EditProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\ListProducts;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// =============================================================================
// Trilha de auditoria de AÇÕES do /admin (tabela `audit_events`).
//
// Decisão do dono: toda ação de admin fica registrada no BANCO. A captura é
// central (Twstec\Kit\Admin\Support\AdminAudit + Twstec\Kit\Foundation\Audit\AuditTrail): cada
// teste aqui dispara a ação pela tela, como o operador faria, e confere a
// linha — ação estável, quem, qual registro, o antes/depois redigido e a
// origem. As tentativas RECUSADAS pelas guardas também ficam (`denied`).
// =============================================================================

beforeEach(function () {
    // Um segundo admin garante que o ator nunca é o "último admin ativo".
    $this->outroAdmin = User::factory()->create(['is_admin' => true]);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

/**
 * A única linha de auditoria com esta ação.
 */
function auditRow(string $action, ?string $subjectUuid = null): AuditEvent
{
    return AuditEvent::query()
        ->where('action', $action)
        ->when($subjectUuid !== null, fn ($q) => $q->where('subject_uuid', $subjectUuid))
        ->sole();
}

// -----------------------------------------------------------------------------
// Usuários
// -----------------------------------------------------------------------------

it('criar usuário grava user.created com o retrato redigido: sem senha, nome e e-mail mascarados', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana Beatriz Nova',
            'email' => 'ana.nova@example.com',
            'password' => 'Senha-Forte123',
            'password_confirmation' => 'Senha-Forte123',
            'status' => UserStatus::Active->value,
            'is_admin' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ana.nova@example.com')->sole();
    $evento = auditRow('user.created', $user->uuid);
    $bruto = json_encode($evento->getAttributes());

    expect($evento->outcome)->toBe(AuditOutcome::Success)
        ->and($evento->context)->toBe(AuditContext::Admin)
        ->and($evento->actor_uuid)->toBe($this->admin->uuid)
        ->and($evento->changes['name'])->toBe(['before' => null, 'after' => 'A*** B*** N***'])
        ->and($evento->changes['email']['after'])->toBe('a***@example.com')
        ->and($evento->changes['password'])->toBe(['before' => null, 'after' => '[REDACTED]'])
        ->and($evento->changes['status']['after'])->toBe('active')
        ->and($evento->changes['is_admin']['after'])->toBeFalse()
        ->and($bruto)->not->toContain('Senha-Forte123')
        ->and($bruto)->not->toContain((string) $user->getRawOriginal('password'))
        ->and($bruto)->not->toContain('ana.nova@example.com')
        ->and($bruto)->not->toContain('Ana Beatriz');
});

it('editar usuário grava user.updated só com os campos que mudaram (senha nova = [REDACTED])', function () {
    $alvo = User::factory()->create(['name' => 'Carlos Antigo', 'status' => UserStatus::Active]);

    Livewire::test(EditUser::class, ['record' => $alvo->uuid])
        ->fillForm([
            'name' => 'Carlos Novo',
            'password' => 'Outra-Senha456',
            'password_confirmation' => 'Outra-Senha456',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $evento = auditRow('user.updated', $alvo->uuid);

    expect(array_keys($evento->changes))->toEqualCanonicalizing(['name', 'password'])
        ->and($evento->changes['name'])->toBe(['before' => 'C*** A***', 'after' => 'C*** N***'])
        ->and($evento->changes['password'])->toBe(['before' => '[REDACTED]', 'after' => '[REDACTED]'])
        ->and(json_encode($evento->getAttributes()))->not->toContain('Outra-Senha456');
});

it('bloquear e desbloquear gravam user.blocked e user.unblocked com o de/para da situação', function () {
    $alvo = User::factory()->create(['status' => UserStatus::Active]);

    Livewire::test(ListUsers::class)->callTableAction('block', $alvo);
    Livewire::test(ListUsers::class)->callTableAction('unblock', $alvo->fresh());

    expect(auditRow('user.blocked', $alvo->uuid)->changes['status'])->toBe(['before' => 'active', 'after' => 'blocked'])
        ->and(auditRow('user.unblocked', $alvo->uuid)->changes['status'])->toBe(['before' => 'blocked', 'after' => 'active']);
});

it('excluir usuário grava user.deleted com o retrato redigido do que saiu', function () {
    $alvo = User::factory()->create(['email' => 'sai@example.com']);

    Livewire::test(ListUsers::class)->callTableAction('delete', $alvo);

    $evento = auditRow('user.deleted', $alvo->uuid);

    expect(User::query()->whereKey($alvo->id)->exists())->toBeFalse()
        ->and($evento->changes['email'])->toBe(['before' => 's***@example.com', 'after' => null])
        ->and($evento->changes['password']['before'])->toBe('[REDACTED]')
        ->and($evento->changes['remember_token']['before'] ?? null)->toBeIn([null, '[REDACTED]']);
});

it('tentativa RECUSADA de bloquear conta demo fica registrada como denied, sem mudar nada', function () {
    config()->set('ui.demo_login.enabled', true);
    $demo = User::factory()->create(['email' => config('ui.demo_login.email'), 'status' => UserStatus::Active]);

    Livewire::test(ListUsers::class)
        ->callTableAction('block', $demo)
        ->assertNotified(__('admin.users.account_protected'));

    $evento = auditRow('user.blocked', $demo->uuid);

    expect($demo->fresh()->status)->toBe(UserStatus::Active)
        ->and($evento->outcome)->toBe(AuditOutcome::Denied)
        ->and($evento->reason)->toBe(__('admin.users.account_protected'))
        ->and($evento->changes)->toBeNull()
        ->and($evento->actor_uuid)->toBe($this->admin->uuid);
})->group('demo');

it('tentativa RECUSADA de bloquear a si mesmo fica registrada como denied', function () {
    Livewire::test(ListUsers::class)->callTableAction('block', $this->admin);

    $evento = auditRow('user.blocked', $this->admin->uuid);

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active)
        ->and($evento->outcome)->toBe(AuditOutcome::Denied)
        ->and($evento->reason)->toBe(__('admin.users.cannot_block_self'));
});

it('tentativa RECUSADA de excluir a si mesmo (guarda do servidor) fica registrada como denied', function () {
    // A ação some da tela para a própria conta; a guarda `before` é a segunda
    // barreira — exercitada direto, como faria uma chamada forjada.
    $acao = UserResource::deleteAction()->record($this->admin);

    try {
        $acao->callBefore();
    } catch (Cancel) {
        // cancel() é o fluxo esperado da recusa.
    }

    $evento = auditRow('user.deleted', $this->admin->uuid);

    expect(User::query()->whereKey($this->admin->id)->exists())->toBeTrue()
        ->and($evento->outcome)->toBe(AuditOutcome::Denied)
        ->and($evento->reason)->toBe(__('admin.users.cannot_delete_self'));
});

it('edição RECUSADA pela guarda (tirar o próprio acesso) fica registrada como denied', function () {
    Livewire::test(EditUser::class, ['record' => $this->admin->uuid])
        ->fillForm(['status' => UserStatus::Blocked->value])
        ->call('save');

    $evento = auditRow('user.updated', $this->admin->uuid);

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active)
        ->and($evento->outcome)->toBe(AuditOutcome::Denied)
        ->and($evento->reason)->toBe(__('admin.users.cannot_block_self'));
});

// -----------------------------------------------------------------------------
// Chaves de API, produtos, configurações
// -----------------------------------------------------------------------------

it('revogar chave de API grava api_key.revoked, sem o hash da secreta', function () {
    $dono = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($dono);

    Livewire::test(ListApiKeys::class)->callTableAction('revoke', $key);

    $evento = auditRow('api_key.revoked', $key->uuid);

    expect($key->fresh()->status)->toBe(ApiKeyStatus::Revoked)
        ->and($evento->changes['status'])->toBe(['before' => 'active', 'after' => 'revoked'])
        ->and(json_encode($evento->getAttributes()))->not->toContain($secret)
        ->and(json_encode($evento->getAttributes()))->not->toContain((string) $key->getRawOriginal('secret_hash'));
})->group('accounts');

it('produto: criar, editar e excluir gravam product.created/updated/deleted', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['title' => 'Teclado', 'price' => '100,00'])
        ->call('create')
        ->assertHasNoFormErrors();

    $produto = Product::query()->where('title', 'Teclado')->sole();

    Livewire::test(EditProduct::class, ['record' => $produto->uuid])
        ->fillForm(['title' => 'Teclado Mecânico', 'price' => '150,00'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ListProducts::class)->callTableAction('delete', $produto->fresh());

    expect(auditRow('product.created', $produto->uuid)->changes['price']['after'])->toBe(10000)
        ->and(auditRow('product.updated', $produto->uuid)->changes)->toMatchArray([
            'title' => ['before' => 'Teclado', 'after' => 'Teclado Mecânico'],
            'price' => ['before' => 10000, 'after' => 15000],
        ])
        ->and(auditRow('product.deleted', $produto->uuid)->changes['title']['before'])->toBe('Teclado Mecânico');
})->group('demo');

it('configurações: cada chave alterada vira setting.changed com o de/para; chave intocada não gera linha', function () {
    // A expiração das chaves de API é do pacote de contas (opcional); sem
    // ele, a mesma prova com o limite das rotas sensíveis.
    $chave = Kit::has('accounts') ? 'api_keys.inactivity.months' : 'security.rate_limit.sensitive';
    $campo = 'data.'.str_replace('.', '_', $chave);

    Livewire::test(Settings::class)
        ->set($campo, 6)
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(Settings::class)
        ->set($campo, 9)
        ->call('save');

    Livewire::test(Settings::class)
        ->set($campo, null)
        ->call('save');

    $linhas = AuditEvent::query()->where('action', 'setting.changed')->orderBy('id')->get();

    expect($linhas)->toHaveCount(3)
        ->and($linhas->pluck('changes')->all())->toBe([
            [$chave => ['before' => null, 'after' => 6]],
            [$chave => ['before' => 6, 'after' => 9]],
            [$chave => ['before' => 9, 'after' => null]],
        ])
        ->and($linhas->every(fn (AuditEvent $e): bool => $e->context === AuditContext::Admin
            && $e->actor_uuid === $this->admin->uuid
            && $e->subject_type === 'setting'))->toBeTrue();
});

// -----------------------------------------------------------------------------
// Perfil do admin e segundo fator
// -----------------------------------------------------------------------------

it('salvar o próprio perfil grava user.updated com o nome mascarado', function () {
    $this->admin->forceFill(['name' => 'Rita Souza'])->save();

    Livewire::test(Profile::class)
        ->fillForm(['name' => 'Rita Souza Lima'])
        ->call('save');

    expect(auditRow('user.updated', $this->admin->uuid)->changes['name'])
        ->toBe(['before' => 'R*** S***', 'after' => 'R*** S*** L***']);
});

it('ligar e desligar o 2FA do próprio admin gravam two_factor_enabled/disabled; a senha errada fica como denied', function () {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
    config()->set('ui.demo_login.enabled', false);
    $this->admin->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();

    $codigo = function (): string {
        /** @var VerificationCodeMail $mail */
        $mail = Mail::queued(VerificationCodeMail::class)
            ->filter(fn (VerificationCodeMail $m): bool => $m->purpose === VerificationPurpose::SensitiveAction)
            ->last();

        return $mail->code;
    };

    Livewire::test(Profile::class)
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'errada']);

    Livewire::test(Profile::class)
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'Trans4cao!Segura'])
        ->setActionData(['code' => $codigo()])
        ->callMountedAction();

    Livewire::test(Profile::class)
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'Trans4cao!Segura'])
        ->setActionData(['code' => $codigo()])
        ->callMountedAction();

    $linhas = AuditEvent::query()->where('subject_uuid', $this->admin->uuid)->orderBy('id')->get();

    // Códigos e tokens do fluxo de confirmação não viram linha (models
    // ignorados): só a tentativa recusada e as duas mudanças.
    expect(AuditEvent::query()->count())->toBe(3);

    expect($linhas->map(fn (AuditEvent $e): string => $e->action.':'.$e->outcome->value)->all())->toBe([
        'user.two_factor_enabled:denied',
        'user.two_factor_enabled:success',
        'user.two_factor_disabled:success',
    ])
        ->and($linhas[0]->reason)->toBe(__('auth.transaction_password.invalid'))
        ->and($linhas[1]->changes['two_factor_enabled_at']['before'])->toBeNull()
        ->and($linhas[1]->changes['two_factor_enabled_at']['after'])->toBeString()
        ->and($linhas[2]->changes['two_factor_enabled_at']['after'])->toBeNull()
        // O código e a senha de transação nunca chegam à trilha.
        ->and(json_encode($linhas->map->getAttributes()))->not->toContain('Trans4cao!Segura');
});

// -----------------------------------------------------------------------------
// O que NÃO é ação de dado não vira linha
// -----------------------------------------------------------------------------

it('só olhar, filtrar e trocar tabela/cards não grava nada', function () {
    User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->filterTable('status', UserStatus::Active->value)
        ->callTableAction(ViewModeToggle::NAME)
        ->callTableAction(ViewModeToggle::NAME);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('pelo endpoint real: IP, User-Agent e o MESMO correlation_id da linha de request_logs da requisição', function () {
    $alvo = User::factory()->create();

    $pagina = $this->get('/admin/users')->assertOk();
    $snapshot = livewireSnapshotFrom((string) $pagina->getContent(), 'ListUsers');

    $chamar = function (string $snapshot, string $method, array $params) {
        return $this->call('POST', EndpointResolver::updatePath(), [], [], [], [
            'REMOTE_ADDR' => '203.0.113.7',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LIVEWIRE' => '1',
            'HTTP_USER_AGENT' => 'Navegador-Do-Operador/1.0',
        ], json_encode(['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['method' => $method, 'params' => $params]],
        ]]], JSON_THROW_ON_ERROR));
    };

    // Abre o modal de confirmação (nada muda) e confirma (bloqueia).
    $montado = $chamar($snapshot, 'mountAction', ['block', [], ['table' => true, 'recordKey' => (string) $alvo->getKey()]])->assertOk();
    $confirmado = $chamar($montado->json('components.0.snapshot'), 'callMountedAction', [])->assertOk();

    $evento = auditRow('user.blocked', $alvo->uuid);
    $correlacao = $confirmado->headers->get('X-Correlation-Id');

    expect($alvo->fresh()->status)->toBe(UserStatus::Blocked)
        ->and($evento->ip)->toBe('203.0.113.7')
        ->and($evento->user_agent)->toBe('Navegador-Do-Operador/1.0')
        ->and($evento->correlation_id)->toBe($correlacao)
        ->and(RequestLog::query()->where('correlation_id', $correlacao)->exists())->toBeTrue();
});
