<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\SensitiveActionToken;
use Twstec\Kit\Auth\Models\VerificationCode;

// Fluxo completo de AÇÃO SENSÍVEL (senha de transação + código por e-mail):
// senha de transação + código de 6 dígitos por e-mail → token de ação
// sensível (curta duração, USO ÚNICO, só hash no banco).

beforeEach(function () {
    // Os testes deste arquivo fazem várias chamadas às rotas sensíveis;
    // o throttle HTTP padrão (5/min) atrapalharia — o que está sob teste
    // aqui são os limites de NEGÓCIO (cooldown/tentativas), não o rate limit.
    config()->set('security.rate_limit.sensitive', 100);

    Route::post('/_test/acao-sensivel', fn () => response()->json(['executado' => true]))
        ->middleware(['auth', 'sensitive.token']);
});

/**
 * Usuário autenticado com senha de transação definida.
 */
function userComSenhaDeTransacao(): User
{
    /** @var User $user */
    $user = User::factory()->withTransactionPassword()->create();

    test()->actingAs($user);

    return $user;
}

/**
 * Solicita o código e captura o valor enviado no e-mail (Mail::fake).
 */
function solicitarCodigo(User $user): string
{
    test()->postJson('/sensitive-actions/code', [
        'transaction_password' => 'Trans4cao!Segura',
    ])->assertOk();

    $codigo = null;

    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo) {
        $codigo = $mail->code;

        return true;
    });

    return (string) $codigo;
}

it('exige autenticação nos endpoints de ação sensível (deny-by-default)', function () {
    $this->postJson('/sensitive-actions/code', ['transaction_password' => 'x'])
        ->assertUnauthorized();

    $this->postJson('/sensitive-actions/confirm', ['code' => '123456'])
        ->assertUnauthorized();

    $this->postJson('/_test/acao-sensivel', [])->assertUnauthorized();
});

it('completa o fluxo: senha de transação + código → token → operação sensível', function () {
    Mail::fake();
    $user = userComSenhaDeTransacao();

    // Passo 1: senha de transação válida → código enviado por e-mail (queue).
    $this->postJson('/sensitive-actions/code', [
        'transaction_password' => 'Trans4cao!Segura',
    ])->assertOk()
        ->assertJsonPath('message', __('auth.verification_code.sent'))
        ->assertJsonPath('expires_in_minutes', 10)
        ->assertJsonPath('resend_available_in_seconds', 60);

    $codigo = null;

    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo) {
        $codigo = $mail->code;

        return true;
    });

    expect($codigo)->toMatch('/^\d{6}$/');

    // No banco: SOMENTE o hash do código, com expiração (nunca plaintext).
    $registro = VerificationCode::query()->sole();

    expect($registro->code_hash)->not->toBe($codigo)
        ->and(Hash::check($codigo, $registro->code_hash))->toBeTrue()
        ->and($registro->expires_at->isFuture())->toBeTrue();

    // Passo 2: código válido → token de ação sensível emitido UMA vez.
    $response = $this->postJson('/sensitive-actions/confirm', ['code' => $codigo]);

    $response->assertOk()
        ->assertJsonPath('message', __('auth.sensitive_action.token_issued'))
        ->assertJsonStructure(['token', 'expires_at']);

    $token = $response->json('token');

    // No banco: SOMENTE o hash SHA-256 do token.
    $registroToken = SensitiveActionToken::query()->sole();

    expect($registroToken->token_hash)->toBe(hash('sha256', $token))
        ->and($registroToken->token_hash)->not->toBe($token);

    // Passo 3: o token autoriza a operação sensível.
    $this->postJson('/_test/acao-sensivel', [], ['X-Sensitive-Action-Token' => $token])
        ->assertOk()
        ->assertJsonPath('executado', true);
});

it('rejeita senha de transação incorreta na solicitação do código', function () {
    Mail::fake();
    userComSenhaDeTransacao();

    $this->postJson('/sensitive-actions/code', [
        'transaction_password' => 'SenhaErrada123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('transaction_password');

    Mail::assertNothingQueued();
    expect(VerificationCode::query()->count())->toBe(0);
});

it('rejeita solicitação de código sem senha de transação definida', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/sensitive-actions/code', [
        'transaction_password' => 'Qualquer123',
    ])->assertUnprocessable();

    Mail::assertNothingQueued();
});

it('rejeita código inválido e conta as tentativas', function () {
    Mail::fake();
    $user = userComSenhaDeTransacao();

    $codigo = solicitarCodigo($user);
    $errado = $codigo === '000000' ? '000001' : '000000';

    $this->postJson('/sensitive-actions/confirm', ['code' => $errado])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $registro = VerificationCode::query()->sole();

    expect($registro->attempts)->toBe(1)
        ->and($registro->consumed_at)->toBeNull();

    expect(SensitiveActionToken::query()->count())->toBe(0);
});

