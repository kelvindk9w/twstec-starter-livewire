{{-- Segundo passo do login (TwoFactorChallengeController::create).

     Quem está aqui acertou a senha, mas AINDA NÃO está autenticado: a sessão
     guarda só o estado intermediário. A tela diz para onde o código foi,
     recebe os 6 dígitos e oferece as duas saídas — pedir outro código (com
     intervalo mínimo no servidor) e desistir, que volta ao login. Mesmo layout
     das demais telas de autenticação: cabeçalho, idioma e tema do site. --}}
@extends('layouts.auth')

@section('title', __('auth.two_factor.title'))

@section('content')
    <h1 class="mb-2 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.two_factor.title') }}</h1>

    <p class="mb-6 text-sm text-gray-600 dark:text-gray-300" data-two-factor-intro>
        {{ __('auth.two_factor.intro', ['email' => $email, 'minutes' => $codeTtlMinutes]) }}
    </p>

    <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-4">
        @csrf

        <x-input :label="__('auth.two_factor.code_label')" name="code"
                 inputmode="numeric" pattern="[0-9]*" maxlength="6"
                 autocomplete="one-time-code"
                 :error="field_error('code')"
                 required autofocus />

        <x-button type="submit" class="w-full">{{ __('auth.two_factor.submit') }}</x-button>
    </form>

    <form method="POST" action="{{ route('two-factor.resend') }}" class="mt-3">
        @csrf
        <x-button type="submit" variant="secondary" class="w-full" data-two-factor-resend>
            {{ __('auth.two_factor.resend') }}
        </x-button>
    </form>

    <p class="mt-2 text-center text-caption text-text-muted">{{ __('auth.two_factor.resend_hint') }}</p>

    <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-4">
        @csrf
        <x-button type="submit" variant="ghost" class="w-full">{{ __('auth.two_factor.cancel') }}</x-button>
    </form>
@endsection
