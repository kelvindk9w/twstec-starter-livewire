@extends('layouts.auth')

@section('title', __('auth.ui.forgot_title'))

@section('content')
    <h1 class="mb-2 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.ui.forgot_title') }}</h1>
    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.ui.forgot_subtitle') }}</p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-input :label="__('auth.ui.email')" name="email" type="email" :value="old('email')" :error="field_error('email')" required autofocus autocomplete="username" />

        <x-button type="submit" class="w-full">{{ __('auth.ui.forgot_submit') }}</x-button>
    </form>

    <p class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ route('login') }}" class="text-brand hover:underline">{{ __('auth.ui.login_link') }}</a>
    </p>
@endsection
