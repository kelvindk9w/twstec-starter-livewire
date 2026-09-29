<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Auth\Actions\AttemptLogin;
use Twstec\Kit\Auth\Actions\CompleteTwoFactorLogin;
use Twstec\Kit\Auth\Actions\Logout;
use Twstec\Kit\Auth\Actions\RegisterUser;
use Twstec\Kit\Auth\Actions\ResendEmailVerification;
use Twstec\Kit\Auth\Actions\ResetPassword;
use Twstec\Kit\Auth\Actions\SendPasswordResetLink;
use Twstec\Kit\Auth\Actions\VerifyEmail;
use Twstec\Kit\Auth\Enums\EmailVerificationOutcome;
use Twstec\Kit\Auth\Enums\LoginOutcome;
use Twstec\Kit\Auth\Enums\TwoFactorChallengeOutcome;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Notifications\ResetPasswordNotification;
use Twstec\Kit\Auth\Notifications\VerifyEmailNotification;
use Twstec\Kit\Auth\Support\EmailVerification;
use Twstec\Kit\Auth\Support\PendingTwoFactorLogin;

// =============================================================================
// ACTIONS DA AUTENTICAÇÃO — a regra, chamada sem controller e sem resposta.
//
// É o que um front diferente (outro starter) reaproveita: aqui cada Action é
// chamada direto, com uma requisição montada à mão, e o efeito é conferido —
// sessão, limiter, estado intermediário, e-mail, eventos. Os testes de ponta a
// ponta dos fluxos (LoginTest, TwoFactorLoginTest, RegistrationTest,
// PasswordResetTest, EmailVerificationTest) continuam provando as telas.
// =============================================================================

beforeEach(function () {
    Mail::fake();
});

/**
 * Requisição com sessão iniciada, como a que chega do middleware web.
 */
function requisicaoComSessao(string $ip = '10.0.0.1'): Request
{
    $request = Request::create('/qualquer', 'POST', server: ['REMOTE_ADDR' => $ip]);
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);

    return $request;
}

// --- AttemptLogin -------------------------------------------------------------

it('AttemptLogin: senha certa autentica e troca o ID da sessão', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123']);
    $request = requisicaoComSessao();
    $antes = $request->session()->getId();

    $resultado = app(AttemptLogin::class)->handle($request, $user->email, 'SenhaForte123', false);

    expect($resultado)->toBe(LoginOutcome::Authenticated)
        ->and(Auth::id())->toBe($user->id)
        ->and($request->session()->getId())->not->toBe($antes);
});

// Sessão autenticada nasce com ID e token CSRF novos. (No Laravel 13 o
// Auth::login já faz isso; o regenerate() explícito da Action é a segunda
// barreira, para o caso de o guard mudar.)
it('AttemptLogin: senha certa renova também o token CSRF da sessão', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123']);
    $request = requisicaoComSessao();
    $token = $request->session()->token();

    app(AttemptLogin::class)->handle($request, $user->email, 'SenhaForte123', false);

    expect($request->session()->token())->not->toBe($token);
});

it('AttemptLogin: senha errada conta no limiter de e-mail + IP e recusa com a mensagem única', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123']);
    $request = requisicaoComSessao('10.0.0.2');
    $action = app(AttemptLogin::class);
    $chave = $action->throttleKey($request, $user->email);

    expect($chave)->toBe('login|'.strtolower($user->email).'|10.0.0.2');

    expect(fn () => $action->handle($request, $user->email, 'errada', false))
        ->toThrow(ValidationException::class, __('auth.failed'));

    expect(RateLimiter::attempts($chave))->toBe(1)
        ->and(Auth::check())->toBeFalse();
});

it('AttemptLogin: esgotadas as tentativas, recusa até a senha certa', function () {
    config()->set('auth.login.max_attempts', 2);
    $user = User::factory()->create(['password' => 'SenhaForte123']);
    $request = requisicaoComSessao('10.0.0.3');
    $action = app(AttemptLogin::class);

    foreach (range(1, 2) as $tentativa) {
        try {
            $action->handle($request, $user->email, 'errada', false);
        } catch (ValidationException) {
        }
    }

    expect(fn () => $action->handle($request, $user->email, 'SenhaForte123', false))
        ->toThrow(ValidationException::class);

    expect(Auth::check())->toBeFalse();
});

it('AttemptLogin: acerto limpa o contador de tentativas', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123']);
    $request = requisicaoComSessao('10.0.0.4');
    $action = app(AttemptLogin::class);

    try {
        $action->handle($request, $user->email, 'errada', false);
    } catch (ValidationException) {
    }

    $action->handle($request, $user->email, 'SenhaForte123', false);

    expect(RateLimiter::attempts($action->throttleKey($request, $user->email)))->toBe(0);
});

