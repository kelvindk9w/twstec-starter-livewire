<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\SensitiveActionToken;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Auth\Services\TwoFactorLogin;
use Twstec\Kit\Demo\Accounts\Exceptions\DemoAccountProtectedException;

// Ligar/desligar a verificação em duas etapas no /profile: ação sensível
// (senha de transação + código por e-mail), com o token consumido pelo
// próprio TwoFactorLogin. Conta sem senha de transação é orientada; conta demo
// protegida não muda.

beforeEach(function () {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
});

function profileTfaSensitiveCode(): string
{
    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::SensitiveAction)
        ->last();

    return $mail->code;
}

function profileTfaToggle(User $user, ?string $code = null): Testable
{
    test()->actingAs($user);

    $component = Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->assertSet('codeSent', true);

    return $component
        ->set('sensitiveCode', $code ?? profileTfaSensitiveCode())
        ->call('confirmSensitiveAction');
}

it('mostra o cartão desligado e o motivo quando falta a senha de transação', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee(__('panel.profile.two_factor_heading'))
        ->assertSee(__('panel.profile.two_factor_off'))
        ->assertSee(__('auth.two_factor.requires_transaction_password'));

    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->assertHasErrors(['twoFactor'])
        ->assertSet('pendingAction', null);
});

it('liga com senha de transação + código por e-mail e consome o token', function () {
    $user = User::factory()->withTransactionPassword()->create();

    profileTfaToggle($user)
        ->assertHasNoErrors()
        ->assertSet('pendingAction', null)
        ->assertSee(__('auth.two_factor.enabled'))
        ->assertSee(__('panel.profile.two_factor_on'));

    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull()
        ->and(SensitiveActionToken::query()->whereNull('consumed_at')->count())->toBe(0);
});

it('desliga pelo mesmo fluxo', function () {
    $user = User::factory()->withTransactionPassword()->create(['two_factor_enabled_at' => now()]);

    $this->actingAs($user);
    Livewire::test(Profile::class)->call('requestTwoFactorToggle')->assertSet('pendingAction', 'two_factor_disable');

    profileTfaToggle($user)->assertHasNoErrors();

    expect($user->fresh()->two_factor_enabled_at)->toBeNull();
});

it('senha de transação errada não envia código e não liga nada', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->set('sensitivePassword', 'errada')
        ->call('sendSensitiveCode')
        ->assertHasErrors(['sensitivePassword'])
        ->assertSet('codeSent', false);

    Mail::assertNothingQueued();
    expect($user->fresh()->two_factor_enabled_at)->toBeNull();
});

it('código errado não liga', function () {
    $user = User::factory()->withTransactionPassword()->create();

    $this->actingAs($user);
    $component = Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode');

    $wrong = profileTfaSensitiveCode() === '000000' ? '000001' : '000000';

    $component->set('sensitiveCode', $wrong)
        ->call('confirmSensitiveAction')
        ->assertHasErrors(['sensitiveCode']);

    expect($user->fresh()->two_factor_enabled_at)->toBeNull();
});

it('o service recusa ligar sem token válido — a tela não é a única barreira', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $service = app(TwoFactorLogin::class);

    expect(fn () => $service->enable($user, 'token-inventado'))->toThrow(ValidationException::class);
    expect($user->fresh()->two_factor_enabled_at)->toBeNull();

    // Token legítimo vale uma vez só.
    app(SensitiveActionService::class)->sendCode($user, 'Trans4cao!Segura');
    $issued = app(SensitiveActionService::class)->confirmCode($user, profileTfaSensitiveCode());

    $service->enable($user, $issued['token']);
    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();

    expect(fn () => $service->disable($user->fresh(), $issued['token']))->toThrow(ValidationException::class);
    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();
});

it('conta demo protegida: o cartão explica e nada liga — nem pelo service, nem pelo model', function () {
    config()->set('ui.demo_login.enabled', true);
    $demo = User::factory()->withTransactionPassword()->create(['email' => config('ui.demo_login.email')]);
    $this->actingAs($demo);

    $this->get('/profile')->assertOk()->assertSee(__('auth.two_factor.account_protected'));

    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->assertHasErrors(['twoFactor'])
        ->assertSet('pendingAction', null);

    // Mesmo com um token de ação sensível legítimo em mãos.
    app(SensitiveActionService::class)->sendCode($demo, 'Trans4cao!Segura');
    $issued = app(SensitiveActionService::class)->confirmCode($demo, profileTfaSensitiveCode());

    expect(fn () => app(TwoFactorLogin::class)->enable($demo, $issued['token']))->toThrow(ValidationException::class);

    // E por fora de toda a aplicação (tinker, job): a blindagem do model.
    expect(fn () => $demo->forceFill(['two_factor_enabled_at' => now()])->save())
        ->toThrow(DemoAccountProtectedException::class);

    expect($demo->fresh()->two_factor_enabled_at)->toBeNull();
})->group('demo');

it('com o modo demo desligado, a conta demo é comum e pode ligar', function () {
    config()->set('ui.demo_login.enabled', false);
    $demo = User::factory()->withTransactionPassword()->create(['email' => config('ui.demo_login.email')]);

    profileTfaToggle($demo)->assertHasNoErrors();

    expect($demo->fresh()->two_factor_enabled_at)->not->toBeNull();
})->group('demo');

it('com AUTH_TWO_FACTOR_ENABLED=false o cartão some e a ação recusa', function () {
    config()->set('auth.two_factor.enabled', false);
    $user = User::factory()->withTransactionPassword()->create();
    $this->actingAs($user);

    $this->get('/profile')->assertOk()->assertDontSee(__('panel.profile.two_factor_heading'));

    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->assertHasErrors(['twoFactor']);
});

it('o modal da confirmação diz o que está sendo confirmado', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->assertSee(__('panel.sensitive.heading'))
        ->assertSee(__('panel.profile.two_factor_confirm_enable'));
});
