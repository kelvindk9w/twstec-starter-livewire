<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\VerificationCode;
use Twstec\Kit\Auth\Support\PendingTwoFactorLogin;

// Verificação em duas etapas no LOGIN do painel do cliente (TwoFactorLogin +
// TwoFactorChallengeController): senha certa NÃO autentica; o código por
// e-mail conclui. Estado intermediário, uso único, limites por código, conta e
// IP, anti-enumeração, "manter conectado" só depois do código.

beforeEach(function () {
    Mail::fake();
    // O que está sob teste são os limites de NEGÓCIO, não o throttle HTTP.
    config()->set('security.rate_limit.sensitive', 1000);
});

function tfaUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => 'LoginForte123',
        'two_factor_enabled_at' => now(),
    ], $attributes));
}

/**
 * Último código de LOGIN enviado (Mail::fake).
 */
function tfaLastCode(): string
{
    $mails = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::LoginChallenge);

    expect($mails)->not->toBeEmpty();

    return $mails->last()->code;
}

function tfaWrong(string $code): string
{
    return $code === '000000' ? '000001' : '000000';
}

function tfaPassword(User $user, bool $remember = false): TestResponse
{
    return test()->post('/login', array_filter([
        'email' => $user->email,
        'password' => 'LoginForte123',
        'remember' => $remember ? '1' : null,
    ]));
}

it('senha certa com o segundo fator ligado NÃO autentica: leva à tela do código e envia o e-mail', function () {
    $user = tfaUser();

    tfaPassword($user)->assertRedirect(route('two-factor.challenge'));

    $this->assertGuest();
    expect(Auth::check())->toBeFalse();

    Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail): bool => $mail->hasTo($user->email)
        && $mail->purpose === VerificationPurpose::LoginChallenge);

    // Só o hash vai ao banco, com a finalidade própria.
    $record = VerificationCode::query()->sole();
    expect($record->purpose)->toBe(VerificationPurpose::LoginChallenge)
        ->and($record->code_hash)->not->toBe(tfaLastCode());

    $this->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee(__('auth.two_factor.title'))
        ->assertSee($user->email);
});

