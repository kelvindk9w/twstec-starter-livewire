<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Contracts\Responses\EmailVerificationResponse;
use Twstec\Kit\Auth\Contracts\Responses\FailedPasswordResetResponse;
use Twstec\Kit\Auth\Contracts\Responses\LoginResponse;
use Twstec\Kit\Auth\Contracts\Responses\LogoutResponse;
use Twstec\Kit\Auth\Contracts\Responses\PasswordResetLinkSentResponse;
use Twstec\Kit\Auth\Contracts\Responses\PasswordResetResponse;
use Twstec\Kit\Auth\Contracts\Responses\RegisterResponse;
use Twstec\Kit\Auth\Contracts\Responses\TwoFactorChallengeResponse;
use Twstec\Kit\Auth\Contracts\Responses\TwoFactorLoginResponse;
use Twstec\Kit\Auth\Contracts\Responses\TwoFactorRequiredResponse;
use Twstec\Kit\Auth\Contracts\Responses\VerifyEmailResponse;
use Twstec\Kit\Auth\Enums\EmailVerificationOutcome;
use Twstec\Kit\Auth\Enums\TwoFactorChallengeOutcome;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Providers\AuthServiceProvider;
use Twstec\Kit\Auth\Support\EmailVerification;
use Twstec\Kit\Auth\Support\EmailVerificationResult;
use Twstec\Kit\Auth\Support\TwoFactorChallengeResult;

// =============================================================================
// CONTRATOS DE RESPOSTA DA AUTENTICAÇÃO — o ponto de troca de um front.
//
// A regra dos fluxos mora nas Actions; o que volta ao navegador sai de um
// contrato com implementação padrão no container. Aqui cada contrato é trocado
// por uma resposta JSON de teste e o fluxo real (rota, middleware, Action) é
// exercitado: a resposta muda, a regra não — a sessão continua autenticada,
// o e-mail continua confirmado, a senha continua trocada.
// =============================================================================

beforeEach(function () {
    Mail::fake();
    config()->set('security.rate_limit.sensitive', 1000);
});

/**
 * Resposta JSON de teste que marca qual contrato respondeu e com que dado.
 */
function respostaDeTeste(string $contrato, mixed $dado = null): JsonResponse
{
    return new JsonResponse(['contrato' => $contrato, 'dado' => $dado], 299);
}

it('registra uma implementação padrão para cada contrato', function () {
    foreach (AuthServiceProvider::RESPONSES as $contrato => $padrao) {
        expect(app($contrato))->toBeInstanceOf($padrao)
            ->and(app($contrato))->toBeInstanceOf($contrato);
    }

    expect(AuthServiceProvider::RESPONSES)->toHaveCount(11);
});

it('o padrão não passa por cima de uma resposta que o app já registrou', function () {
    $propria = new class implements LoginResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('propria');
        }
    };

    app()->bind(LoginResponse::class, fn () => $propria);

    (new AuthServiceProvider(app()))->register();

    expect(app(LoginResponse::class))->toBe($propria);
});

it('login: o controller usa o LoginResponse registrado, e a sessão nasce autenticada', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123']);

    app()->instance(LoginResponse::class, new class implements LoginResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('login', Auth::id());
        }
    });

    $this->post('/login', ['email' => $user->email, 'password' => 'SenhaForte123'])
        ->assertStatus(299)
        ->assertExactJson(['contrato' => 'login', 'dado' => $user->id]);

    $this->assertAuthenticatedAs($user);
});

it('login com segundo fator: usa o TwoFactorRequiredResponse, sem autenticar', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123', 'two_factor_enabled_at' => now()]);

    app()->instance(TwoFactorRequiredResponse::class, new class implements TwoFactorRequiredResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('dois-fatores');
        }
    });

    $this->post('/login', ['email' => $user->email, 'password' => 'SenhaForte123'])
        ->assertStatus(299)
        ->assertJsonPath('contrato', 'dois-fatores');

    $this->assertGuest();
    Mail::assertQueued(VerificationCodeMail::class);
});

it('segundo fator: código certo usa o TwoFactorLoginResponse; os demais resultados, o TwoFactorChallengeResponse', function () {
    $user = User::factory()->create(['password' => 'SenhaForte123', 'two_factor_enabled_at' => now()]);

    app()->instance(TwoFactorLoginResponse::class, new class implements TwoFactorLoginResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('dois-fatores-ok');
        }
    });
    app()->instance(TwoFactorChallengeResponse::class, new class implements TwoFactorChallengeResponse
    {
        public function toResponse(Request $request, TwoFactorChallengeResult $result): Response
        {
            return respostaDeTeste('dois-fatores-status', $result->outcome->name);
        }
    });

    $this->post('/login', ['email' => $user->email, 'password' => 'SenhaForte123']);

    $code = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $mail): bool => $mail->purpose === VerificationPurpose::LoginChallenge)
        ->last()->code;

    $this->post('/two-factor-challenge', ['code' => $code === '000000' ? '000001' : '000000'])
        ->assertStatus(299)
        ->assertExactJson(['contrato' => 'dois-fatores-status', 'dado' => 'InvalidCode']);

    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertStatus(299)
        ->assertJsonPath('contrato', 'dois-fatores-ok');

    $this->assertAuthenticatedAs($user);
});

