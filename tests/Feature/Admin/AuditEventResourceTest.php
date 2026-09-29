<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\AuditEvents\AuditEventResource;
use Twstec\Kit\Admin\Resources\AuditEvents\Pages\ListAuditEvents;
use Twstec\Kit\Admin\Resources\AuditEvents\Pages\ViewAuditEvent;
use Twstec\Kit\Admin\Resources\RequestLogs\Pages\ViewRequestLog;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Support\ViewModeToggle;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// =============================================================================
// Tela "Auditoria" do /admin: somente leitura, filtros por ação, por quem
// agiu, por registro afetado e por período; detalhe com o resumo já
// mascarado e o link para a linha da trilha de requisições.
// =============================================================================

beforeEach(function () {
    $this->outroAdmin = User::factory()->create(['is_admin' => true]);
    $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'operadora@example.com']);
    $this->actingAs($this->admin);
});

/**
 * Evento gravado como se viesse do /admin, pelo AuditTrail de verdade.
 */
function auditAsAdmin(User $actor, string $action, ?User $subject = null, array $changes = [], ?string $correlation = null): AuditEvent
{
    $scope = new AuditScope(
        context: AuditContext::Admin,
        actorUuid: $actor->uuid,
        actorIsAdmin: true,
        correlationId: $correlation ?? (string) Str::uuid7(),
        ip: '10.0.0.1',
        userAgent: 'Teste',
    );

    return app(AuditTrail::class)->within($scope, fn (): AuditEvent => app(AuditTrail::class)->record($action, $subject, $changes));
}

it('é só leitura: sem criar, editar nem excluir, e não-admin recebe 403', function () {
    $evento = auditAsAdmin($this->admin, 'user.blocked');

    expect(AuditEventResource::canCreate())->toBeFalse()
        ->and(AuditEventResource::canEdit($evento))->toBeFalse()
        ->and(AuditEventResource::canDelete($evento))->toBeFalse()
        ->and(AuditEventResource::canDeleteAny())->toBeFalse()
        ->and(AuditEventResource::hasPage('create'))->toBeFalse()
        ->and(AuditEventResource::hasPage('edit'))->toBeFalse();

    $this->get(AuditEventResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->create())
        ->get(AuditEventResource::getUrl('index'))
        ->assertForbidden();
});

it('lista a ação feita pela tela de usuários, com quem agiu e o registro afetado', function () {
    $alvo = User::factory()->create(['status' => UserStatus::Active]);

    Livewire::test(ListUsers::class)->callTableAction('block', $alvo);

    $evento = AuditEvent::query()->where('action', 'user.blocked')->sole();

    Livewire::test(ListAuditEvents::class)
        ->assertCanSeeTableRecords([$evento])
        ->assertSee('user.blocked')
        ->assertSee('operadora@example.com')
        ->assertSee(__('admin.audit.type_user'))
        ->assertSee(__('admin.audit.outcome_success'));
});

it('filtra por ação, por quem agiu, por registro afetado e por período', function () {
    $alvo = User::factory()->create();
    $outroAlvo = User::factory()->create();

    $bloqueio = auditAsAdmin($this->admin, 'user.blocked', $alvo);
    $revogacao = auditAsAdmin($this->outroAdmin, 'api_key.revoked');

    $this->travelTo(now()->subDays(20));
    $antigo = auditAsAdmin($this->admin, 'user.unblocked', $outroAlvo);
    $this->travelBack();

    Livewire::test(ListAuditEvents::class)
        ->filterTable('action', 'user.blocked')
        ->assertCanSeeTableRecords([$bloqueio])
        ->assertCanNotSeeTableRecords([$revogacao, $antigo]);

    Livewire::test(ListAuditEvents::class)
        ->filterTable('actor', $this->outroAdmin->uuid)
        ->assertCanSeeTableRecords([$revogacao])
        ->assertCanNotSeeTableRecords([$bloqueio, $antigo]);

    Livewire::test(ListAuditEvents::class)
        ->filterTable('subject', ['type' => 'user', 'uuid' => $alvo->uuid])
        ->assertCanSeeTableRecords([$bloqueio])
        ->assertCanNotSeeTableRecords([$revogacao, $antigo]);

    Livewire::test(ListAuditEvents::class)
        ->filterTable('occurred_at', ['from' => now()->subDays(2)->toDateString()])
        ->assertCanSeeTableRecords([$bloqueio, $revogacao])
        ->assertCanNotSeeTableRecords([$antigo]);

    // Valor adulterado (não é uuid) não derruba a consulta — só não acha nada.
    Livewire::test(ListAuditEvents::class)
        ->filterTable('actor', "x' OR 1=1 --")
        ->assertCanNotSeeTableRecords([$bloqueio, $revogacao, $antigo]);
});

