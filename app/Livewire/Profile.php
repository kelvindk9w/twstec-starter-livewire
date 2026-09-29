<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmsSensitiveAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Twstec\Kit\Auth\PasswordPolicy;
use Twstec\Kit\Auth\Services\TransactionPasswordService;
use Twstec\Kit\Auth\Services\TwoFactorLogin;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Rules\SafeFile;

/**
 * Perfil do usuário: dados, senha de login, senha de transação, avatar e a
 * verificação em duas etapas do login — tudo na MESMA tela (simplicidade
 * máxima, sem labirinto).
 *
 * Reuso (sem duplicar lógica):
 * - Senha de transação → TransactionPasswordService (mesma regra do fluxo de autenticação).
 * - Avatar → AvatarService → SecureUploadService (função global de upload:
 *   valida o CONTEÚDO do arquivo e faz re-encode GD antes de persistir).
 * - Verificação em duas etapas → TwoFactorLogin. Ligar e desligar são ações
 *   sensíveis: passam pelo modal de confirmação (ConfirmsSensitiveAction) e o
 *   token emitido é consumido pelo próprio TwoFactorLogin.
 */
final class Profile extends Component
{
    use ConfirmsSensitiveAction;
    use WithFileUploads;

    public string $name = '';

    public string $locale = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $currentTransactionPassword = '';

    public string $transactionPassword = '';

    public string $transactionPasswordConfirmation = '';

    public ?TemporaryUploadedFile $avatar = null;

    public function mount(): void
    {
        $this->name = (string) $this->user()->name;
        $this->locale = $this->user()->preferredLocale();
    }

