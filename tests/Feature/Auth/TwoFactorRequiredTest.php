<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\SensitiveActionToken;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// SEGUNDO FATOR OBRIGATÓRIO (AUTH_TWO_FACTOR_REQUIRED — issue #22), pela pilha
// HTTP de verdade do starter Livewire: depois do login, quem ainda não ligou é
// levado à configuração antes de qualquer outra tela (páginas, formulários,
// ações Livewire, JSON); a configuração é a do kit (senha de transação →
// código → liga) e devolve ao destino; desligar é recusado no servidor, com a
// recusa na trilha; `admins` só alcança administradores; o /admin exige.
// =============================================================================

beforeEach(function (): void {
    Mail::fake();
    config()->set('security.rate_limit.sensitive', 1000);
    config()->set('auth.verification.resend_cooldown_seconds', 0);
});

function requiredTfaLastCode(VerificationPurpose $purpose = VerificationPurpose::SensitiveAction): string
{
    $mails = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === $purpose);

    expect($mails)->not->toBeEmpty();

    return $mails->last()->code;
}

it('`none` (padrão): o login sem o segundo fator vai direto ao painel', function (): void {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'LoginForte123'])->assertRedirect(route('dashboard'));
    $this->get('/dashboard')->assertOk();
});

it('`all`: depois do login, sem o segundo fator, só a configuração abre — páginas, JSON e público', function (): void {
    config()->set('auth.two_factor.required', 'all');
    $user = User::factory()->create(['password' => 'LoginForte123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'LoginForte123'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    foreach (['/dashboard', '/profile', '/notifications', '/settings/transaction-password'] as $tela) {
        $this->get($tela)->assertRedirect(route('two-factor.setup'));
    }

    $this->getJson('/dashboard')
        ->assertForbidden()
        ->assertJsonPath('message', __('auth.two_factor.setup_required'));

    $this->postJson('/sensitive-actions/code', ['transaction_password' => 'x'])->assertForbidden();

    // A tela de configuração: sem senha de transação, o primeiro passo é ela.
    $this->get('/two-factor/setup')
        ->assertOk()
        ->assertSee(__('auth.two_factor_setup.title'))
        ->assertSee(__('auth.two_factor_setup.intro', ['email' => $user->email]))
        ->assertSee(__('auth.two_factor_setup.transaction_password_heading'))
        ->assertSeeHtml('data-two-factor-setup-transaction-password')
        ->assertDontSeeHtml('data-two-factor-setup-send');
});

it('`all`: ação Livewire de uma página já aberta é recusada pelo endpoint real', function (): void {
    $user = User::factory()->create(['name' => 'Nome antes', 'two_factor_enabled_at' => now()]);

    $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'profile');

    // A regra entra (ou o suporte desligou o segundo fator) com a aba aberta.
    config()->set('auth.two_factor.required', 'all');
    $user->forceFill(['two_factor_enabled_at' => null])->save();

    livewireCall($this, $snapshot, 'updateProfile', ['name' => 'Nome sem segundo fator'])
        ->assertRedirect(route('two-factor.setup'));

    expect($user->fresh()->name)->toBe('Nome antes');
});

