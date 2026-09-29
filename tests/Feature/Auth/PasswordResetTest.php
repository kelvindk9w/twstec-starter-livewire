<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Twstec\Kit\Auth\Notifications\ResetPasswordNotification;

// Recuperação de senha por e-mail (broker nativo do Laravel: token com hash
// + expiração). Anti-enumeração: mesma resposta para e-mail inexistente.

it('exibe o formulário de recuperação de senha', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee(__('auth.ui.forgot_title'));
});

it('envia o link de redefinição para e-mail cadastrado', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status', __('passwords.sent'));

    // Notificação PRÓPRIA do kit (traduzida) — ver ResetPasswordNotification.
    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('responde igual para e-mail NÃO cadastrado (anti-enumeração — item 11)', function () {
    Notification::fake();

    $this->post('/forgot-password', ['email' => 'ninguem@example.com'])
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertNothingSent();
});

it('redefine a senha com token válido e invalida o remember_token', function () {
    Notification::fake();

    $user = User::factory()->create(['password' => 'SenhaAntiga123']);

    $token = Password::createToken($user);

    $response = $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'SenhaNova456',
        'password_confirmation' => 'SenhaNova456',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', __('passwords.reset'));

    $user->refresh();

    expect(Hash::check('SenhaNova456', $user->password))->toBeTrue()
        ->and($user->password)->toStartWith('$argon2id$');

    // O token não pode ser reutilizado.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'OutraSenha789',
        'password_confirmation' => 'OutraSenha789',
    ])->assertSessionHasErrors('email');
});

it('rejeita token inválido', function () {
    $user = User::factory()->create(['password' => 'SenhaAntiga123']);

    $this->post('/reset-password', [
        'token' => 'token-invalido',
        'email' => $user->email,
        'password' => 'SenhaNova456',
        'password_confirmation' => 'SenhaNova456',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('SenhaAntiga123', $user->fresh()->password))->toBeTrue();
});

it('rejeita senha nova fraca na redefinição', function () {
    $user = User::factory()->create();

    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'fraca',
        'password_confirmation' => 'fraca',
    ])->assertSessionHasErrors('password');
});

// =============================================================================
// Bug de QA #9 — o e-mail de reset chegava em inglês com pt-BR ativo.
// =============================================================================

it('envia a notificação própria de reset (traduzida), não a do framework', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'idioma@example.com']);

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
    Notification::assertNotSentTo($user, ResetPassword::class);
});

it('renderiza o e-mail de reset no idioma da conta', function (string $locale, string $trecho) {
    $user = User::factory()->create(['locale' => $locale]);

    app()->setLocale($locale);

    $mail = (new ResetPasswordNotification('token-de-teste'))->toMail($user);

    expect($mail->subject)->toBe(__('mail.password_reset.subject', ['platform' => platform()->name]));

    // O corpo agora é a view do layout único do kit (Twstec\Kit\Foundation\Mail), e não
    // mais as linhas montadas pelo MailMessage do framework: o texto sai do
    // HTML renderizado.
    $texto = view($mail->view[0], $mail->viewData)->render();

    expect($texto)->toContain($trecho);
})->with([
    ['pt_BR', 'Redefinir senha'],
    ['en', 'Reset password'],
    ['es', 'Restablecer contraseña'],
]);

it('o usuário expõe a preferência de idioma para as notificações enfileiradas', function () {
    $user = User::factory()->create(['locale' => 'es']);

    expect($user)->toBeInstanceOf(HasLocalePreference::class)
        ->and($user->preferredLocale())->toBe('es');
});
