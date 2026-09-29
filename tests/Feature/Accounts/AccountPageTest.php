<?php

declare(strict_types=1);

use App\Livewire\Account\Create as AccountCreate;
use App\Livewire\Account\Show as AccountShow;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Mail\AccountInvitationMail;
use Twstec\Kit\Accounts\Account\Mail\OrphanedApiKeysMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// A PÁGINA DA CONTA no painel Livewire (/account) e a criação de conta.
//
// - Cada botão aparece só para o papel que pode usá-lo — e a ação chamada
//   por fora (endpoint do Livewire) é recusada no servidor (403). As duas
//   coisas, para cada linha da matriz.
// - Convidar, reenviar, revogar, mudar papel, remover, sair, transferir (com
//   senha de transação + código) e excluir (idem), com a trilha no banco.
// - Seletor de conta no layout, alternador tabela/cartões.
// =============================================================================

/**
 * @return array{empresa: Account, dono: User, admin: User, membro: User}
 */
function contaComEquipe(): array
{
    $dono = User::factory()->withTransactionPassword()->create(['name' => 'Dona Equipe']);
    $admin = User::factory()->withTransactionPassword()->create(['name' => 'Admin Equipe']);
    $membro = User::factory()->create(['name' => 'Membro Equipe']);
    $empresa = app(AccountService::class)->createAccount('Equipe SA', $dono);
    app(AccountService::class)->addMember($empresa, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    return ['empresa' => $empresa, 'dono' => $dono, 'admin' => $admin, 'membro' => $membro];
}

function paginaDaConta(User $pessoa, Account $conta): mixed
{
    test()->actingAs($pessoa);
    session()->put('accounts.current', $conta->uuid);

    return Livewire::actingAs($pessoa)->test(AccountShow::class);
}

/**
 * Passa pela confirmação sensível do modal (senha de transação → código do
 * e-mail capturado com Mail::fake) e executa a ação pendente.
 */
function confirmarSensivel(mixed $tela): mixed
{
    $tela->set('sensitivePassword', 'Trans4cao!Segura')->call('sendSensitiveCode')->assertHasNoErrors();

    $codigo = null;
    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    return $tela->set('sensitiveCode', (string) $codigo)->call('confirmSensitiveAction');
}

it('a página abre no layout do painel, com o seletor mostrando a conta atual', function (): void {
    ['empresa' => $empresa, 'membro' => $membro] = contaComEquipe();

    $this->actingAs($membro)->withSession(['accounts.current' => $empresa->uuid])
        ->get('/account')
        ->assertOk()
        ->assertSee('Equipe SA')
        ->assertSee('Dona Equipe')
        ->assertSee('Admin Equipe')
        ->assertSeeHtml('data-account-switcher')
        ->assertSeeHtml('data-current-account')
        ->assertSee(__('ui.account_switcher.heading'));

    // Em outra tela do painel, o seletor continua lá, com a conta atual.
    $this->actingAs($membro)->withSession(['accounts.current' => $empresa->uuid])
        ->get('/projects')
        ->assertOk()
        ->assertSeeHtml('data-current-account>Equipe SA<');
});

it('member: vê os membros, não vê nenhuma ação de gestão — e o servidor recusa cada uma (403)', function (): void {
    ['empresa' => $empresa, 'membro' => $membro, 'admin' => $admin] = contaComEquipe();

    $tela = paginaDaConta($membro, $empresa)
        ->assertSee('Admin Equipe')
        ->assertDontSeeHtml('data-invite-form')
        ->assertDontSeeHtml('data-action="promote"')
        ->assertDontSeeHtml('data-action="demote"')
        ->assertDontSeeHtml('data-action="remove"')
        ->assertDontSeeHtml('data-action="rename"')
        ->assertDontSeeHtml('data-transfer')
        ->assertDontSeeHtml('data-delete-account')
        ->assertSeeHtml('data-leave');

    Mail::fake();
    $tela->set('inviteEmail', 'x@example.com')->call('invite')->assertForbidden();
    paginaDaConta($membro, $empresa)->call('changeRole', $admin->uuid, 'member')->assertForbidden();
    paginaDaConta($membro, $empresa)->set('removingUuid', $admin->uuid)->call('removeMember')->assertForbidden();
    paginaDaConta($membro, $empresa)->call('startRename')->assertForbidden();
    paginaDaConta($membro, $empresa)->set('transferTo', $admin->uuid)->call('requestTransfer')->assertForbidden();
    paginaDaConta($membro, $empresa)->call('requestDelete')->assertForbidden();

    Mail::assertNotQueued(AccountInvitationMail::class);
    expect($empresa->roleOf($admin))->toBe(AccountRole::Admin)
        // Cada recusa na trilha: as 3 das Actions (convite, papel, remoção) e
        // as 3 das pré-checagens (renomear, transferir, excluir).
        ->and(AuditEvent::query()->where('tenant_uuid', $empresa->uuid)->where('outcome', 'denied')->pluck('action')->sort()->values()->all())
        ->toBe(['account.deleted', 'account.invitation_created', 'account.member_removed', 'account.member_role_changed', 'account.ownership_transferred', 'account.renamed']);
});

it('pré-checagem forjada por quem não pode: o mesmo 403 e a recusa na trilha, com quem tentou', function (): void {
    ['empresa' => $empresa, 'admin' => $admin, 'membro' => $membro, 'dono' => $dono] = contaComEquipe();
    Mail::fake();
    paginaDaConta($dono, $empresa)->set('inviteEmail', 'aberto@example.com')->set('inviteRole', 'member')->call('invite')->assertHasNoErrors();
    $convite = Accounts::actingAs($empresa, fn () => AccountInvitation::query()->where('email', 'aberto@example.com')->sole());

    // Admin forja as ações de dono; member forja as de gestão.
    paginaDaConta($admin, $empresa)->set('transferTo', $membro->uuid)->call('requestTransfer')->assertForbidden();
    paginaDaConta($admin, $empresa)->call('requestDelete')->assertForbidden();
    paginaDaConta($membro, $empresa)->call('startRename')->assertForbidden();
    paginaDaConta($membro, $empresa)->call('startRemove', $admin->uuid)->assertForbidden();
    paginaDaConta($membro, $empresa)->call('startRevokeInvitation', $convite->uuid)->assertForbidden();

    $recusas = AuditEvent::query()->where('tenant_uuid', $empresa->uuid)->where('outcome', 'denied')->orderBy('id')->get();

    expect($recusas->map(fn (AuditEvent $e): array => [$e->action, $e->actor_uuid, $e->reason])->all())->toBe([
        ['account.ownership_transferred', $admin->uuid, __('accounts.authorization.denied')],
        ['account.deleted', $admin->uuid, __('accounts.authorization.denied')],
        ['account.renamed', $membro->uuid, __('accounts.authorization.denied')],
        ['account.member_removed', $membro->uuid, __('accounts.authorization.denied')],
        ['account.invitation_revoked', $membro->uuid, __('accounts.authorization.denied')],
    ])
        ->and($empresa->fresh()->owner->is($dono))->toBeTrue()
        ->and($empresa->hasMember($admin))->toBeTrue()
        ->and($convite->fresh()->revoked_at)->toBeNull();
    Mail::assertNotQueued(VerificationCodeMail::class);

    // Quem pode passa pela pré-checagem sem linha de recusa.
    paginaDaConta($dono, $empresa)->set('transferTo', $membro->uuid)->call('requestTransfer')->assertSet('pendingAction', 'transfer');
    paginaDaConta($dono, $empresa)->call('startRemove', $membro->uuid)->assertSet('removingUuid', $membro->uuid);
    expect(AuditEvent::query()->where('outcome', 'denied')->count())->toBe(5);
});

it('admin: convida e mexe só em member; não vê transferir/excluir nem ação sobre o dono e o outro admin', function (): void {
    ['empresa' => $empresa, 'admin' => $admin, 'membro' => $membro, 'dono' => $dono] = contaComEquipe();
    $outroAdmin = User::factory()->create(['name' => 'Outro Admin']);
    app(AccountService::class)->addMember($empresa, $outroAdmin, AccountRole::Admin);

    $html = paginaDaConta($admin, $empresa)->html();

    // Ações sobre o member: promover e remover.
    expect(substr_count($html, 'data-action="promote"'))->toBe(1)
        ->and(substr_count($html, 'data-action="remove"'))->toBe(1)
        ->and(substr_count($html, 'data-action="demote"'))->toBe(0)
        ->and($html)->toContain('data-invite-form')
        ->and($html)->toContain('data-action="rename"')
        ->and($html)->not->toContain('data-transfer')
        ->and($html)->not->toContain('data-delete-account');

    // Forjado pelo endpoint: rebaixar o outro admin e remover o dono → 403.
    paginaDaConta($admin, $empresa)->call('changeRole', $outroAdmin->uuid, 'member')->assertForbidden();
    paginaDaConta($admin, $empresa)->set('removingUuid', $dono->uuid)->call('removeMember')->assertForbidden();

    paginaDaConta($admin, $empresa)->call('changeRole', $membro->uuid, 'admin')->assertHasNoErrors();

    expect($empresa->roleOf($membro))->toBe(AccountRole::Admin)
        ->and($empresa->roleOf($outroAdmin))->toBe(AccountRole::Admin)
        ->and($empresa->hasMember($dono))->toBeTrue();
});

it('dono: vê tudo — ações em admins e members, transferir e excluir; nenhuma ação sobre si mesmo', function (): void {
    ['empresa' => $empresa, 'dono' => $dono] = contaComEquipe();

    $html = paginaDaConta($dono, $empresa)->html();

    expect(substr_count($html, 'data-action="remove"'))->toBe(2)
        ->and(substr_count($html, 'data-action="promote"'))->toBe(1)
        ->and(substr_count($html, 'data-action="demote"'))->toBe(1)
        ->and($html)->toContain('data-transfer')
        ->and($html)->toContain('data-delete-account')
        ->and($html)->not->toContain('data-leave');
});

it('convidar pela tela: mesma mensagem para e-mail com e sem conta; reenviar e revogar', function (): void {
    ['empresa' => $empresa, 'dono' => $dono, 'membro' => $membro] = contaComEquipe();
    User::factory()->create(['email' => 'ja.existe@example.com']);
    Mail::fake();

    $comConta = paginaDaConta($dono, $empresa)->set('inviteEmail', 'ja.existe@example.com')->set('inviteRole', 'admin')->call('invite')->assertHasNoErrors();
    $semConta = paginaDaConta($dono, $empresa)->set('inviteEmail', 'nao.existe@example.com')->call('invite')->assertHasNoErrors();

    // A tela responde igual nos dois casos (só o e-mail digitado muda).
    $comConta->assertSee(__('panel.account.invited', ['email' => 'ja.existe@example.com']));
    $semConta->assertSee(__('panel.account.invited', ['email' => 'nao.existe@example.com']));
    Mail::assertQueued(AccountInvitationMail::class, 2);

    $tela = paginaDaConta($dono, $empresa)->assertSeeHtml('data-invitation="ja.existe@example.com"')->assertSeeHtml('data-invitation="nao.existe@example.com"');

    $convite = Accounts::actingAs($empresa, fn () => AccountInvitation::query()->where('email', 'nao.existe@example.com')->sole());
    $tela->call('resendInvitation', $convite->uuid)->assertHasNoErrors();
    $tela->call('startRevokeInvitation', $convite->uuid)->call('revokeInvitation')->assertHasNoErrors();

    expect($convite->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('tenant_uuid', $empresa->uuid)->pluck('action')->all())
        ->toBe(['account.invitation_created', 'account.invitation_created', 'account.invitation_resent', 'account.invitation_revoked']);

    // Quem já é membro: erro no campo, com a recusa na trilha.
    paginaDaConta($dono, $empresa)->set('inviteEmail', $membro->email)->call('invite')
        ->assertHasErrors(['inviteEmail' => __('accounts.invitations.already_member')]);
    expect(AuditEvent::query()->where('action', 'account.invitation_created')->where('outcome', 'denied')->count())->toBe(1);
});

it('remover membro pela tela: confirmação, as chaves dele continuam e os donos recebem o aviso', function (): void {
    ['empresa' => $empresa, 'dono' => $dono, 'admin' => $admin] = contaComEquipe();
    ['api_key' => $chave] = criarChave($admin, ['name' => 'Chave do admin'], $empresa);
    Mail::fake();

    paginaDaConta($dono, $empresa)
        ->call('startRemove', $admin->uuid)
        ->assertSee(__('panel.account.remove_title'))
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($empresa->hasMember($admin))->toBeFalse()
        ->and(comoSistema(fn () => $chave->fresh()->isUsable()))->toBeTrue();

    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->hasTo($dono->email) && $m->keys[0]['name'] === 'Chave do admin');
});