it('configuração completa: senha de transação → código → liga e volta à tela que a pessoa queria', function (): void {
    config()->set('auth.two_factor.required', 'all');
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')->assertRedirect(route('two-factor.setup'));

    // 1. Senha de transação (o envio de sempre, aberto para quem está pendente).
    $this->from('/two-factor/setup')->put('/settings/transaction-password', [
        'transaction_password' => 'Trans4cao!Segura',
        'transaction_password_confirmation' => 'Trans4cao!Segura',
    ])->assertRedirect('/two-factor/setup');

    $this->get('/two-factor/setup')
        ->assertOk()
        ->assertSee(__('auth.two_factor_setup.confirm_heading'))
        ->assertSeeHtml('data-two-factor-setup-send')
        ->assertDontSeeHtml('data-two-factor-setup-code');

    // 2. Senha de transação → código por e-mail.
    $this->from('/two-factor/setup')->post('/two-factor/setup/code', ['transaction_password' => 'Trans4cao!Segura'])
        ->assertRedirect('/two-factor/setup')
        ->assertSessionHas('status', __('auth.verification_code.sent'));

    Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail): bool => $mail->hasTo($user->email)
        && $mail->purpose === VerificationPurpose::TwoFactorSetup);

    $this->get('/two-factor/setup')
        ->assertOk()
        ->assertSee(__('auth.two_factor_setup.code_intro', ['email' => $user->email, 'minutes' => 10]))
        ->assertSeeHtml('data-two-factor-setup-code');

    // 3. Código → liga → volta para /profile, com o aviso.
    $this->post('/two-factor/setup', ['code' => requiredTfaLastCode(VerificationPurpose::TwoFactorSetup)])
        ->assertRedirect(url('/profile'))
        ->assertSessionHas('status', __('auth.two_factor.setup_done'));

    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull()
        ->and(SensitiveActionToken::query()->whereNull('consumed_at')->count())->toBe(0);

    $this->get('/profile')->assertOk()->assertSee(__('panel.profile.two_factor_on'));

    // Configurada, a tela de configuração devolve ao painel.
    $this->get('/two-factor/setup')->assertRedirect(route('dashboard'));
});

it('logo depois da configuração, a primeira confirmação de segurança manda o código na hora — o intervalo vale dentro de cada família', function (): void {
    // O intervalo padrão (60 s), com o relógio parado no segundo: sem a
    // família própria da configuração, o primeiro pedido abaixo esperaria.
    config()->set('auth.verification.resend_cooldown_seconds', 60);
    config()->set('auth.two_factor.required', 'all');
    $this->freezeSecond();

    $user = User::factory()->create(['transaction_password' => 'Trans4cao!Segura']);
    $this->actingAs($user);

    $this->from('/two-factor/setup')->post('/two-factor/setup/code', ['transaction_password' => 'Trans4cao!Segura'])
        ->assertSessionHasNoErrors();
    $this->post('/two-factor/setup', ['code' => requiredTfaLastCode(VerificationPurpose::TwoFactorSetup)])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();

    // No mesmo segundo: ainda pede a senha de transação certa…
    $this->postJson('/sensitive-actions/code', ['transaction_password' => 'errada'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_password' => __('auth.transaction_password.invalid')]);

    // …e, com ela, o código da confirmação sai sem "aguarde".
    $this->postJson('/sensitive-actions/code', ['transaction_password' => 'Trans4cao!Segura'])
        ->assertOk()
        ->assertJsonPath('message', __('auth.verification_code.sent'));

    $this->postJson('/sensitive-actions/confirm', ['code' => requiredTfaLastCode(VerificationPurpose::SensitiveAction)])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at']);

    // Dentro da família da confirmação, o intervalo continua: o próximo espera.
    $this->postJson('/sensitive-actions/code', ['transaction_password' => 'Trans4cao!Segura'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_password' => __('auth.verification_code.resend_cooldown', ['seconds' => 60])]);
});

it('`all`: o próximo login, já configurado, pede o código do segundo fator', function (): void {
    config()->set('auth.two_factor.required', 'all');
    $user = User::factory()->create(['password' => 'LoginForte123', 'two_factor_enabled_at' => now()]);

    $this->post('/login', ['email' => $user->email, 'password' => 'LoginForte123'])->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => requiredTfaLastCode(VerificationPurpose::LoginChallenge)])
        ->assertRedirect(route('dashboard'));
    $this->get('/dashboard')->assertOk();
});

it('com a regra valendo, desligar pelo perfil é recusado no servidor e a recusa vai para a trilha', function (): void {
    $user = User::factory()->withTransactionPassword()->create(['two_factor_enabled_at' => now()]);
    $this->actingAs($user);

    config()->set('auth.two_factor.required', 'all');

    // A tela explica e desabilita o botão…
    $this->get('/profile')
        ->assertOk()
        ->assertSee(__('panel.profile.two_factor_required'))
        ->assertSee(__('auth.two_factor.required_cannot_disable'));

    // …e o servidor recusa o pedido mesmo assim, antes de mandar código.
    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->assertHasErrors(['twoFactor'])
        ->assertSet('pendingAction', null);

    Mail::assertNothingQueued();
    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();

    $recusa = AuditEvent::query()->where('action', 'user.two_factor_disabled')->sole();

    expect($recusa->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusa->context->value)->toBe('panel')
        ->and($recusa->actor_uuid)->toBe($user->uuid)
        ->and($recusa->subject_uuid)->toBe($user->uuid)
        ->and($recusa->reason)->toBe(__('auth.two_factor.required_cannot_disable'));
});

it('a regra que entra com a confirmação aberta: o último passo também recusa, sem desligar', function (): void {
    $user = User::factory()->withTransactionPassword()->create(['two_factor_enabled_at' => now()]);
    $this->actingAs($user);

    $component = Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->assertSet('codeSent', true);

    config()->set('auth.two_factor.required', 'all');

    $component->set('sensitiveCode', requiredTfaLastCode())
        ->call('confirmSensitiveAction')
        ->assertHasErrors(['sensitiveCode']);

    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'user.two_factor_disabled')->where('outcome', AuditOutcome::Denied->value)->count())->toBe(1);
});