it('recusas aparecem com o resultado "Recusada" e filtram à parte', function () {
    config()->set('ui.demo_login.enabled', true);
    $demo = User::factory()->create(['email' => config('ui.demo_login.email')]);

    Livewire::test(ListUsers::class)->callTableAction('block', $demo);

    $recusa = AuditEvent::query()->where('outcome', 'denied')->sole();

    Livewire::test(ListAuditEvents::class)
        ->filterTable('outcome', 'denied')
        ->assertCanSeeTableRecords([$recusa])
        ->assertSee(__('admin.audit.outcome_denied'));
})->group('demo');

it('o detalhe mostra o resumo já mascarado e aponta para a requisição pelo correlation_id', function () {
    $correlacao = (string) Str::uuid7();
    $requisicao = RequestLog::query()->create([
        'correlation_id' => $correlacao,
        'method' => 'POST',
        'endpoint' => 'livewire/update',
        'status' => RequestLogStatus::Concluida,
    ]);

    $alvo = User::factory()->create();
    $evento = auditAsAdmin($this->admin, 'user.updated', $alvo, [
        'name' => ['before' => 'Paula Antiga', 'after' => 'Paula Nova'],
        'email' => ['before' => 'paula@example.com', 'after' => 'paula.nova@example.com'],
        'password' => ['before' => 'x', 'after' => 'SenhaNova-123'],
    ], $correlacao);

    Livewire::test(ViewAuditEvent::class, ['record' => $evento->uuid])
        ->assertOk()
        ->assertSee('user.updated')
        ->assertSee('P*** A***')
        ->assertSee('P*** N***')
        ->assertSee('p***@example.com')
        ->assertSee('[REDACTED]')
        ->assertDontSee('Paula Antiga')
        ->assertDontSee('paula@example.com')
        ->assertDontSee('SenhaNova-123')
        ->assertSee(e(route('filament.admin.resources.request-logs.view', ['record' => $requisicao->uuid])), false)
        ->assertSee(e(route('filament.admin.resources.users.view', ['record' => $alvo->uuid])), false);
});

it('o detalhe da requisição aponta de volta para as ações auditadas dela', function () {
    $correlacao = (string) Str::uuid7();
    $requisicao = RequestLog::query()->create([
        'correlation_id' => $correlacao,
        'method' => 'POST',
        'endpoint' => 'livewire/update',
        'status' => RequestLogStatus::Concluida,
    ]);

    auditAsAdmin($this->admin, 'user.blocked', null, [], $correlacao);

    Livewire::test(ViewRequestLog::class, ['record' => $requisicao->uuid])
        ->assertSee(__('admin.audit.view_actions'))
        ->assertSee('correlation_id', false);

    $outra = RequestLog::query()->create([
        'correlation_id' => (string) Str::uuid7(),
        'method' => 'GET',
        'endpoint' => '/',
        'status' => RequestLogStatus::Concluida,
    ]);

    Livewire::test(ViewRequestLog::class, ['record' => $outra->uuid])
        ->assertDontSee(__('admin.audit.view_actions'));

    Livewire::test(ListAuditEvents::class)
        ->filterTable('correlation_id', ['value' => $correlacao])
        ->assertCountTableRecords(1);
});

it('tem o alternador tabela/cartões da base e a ação de ver no cartão', function () {
    $evento = auditAsAdmin($this->admin, 'user.blocked');

    expect(AuditEventResource::hasCardView())->toBeTrue();

    Livewire::test(ListAuditEvents::class)->callTableAction(ViewModeToggle::NAME);

    Livewire::test(ListAuditEvents::class)
        ->assertCanSeeTableRecords([$evento])
        ->assertSee('user.blocked')
        ->assertTableActionVisible('view', $evento);
});

it('tem as traduções da tela nos três idiomas', function (string $locale) {
    // As chaves da tela vêm do pacote twstec/kit-admin; as dos tipos da
    // demonstração, do lang/admin.php do pacote dela (mesmo grupo).
    foreach (array_keys(array_replace((require base_path('vendor/twstec/kit-admin/lang/pt_BR/admin.php'))['audit'], (require base_path('vendor/twstec/kit-demo/lang/pt_BR/admin.php'))['audit'])) as $chave) {
        expect(app('translator')->hasForLocale("admin.audit.{$chave}", $locale))
            ->toBeTrue("Falta admin.audit.{$chave} em {$locale}");
    }
})->with(['pt_BR', 'en', 'es'])->group('demo');
