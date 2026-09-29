<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Twstec\Kit\Admin\Pages\Auth\Login;
use Twstec\Kit\Admin\Pages\Profile;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\VerificationCode;

// Segundo fator no /admin (Filament 5): o provedor EmailCodeAuthentication
// pluga o MOTOR do kit no MFA do Filament — mesma preferência do painel do
// cliente, mesmo código por e-mail, mesmos limites. Ligar/desligar no
// /admin/profile com a mesma confirmação sensível.

beforeEach(function () {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
    // Sem pré-preenchimento das credenciais demo nesta suíte.
    config()->set('ui.demo_login.enabled', false);
});

function adminTfaCode(VerificationPurpose $purpose = VerificationPurpose::LoginChallenge): string
{
    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === $purpose)
        ->last();

    return $mail->code;
}

function adminTfaUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'is_admin' => true,
        'password' => 'AdminForte123',
        'two_factor_enabled_at' => now(),
    ], $attributes));
}

function adminTfaPasswordStep(User $user): Testable
{
    return Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'AdminForte123'])
        ->call('authenticate');
}

it('login do /admin com o segundo fator ligado: senha certa não autentica e o código vai por e-mail', function () {
    $user = adminTfaUser();

    adminTfaPasswordStep($user)
        ->assertHasNoFormErrors()
        ->assertNotSet('userUndertakingMultiFactorAuthentication', null)
        ->assertSee(__('auth.two_factor.code_label'));

    $this->assertGuest();
    Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::LoginChallenge
        && $mail->hasTo($user->email));
});

it('código certo conclui o login do /admin', function () {
    $user = adminTfaUser();

    adminTfaPasswordStep($user)
        ->set('data.multiFactor.kit_email_code.code', adminTfaCode())
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('código errado não conclui e o mesmo código não vale duas vezes', function () {
    $user = adminTfaUser();
    $component = adminTfaPasswordStep($user);
    $code = adminTfaCode();
    $wrong = $code === '000000' ? '000001' : '000000';

    $component->set('data.multiFactor.kit_email_code.code', $wrong)
        ->call('authenticate')
        ->assertHasErrors();
    $this->assertGuest();

    $component->set('data.multiFactor.kit_email_code.code', $code)->call('authenticate');
    $this->assertAuthenticatedAs($user);

    Auth::logout();

    // Repetir o código consumido numa nova tentativa (sem código novo no
    // intervalo de reenvio) não abre sessão.
    config()->set('auth.verification.resend_cooldown_seconds', 600);
    adminTfaPasswordStep($user)
        ->set('data.multiFactor.kit_email_code.code', $code)
        ->call('authenticate')
        ->assertHasErrors();

    $this->assertGuest();
});

it('sem o segundo fator ligado, o /admin entra só com a senha', function () {
    $user = adminTfaUser(['two_factor_enabled_at' => null]);

    adminTfaPasswordStep($user)->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
    Mail::assertNothingQueued();
});

it('voltar ao formulário de senha invalida o código enviado', function () {
    $user = adminTfaUser();

    adminTfaPasswordStep($user)
        ->assertSee(__('auth.two_factor.cancel'))
        ->call('cancelMultiFactorChallenge')
        ->assertSet('userUndertakingMultiFactorAuthentication', null)
        ->assertRedirect(Filament\Facades\Filament::getLoginUrl());

    expect(VerificationCode::query()->whereNull('consumed_at')->count())->toBe(0);
    $this->assertGuest();
});

it('o estado intermediário do /admin expira', function () {
    config()->set('auth.two_factor.challenge_ttl_minutes', 5);
    config()->set('auth.verification.code_ttl_minutes', 30);
    $user = adminTfaUser();
    $component = adminTfaPasswordStep($user);
    $code = adminTfaCode();

    $this->travel(6)->minutes();

    $component->set('data.multiFactor.kit_email_code.code', $code)
        ->call('authenticate')
        ->assertNotified(__('auth.two_factor.challenge_expired'))
        ->assertSet('userUndertakingMultiFactorAuthentication', null)
        ->assertRedirect(Filament\Facades\Filament::getLoginUrl());

    $this->assertGuest();
    expect(VerificationCode::query()->whereNull('consumed_at')->count())->toBe(0);
});

it('limite por conta vale também no /admin', function () {
    config()->set('auth.verification.max_attempts', 100);
    config()->set('auth.two_factor.max_attempts_per_account', 2);
    $user = adminTfaUser();
    $component = adminTfaPasswordStep($user);
    $code = adminTfaCode();
    $wrong = $code === '000000' ? '000001' : '000000';

    $component->set('data.multiFactor.kit_email_code.code', $wrong)->call('authenticate');
    $component->set('data.multiFactor.kit_email_code.code', $wrong)->call('authenticate');

    // Bloqueado: nem o código certo passa.
    $component->set('data.multiFactor.kit_email_code.code', $code)->call('authenticate')->assertHasErrors();
    $this->assertGuest();
});

it('perfil do /admin liga e desliga com senha de transação + código', function () {
    $admin = adminTfaUser(['two_factor_enabled_at' => null]);
    $admin->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();
    $this->actingAs($admin);

    Livewire::test(Profile::class)
        ->assertSee(__('panel.profile.two_factor_off'))
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'Trans4cao!Segura'])
        ->assertActionMounted('confirmTwoFactor')
        ->setActionData(['code' => adminTfaCode(VerificationPurpose::SensitiveAction)])
        ->callMountedAction()
        ->assertNotified(__('auth.two_factor.enabled'));

    expect($admin->fresh()->two_factor_enabled_at)->not->toBeNull();

    Livewire::test(Profile::class)
        ->assertSee(__('panel.profile.two_factor_on'))
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'Trans4cao!Segura'])
        ->setActionData(['code' => adminTfaCode(VerificationPurpose::SensitiveAction)])
        ->callMountedAction()
        ->assertNotified(__('auth.two_factor.disabled'));

    expect($admin->fresh()->two_factor_enabled_at)->toBeNull();
});