it('AttemptLogin: conta inativa com a senha certa é recusada com a mensagem própria', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123', 'status' => UserStatus::Blocked]);

    expect(fn () => app(AttemptLogin::class)->handle(requisicaoComSessao(), $user->email, 'SenhaForte123', false))
        ->toThrow(ValidationException::class, __('auth.account_inactive'));

    expect(Auth::check())->toBeFalse();
});

it('AttemptLogin: com segundo fator, abre o estado intermediário com sessão nova e não autentica', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123', 'two_factor_enabled_at' => now()]);
    $request = requisicaoComSessao();
    $antes = $request->session()->getId();

    $resultado = app(AttemptLogin::class)->handle($request, $user->email, 'SenhaForte123', true);

    expect($resultado)->toBe(LoginOutcome::TwoFactorRequired)
        ->and(Auth::check())->toBeFalse()
        ->and(PendingTwoFactorLogin::user($request)?->is($user))->toBeTrue()
        ->and(PendingTwoFactorLogin::remember($request))->toBeTrue()
        ->and($request->session()->getId())->not->toBe($antes);
});

// --- Logout -------------------------------------------------------------------

it('Logout: desautentica, invalida a sessão e renova o token CSRF', function () {
    $user = User::factory()->create();
    $request = requisicaoComSessao();
    Auth::login($user);
    $request->session()->put('marca', 'x');
    $token = $request->session()->token();

    app(Logout::class)->handle($request);

    expect(Auth::check())->toBeFalse()
        ->and($request->session()->has('marca'))->toBeFalse()
        ->and($request->session()->token())->not->toBe($token);
});

// --- RegisterUser -------------------------------------------------------------

