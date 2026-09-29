@extends('layouts.auth')

@section('title', __('auth.ui.register_title'))

@section('content')
    <h1 class="mb-6 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.ui.register_title') }}</h1>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <x-input :label="__('auth.ui.name')" name="name" :value="old('name')" :error="field_error('name')" required autofocus autocomplete="name" />
        <x-input :label="__('auth.ui.email')" name="email" type="email" :value="old('email')" :error="field_error('email')" required autocomplete="username" />
        <x-input :label="__('auth.ui.password')" name="password" type="password" :hint="ucfirst(\Twstec\Kit\Auth\PasswordPolicy::hint())" :error="field_error('password')" required autocomplete="new-password" />
        <x-input :label="__('auth.ui.password_confirmation')" name="password_confirmation" type="password" :error="field_error('password_confirmation')" required autocomplete="new-password" />

        <x-button type="submit" class="w-full">{{ __('auth.ui.register_submit') }}</x-button>
    </form>

    <p class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ route('login') }}" class="text-brand hover:underline">{{ __('auth.ui.login_link') }}</a>
    </p>
@endsection
