{{-- Aviso de e-mail não confirmado (EmailVerificationController::notice).

     É a única tela que a conta nova vê até clicar no link: diz para onde o
     e-mail foi, oferece o reenvio (com cooldown no servidor) e a saída. Mesmo
     layout das telas de autenticação — cabeçalho, idioma e tema do site. A
     confirmação de envio sai no toast do site (session('status')); o motivo de
     um reenvio recusado ou de um link inválido fica FIXO na tela, ao lado do
     botão que resolve. --}}
@extends('layouts.auth')

@section('title', __('auth.email_verification.title'))

@section('content')
    <h1 class="mb-2 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.email_verification.title') }}</h1>

    <p class="mb-2 text-sm text-gray-600 dark:text-gray-300" data-verification-intro>
        {{ __('auth.email_verification.intro', ['email' => $email]) }}
    </p>
    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.email_verification.hint') }}</p>

    @if (session('verification_error'))
        <x-alert type="warning" class="mb-4" data-verification-error>{{ session('verification_error') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-button type="submit" class="w-full">{{ __('auth.email_verification.resend') }}</x-button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <x-button type="submit" variant="ghost" class="w-full">{{ __('auth.ui.logout') }}</x-button>
    </form>
@endsection