it('sair da conta pela tela: volta para a conta pessoal', function (): void {
    ['empresa' => $empresa, 'membro' => $membro] = contaComEquipe();

    paginaDaConta($membro, $empresa)->call('startLeave')->call('leave')->assertRedirect(route('dashboard'));

    expect($empresa->hasMember($membro))->toBeFalse()
        ->and(session('accounts.current'))->toBeNull();

    $this->actingAs($membro)->get('/account')->assertOk()
        ->assertDontSeeHtml('data-account-name>Equipe SA<')
        ->assertDontSeeHtml('data-current-account>Equipe SA<');
});

it('transferir pela tela: senha de transação + código; o escolhido vira dono e o antigo, admin', function (): void {
    ['empresa' => $empresa, 'dono' => $dono, 'membro' => $membro] = contaComEquipe();
    Mail::fake();

    // Senha de transação errada: nada muda.
    paginaDaConta($dono, $empresa)->set('transferTo', $membro->uuid)->call('requestTransfer')
        ->set('sensitivePassword', 'errada')->call('sendSensitiveCode')->assertHasErrors('sensitivePassword');
    expect($empresa->fresh()->owner->is($dono))->toBeTrue();

    $tela = paginaDaConta($dono, $empresa)->set('transferTo', $membro->uuid)->call('requestTransfer')->assertSet('pendingAction', 'transfer');
    confirmarSensivel($tela)->assertHasNoErrors();

    $conta = $empresa->fresh();
    expect($conta->owner->is($membro))->toBeTrue()
        ->and($conta->roleOf($dono))->toBe(AccountRole::Admin)
        ->and($conta->memberships()->where('role', 'owner')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.ownership_transferred')->where('outcome', 'success')->count())->toBe(1);
});

it('transferir sem senha de transação definida: a tela não abre a confirmação', function (): void {
    $dono = User::factory()->create();
    $membro = User::factory()->create();
    $empresa = app(AccountService::class)->createAccount('Sem Senha', $dono);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    paginaDaConta($dono, $empresa)->set('transferTo', $membro->uuid)->call('requestTransfer')
        ->assertHasErrors('transferTo')
        ->assertSet('pendingAction', null);
});

it('excluir pela tela: só o dono, com a confirmação sensível; os dados saem e a pessoa volta à conta pessoal', function (): void {
    ['empresa' => $empresa, 'dono' => $dono] = contaComEquipe();
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Projeto da equipe']), $dono);
    Mail::fake();

    $tela = paginaDaConta($dono, $empresa)->call('requestDelete')->assertSet('pendingAction', 'delete');
    confirmarSensivel($tela)->assertRedirect(route('dashboard'));

    expect(Account::query()->whereKey($empresa->getKey())->exists())->toBeFalse()
        ->and(comoSistema(fn () => Project::query()->where('name', 'Projeto da equipe')->exists()))->toBeFalse()
        ->and(session('accounts.current'))->toBeNull();
});

it('a conta pessoal: sem renomear, transferir nem excluir; aceita membros', function (): void {
    $dona = User::factory()->withTransactionPassword()->create();
    $pessoal = contaPessoal($dona);

    $html = paginaDaConta($dona, $pessoal)->html();
    expect($html)->not->toContain('data-action="rename"')
        ->and($html)->not->toContain('data-transfer')
        ->and($html)->not->toContain('data-delete-account')
        ->and($html)->toContain('data-invite-form');

    paginaDaConta($dona, $pessoal)->call('requestDelete')->assertHasNoErrors()->assertSet('pendingAction', 'delete');
});

it('renomear pela tela (dono/admin) e criar conta nova — a pessoa vira dona e passa a trabalhar nela', function (): void {
    ['empresa' => $empresa, 'admin' => $admin] = contaComEquipe();

    paginaDaConta($admin, $empresa)->call('startRename')->set('name', 'Equipe Renomeada')->call('rename')->assertHasNoErrors();
    expect($empresa->fresh()->name)->toBe('Equipe Renomeada');

    Livewire::actingAs($admin)->test(AccountCreate::class)
        ->set('name', '')->call('create')->assertHasErrors('name')
        ->set('name', 'Minha Empresa Nova')->call('create')->assertRedirect(route('panel.account'));

    $nova = Account::query()->where('name', 'Minha Empresa Nova')->sole();
    expect($nova->owner->is($admin))->toBeTrue()
        ->and(session('accounts.current'))->toBe($nova->uuid);
});

it('alternador tabela/cartões: a escolha fica na sessão e a lista muda de forma', function (): void {
    ['empresa' => $empresa, 'dono' => $dono] = contaComEquipe();

    paginaDaConta($dono, $empresa)
        ->assertSet('view', 'table')
        ->assertDontSeeHtml('data-members-cards')
        ->call('setView', 'cards')
        ->assertSeeHtml('data-members-cards')
        ->assertSeeHtml('aria-pressed="true"');

    expect(session(AccountShow::VIEW_SESSION_KEY))->toBe('cards');
    paginaDaConta($dono, $empresa)->assertSet('view', 'cards');
    paginaDaConta($dono, $empresa)->call('setView', 'qualquer')->assertSet('view', 'table');
});

it('seletor: troca de conta pelo POST só para conta de que a pessoa é membro; os dados seguem a conta', function (): void {
    ['empresa' => $empresa, 'membro' => $membro] = contaComEquipe();
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Projeto da Equipe']), $membro);
    projetoDe($membro, 'Projeto pessoal');
    $alheia = app(AccountService::class)->createAccount('Alheia', User::factory()->create());

    $this->actingAs($membro)->from('/projects')->post(route('accounts.switch', $empresa->uuid))->assertRedirect('/projects');
    $this->get('/projects')->assertSee('Projeto da Equipe')->assertDontSee('Projeto pessoal');

    $this->post(route('accounts.switch', $alheia->uuid))->assertForbidden();
    $this->get('/projects')->assertSee('Projeto da Equipe');

    $this->post(route('accounts.switch', contaPessoal($membro)->uuid));
    $this->get('/projects')->assertSee('Projeto pessoal')->assertDontSee('Projeto da Equipe');

    expect(AuditEvent::query()->where('action', 'account.switched')->where('outcome', 'denied')->count())->toBe(1);
});

it('o link assinado do e-mail abre as chaves na conta certa; sem assinatura, não troca nada', function (): void {
    ['empresa' => $empresa, 'dono' => $dono] = contaComEquipe();

    $this->actingAs($dono)->get(route('accounts.open', ['account' => $empresa->uuid, 'to' => 'api-keys']))->assertForbidden();
    expect(session('accounts.current'))->toBeNull();

    $assinada = URL::signedRoute('accounts.open', ['account' => $empresa->uuid, 'to' => 'api-keys'], absolute: false);
    $this->actingAs($dono)->get($assinada)->assertRedirect(route('panel.api-keys'));
    expect(session('accounts.current'))->toBe($empresa->uuid);

    $outra = URL::signedRoute('accounts.open', ['account' => $empresa->uuid, 'to' => 'qualquer'], absolute: false);
    $this->actingAs($dono)->get($outra)->assertNotFound();
});

it('isolamento: o membro vê os projetos da conta selecionada e NÃO os da outra', function (): void {
    ['empresa' => $empresa, 'membro' => $membro] = contaComEquipe();
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da Equipe']), $membro);
    $outra = app(AccountService::class)->createAccount('Outra SA', $dono2 = User::factory()->create());
    Accounts::actingAs($outra, fn () => Project::createWithPublicCodeRetry(['name' => 'Da Outra']), $dono2);

    session()->put('accounts.current', $empresa->uuid);
    Livewire::actingAs($membro)->test(ProjectsIndex::class)->assertSee('Da Equipe')->assertDontSee('Da Outra');

    // Selecionar à força uma conta de que não é membro não abre nada.
    session()->put('accounts.current', $outra->uuid);
    Livewire::actingAs($membro)->test(ProjectsIndex::class)->assertDontSee('Da Outra')->assertDontSee('Da Equipe');
});

it('sair pela tela e depois ter o acesso excluído: o aviso de chave órfã sai uma vez só por destinatário', function (): void {
    ['empresa' => $empresa, 'membro' => $membro, 'dono' => $dono, 'admin' => $admin] = contaComEquipe();
    criarChave($membro, ['name' => 'Chave do membro'], $empresa);
    Mail::fake();

    paginaDaConta($membro, $empresa)->call('startLeave')->call('leave')->assertRedirect(route('dashboard'));
    $membro->delete();

    Mail::assertQueued(OrphanedApiKeysMail::class, 2);
    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->hasTo($dono->email) && ! $m->deleted);
    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->hasTo($admin->email) && ! $m->deleted);
});
