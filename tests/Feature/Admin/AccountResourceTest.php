<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ListAccounts;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ViewAccount;

// =============================================================================
// "Contas" no /admin do starter: somente leitura, todas as contas (modo
// sistema do painel), filtro por tipo e o detalhe com membros e papéis.
// =============================================================================

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('lista as contas com filtro por tipo e abre o detalhe com os membros', function (): void {
    $dona = User::factory()->create(['name' => 'Dona Admin Teste']);
    $membro = User::factory()->create(['name' => 'Membro Admin Teste']);
    $empresa = app(AccountService::class)->createAccount('Empresa no Admin', $dona);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    Livewire::test(ListAccounts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$empresa, contaPessoal($membro)])
        ->filterTable('personal', false)
        ->assertCanSeeTableRecords([$empresa])
        ->assertCanNotSeeTableRecords([contaPessoal($membro)])
        ->searchTable('Empresa no Admin')
        ->assertCanSeeTableRecords([$empresa]);

    Livewire::test(ViewAccount::class, ['record' => $empresa->uuid])
        ->assertOk()
        ->assertSee('Dona Admin Teste')
        ->assertSee('Membro Admin Teste')
        ->assertSee(__('accounts.roles.member'));

    $this->get('/admin/accounts')->assertOk()->assertSee('Empresa no Admin');
    $this->get("/admin/accounts/{$empresa->uuid}")->assertOk()->assertSee('Membro Admin Teste');
});