it('`admins`: a conta comum segue sem obrigação; o administrador (qualquer papel) é levado à configuração', function (string $papel): void {
    config()->set('auth.two_factor.required', 'admins');

    $comum = User::factory()->create();
    $this->actingAs($comum)->get('/dashboard')->assertOk();

    $admin = User::factory()->admin($papel)->create();
    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('two-factor.setup'));

    // E a conta comum pode desligar o dela.
    $comum->forceFill(['two_factor_enabled_at' => now(), 'transaction_password' => 'Trans4cao!Segura'])->save();
    $this->actingAs($comum);
    Livewire::test(Profile::class)
        ->call('requestTwoFactorToggle')
        ->assertHasNoErrors()
        ->assertSet('pendingAction', 'two_factor_disable');
})->with(['owner', 'operations', 'support', 'auditor'])->group('admin');

it('`admins`: o /admin exige o segundo fator, e a configuração devolve ao /admin', function (): void {
    config()->set('auth.two_factor.required', 'admins');
    $admin = User::factory()->admin()->withTransactionPassword()->create();
    $this->actingAs($admin);

    $this->get('/admin/users')->assertRedirect(route('two-factor.setup'));

    $this->from('/two-factor/setup')->post('/two-factor/setup/code', ['transaction_password' => 'Trans4cao!Segura'])
        ->assertRedirect('/two-factor/setup');
    $this->post('/two-factor/setup', ['code' => requiredTfaLastCode(VerificationPurpose::TwoFactorSetup)])
        ->assertRedirect(url('/admin/users'));

    $this->get('/admin/users')->assertOk();
})->group('admin');

it('carência: quem já existia opera com o aviso do prazo; depois do prazo, configura', function (): void {
    config()->set('auth.two_factor.required', 'all');
    config()->set('auth.two_factor.grace_days', 14);
    config()->set('auth.two_factor.required_since', '2026-09-01');

    $this->travelTo('2026-09-10 10:00:00');
    $antiga = User::factory()->create(['created_at' => '2026-03-01 10:00:00']);

    $this->actingAs($antiga)->get('/dashboard')
        ->assertOk()
        ->assertSeeHtml('data-two-factor-grace')
        ->assertSee(route('two-factor.setup'));

    // A tela de configuração também abre, voluntária, com o prazo e a saída.
    $this->get('/two-factor/setup')
        ->assertOk()
        ->assertSeeHtml('data-two-factor-setup-grace')
        ->assertSeeHtml('data-two-factor-setup-later');

    $this->travelTo('2026-09-15 10:00:01');
    $this->get('/dashboard')->assertRedirect(route('two-factor.setup'));
});

it('sem a regra, a tela de configuração devolve ao painel e não há aviso', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/two-factor/setup')->assertRedirect(route('dashboard'));
    $this->get('/dashboard')->assertOk()->assertDontSeeHtml('data-two-factor-grace');
});

it('conta demo protegida fica de fora da regra (não pode ligar o segundo fator)', function (): void {
    config()->set('ui.demo_login.enabled', true);
    config()->set('auth.two_factor.required', 'all');

    $demo = User::factory()->create(['email' => config('ui.demo_login.email')]);

    $this->actingAs($demo)->get('/dashboard')->assertOk();
})->group('demo');
