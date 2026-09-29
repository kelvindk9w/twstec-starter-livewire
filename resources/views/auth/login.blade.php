@extends('layouts.auth')

@section('title', __('auth.ui.login_title'))

@section('content')
    <h1 class="mb-6 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.ui.login_title') }}</h1>

    {{-- Credenciais sugeridas (ponto de extensão LoginPrefillProvider): sem
         extensão registrada, os campos nascem vazios e não há aviso. Quem
         sugere decide também QUANDO — a demonstração do kit, por exemplo,
         nunca sugere em APP_ENV=production sem opt-out declarado. --}}
    @php($prefill = \Twstec\Kit\Auth\Support\LoginPrefill::for('web'))

    @if ($prefill?->notice !== null)
        <x-alert type="info" class="mb-4">
            {{ $prefill->notice }}<br>
            <strong>{{ $prefill->label }}:</strong>
            {{ $prefill->email }} / {{ $prefill->password }}
        </x-alert>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <x-input :label="__('auth.ui.email')" name="email" type="email"
                 :value="old('email', $prefill?->email ?? '')"
                 :error="field_error('email')"
                 required autofocus autocomplete="username" />

        <x-input :label="__('auth.ui.password')" name="password" type="password"
                 :value="$prefill?->password ?? ''"
                 :error="field_error('password')"
                 required autocomplete="current-password" />

        <x-checkbox :label="__('auth.ui.remember_me')" name="remember" />

        <x-button type="submit" class="w-full">{{ __('auth.ui.login_submit') }}</x-button>
    </form>

    <p class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ route('password.request') }}" class="text-brand hover:underline">{{ __('auth.ui.forgot_password') }}</a>
        ·
        <a href="{{ route('register') }}" class="text-brand hover:underline">{{ __('auth.ui.register_link') }}</a>
    </p>
@endsection