it('segundo fator sem estado intermediário: a tela também responde pelo contrato', function () {
    app()->instance(TwoFactorChallengeResponse::class, new class implements TwoFactorChallengeResponse
    {
        public function toResponse(Request $request, TwoFactorChallengeResult $result): Response
        {
            return respostaDeTeste('dois-fatores-status', $result->outcome->name);
        }
    });

    $this->get('/two-factor-challenge')->assertExactJson(['contrato' => 'dois-fatores-status', 'dado' => 'Missing']);
    $this->post('/two-factor-challenge/cancel')->assertExactJson(['contrato' => 'dois-fatores-status', 'dado' => 'Cancelled']);
});

it('logout: usa o LogoutResponse e a sessão acaba', function () {
    $user = User::factory()->create();

    app()->instance(LogoutResponse::class, new class implements LogoutResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('logout');
        }
    });

    $this->actingAs($user)->post('/logout')->assertStatus(299)->assertJsonPath('contrato', 'logout');

    $this->assertGuest();
});

it('cadastro: usa o RegisterResponse com a conta criada', function () {
    Notification::fake();

    app()->instance(RegisterResponse::class, new class implements RegisterResponse
    {
        public function toResponse(Request $request, AuthUser $user): Response
        {
            return respostaDeTeste('cadastro', $user->email);
        }
    });

    $this->post('/register', [
        'name' => 'Nova Pessoa',
        'email' => 'nova@example.com',
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ])->assertStatus(299)->assertExactJson(['contrato' => 'cadastro', 'dado' => 'nova@example.com']);

    $this->assertAuthenticated();
});

it('link de redefinição: usa o PasswordResetLinkSentResponse, exista ou não o e-mail', function () {
    Notification::fake();
    $user = User::factory()->create();

    app()->instance(PasswordResetLinkSentResponse::class, new class implements PasswordResetLinkSentResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('link');
        }
    });

    $this->post('/forgot-password', ['email' => $user->email])->assertStatus(299)->assertJsonPath('contrato', 'link');
    $this->post('/forgot-password', ['email' => 'ninguem@example.com'])->assertStatus(299)->assertJsonPath('contrato', 'link');
});

it('redefinição: sucesso usa o PasswordResetResponse; recusa, o FailedPasswordResetResponse', function () {
    $user = User::factory()->create(['password' => 'SenhaAntiga123']);

    app()->instance(PasswordResetResponse::class, new class implements PasswordResetResponse
    {
        public function toResponse(Request $request, string $status): Response
        {
            return respostaDeTeste('redefinida', $status);
        }
    });
    app()->instance(FailedPasswordResetResponse::class, new class implements FailedPasswordResetResponse
    {
        public function toResponse(Request $request, string $status): Response
        {
            return respostaDeTeste('recusada', $status);
        }
    });

    $dados = ['email' => $user->email, 'password' => 'SenhaNova12345', 'password_confirmation' => 'SenhaNova12345'];

    $this->post('/reset-password', [...$dados, 'token' => 'token-invalido'])
        ->assertExactJson(['contrato' => 'recusada', 'dado' => Password::InvalidToken]);

    $this->post('/reset-password', [...$dados, 'token' => Password::createToken($user)])
        ->assertExactJson(['contrato' => 'redefinida', 'dado' => Password::PasswordReset]);

    expect(Auth::validate(['email' => $user->email, 'password' => 'SenhaNova12345']))->toBeTrue();
});

it('verificação de e-mail: link aceito usa o VerifyEmailResponse; o resto, o EmailVerificationResponse', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    app()->instance(VerifyEmailResponse::class, new class implements VerifyEmailResponse
    {
        public function toResponse(Request $request): Response
        {
            return respostaDeTeste('verificado');
        }
    });
    app()->instance(EmailVerificationResponse::class, new class implements EmailVerificationResponse
    {
        public function toResponse(Request $request, EmailVerificationResult $result): Response
        {
            return respostaDeTeste('verificacao-status', $result->outcome->name);
        }
    });

    $this->actingAs($user)->post('/email/verification-notification')
        ->assertExactJson(['contrato' => 'verificacao-status', 'dado' => 'LinkSent']);

    $this->actingAs($user)->get(EmailVerification::verificationUrl($user).'x')
        ->assertExactJson(['contrato' => 'verificacao-status', 'dado' => 'InvalidLink']);

    $this->actingAs($user)->get(EmailVerification::verificationUrl($user))
        ->assertExactJson(['contrato' => 'verificado', 'dado' => null]);

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user->fresh())->get('/email/verify')
        ->assertExactJson(['contrato' => 'verificacao-status', 'dado' => 'NotPending']);
});

// --- As respostas PADRÃO, caso a caso (o que as telas Blade sempre fizeram) ----

/**
 * Requisição com sessão própria, para ler o flash que a resposta deixou.
 */