it('invalida o código ao esgotar o máximo de tentativas', function () {
    Mail::fake();
    config()->set('auth.verification.max_attempts', 3);
    $user = userComSenhaDeTransacao();

    $codigo = solicitarCodigo($user);
    $errado = $codigo === '000000' ? '000001' : '000000';

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/sensitive-actions/confirm', ['code' => $errado])
            ->assertUnprocessable();
    }

    $registro = VerificationCode::query()->sole();

    expect($registro->attempts)->toBe(3)
        ->and($registro->consumed_at)->not->toBeNull();

    // Nem o código CORRETO funciona mais — é preciso solicitar um novo.
    $this->postJson('/sensitive-actions/confirm', ['code' => $codigo])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', __('auth.verification_code.expired'));
});

it('rejeita código expirado', function () {
    Mail::fake();
    config()->set('auth.verification.code_ttl_minutes', 10);
    $user = userComSenhaDeTransacao();

    $codigo = solicitarCodigo($user);

    $this->travel(11)->minutes();

    $this->postJson('/sensitive-actions/confirm', ['code' => $codigo])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', __('auth.verification_code.expired'));
});

it('impõe cooldown de reenvio e invalida o código anterior ao reenviar', function () {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 60);
    // Relógio congelado: o cooldown restante é calculado por
    // created_at->diffInSeconds(now()) — sem freeze, >1s decorrido entre criar
    // o código e tentar reenviar tornava a asserção de "60s restantes" flake.
    $this->freezeTime();
    $user = userComSenhaDeTransacao();

    $primeiroCodigo = solicitarCodigo($user);

    // Reenvio imediato → bloqueado pelo cooldown.
    $this->postJson('/sensitive-actions/code', [
        'transaction_password' => 'Trans4cao!Segura',
    ])->assertUnprocessable()
        ->assertJsonPath(
            'errors.transaction_password.0',
            __('auth.verification_code.resend_cooldown', ['seconds' => 60]),
        );

    // Após o cooldown, o reenvio funciona...
    $this->travel(61)->seconds();

    $this->postJson('/sensitive-actions/code', [
        'transaction_password' => 'Trans4cao!Segura',
    ])->assertOk();

    // ...e o código anterior foi invalidado.
    $codigos = VerificationCode::query()->orderBy('id')->get();

    expect($codigos)->toHaveCount(2)
        ->and($codigos[0]->consumed_at)->not->toBeNull()
        ->and($codigos[1]->consumed_at)->toBeNull();

    $this->postJson('/sensitive-actions/confirm', ['code' => $primeiroCodigo])
        ->assertUnprocessable();
});

it('rejeita token de ação sensível ausente, inválido ou expirado', function () {
    Mail::fake();
    $user = userComSenhaDeTransacao();

    // Ausente.
    $this->postJson('/_test/acao-sensivel', [])
        ->assertForbidden()
        ->assertJsonPath('message', __('auth.sensitive_action.invalid_token'));

    // Inválido.
    $this->postJson('/_test/acao-sensivel', [], ['X-Sensitive-Action-Token' => 'token-falso'])
        ->assertForbidden();

    // Expirado: emite um token real e avança o relógio além da validade.
    $codigo = solicitarCodigo($user);

    $token = $this->postJson('/sensitive-actions/confirm', ['code' => $codigo])->json('token');

    $this->travel(11)->minutes();

    $this->postJson('/_test/acao-sensivel', [], ['X-Sensitive-Action-Token' => $token])
        ->assertForbidden();
});

it('consome o token no primeiro uso (uso único)', function () {
    Mail::fake();
    $user = userComSenhaDeTransacao();

    $codigo = solicitarCodigo($user);

    $token = $this->postJson('/sensitive-actions/confirm', ['code' => $codigo])->json('token');

    $this->postJson('/_test/acao-sensivel', [], ['X-Sensitive-Action-Token' => $token])
        ->assertOk();

    // Segundo uso do MESMO token → negado.
    $this->postJson('/_test/acao-sensivel', [], ['X-Sensitive-Action-Token' => $token])
        ->assertForbidden();

    expect(SensitiveActionToken::query()->sole()->consumed_at)->not->toBeNull();
});

it('não permite usar o token de outro usuário', function () {
    Mail::fake();
    $user = userComSenhaDeTransacao();
    $outroUser = User::factory()->withTransactionPassword()->create();

    $codigo = solicitarCodigo($user);

    $token = $this->postJson('/sensitive-actions/confirm', ['code' => $codigo])->json('token');

    $this->actingAs($outroUser)
        ->postJson('/_test/acao-sensivel', [], ['X-Sensitive-Action-Token' => $token])
        ->assertForbidden();
});
