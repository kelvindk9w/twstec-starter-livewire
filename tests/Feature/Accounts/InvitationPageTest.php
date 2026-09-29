<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Accounts\Account\Actions\InviteMember;
use Twstec\Kit\Accounts\Account\Actions\RevokeInvitation;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Mail\AccountInvitationMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Mail\MailPreview;

// =============================================================================
// A TELA PÚBLICA DO CONVITE (/invitations/{token}) e os envios dela, pela
// pilha HTTP de verdade do starter (rotas, sessão, CSRF desligado nos testes).
//
// Logado com o e-mail certo → aceitar; deslogado com conta → entrar e voltar;
// deslogado sem conta → criar a conta (verificada) e entrar; e os estados de
// erro: expirado, revogado, já usado, e-mail diferente (sem dado da conta).
// =============================================================================

/**
 * @return array{conta: Account, dona: User, token: string}
 */
function conviteDaEmpresa(string $email, AccountRole $papel = AccountRole::Member): array
{
    $dona = User::factory()->create(['name' => 'Dona do Convite']);
    $conta = app(AccountService::class)->createAccount('Empresa Convidante', $dona);
    Accounts::actingAs($conta, fn () => Project::createWithPublicCodeRetry(['name' => 'Projeto da Convidante']), $dona);

    Mail::fake();
    test()->actingAs($dona);
    Accounts::actingAs($conta, fn () => app(InviteMember::class)->handle($dona, $email, $papel), $dona);

    $token = null;
    Mail::assertQueued(AccountInvitationMail::class, function (AccountInvitationMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    app('auth')->forgetGuards();
    session()->flush();

    return ['conta' => $conta, 'dona' => $dona, 'token' => (string) $token];
}

it('o e-mail do convite sai no template do kit, com o link da tela e sem o hash', function (): void {
    ['token' => $token] = conviteDaEmpresa('convidada@example.com');

    $mail = new AccountInvitationMail('Empresa Convidante', 'Dona do Convite', 'Membro', $token, now()->addDays(7));
    $html = $mail->render();

    expect($html)->toContain(route('invitations.show', $token))
        ->and($html)->toContain('Empresa Convidante')
        ->and($html)->not->toContain(hash('sha256', $token))
        ->and(MailPreview::slugs())->toContain('account-invitation', 'orphaned-api-keys');
});

it('deslogado e SEM conta: a tela mostra o convite e o formulário; criar a conta aceita, verificada, e abre os projetos da conta', function (): void {
    ['conta' => $conta, 'token' => $token] = conviteDaEmpresa('nova.pessoa@example.com');

    $this->get("/invitations/{$token}")
        ->assertOk()
        ->assertSeeHtml('data-invitation-mode="register"')
        ->assertSee('Empresa Convidante')
        ->assertSee('Dona do Convite')
        ->assertSee('nova.pessoa@example.com');

    $this->post("/invitations/{$token}/register", [
        'name' => 'Nova Pessoa',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ])->assertRedirect(route('dashboard'));

    $nova = User::query()->where('email', 'nova.pessoa@example.com')->sole();
    $this->assertAuthenticatedAs($nova);

    expect($nova->hasVerifiedEmail())->toBeTrue()
        ->and($conta->roleOf($nova))->toBe(AccountRole::Member);

    // Sem passar pelo aviso de verificação: o painel abre direto na conta do convite.
    $this->get('/projects')->assertOk()->assertSee('Projeto da Convidante');
});

it('deslogado COM conta: a tela pede para entrar e o login volta para o convite', function (): void {
    $pessoa = User::factory()->create(['email' => 'ja.tem.conta@example.com', 'password' => 'SenhaForte123']);
    ['conta' => $conta, 'token' => $token] = conviteDaEmpresa('ja.tem.conta@example.com');

    $this->get("/invitations/{$token}")->assertOk()->assertSeeHtml('data-invitation-mode="login"');

    $this->post('/login', ['email' => 'ja.tem.conta@example.com', 'password' => 'SenhaForte123'])
        ->assertRedirect(route('invitations.show', $token));

    $this->get("/invitations/{$token}")->assertOk()->assertSeeHtml('data-invitation-mode="accept"');
    $this->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));

    expect($conta->hasMember($pessoa))->toBeTrue()
        ->and(session('accounts.current'))->toBe($conta->uuid);
});