function requisicaoParaResposta(): Request
{
    $request = Request::create('/qualquer', 'POST');
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);
    app()->instance('request', $request);

    return $request;
}

/**
 * @return array{url: string, erros: array<string, list<string>>, status: mixed, erro_verificacao: mixed}
 */
function lerRedirect(Response $resposta, Request $request): array
{
    expect($resposta)->toBeInstanceOf(RedirectResponse::class);

    /** @var RedirectResponse $resposta */
    $erros = $request->session()->get('errors');

    return [
        'url' => $resposta->getTargetUrl(),
        'erros' => $erros === null ? [] : $erros->getBag('default')->toArray(),
        'status' => $request->session()->get('status'),
        'erro_verificacao' => $request->session()->get('verification_error'),
    ];
}

it('TwoFactorChallengeResponse padrão: cada resultado vai para o lugar de sempre', function (TwoFactorChallengeOutcome $outcome, string $rota, array $erros, ?string $status) {
    $request = requisicaoParaResposta();

    $resposta = app(TwoFactorChallengeResponse::class)
        ->toResponse($request, new TwoFactorChallengeResult($outcome, 'motivo', 42));

    $traduzir = fn (string $chave): string => $chave === 'motivo' ? 'motivo' : __($chave, ['seconds' => 42]);

    expect(lerRedirect($resposta, $request))->toBe([
        'url' => route($rota),
        'erros' => array_map(fn (string $chave): array => [$traduzir($chave)], $erros),
        'status' => $status === null ? null : __($status),
        'erro_verificacao' => null,
    ]);
})->with([
    'código errado' => [TwoFactorChallengeOutcome::InvalidCode, 'two-factor.challenge', ['code' => 'auth.two_factor.invalid'], null],
    'código vencido' => [TwoFactorChallengeOutcome::ExpiredCode, 'two-factor.challenge', ['code' => 'auth.two_factor.expired'], null],
    'reenvio em espera' => [TwoFactorChallengeOutcome::ResendCooldown, 'two-factor.challenge', ['code' => 'auth.two_factor.resend_cooldown'], null],
    'reenviado' => [TwoFactorChallengeOutcome::CodeResent, 'two-factor.challenge', [], 'auth.two_factor.resent'],
    'abandonado' => [TwoFactorChallengeOutcome::Abandoned, 'login', ['email' => 'motivo'], null],
    'desistência' => [TwoFactorChallengeOutcome::Cancelled, 'login', [], 'auth.two_factor.cancelled'],
    'sem estado' => [TwoFactorChallengeOutcome::Missing, 'login', [], null],
    'estado vencido' => [TwoFactorChallengeOutcome::Expired, 'login', ['email' => 'auth.two_factor.challenge_expired'], null],
]);

it('EmailVerificationResponse padrão: cada resultado vai para o lugar de sempre', function (EmailVerificationOutcome $outcome, string $rota, ?string $erro, ?string $status) {
    $request = requisicaoParaResposta();

    $resposta = app(EmailVerificationResponse::class)
        ->toResponse($request, new EmailVerificationResult($outcome, 42));

    expect(lerRedirect($resposta, $request))->toBe([
        'url' => route($rota),
        'erros' => [],
        'status' => $status === null ? null : __($status),
        'erro_verificacao' => $erro === null ? null : __($erro, ['seconds' => 42]),
    ]);
})->with([
    'nada pendente' => [EmailVerificationOutcome::NotPending, 'dashboard', null, null],
    'outra conta' => [EmailVerificationOutcome::WrongAccount, 'verification.notice', 'auth.email_verification.wrong_account', null],
    'link inválido' => [EmailVerificationOutcome::InvalidLink, 'verification.notice', 'auth.email_verification.invalid_link', null],
    'em espera' => [EmailVerificationOutcome::Cooldown, 'verification.notice', 'auth.email_verification.cooldown', null],
    'reenviado' => [EmailVerificationOutcome::LinkSent, 'verification.notice', null, 'auth.email_verification.sent'],
]);

it('as respostas de sucesso padrão levam ao destino de sempre', function () {
    $request = requisicaoParaResposta();

    expect(lerRedirect(app(LoginResponse::class)->toResponse($request), $request)['url'])->toBe(route('dashboard'))
        ->and(lerRedirect(app(TwoFactorLoginResponse::class)->toResponse($request), $request)['url'])->toBe(route('dashboard'))
        ->and(lerRedirect(app(TwoFactorRequiredResponse::class)->toResponse($request), $request)['url'])->toBe(route('two-factor.challenge'))
        ->and(lerRedirect(app(LogoutResponse::class)->toResponse($request), $request))->toMatchArray(['url' => route('login'), 'status' => __('auth.logged_out')])
        ->and(lerRedirect(app(PasswordResetResponse::class)->toResponse($request, Password::PasswordReset), $request))->toMatchArray(['url' => route('login'), 'status' => __(Password::PasswordReset)]);

    $request->session()->put('url.intended', '/projects');

    expect(lerRedirect(app(VerifyEmailResponse::class)->toResponse($request), $request))
        ->toMatchArray(['url' => url('/projects'), 'status' => __('auth.email_verification.verified')]);
});