it('RegisterUser: cria a conta no idioma atual, autentica com sessão nova e envia a verificação', function () {
    Notification::fake();
    app()->setLocale('es');
    $request = requisicaoComSessao();
    $antes = $request->session()->getId();

    $user = app(RegisterUser::class)->handle($request, [
        'name' => 'Nova',
        'email' => 'nova@example.com',
        'password' => 'SenhaForte123',
    ]);

    expect($user->exists)->toBeTrue()
        ->and($user->locale)->toBe('es')
        ->and($user->codigo_publico)->toStartWith('USR-')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and(Auth::id())->toBe($user->id)
        ->and($request->session()->getId())->not->toBe($antes);

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('RegisterUser: com a verificação desligada, não envia e-mail', function () {
    Notification::fake();
    config()->set('auth.email_verification.required', false);

    app(RegisterUser::class)->handle(requisicaoComSessao(), [
        'name' => 'Nova',
        'email' => 'nova2@example.com',
        'password' => 'SenhaForte123',
    ]);

    Notification::assertNothingSent();
});

// --- SendPasswordResetLink / ResetPassword --------------------------------------

it('SendPasswordResetLink: envia para conta existente e fica calado para e-mail desconhecido', function () {
    Notification::fake();
    $user = User::factory()->create();

    app(SendPasswordResetLink::class)->handle($user->email);
    app(SendPasswordResetLink::class)->handle('ninguem@example.com');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
    Notification::assertCount(1);
});

it('ResetPassword: troca a senha, renova o remember_token e dispara PasswordReset', function () {
    Event::fake([PasswordReset::class]);
    $user = User::factory()->create(['password' => 'SenhaAntiga123']);
    $lembrar = $user->remember_token;

    $status = app(ResetPassword::class)->handle([
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'SenhaNova12345',
    ]);

    expect(ResetPassword::succeeded($status))->toBeTrue()
        ->and(Auth::validate(['email' => $user->email, 'password' => 'SenhaNova12345']))->toBeTrue()
        ->and($user->fresh()?->remember_token)->not->toBe($lembrar);

    Event::assertDispatched(PasswordReset::class);
});

it('ResetPassword: token inválido não troca nada e devolve o status do broker', function () {
    $user = User::factory()->create(['password' => 'SenhaAntiga123']);

    $status = app(ResetPassword::class)->handle([
        'token' => 'invalido',
        'email' => $user->email,
        'password' => 'SenhaNova12345',
    ]);

    expect($status)->toBe(Password::InvalidToken)
        ->and(ResetPassword::succeeded($status))->toBeFalse()
        ->and(Auth::validate(['email' => $user->email, 'password' => 'SenhaAntiga123']))->toBeTrue();
});

// --- VerifyEmail / ResendEmailVerification --------------------------------------

it('VerifyEmail: link de outra conta é recusado antes de qualquer outra conferência', function () {
    $user = User::factory()->unverified()->create();
    $outro = User::factory()->unverified()->create();

    $resultado = app(VerifyEmail::class)->handle(requisicaoComSessao(), $user, (string) $outro->uuid, sha1($outro->email));

    expect($resultado->outcome)->toBe(EmailVerificationOutcome::WrongAccount)
        ->and($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('VerifyEmail: link aceito confirma o e-mail uma vez só (um evento Verified)', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();
    $url = EmailVerification::verificationUrl($user);
    $request = Request::create($url);
    $partes = explode('/', (string) parse_url($url, PHP_URL_PATH));
    $hash = array_pop($partes);
    $uuid = array_pop($partes);

    $primeiro = app(VerifyEmail::class)->handle($request, $user, $uuid, $hash);
    $segundo = app(VerifyEmail::class)->handle($request, $user->fresh(), $uuid, $hash);

    expect($primeiro->outcome)->toBe(EmailVerificationOutcome::Verified)
        ->and($segundo->outcome)->toBe(EmailVerificationOutcome::Verified)
        ->and($user->fresh()?->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatchedTimes(Verified::class, 1);
});

it('ResendEmailVerification: envia, depois pede espera; sem pendência, não faz nada', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $primeiro = app(ResendEmailVerification::class)->handle($user);
    $segundo = app(ResendEmailVerification::class)->handle($user);

    expect($primeiro->outcome)->toBe(EmailVerificationOutcome::LinkSent)
        ->and($segundo->outcome)->toBe(EmailVerificationOutcome::Cooldown)
        ->and($segundo->seconds)->toBeGreaterThan(0);

    $verificado = User::factory()->create();

    expect(app(ResendEmailVerification::class)->handle($verificado)->outcome)->toBe(EmailVerificationOutcome::NotPending);
});

// --- CompleteTwoFactorLogin -----------------------------------------------------

it('CompleteTwoFactorLogin: sem estado é Missing; com estado vencido é Expired e o estado some', function () {
    $request = requisicaoComSessao();

    expect(app(CompleteTwoFactorLogin::class)->handle($request, '123456')->outcome)->toBe(TwoFactorChallengeOutcome::Missing);

    $user = User::factory()->create(['two_factor_enabled_at' => now()]);
    PendingTwoFactorLogin::start($request, $user, false, 10);
    $this->travel(11)->minutes();

    expect(app(CompleteTwoFactorLogin::class)->handle($request, '123456')->outcome)->toBe(TwoFactorChallengeOutcome::Expired)
        ->and(PendingTwoFactorLogin::exists($request))->toBeFalse();
});

it('CompleteTwoFactorLogin: conta que ficou inativa no meio do caminho é Abandoned, com o motivo', function () {
    $user = User::factory()->create(['two_factor_enabled_at' => now()]);
    $request = requisicaoComSessao();
    PendingTwoFactorLogin::start($request, $user, false, 10);
    $user->forceFill(['status' => UserStatus::Blocked])->save();

    $resultado = app(CompleteTwoFactorLogin::class)->handle($request, '123456');

    expect($resultado->outcome)->toBe(TwoFactorChallengeOutcome::Abandoned)
        ->and($resultado->message)->toBe(__('auth.account_inactive'))
        ->and(PendingTwoFactorLogin::exists($request))->toBeFalse()
        ->and(Auth::check())->toBeFalse();
});

it('CompleteTwoFactorLogin: código certo autentica com ID de sessão e token CSRF novos, e aplica o "manter conectado"', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123', 'two_factor_enabled_at' => now()]);
    $request = requisicaoComSessao();

    app(AttemptLogin::class)->handle($request, $user->email, 'SenhaForte123', true);

    $code = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::LoginChallenge)
        ->last()->code;
    $id = $request->session()->getId();
    $token = $request->session()->token();

    $resultado = app(CompleteTwoFactorLogin::class)->handle($request, $code);

    expect($resultado->outcome)->toBe(TwoFactorChallengeOutcome::Authenticated)
        ->and(Auth::id())->toBe($user->id)
        ->and(Auth::viaRemember() || $user->fresh()?->getRememberToken() !== null)->toBeTrue()
        ->and(PendingTwoFactorLogin::exists($request))->toBeFalse()
        ->and($request->session()->getId())->not->toBe($id)
        ->and($request->session()->token())->not->toBe($token);
});

it('CompleteTwoFactorLogin: desistir apaga o estado intermediário', function () {
    $user = User::factory()->create(['two_factor_enabled_at' => now()]);
    $request = requisicaoComSessao();
    PendingTwoFactorLogin::start($request, $user, false, 10);

    expect(app(CompleteTwoFactorLogin::class)->cancel($request)->outcome)->toBe(TwoFactorChallengeOutcome::Cancelled)
        ->and(PendingTwoFactorLogin::exists($request))->toBeFalse();
});
