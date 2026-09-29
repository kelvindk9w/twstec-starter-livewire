<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Auth\Services\SensitiveActionService;

/**
 * Confirmação de AÇÃO SENSÍVEL nas telas Livewire do painel:
 * senha de transação → código por e-mail → token de uso único → executa.
 *
 * O componente que usa o trait:
 *   - abre a confirmação com `openSensitiveModal('<acao>')`;
 *   - implementa `performSensitiveAction('<acao>', $token)`, que recebe o
 *     token EM CLARO recém-emitido e é o único lugar onde a operação acontece.
 *     Quem consome o token é a operação (ou o service dela — ex.:
 *     TwoFactorLogin::enable) exatamente como o middleware `sensitive.token`
 *     faria na API;
 *   - inclui o modal `livewire.partials.sensitive-action-modal`.
 *
 * Toda a regra (senha, código, tentativas, cooldown, token) é do
 * SensitiveActionService: aqui só há o estado da tela.
 */
trait ConfirmsSensitiveAction
{
    /** Ação aguardando confirmação (null = modal fechado). */
    public ?string $pendingAction = null;

    public string $sensitivePassword = '';

    public string $sensitiveCode = '';

    public bool $codeSent = false;

    /**
     * Executa a ação confirmada. `$token` é o token de ação sensível em
     * claro, ainda NÃO consumido.
     */
    abstract protected function performSensitiveAction(string $action, string $token): void;

    public function sendSensitiveCode(SensitiveActionService $sensitive): void
    {
        $this->validate(['sensitivePassword' => ['required', 'string']], [], ['sensitivePassword' => __('auth.ui.transaction_password_title')]);

        try {
            $sensitive->sendCode($this->sensitiveUser(), $this->sensitivePassword);
        } catch (ValidationException $exception) {
            throw $this->mapSensitiveErrors($exception);
        }

        $this->codeSent = true;
        $this->reset('sensitiveCode');
    }

    public function confirmSensitiveAction(SensitiveActionService $sensitive): void
    {
        $this->validate(['sensitiveCode' => ['required', 'string', 'size:6']], [], ['sensitiveCode' => __('panel.sensitive.code')]);

        if ($this->pendingAction === null) {
            return;
        }

        try {
            // Código válido → token de ação sensível (uso único, curta duração).
            $issued = $sensitive->confirmCode($this->sensitiveUser(), $this->sensitiveCode);

            $this->performSensitiveAction($this->pendingAction, $issued['token']);
        } catch (ValidationException $exception) {
            throw $this->mapSensitiveErrors($exception);
        }

        $this->closeSensitiveModal();
    }

    public function cancelSensitiveAction(): void
    {
        $this->closeSensitiveModal();
    }

    /**
     * Segundos restantes de cooldown de reenvio do código (a tela desabilita
     * o botão de reenvio durante a janela — mesma regra do service).
     */
    public function resendCooldown(): int
    {
        // Sem DI de método: a view chama $this->resendCooldown() diretamente.
        return app(SensitiveActionService::class)->resendCooldownRemaining($this->sensitiveUser());
    }

    protected function openSensitiveModal(string $action): void
    {
        $this->resetValidation();
        $this->pendingAction = $action;
        $this->codeSent = false;
        $this->reset('sensitivePassword', 'sensitiveCode');
    }

    protected function closeSensitiveModal(): void
    {
        $this->pendingAction = null;
        $this->codeSent = false;
        $this->reset('sensitivePassword', 'sensitiveCode');
    }

    /**
     * O SensitiveActionService lança erros com chaves snake_case
     * ('transaction_password', 'code') — mapeia para os campos do modal. As
     * demais chaves (erros da própria operação) seguem como vieram.
     */
    protected function mapSensitiveErrors(ValidationException $exception): ValidationException
    {
        $map = ['transaction_password' => 'sensitivePassword', 'code' => 'sensitiveCode'];

        $errors = [];

        foreach ($exception->errors() as $key => $messages) {
            $errors[$map[$key] ?? $key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }

    private function sensitiveUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
