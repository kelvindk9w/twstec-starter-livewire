<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Accounts\Account\Actions\InviteMember;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Mail\AccountInvitationMail;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;

// =============================================================================
// CADASTRO PÚBLICO FECHADO (AUTH_REGISTRATION_ENABLED=false — issue #22), pela
// pilha HTTP do starter Livewire: GET e POST /register respondem 404, nenhuma
// tela oferece "Criar conta", e o convite continua criando contas.
// =============================================================================

/**
 * As marcas de um link de cadastro numa página: o endereço e o texto do botão
 * e do link do login.
 */
function registrationLinkMarks(): array
{
    return [route('register'), __('landing.nav.register'), __('auth.ui.register_link')];
}

it('aberto (padrão): a tela de login oferece o cadastro — a referência dos testes abaixo', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee(route('register'))
        ->assertSee(__('auth.ui.register_link'));
});

it('fechado: GET e POST /register respondem 404 e nenhuma conta nasce', function (): void {
    config()->set('auth.registration.enabled', false);

    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Fulano',
        'email' => 'fulano@example.com',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ])->assertNotFound();

    // Nem a validação responde (não vira oráculo de e-mail cadastrado).
    $this->post('/register', ['email' => 'invalido'])->assertNotFound()->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'fulano@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

it('fechado: nenhuma tela pública renderiza link de cadastro', function (string $tela): void {
    config()->set('auth.registration.enabled', false);

    $html = (string) $this->get($tela)->assertOk()->getContent();

    foreach (registrationLinkMarks() as $marca) {
        expect($html)->not->toContain(e($marca));
    }

    expect($html)->not->toContain('href="'.route('register').'"');
})->with(['/login', '/', '/forgot-password']);

it('fechado: o convite continua criando a conta (deslogado, sem conta)', function (): void {
    config()->set('auth.registration.enabled', false);

    $dona = User::factory()->create();
    $conta = app(AccountService::class)->createAccount('Empresa Fechada', $dona);

    Mail::fake();
    $this->actingAs($dona);
    Accounts::actingAs($conta, fn () => app(InviteMember::class)->handle($dona, 'convidada.fechado@example.com', AccountRole::Member), $dona);

    $token = null;
    Mail::assertQueued(AccountInvitationMail::class, function (AccountInvitationMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    app('auth')->forgetGuards();
    session()->flush();

    $this->get("/invitations/{$token}")->assertOk()->assertSeeHtml('data-invitation-mode="register"');

    $this->post("/invitations/{$token}/register", [
        'name' => 'Convidada',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ])->assertRedirect(route('dashboard'));

    $nova = User::query()->where('email', 'convidada.fechado@example.com')->sole();

    $this->assertAuthenticatedAs($nova);
    expect($conta->roleOf($nova))->toBe(AccountRole::Member);
})->group('accounts');
