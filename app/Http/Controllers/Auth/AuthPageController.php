<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Auth\Actions\CompleteTwoFactorLogin;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Contracts\Responses\EmailVerificationResponse;
use Twstec\Kit\Auth\Contracts\Responses\TwoFactorSetupResponse;
use Twstec\Kit\Auth\Enums\EmailVerificationOutcome;
use Twstec\Kit\Auth\Http\Controllers\TwoFactorChallengeController;
use Twstec\Kit\Auth\Http\Controllers\TwoFactorSetupController;
use Twstec\Kit\Auth\Services\TwoFactorLogin;
use Twstec\Kit\Auth\Support\EmailVerification;
use Twstec\Kit\Auth\Support\EmailVerificationResult;
use Twstec\Kit\Auth\Support\Registration;
use Twstec\Kit\Auth\Support\TwoFactorChallengeResult;
use Twstec\Kit\Auth\Support\TwoFactorRequirement;

/**
 * As TELAS de autenticação do starter Livewire (views Blade em
 * resources/views/auth) — só GET, só HTTP.
 *
 * O que cada formulário faz ao ser enviado é do pacote twstec/kit-auth: os
 * controllers dele (Twstec\Kit\Auth\Http\Controllers) recebem o POST, chamam
 * a Action e respondem pelo contrato de resposta. Aqui fica só o que é do
 * front: qual view mostrar e com que dados. Um front novo (React, por exemplo)
 * troca esta classe pelas páginas dele e reaproveita as rotas de POST.
 */
final class AuthPageController
{
    public function login(): View
    {
        return view('auth.login');
    }

    /**
     * Tela de cadastro — 404 com o cadastro público fechado
     * (AUTH_REGISTRATION_ENABLED=false).
     */
    public function register(): View
    {
        Registration::ensureOpen();

        return view('auth.register');
    }

    public function forgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function resetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Tela do código do segundo passo. Sem estado intermediário (ou com ele
     * vencido), responde como o próprio fluxo responderia — volta ao login.
     */
    public function twoFactorChallenge(Request $request, CompleteTwoFactorLogin $challenge, TwoFactorLogin $twoFactor): View|Response
    {
        $user = $challenge->pendingUser($request);

        if ($user instanceof TwoFactorChallengeResult) {
            return TwoFactorChallengeController::respond($request, $user);
        }

        return view('auth.two-factor-challenge', [
            'email' => $user->email,
            'codeTtlMinutes' => $twoFactor->codeTtlMinutes(),
        ]);
    }

    /**
     * Aviso de e-mail pendente. Com a exigência desligada, ou com o e-mail já
     * confirmado, devolve ao painel.
     */
    public function verifyEmailNotice(Request $request): View|Response
    {
        /** @var AuthUser $user */
        $user = $request->user();

        if (! EmailVerification::pendingFor($user)) {
            return app(EmailVerificationResponse::class)
                ->toResponse($request, new EmailVerificationResult(EmailVerificationOutcome::NotPending));
        }

        return view('auth.verify-email', ['email' => $user->email]);
    }

    public function transactionPassword(): View
    {
        return view('auth.transaction-password');
    }

    /**
     * Configuração do segundo fator OBRIGATÓRIO (AUTH_TWO_FACTOR_REQUIRED).
     * Sem nada a configurar (regra não vale para a conta, ou ela já ligou),
     * segue para o destino como o próprio fluxo seguiria. A tela mostra o
     * passo em que a pessoa está: definir a senha de transação, mandar o
     * código, digitar o código.
     */
    public function twoFactorSetup(Request $request, TwoFactorRequirement $requirement, TwoFactorLogin $twoFactor): View|Response
    {
        /** @var AuthUser $user */
        $user = $request->user();

        if (! $requirement->pendingFor($user)) {
            return app(TwoFactorSetupResponse::class)->toResponse($request);
        }

        return view('auth.two-factor-setup', [
            'email' => $user->email,
            'hasTransactionPassword' => $user->hasTransactionPassword(),
            'codeSent' => $user->hasTransactionPassword() && TwoFactorSetupController::codeSent($request),
            'codeTtlMinutes' => $twoFactor->codeTtlMinutes(),
            'graceEndsAt' => $requirement->graceEndsAt($user),
        ]);
    }
}
