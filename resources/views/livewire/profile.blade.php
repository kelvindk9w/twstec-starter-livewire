<div class="space-y-6">
    <div>
        <h1 class="font-display text-h1">{{ __('panel.profile.title') }}</h1>
        <p class="mt-1.5 max-w-2xl text-sm text-text-muted">{{ __('panel.profile.subtitle') }}</p>
    </div>

    {{-- Dados básicos ------------------------------------------------------ --}}
    <x-card :title="__('panel.profile.data_heading')">
        @if (session('profile_status'))
            <x-alert type="success" class="mb-4">{{ session('profile_status') }}</x-alert>
        @endif

        <form wire:submit="updateProfile" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input
                    :label="__('panel.common.name')"
                    name="name"
                    wire:model="name"
                    required
                    :error="$errors->first('name')"
                />
                <x-select
                    :label="__('panel.profile.locale_label')"
                    name="locale"
                    wire:model="locale"
                    :hint="__('panel.profile.locale_hint')"
                    :error="$errors->first('locale')"
                >
                    @foreach (platform()->availableLocales as $availableLocale)
                        <option value="{{ $availableLocale }}">{{ __("ui.locale.names.{$availableLocale}") }}</option>
                    @endforeach
                </x-select>
            </div>

            {{-- O e-mail é a chave de acesso: campo desabilitado (com fundo
                 próprio, via --color-surface-disabled) em vez de um parágrafo
                 solto — a tela não muda de vocabulário no meio. --}}
            <x-input
                :label="__('auth.ui.email')"
                name="email"
                type="email"
                :value="$user->email"
                :disabled="true"
                :hint="__('panel.profile.email_readonly')"
            />

            <div class="flex justify-end">
                {{-- Quatro botões "Salvar" idênticos numa tela que salva quatro
                     coisas diferentes não dizem o que fazem. Cada um nomeia o
                     próprio escopo. --}}
                <x-button type="submit">{{ __('panel.profile.save_data') }}</x-button>
            </div>
        </form>
    </x-card>

    {{-- Aparência (tema claro/escuro/sistema) -------------------------------- --}}
    <x-card :title="__('panel.profile.theme_heading')" :description="__('panel.profile.theme_hint')">
        <div class="inline-flex gap-1 rounded-lg border border-border p-1" role="group" aria-label="{{ __('panel.profile.theme_heading') }}">
            @foreach (['system' => 'computer-desktop', 'light' => 'sun', 'dark' => 'moon'] as $themeValue => $themeIcon)
                <button type="button" data-theme-set="{{ $themeValue }}" aria-pressed="false"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-md px-3 py-2 text-sm text-gray-600 transition-[background-color,color] duration-150 ease-(--ease-out) sm:min-h-0 sm:py-1.5 dark:text-gray-300">
                    <x-ui-icon :name="$themeIcon" class="h-4 w-4" />
                    {{ __("ui.theme.{$themeValue}") }}
                </button>
            @endforeach
        </div>
    </x-card>

    {{-- Avatar (função global de upload seguro) — só com o pacote de uploads
         (twstec/kit-uploads) instalado; sem ele, o avatar são as iniciais. --}}
    @kit('uploads')
    <x-card :title="__('panel.profile.avatar_heading')" :description="__('panel.profile.avatar_hint')">
        @if (session('avatar_status'))
            <x-alert type="success" class="mb-4">{{ session('avatar_status') }}</x-alert>
        @endif

        <form wire:submit="updateAvatar" class="flex flex-wrap items-center gap-4">
            {{-- MESMO avatar do cabeçalho (<x-avatar>): a prévia aqui e o
                 gatilho do menu da conta não podem divergir. --}}
            <x-avatar :user="$user" size="lg" />

            <div class="min-w-0 flex-1">
                {{-- Seletor de arquivo do KIT: o nativo desenha o próprio botão
                     com o texto do sistema operacional ("Choose File / No file
                     chosen") — em inglês, numa tela pt-BR. --}}
                <x-file-input
                    name="avatar"
                    wire:model="avatar"
                    accept="image/*"
                    :button-label="__('panel.profile.choose_file')"
                    :error="$errors->first('avatar')"
                />
                <div wire:loading wire:target="avatar" class="mt-2 flex items-center gap-2 text-caption text-text-muted">
                    <x-spinner size="sm" />
                    {{ __('panel.profile.avatar_uploading') }}
                </div>
            </div>

            <x-button type="submit" :disabled="! $avatar">{{ __('panel.profile.save_avatar') }}</x-button>
        </form>
    </x-card>
    @endkit

    {{-- Senha de login -------------------------------------------------------- --}}
    <x-card :title="__('panel.profile.password_heading')" :description="__('panel.profile.password_hint', ['rules' => \Twstec\Kit\Auth\PasswordPolicy::hint()])">
        @if (session('password_status'))
            <x-alert type="success" class="mb-4">{{ session('password_status') }}</x-alert>
        @endif

        <form wire:submit="updatePassword" class="space-y-4">
            <x-input :label="__('panel.profile.current_password')" name="currentPassword" type="password"
                     wire:model="currentPassword" autocomplete="current-password"
                     :error="$errors->first('currentPassword')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input :label="__('auth.ui.new_password')" name="password" type="password"
                         wire:model="password" autocomplete="new-password"
                         :error="$errors->first('password')" />
                <x-input :label="__('auth.ui.password_confirmation')" name="passwordConfirmation" type="password"
                         wire:model="passwordConfirmation" autocomplete="new-password"
                         :error="$errors->first('passwordConfirmation')" />
            </div>
            <div class="flex justify-end">
                <x-button type="submit">{{ __('panel.profile.save_password') }}</x-button>
            </div>
        </form>
    </x-card>

    {{-- Senha de transação (hash separado) --------------------------- --}}
    <x-card :title="__('panel.profile.transaction_password_heading')" :description="__('panel.profile.transaction_password_hint')">
        <x-slot:actions>
            <x-badge :color="$user->hasTransactionPassword() ? 'green' : 'yellow'">
                {{ $user->hasTransactionPassword() ? __('panel.profile.transaction_password_set') : __('panel.profile.transaction_password_not_set') }}
            </x-badge>
        </x-slot:actions>

        @if (session('transaction_password_status'))
            <x-alert type="success" class="mb-4">{{ session('transaction_password_status') }}</x-alert>
        @endif

        <form wire:submit="updateTransactionPassword" class="space-y-4">
            @if ($user->hasTransactionPassword())
                <x-input :label="__('auth.ui.current_transaction_password')" name="currentTransactionPassword" type="password"
                         wire:model="currentTransactionPassword" autocomplete="off"
                         :error="$errors->first('currentTransactionPassword')" />
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input :label="__('auth.ui.new_transaction_password')" name="transactionPassword" type="password"
                         wire:model="transactionPassword" autocomplete="new-password"
                         :error="$errors->first('transactionPassword')" />
                <x-input :label="__('auth.ui.password_confirmation')" name="transactionPasswordConfirmation" type="password"
                         wire:model="transactionPasswordConfirmation" autocomplete="new-password"
                         :error="$errors->first('transactionPasswordConfirmation')" />
            </div>
            <div class="flex justify-end">
                <x-button type="submit">{{ __('panel.profile.save_transaction_password') }}</x-button>
            </div>
        </form>
    </x-card>

    {{-- Verificação em duas etapas no login (TwoFactorLogin) ---------------------
         Depois da senha de transação de propósito: ligar e desligar são ações
         sensíveis e dependem dela. Quem não pode mudar (conta protegida,
         sem senha de transação) vê o motivo no lugar do botão. --}}
    @if ($twoFactorAvailable)
        <x-card :title="__('panel.profile.two_factor_heading')" :description="__('panel.profile.two_factor_hint', ['email' => $user->email])" data-two-factor-card>
            <x-slot:actions>
                <x-badge :color="$twoFactorEnabled ? 'green' : 'gray'" data-two-factor-status>
                    {{ $twoFactorEnabled ? __('panel.profile.two_factor_on') : __('panel.profile.two_factor_off') }}
                </x-badge>
            </x-slot:actions>

            @if (session('two_factor_status'))
                <x-alert type="success" class="mb-4">{{ session('two_factor_status') }}</x-alert>
            @endif

            @if ($twoFactorBlockedReason !== null)
                <x-alert type="info" class="mb-4" data-two-factor-blocked>{{ $twoFactorBlockedReason }}</x-alert>
            @endif

            @error('twoFactor')
                <x-alert type="warning" class="mb-4">{{ $message }}</x-alert>
            @enderror

            <p class="text-sm text-text-muted">{{ __('panel.profile.two_factor_recovery') }}</p>

            <div class="mt-4 flex justify-end">
                <x-button type="button" :variant="$twoFactorEnabled ? 'secondary' : 'primary'"
                          wire:click="requestTwoFactorToggle"
                          :disabled="$twoFactorBlockedReason !== null">
                    {{ $twoFactorEnabled ? __('panel.profile.two_factor_disable') : __('panel.profile.two_factor_enable') }}
                </x-button>
            </div>
        </x-card>
    @endif

    {{-- Confirmação sensível (senha de transação → código por e-mail). --}}
    @include('livewire.partials.sensitive-action-modal')
</div>
