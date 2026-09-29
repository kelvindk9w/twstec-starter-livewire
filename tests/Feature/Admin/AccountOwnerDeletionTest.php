<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Support\Exceptions\Cancel;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// EXCLUSÃO DE PESSOA com contas (pelo /admin e por qualquer outro caminho).
//
// - Dona de conta com outros membros: recusada até transferir a propriedade
//   (a transferência chega com as telas de membros). No /admin a ação some e,
//   forjada, é recusada no servidor com o motivo na trilha (`denied`); por
//   fora do painel, o model recusa com exceção. Nada é apagado.
// - Sem conta compartilhada: o mesmo efeito da 1.x — a conta pessoal sai com
//   projetos e chaves.
// =============================================================================

beforeEach(function (): void {
    $this->outroAdmin = User::factory()->create(['is_admin' => true]);
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

/**
 * @return array{dona: User, empresa: Account}
 */
function donaDeEmpresaComMembro(): array
{
    $dona = User::factory()->create();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(AccountService::class)->addMember($empresa, User::factory()->create(), AccountRole::Member);
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    return ['dona' => $dona, 'empresa' => $empresa];
}

it('no /admin, a exclusão da dona de conta com membros não é oferecida e, forjada, é recusada com o motivo na trilha', function (): void {
    ['dona' => $dona, 'empresa' => $empresa] = donaDeEmpresaComMembro();

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('delete', $dona);

    $acao = UserResource::deleteAction()->record($dona);

    try {
        $acao->callBefore();
    } catch (Cancel) {
        // cancel() é o fluxo esperado da recusa.
    }

    $evento = AuditEvent::query()->where('action', 'user.deleted')->where('subject_uuid', $dona->uuid)->sole();

    expect($evento->outcome)->toBe(AuditOutcome::Denied)
        ->and($evento->reason)->toContain($empresa->codigo_publico)
        ->and($evento->reason)->toBe(app(AccountService::class)->deletionDenial($dona))
        ->and(User::query()->whereKey($dona->id)->exists())->toBeTrue()
        ->and($empresa->fresh())->not->toBeNull()
        ->and(Project::query()->where('account_id', $empresa->id)->count())->toBe(1);
});

it('por fora do painel (tinker, job, comando), o model recusa a exclusão com exceção e nada muda', function (): void {
    ['dona' => $dona, 'empresa' => $empresa] = donaDeEmpresaComMembro();

    expect(fn () => $dona->delete())->toThrow(OwnerOfSharedAccountException::class);

    expect($dona->fresh())->not->toBeNull()
        ->and($empresa->fresh())->not->toBeNull()
        ->and(contaPessoal($dona))->not->toBeNull()
        ->and($empresa->members()->count())->toBe(2);
});

it('sem conta compartilhada, excluir pelo /admin leva a conta pessoal com projetos e chaves (como na 1.x)', function (): void {
    $pessoa = User::factory()->create();
    $conta = contaPessoal($pessoa);
    projetoDe($pessoa, 'Meu');
    criarChave($pessoa);

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('delete', $pessoa)
        ->callTableAction('delete', $pessoa);

    expect(User::query()->whereKey($pessoa->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($conta->id)->exists())->toBeFalse()
        ->and(Project::query()->where('account_id', $conta->id)->count())->toBe(0)
        ->and(ApiKey::query()->where('account_id', $conta->id)->count())->toBe(0);
});
