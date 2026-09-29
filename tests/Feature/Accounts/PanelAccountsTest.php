<?php

declare(strict_types=1);

use App\Livewire\ApiKeys\Index as ApiKeysIndex;
use App\Livewire\Dashboard;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Models\User;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

// =============================================================================
// CONTAS COM MEMBROS no painel do usuário (Livewire), sem telas novas.
//
// - O dono da conta pessoal (o único caso da 1.x) opera exatamente como antes.
// - Uma pessoa em duas contas vê, em cada tela, só a conta atual — a pessoal
//   por padrão, a selecionada na sessão quando há seleção — e nunca a outra.
// - O papel na conta decide as ações: member cria e edita projeto, não
//   exclui nem gere chaves (403 no servidor e botões fora da tela).
// =============================================================================

/**
 * Empresa de $dona com $pessoa dentro, no papel dado; um projeto e uma chave
 * em cada conta.
 *
 * @return array{empresa: Account, pessoa: User, dona: User}
 */
function empresaComMembro(AccountRole $papel = AccountRole::Member): array
{
    $dona = User::factory()->create();
    $pessoa = User::factory()->withTransactionPassword()->create();
    $empresa = app(AccountService::class)->createAccount('Empresa X', $dona);
    app(AccountService::class)->addMember($empresa, $pessoa, $papel);

    projetoDe($pessoa, 'Projeto pessoal');
    criarChave($pessoa, ['name' => 'Chave pessoal']);
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Projeto da empresa']), $dona);
    criarChave($dona, ['name' => 'Chave da empresa'], $empresa);

    return ['empresa' => $empresa, 'pessoa' => $pessoa, 'dona' => $dona];
}

/**
 * O Livewire da pessoa com a conta selecionada na sessão (como depois de ela
 * escolher a conta no painel).
 */
function livewireNaConta(User $pessoa, Account $conta): LivewireManager
{
    session()->put('accounts.current', $conta->uuid);

    return Livewire::actingAs($pessoa);
}

it('pessoa em duas contas: sem seleção, o painel mostra só a conta pessoal', function (): void {
    ['pessoa' => $pessoa] = empresaComMembro();

    $this->actingAs($pessoa)->get('/projects')->assertOk()->assertSee('Projeto pessoal')->assertDontSee('Projeto da empresa');
    $this->actingAs($pessoa)->get('/api-keys')->assertOk()->assertSee('Chave pessoal')->assertDontSee('Chave da empresa');
});

it('pessoa em duas contas: selecionada a empresa, o painel mostra só a empresa', function (): void {
    ['pessoa' => $pessoa, 'empresa' => $empresa] = empresaComMembro();

    $this->actingAs($pessoa)->withSession(['accounts.current' => $empresa->uuid]);

    $this->get('/projects')->assertOk()->assertSee('Projeto da empresa')->assertDontSee('Projeto pessoal');
    $this->get('/api-keys')->assertOk()->assertSee('Chave da empresa')->assertDontSee('Chave pessoal');
    $this->get('/dashboard')->assertOk();

    // A visão geral conta a empresa (1 projeto, 1 chave ativa), não a pessoal.
    livewireNaConta($pessoa, $empresa)
        ->test(Dashboard::class)
        ->assertViewHas('projectsCount', 1)
        ->assertViewHas('activeKeysCount', 1);
});

it('a ação do painel sobre dado da OUTRA conta da mesma pessoa é 404 uniforme', function (): void {
    ['pessoa' => $pessoa, 'empresa' => $empresa] = empresaComMembro(AccountRole::Admin);

    $daEmpresa = comoSistema(fn () => Project::query()->where('account_id', $empresa->id)->sole());
    $pessoal = comoSistema(fn () => Project::query()->where('account_id', contaPessoal($pessoa)->id)->sole());

    // Na conta pessoal, o projeto da empresa não existe.
    Livewire::actingAs($pessoa)->test(ProjectsIndex::class)
        ->call('startEdit', $daEmpresa->uuid)
        ->assertNotFound();

    // Na empresa, o projeto pessoal não existe.
    livewireNaConta($pessoa, $empresa)
        ->test(ProjectsIndex::class)
        ->call('startDelete', $pessoal->uuid)
        ->assertNotFound();

    expect(comoSistema(fn () => Project::query()->count()))->toBe(2);
});

