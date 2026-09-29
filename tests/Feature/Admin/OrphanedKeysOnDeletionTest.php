<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Actions\LeaveAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Mail\OrphanedApiKeysMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;

// =============================================================================
// AVISO DE CHAVE ÓRFÃ também na EXCLUSÃO da pessoa (decisão do dono: as chaves
// continuam valendo quando quem criou sai, COM AVISO AOS DONOS).
//
// Por qualquer caminho — o /admin (dentro da transação do painel) ou o model
// direto —, quem era admin/member de conta alheia e criou chave lá deixa o
// dono e os admins daquela conta avisados: uma vez por conta, sem segredo,
// pela fila. Saída pela página seguida de exclusão não duplica; pessoa sem
// chave não gera e-mail; exclusão recusada não avisa ninguém.
// =============================================================================

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

/**
 * @return array{dona: User, admin: User, pessoa: User, empresa: Account, chave: array{api_key: ApiKey, secret_key: string}}
 */
function empresaComChaveDaPessoa(bool $comChave = true): array
{
    $dona = User::factory()->create();
    $admin = User::factory()->create();
    $pessoa = User::factory()->create(['name' => 'Pessoa Excluída']);
    $empresa = app(AccountService::class)->createAccount('Empresa das Chaves', $dona);
    app(AccountService::class)->addMember($empresa, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($empresa, $pessoa, AccountRole::Member);
    $chave = $comChave ? criarChave($pessoa, ['name' => 'Chave da pessoa'], $empresa) : null;

    // Uma chave da pessoa na conta PESSOAL dela sai junto (não é órfã).
    criarChave($pessoa, ['name' => 'Chave pessoal']);

    return ['dona' => $dona, 'admin' => $admin, 'pessoa' => $pessoa, 'empresa' => $empresa, 'chave' => $chave];
}

/**
 * @return list<string> destinatários dos avisos enfileirados
 */
function avisosDeChaveOrfa(): array
{
    $para = [];
    Mail::assertQueued(OrphanedApiKeysMail::class, function (OrphanedApiKeysMail $m) use (&$para): bool {
        $para[] = $m->to[0]['address'];

        return true;
    });
    sort($para);

    return $para;
}

it('excluída pelo /admin: dono e admins da conta alheia recebem o aviso, sem segredo, e a chave continua valendo', function (): void {
    ['dona' => $dona, 'admin' => $admin, 'pessoa' => $pessoa, 'chave' => $chave] = empresaComChaveDaPessoa();
    Mail::fake();

    Livewire::test(ListUsers::class)->callTableAction('delete', $pessoa);

    expect(User::query()->whereKey($pessoa->id)->exists())->toBeFalse();

    $esperado = [$dona->email, $admin->email];
    sort($esperado);
    expect(avisosDeChaveOrfa())->toBe($esperado);

    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->deleted
        && $m->keys === [['name' => 'Chave da pessoa', 'code' => $chave['api_key']->codigo_publico, 'public_key' => $chave['api_key']->public_key]]
        && ! str_contains(serialize($m), $chave['secret_key'])
        && $m->afterCommit === true);

    expect(comoSistema(fn () => $chave['api_key']->fresh()->isUsable()))->toBeTrue();
});

it('excluída pelo model direto: o mesmo aviso', function (): void {
    ['dona' => $dona, 'admin' => $admin, 'pessoa' => $pessoa] = empresaComChaveDaPessoa();
    Mail::fake();

    $pessoa->delete();

    $esperado = [$dona->email, $admin->email];
    sort($esperado);
    expect(avisosDeChaveOrfa())->toBe($esperado);
});

it('saiu pela página e depois foi excluída: um aviso só por destinatário (sem duplicar)', function (): void {
    ['dona' => $dona, 'pessoa' => $pessoa, 'empresa' => $empresa] = empresaComChaveDaPessoa();
    Mail::fake();

    // A mesma Action que o botão "Sair da conta" da página chama. (Aqui, em
    // tests/Feature/Admin, o teste roda em modo sistema — a tela do painel
    // não abre; a prova pela tela está em tests/Feature/Accounts.)
    Accounts::actingAs($empresa, fn () => app(LeaveAccount::class)->handle($pessoa), $pessoa);

    $pessoa->delete();

    Mail::assertQueued(OrphanedApiKeysMail::class, 2);
    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->hasTo($dona->email) && ! $m->deleted);
});

it('pessoa sem chave em conta alheia: nenhum e-mail', function (): void {
    ['pessoa' => $pessoa] = empresaComChaveDaPessoa(comChave: false);
    Mail::fake();

    $pessoa->delete();

    Mail::assertNotQueued(OrphanedApiKeysMail::class);
});

it('exclusão recusada (dona de conta compartilhada que criou chave em conta alheia): nenhum aviso', function (): void {
    ['dona' => $dona, 'empresa' => $empresa] = empresaComChaveDaPessoa();
    $outra = app(AccountService::class)->createAccount('Outra', User::factory()->create());
    app(AccountService::class)->addMember($outra, $dona, AccountRole::Admin);
    criarChave($dona, ['name' => 'Chave da dona na outra'], $outra);
    Mail::fake();

    expect(fn () => $dona->delete())->toThrow(OwnerOfSharedAccountException::class);

    Mail::assertNotQueued(OrphanedApiKeysMail::class);
    expect($empresa->fresh())->not->toBeNull();
});