it('o estado intermediário não abre o painel', function () {
    tfaPassword(tfaUser());

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get('/profile')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('código certo autentica, com ID de sessão novo, e leva ao painel', function () {
    $user = tfaUser();

    tfaPassword($user);
    $sessionIdBefore = session()->getId();

    $this->post(route('two-factor.challenge'), ['code' => tfaLastCode()])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionIdBefore)
        ->and(session()->has(PendingTwoFactorLogin::SESSION_KEY))->toBeFalse();
});

it('depois do código, volta à página que a pessoa tentou abrir (SafeRedirect)', function () {
    $user = tfaUser();

    $this->get('/notifications')->assertRedirect(route('login'));
    tfaPassword($user);

    $this->post(route('two-factor.challenge'), ['code' => tfaLastCode()])
        ->assertRedirect(url('/notifications'));
});

it('"manter conectado" só vale depois do segundo fator', function () {
    $user = tfaUser();
    $recaller = Auth::guard('web')->getRecallerName();

    $tokenBefore = $user->remember_token;

    tfaPassword($user, remember: true)->assertCookieMissing($recaller);
    expect($user->fresh()->remember_token)->toBe($tokenBefore);

    $this->post(route('two-factor.challenge'), ['code' => tfaLastCode()])
        ->assertCookie($recaller);
});

it('sem "manter conectado", o código certo não cria o cookie de longa duração', function () {
    $user = tfaUser();

    tfaPassword($user);

    $this->post(route('two-factor.challenge'), ['code' => tfaLastCode()])
        ->assertCookieMissing(Auth::guard('web')->getRecallerName());
});

it('código errado não autentica e conta a tentativa', function () {
    $user = tfaUser();
    tfaPassword($user);

    $this->post(route('two-factor.challenge'), ['code' => tfaWrong(tfaLastCode())])
        ->assertRedirect(route('two-factor.challenge'))
        ->assertSessionHasErrors(['code' => __('auth.two_factor.invalid')]);

    $this->assertGuest();
    expect(VerificationCode::query()->sole()->attempts)->toBe(1);
});

it('recusa código fora do formato sem chegar a conferir', function (string $code) {
    tfaPassword(tfaUser());

    $this->post(route('two-factor.challenge'), ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
})->with(['', '12345', '1234567', 'abcdef', '12 456']);

it('esgotadas as tentativas do código, nem o código certo vale mais', function () {
    config()->set('auth.verification.max_attempts', 3);
    $user = tfaUser();
    tfaPassword($user);
    $code = tfaLastCode();

    foreach (range(1, 3) as $ignored) {
        $this->post(route('two-factor.challenge'), ['code' => tfaWrong($code)]);
    }

    $this->post(route('two-factor.challenge'), ['code' => $code])
        ->assertSessionHasErrors(['code' => __('auth.two_factor.expired')]);

    $this->assertGuest();
});

it('código é de USO ÚNICO: o mesmo código não abre uma segunda sessão', function () {
    $user = tfaUser();
    tfaPassword($user);
    $code = tfaLastCode();

    $this->post(route('two-factor.challenge'), ['code' => $code])->assertRedirect(route('dashboard'));
    $this->post('/logout');
    $this->assertGuest();

    // Novo login dentro do intervalo de reenvio: nenhum código novo sai, e o
    // antigo já foi consumido.
    tfaPassword($user)->assertRedirect(route('two-factor.challenge'));

    $this->post(route('two-factor.challenge'), ['code' => $code])
        ->assertSessionHasErrors(['code' => __('auth.two_factor.expired')]);

    $this->assertGuest();
});

it('código de AÇÃO SENSÍVEL não conclui o login (finalidades separadas)', function () {
    $user = tfaUser();
    tfaPassword($user);

    // Um código de outra finalidade para a mesma conta, conhecido pelo teste.
    VerificationCode::query()->create([
        'user_id' => $user->id,
        'channel' => 'email',
        'purpose' => VerificationPurpose::SensitiveAction,
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
    ]);

    $login = VerificationCode::query()->where('purpose', VerificationPurpose::LoginChallenge->value)->sole();
    $login->update(['code_hash' => Hash::make('654321')]);

    $this->post(route('two-factor.challenge'), ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('bloqueia a CONTA depois de N códigos errados, mesmo pedindo códigos novos', function () {
    config()->set('auth.verification.max_attempts', 100);
    config()->set('auth.verification.resend_cooldown_seconds', 0);
    config()->set('auth.two_factor.max_attempts_per_account', 3);
    $user = tfaUser();
    tfaPassword($user);

    $this->post(route('two-factor.challenge'), ['code' => tfaWrong(tfaLastCode())]);
    $this->post(route('two-factor.resend'));
    $this->post(route('two-factor.challenge'), ['code' => tfaWrong(tfaLastCode())]);

    // Terceiro erro: bloqueio — o estado intermediário acaba e o código morre.
    $code = tfaLastCode();
    $this->post(route('two-factor.challenge'), ['code' => tfaWrong($code)])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toBe(__('auth.two_factor.locked', ['minutes' => 15]));
    expect(VerificationCode::query()->whereNull('consumed_at')->count())->toBe(0);

    // A senha certa de novo não reabre o desafio enquanto durar o bloqueio.
    Mail::fake();
    tfaPassword($user)->assertSessionHasErrors('email');
    Mail::assertNothingQueued();
    $this->assertGuest();

    // Passada a janela, volta a funcionar.
    $this->travel(16)->minutes();
    tfaPassword($user)->assertRedirect(route('two-factor.challenge'));
    $this->post(route('two-factor.challenge'), ['code' => tfaLastCode()])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('bloqueia o IP que erra códigos de várias contas', function () {
    config()->set('auth.two_factor.max_attempts_per_account', 100);
    config()->set('auth.two_factor.max_attempts_per_ip', 2);

    $first = tfaUser();
    tfaPassword($first);
    $this->post(route('two-factor.challenge'), ['code' => tfaWrong(tfaLastCode())]);
    $this->post(route('two-factor.cancel'));

    $second = tfaUser();
    tfaPassword($second);
    $this->post(route('two-factor.challenge'), ['code' => tfaWrong(tfaLastCode())])
        ->assertRedirect(route('login'));

    // Mesmo com o código certo de uma terceira conta, o IP está bloqueado.
    $third = tfaUser();
    tfaPassword($third)->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('o estado intermediário expira', function () {
    config()->set('auth.two_factor.challenge_ttl_minutes', 5);
    config()->set('auth.verification.code_ttl_minutes', 30);
    $user = tfaUser();
    tfaPassword($user);
    $code = tfaLastCode();

    $this->travel(6)->minutes();

    $this->get(route('two-factor.challenge'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.two_factor.challenge_expired')]);

    $this->post(route('two-factor.challenge'), ['code' => $code])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('senha trocada durante o desafio derruba o estado intermediário', function () {
    $user = tfaUser();
    tfaPassword($user);
    $code = tfaLastCode();

    $user->forceFill(['password' => 'OutraSenha456'])->save();

    $this->post(route('two-factor.challenge'), ['code' => $code])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('conta bloqueada durante o desafio continua barrada', function () {
    $user = tfaUser();
    tfaPassword($user);
    $code = tfaLastCode();

    $user->forceFill(['status' => UserStatus::Blocked])->save();

    $this->post(route('two-factor.challenge'), ['code' => $code])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.account_inactive')]);

    $this->assertGuest();
});

it('conta inativa com segundo fator recebe a mesma recusa do login atual, sem código', function () {
    $user = tfaUser(['status' => UserStatus::Pending]);

    tfaPassword($user)->assertSessionHasErrors(['email' => __('auth.account_inactive')]);

    Mail::assertNothingQueued();
    $this->assertGuest();
});

it('anti-enumeração: senha errada numa conta com segundo fator responde igual a conta inexistente', function () {
    $user = tfaUser();

    $wrong = $this->post('/login', ['email' => $user->email, 'password' => 'Errada999']);
    $wrong->assertRedirect()->assertSessionHasErrors(['email' => __('auth.failed')]);
    $wrongTarget = $wrong->headers->get('Location');

    $missing = $this->post('/login', ['email' => 'ninguem@example.com', 'password' => 'Errada999']);
    $missing->assertSessionHasErrors(['email' => __('auth.failed')]);

    expect($missing->headers->get('Location'))->toBe($wrongTarget)
        ->and(session()->has(PendingTwoFactorLogin::SESSION_KEY))->toBeFalse();

    Mail::assertNothingQueued();
});

it('reenviar respeita o intervalo e o código novo invalida o anterior', function () {
    config()->set('auth.verification.resend_cooldown_seconds', 60);
    $user = tfaUser();
    tfaPassword($user);
    $first = tfaLastCode();

    $this->post(route('two-factor.resend'))
        ->assertSessionHasErrors('code');
    Mail::assertQueuedCount(1);

    $this->travel(61)->seconds();

    $this->post(route('two-factor.resend'))
        ->assertRedirect(route('two-factor.challenge'))
        ->assertSessionHas('status', __('auth.two_factor.resent'));
    Mail::assertQueuedCount(2);

    $second = tfaLastCode();

    if ($first !== $second) {
        $this->post(route('two-factor.challenge'), ['code' => $first])->assertSessionHasErrors('code');
    }

    $this->post(route('two-factor.challenge'), ['code' => $second])->assertRedirect(route('dashboard'));
});

it('voltar ao login cancela: o código deixa de valer e a tela do código fecha', function () {
    $user = tfaUser();
    tfaPassword($user);
    $code = tfaLastCode();

    $this->post(route('two-factor.cancel'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('auth.two_factor.cancelled'));

    expect(VerificationCode::query()->whereNull('consumed_at')->count())->toBe(0);

    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    $this->post(route('two-factor.challenge'), ['code' => $code])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('a tela do código sem estado intermediário volta ao login; logado, é recusada pelo guest', function () {
    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('two-factor.challenge'))->assertRedirect(route('dashboard'));
});

it('sem o segundo fator ligado, o login continua em um passo só', function () {
    $user = User::factory()->create(['password' => 'LoginForte123']);

    tfaPassword($user)->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    Mail::assertNothingQueued();
});

it('com AUTH_TWO_FACTOR_ENABLED=false o login ignora a preferência gravada', function () {
    config()->set('auth.two_factor.enabled', false);
    $user = tfaUser();

    tfaPassword($user)->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();
});

it('trocar a senha no perfil NÃO desliga o segundo fator', function () {
    $user = tfaUser();
    $this->actingAs($user);

    Livewire\Livewire::test(Profile::class)
        ->set('currentPassword', 'LoginForte123')
        ->set('password', 'NovaSenha789')
        ->set('passwordConfirmation', 'NovaSenha789')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();
});

it('redefinir a senha por e-mail NÃO desliga o segundo fator (e o login segue pedindo o código)', function () {
    $user = tfaUser();
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Redefinida123',
        'password_confirmation' => 'Redefinida123',
    ])->assertRedirect(route('login'));

    expect($user->fresh()->two_factor_enabled_at)->not->toBeNull();

    $this->post('/login', ['email' => $user->email, 'password' => 'Redefinida123'])
        ->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
});

it('o e-mail do código de login sai no idioma do destinatário, com o texto do login', function () {
    $user = tfaUser(['locale' => 'es']);

    tfaPassword($user);

    Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail): bool => $mail->locale === 'es');

    $html = (new VerificationCodeMail('123456', VerificationPurpose::LoginChallenge))->locale('es')->render();

    expect($html)->toContain(__('mail.login_code.heading', locale: 'es'))
        ->and($html)->toContain('123456')
        ->and($html)->not->toContain(__('mail.verification_code.intro', locale: 'es'));
});