    /**
     * Atualiza os dados básicos (nome, idioma). O e-mail é a chave de acesso
     * da conta — troca de e-mail exige fluxo próprio de verificação (futuro).
     */
    public function updateProfile(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'string', Rule::in(platform()->availableLocales)],
        ], [], [
            'locale' => __('panel.profile.locale_label'),
        ]);

        $this->user()->forceFill(['name' => $validated['name'], 'locale' => $validated['locale']])->save();

        // Reflete imediatamente na interface da resposta (o middleware
        // SetLocale garante nas próximas requisições).
        app()->setLocale($validated['locale']);

        session()->flash('profile_status', __('panel.common.saved'));
    }

    /**
     * Troca da senha de LOGIN: exige a senha atual e aplica as mesmas
     * regras de força do cadastro (config/auth.php password_rules).
     */
    public function updatePassword(): void
    {
        $validated = $this->validate([
            'currentPassword' => ['required', 'string'],
            'password' => [
                'required',
                PasswordPolicy::rule(),
            ],
            'passwordConfirmation' => ['required', 'same:password'],
        ], [], [
            'currentPassword' => __('panel.profile.current_password'),
            'password' => __('auth.ui.new_password'),
            'passwordConfirmation' => __('auth.ui.password_confirmation'),
        ]);

        $user = $this->user();

        if (! Hash::check($validated['currentPassword'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => __('panel.profile.current_password_invalid'),
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        $this->reset('currentPassword', 'password', 'passwordConfirmation');
        session()->flash('password_status', __('panel.profile.password_updated'));
    }

    /**
     * Define/altera a senha de TRANSAÇÃO — delega ao TransactionPasswordService
     * (regra única: hash separado, ≠ senha de login, atual exigida).
     */
    public function updateTransactionPassword(TransactionPasswordService $service): void
    {
        $validated = $this->validate([
            'currentTransactionPassword' => [
                $this->user()->hasTransactionPassword() ? 'required' : 'nullable',
                'string',
            ],
            'transactionPassword' => [
                'required',
                Password::min((int) config('auth.transaction_password.min_length', 8))
                    ->letters()
                    ->numbers(),
            ],
            'transactionPasswordConfirmation' => ['required', 'same:transactionPassword'],
        ], [], [
            'currentTransactionPassword' => __('auth.ui.current_transaction_password'),
            'transactionPassword' => __('auth.ui.new_transaction_password'),
        ]);

        try {
            $service->update(
                $this->user(),
                $validated['transactionPassword'],
                $validated['currentTransactionPassword'] ?? null,
            );
        } catch (ValidationException $exception) {
            // Mapeia as chaves do service (snake_case) para os campos do form.
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [lcfirst(\Str::camel($key)) => $messages])
                    ->all(),
            );
        }

        $this->reset('currentTransactionPassword', 'transactionPassword', 'transactionPasswordConfirmation');
        session()->flash('transaction_password_status', __('auth.transaction_password.saved'));
    }

    /**
     * Avatar: mesma função global de upload seguro (validação por
     * conteúdo + re-encode GD), pelo AvatarService do pacote de uploads — a
     * foto é da PESSOA (upload pessoal, sem conta) e aparece em todas as
     * contas dela.
     *
     * Só com o pacote de uploads (twstec/kit-uploads, opcional) instalado —
     * sem ele a ação não existe (404), e o service é pedido ao container só
     * depois dessa pergunta.
     */
    public function updateAvatar(): void
    {
        abort_unless(Kit::has('uploads'), 404);

        $avatars = app(AvatarService::class);

        $maxKb = (int) setting('uploads.types.image.max_kb');

        // SafeFile vem PRIMEIRO, com bail: a validação por conteúdo do kit é
        // quem explica a recusa. Desde o Livewire 4.4.2 o upload temporário
        // detecta o MIME pelo conteúdo, então `image`/`mimes` também recusam o
        // texto renomeado para .png — mas com a mensagem genérica do framework
        // ("deve ser uma imagem"), que não diz o porquê. Elas seguem na lista
        // como segunda camada; o SecureUploadService revalida ao persistir.
        $this->validate([
            'avatar' => ['bail', 'required', new SafeFile(['image']), 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ], [], [
            'avatar' => __('panel.profile.avatar_heading'),
        ]);

        try {
            $avatars->replace($this->user(), $this->avatar);
        } catch (UploadRejectedException $exception) {
            throw ValidationException::withMessages(['avatar' => $exception->getMessage()]);
        }

        $this->reset('avatar');
        session()->flash('avatar_status', __('panel.profile.avatar_updated'));
    }

    /**
     * Abre a confirmação sensível para ligar/desligar a verificação em duas
     * etapas. Conta que não pode (conta protegida, sem senha de transação,
     * opção desligada na instalação) recebe o motivo em vez do modal.
     */
    public function requestTwoFactorToggle(TwoFactorLogin $twoFactor): void
    {
        $reason = $twoFactor->blockedReason($this->user());

        if ($reason !== null) {
            throw ValidationException::withMessages(['twoFactor' => $reason]);
        }

        $this->openSensitiveModal($twoFactor->enabledFor($this->user()) ? 'two_factor_disable' : 'two_factor_enable');
    }

    protected function performSensitiveAction(string $action, string $token): void
    {
        $twoFactor = app(TwoFactorLogin::class);

        try {
            match ($action) {
                'two_factor_enable' => $twoFactor->enable($this->user(), $token),
                'two_factor_disable' => $twoFactor->disable($this->user(), $token),
                default => null,
            };
        } catch (ValidationException $exception) {
            // Recusa da própria operação: aparece no passo em que a pessoa está.
            throw ValidationException::withMessages(['sensitiveCode' => collect($exception->errors())->flatten()->all()]);
        }

        session()->flash('two_factor_status', __($action === 'two_factor_enable' ? 'auth.two_factor.enabled' : 'auth.two_factor.disabled'));
    }

    public function render(): View
    {
        $user = $this->user()->fresh();
        $twoFactor = app(TwoFactorLogin::class);

        return view('livewire.profile', [
            'user' => $user,
            'twoFactorAvailable' => TwoFactorLogin::available(),
            'twoFactorEnabled' => $twoFactor->enabledFor($user),
            'twoFactorBlockedReason' => $twoFactor->blockedReason($user),
            'sensitiveDescription' => match ($this->pendingAction) {
                'two_factor_enable' => __('panel.profile.two_factor_confirm_enable'),
                'two_factor_disable' => __('panel.profile.two_factor_confirm_disable'),
                default => null,
            },
        ])->title(__('panel.profile.title'));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