it('member: cria e edita projeto, não exclui nem gere chaves (403) e não vê esses botões', function (): void {
    ['pessoa' => $pessoa, 'empresa' => $empresa] = empresaComMembro(AccountRole::Member);
    $daEmpresa = comoSistema(fn () => Project::query()->where('account_id', $empresa->id)->sole());
    $chave = comoSistema(fn () => ApiKey::query()->where('account_id', $empresa->id)->sole());

    $projetos = livewireNaConta($pessoa, $empresa)->test(ProjectsIndex::class);

    $projetos->assertSee(__('panel.projects.new'))
        ->assertSeeHtml('wire:click="startEdit(')
        ->assertDontSeeHtml('wire:click="startDelete(');

    $projetos->call('startCreate')->set('name', 'Criado pelo membro')->call('create')->assertHasNoErrors();
    $projetos->call('startDelete', $daEmpresa->uuid)->assertForbidden();

    expect(comoSistema(fn () => Project::query()->where('account_id', $empresa->id)->pluck('name')->sort()->values()->all()))
        ->toBe(['Criado pelo membro', 'Projeto da empresa'])
        ->and(comoSistema(fn () => Project::query()->where('name', 'Criado pelo membro')->value('created_by')))->toBe($pessoa->id);

    livewireNaConta($pessoa, $empresa)
        ->test(ApiKeysIndex::class)
        ->assertSee('Chave da empresa')
        ->assertDontSeeHtml('wire:click="startCreate"')
        ->assertDontSeeHtml('wire:click="startRevoke(')
        ->call('startCreate')->assertForbidden();

    livewireNaConta($pessoa, $empresa)
        ->test(ApiKeysIndex::class)
        ->call('startRevoke', $chave->uuid)->assertForbidden();

    livewireNaConta($pessoa, $empresa)
        ->test(ApiKeysIndex::class)
        ->set('revokingKeyUuid', $chave->uuid)
        ->call('revoke')->assertForbidden();

    expect(comoSistema(fn () => $chave->fresh()->status->value))->toBe('active');
});

it('admin da empresa gere chaves e exclui projetos da empresa', function (): void {
    ['pessoa' => $pessoa, 'empresa' => $empresa] = empresaComMembro(AccountRole::Admin);
    $daEmpresa = comoSistema(fn () => Project::query()->where('account_id', $empresa->id)->sole());

    livewireNaConta($pessoa, $empresa)
        ->test(ProjectsIndex::class)
        ->assertSeeHtml('wire:click="startDelete(')
        ->call('startDelete', $daEmpresa->uuid)
        ->call('removeProject')
        ->assertHasNoErrors();

    expect(comoSistema(fn () => Project::query()->whereKey($daEmpresa->id)->exists()))->toBeFalse();

    livewireNaConta($pessoa, $empresa)
        ->test(ApiKeysIndex::class)
        ->assertSeeHtml('wire:click="startCreate"')
        ->call('startCreate')
        ->assertHasNoErrors();
});

it('o dono da conta pessoal vê e faz tudo, exatamente como na 1.x', function (): void {
    $dono = User::factory()->create();
    $projeto = projetoDe($dono, 'Meu');
    criarChave($dono, ['name' => 'Minha']);

    Livewire::actingAs($dono)->test(ProjectsIndex::class)
        ->assertSee(__('panel.projects.new'))
        ->assertSeeHtml('wire:click="startEdit(')
        ->assertSeeHtml('wire:click="startDelete(')
        ->call('startDelete', $projeto->uuid)
        ->call('removeProject')
        ->assertHasNoErrors();

    Livewire::actingAs($dono)->test(ApiKeysIndex::class)
        ->assertSeeHtml('wire:click="startCreate"')
        ->assertSeeHtml('wire:click="startRotate(')
        ->assertSeeHtml('wire:click="startRevoke(');
});