it('perfil do /admin: senha de transação errada ou código errado não mudam nada', function () {
    $admin = adminTfaUser(['two_factor_enabled_at' => null]);
    $admin->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();
    $this->actingAs($admin);

    Livewire::test(Profile::class)
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'errada'])
        ->assertNotified(__('auth.transaction_password.invalid'))
        ->assertActionMounted('toggleTwoFactor');

    $component = Livewire::test(Profile::class)
        ->callAction('toggleTwoFactor', data: ['transaction_password' => 'Trans4cao!Segura']);
    $code = adminTfaCode(VerificationPurpose::SensitiveAction);

    $component->setActionData(['code' => $code === '000000' ? '000001' : '000000'])
        ->callMountedAction()
        ->assertNotified(__('auth.verification_code.invalid'));

    expect($admin->fresh()->two_factor_enabled_at)->toBeNull();
});

it('perfil do /admin: sem senha de transação ou conta demo, a ação fica desabilitada com o motivo', function () {
    $admin = adminTfaUser(['two_factor_enabled_at' => null]);
    $this->actingAs($admin);

    Livewire::test(Profile::class)
        ->assertSee(__('auth.two_factor.requires_transaction_password'))
        ->assertActionDisabled('toggleTwoFactor');

    config()->set('ui.demo_login.enabled', true);
    $demo = User::factory()->withTransactionPassword()->create(['email' => config('ui.demo_admin.email'), 'is_admin' => true]);
    $this->actingAs($demo);

    Livewire::test(Profile::class)
        ->assertSee(__('auth.two_factor.account_protected'))
        ->assertActionDisabled('toggleTwoFactor');

    expect($demo->fresh()->two_factor_enabled_at)->toBeNull();
})->group('demo');

it('uma preferência só: quem ligou no /profile é desafiado também no /admin', function () {
    $user = adminTfaUser();

    // Painel do cliente.
    $this->post('/login', ['email' => $user->email, 'password' => 'AdminForte123'])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
    $this->post(route('two-factor.cancel'));

    // /admin.
    adminTfaPasswordStep($user)->assertNotSet('userUndertakingMultiFactorAuthentication', null);
    $this->assertGuest();
});
