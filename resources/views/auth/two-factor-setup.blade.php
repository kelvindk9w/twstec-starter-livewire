{{-- Configuração do segundo fator OBRIGATÓRIO (AUTH_TWO_FACTOR_REQUIRED).

     Quem chega aqui está logado, mas a instalação exige a verificação em duas
     etapas e a conta ainda não a ligou: o resto do painel fica fechado até
     terminar (EnsureTwoFactorIsConfigured, do pacote). É a MESMA regra de
     ligar pelo perfil — senha de transação → código por e-mail → liga —, em
     formulários comuns (não componente Livewire: ação Livewire é recusada a
     quem está pendente). A tela mostra o passo em que a pessoa está:
       1. sem senha de transação: defini-la (o envio de sempre);
       2. com ela: confirmar com a senha → código por e-mail;
       3. código enviado: digitar o código (ou pedir outro).
     Na carência, diz até quando dá para adiar e oferece voltar ao painel. --}}
@extends('layouts.auth')

@section('title', __('auth.two_factor_setup.title'))

@section('content')
    <h1 class="mb-2 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.two_factor_setup.title') }}</h1>

    <p class="mb-2 text-sm text-gray-600 dark:text-gray-300" data-two-factor-setup-intro>
        {{ __('auth.two_factor_setup.intro', ['email' => $email]) }}
    </p>

    @if ($graceEndsAt !== null)
        <x-alert type="info" class="mb-4" data-two-factor-setup-grace>
            {{ __('auth.two_factor_setup.grace', ['date' => $graceEndsAt->translatedFormat(__('auth.two_factor_setup.date_format'))]) }}
        </x-alert>
    @endif

    @error('two_factor')
        <x-alert type="warning" class="mb-4" data-two-factor-setup-error>{{ $message }}</x-alert>
    @enderror

    @if (! $hasTransactionPassword)
        {{-- Passo 1: a senha de transação (pré-requisito de toda ação sensível). --}}
        <h2 class="mb-1 text-sm font-semibold">{{ __('auth.two_factor_setup.transaction_password_heading') }}</h2>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.two_factor_setup.transaction_password_hint') }}</p>

        <form method="POST" action="{{ route('transaction-password.update') }}" class="space-y-4" data-two-factor-setup-transaction-password>
            @csrf
            @method('PUT')

            <x-input :label="__('auth.ui.new_transaction_password')" name="transaction_password" type="password" :error="field_error('transaction_password')" required autocomplete="off" />
            <x-input :label="__('auth.ui.password_confirmation')" name="transaction_password_confirmation" type="password" :error="field_error('transaction_password_confirmation')" required autocomplete="off" />

            <x-button type="submit" class="w-full">{{ __('auth.ui.save') }}</x-button>
        </form>
    @else
        @if ($codeSent)
            {{-- Passo 3: o código. --}}
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-300" data-two-factor-setup-code-intro>
                {{ __('auth.two_factor_setup.code_intro', ['email' => $email, 'minutes' => $codeTtlMinutes]) }}
            </p>

            <form method="POST" action="{{ route('two-factor.setup.store') }}" class="space-y-4" data-two-factor-setup-code>
                @csrf

                <x-input :label="__('auth.two_factor.code_label')" name="code"
                         inputmode="numeric" pattern="[0-9]*" maxlength="6"
                         autocomplete="one-time-code"
                         :error="field_error('code')"
                         required autofocus />

                <x-button type="submit" class="w-full">{{ __('auth.two_factor_setup.submit') }}</x-button>
            </form>

            <p class="mt-6 mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.two_factor_setup.resend_hint') }}</p>
        @else
            {{-- Passo 2: confirmar com a senha de transação. --}}
            <h2 class="mb-1 text-sm font-semibold">{{ __('auth.two_factor_setup.confirm_heading') }}</h2>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.two_factor_setup.confirm_hint') }}</p>
        @endif

        <form method="POST" action="{{ route('two-factor.setup.code') }}" class="space-y-4" data-two-factor-setup-send>
            @csrf

            <x-input :label="__('auth.ui.transaction_password_title')" name="transaction_password" type="password" :error="field_error('transaction_password')" required autocomplete="off" />

            <x-button type="submit" :variant="$codeSent ? 'secondary' : 'primary'" class="w-full">
                {{ $codeSent ? __('auth.two_factor.resend') : __('auth.two_factor_setup.send_code') }}
            </x-button>
        </form>
    @endif

    <p class="mt-4 text-center text-caption text-text-muted">{{ __('panel.profile.two_factor_recovery') }}</p>

    @if ($graceEndsAt !== null)
        <x-button :href="route('dashboard')" variant="ghost" class="mt-4 w-full" data-two-factor-setup-later>{{ __('auth.two_factor_setup.later') }}</x-button>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <x-button type="submit" variant="ghost" class="w-full">{{ __('auth.ui.logout') }}</x-button>
    </form>
@endsection