it('logado com OUTRO e-mail: recusa clara, sem nenhum dado da conta — na tela e no envio', function (): void {
    ['conta' => $conta, 'token' => $token] = conviteDaEmpresa('certa@example.com');
    $outra = User::factory()->create(['email' => 'outra@example.com']);

    $this->actingAs($outra)->get("/invitations/{$token}")
        ->assertOk()
        ->assertSeeHtml('data-invitation-state="wrong_email"')
        ->assertSee(__('accounts.invitations.unavailable.wrong_email'))
        ->assertDontSee('Empresa Convidante')
        ->assertDontSee('Dona do Convite')
        ->assertDontSee('certa@example.com');

    $this->actingAs($outra)->from("/invitations/{$token}")->post("/invitations/{$token}/accept")
        ->assertRedirect("/invitations/{$token}")
        ->assertSessionHas('invitation_error', __('accounts.invitations.unavailable.wrong_email'));

    expect($conta->hasMember($outra))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'account.invitation_accepted')->where('outcome', 'denied')->count())->toBe(1);
});

it('os estados de erro: já usado, revogado, expirado e link inválido — cada um com o motivo', function (): void {
    ['conta' => $conta, 'dona' => $dona, 'token' => $token] = conviteDaEmpresa('estados@example.com');
    $pessoa = User::factory()->create(['email' => 'estados@example.com']);

    $this->actingAs($pessoa)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));
    $this->actingAs($pessoa)->get("/invitations/{$token}")->assertSeeHtml('data-invitation-state="accepted"');

    ['token' => $revogado, 'conta' => $outra, 'dona' => $outraDona] = conviteDaEmpresa('estados@example.com');
    $convite = Accounts::actingAs($outra, fn () => AccountInvitation::query()->sole());
    Accounts::actingAs($outra, fn () => app(RevokeInvitation::class)->handle($outraDona, $convite->uuid), $outraDona);
    $this->get("/invitations/{$revogado}")->assertSeeHtml('data-invitation-state="revoked"')->assertDontSee('Empresa Convidante');

    ['token' => $expirado] = conviteDaEmpresa('expira@example.com');
    $this->travel((int) config('accounts.invitations.expires_hours') + 1)->hours();
    $this->get("/invitations/{$expirado}")->assertSeeHtml('data-invitation-state="expired"')->assertSee(__('accounts.invitations.unavailable.expired'));
    $this->post("/invitations/{$expirado}/register", ['name' => 'X', 'password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123'])
        ->assertSessionHas('invitation_error', __('accounts.invitations.unavailable.expired'));
    expect(User::query()->where('email', 'expira@example.com')->exists())->toBeFalse();

    $this->get('/invitations/'.str_repeat('a', 64))->assertOk()->assertSeeHtml('data-invitation-state="not_found"');
});

it('recusar o convite pela tela: o link morre', function (): void {
    ['conta' => $conta, 'token' => $token] = conviteDaEmpresa('recusa@example.com');

    $this->from("/invitations/{$token}")->post("/invitations/{$token}/decline")->assertRedirect("/invitations/{$token}");
    $this->get("/invitations/{$token}")->assertSeeHtml('data-invitation-state="declined"');

    expect(AuditEvent::query()->where('action', 'account.invitation_declined')->where('outcome', 'success')->count())->toBe(1);
});

it('o token do caminho não vai para a trilha de requisições (grava o padrão da rota)', function (): void {
    ['token' => $token] = conviteDaEmpresa('trilha@example.com');

    $this->get("/invitations/{$token}")->assertOk();

    $endpoints = DB::table('request_logs')->pluck('endpoint')->implode(' ');
    expect($endpoints)->toContain('invitations/{token}')
        ->and($endpoints)->not->toContain($token);
});
