@extends('layouts.auth')

@section('title', __('auth.ui.transaction_password_title'))

@section('content')
    <h1 class="mb-2 font-display text-xl font-semibold tracking-[-0.01em]">{{ __('auth.ui.transaction_password_title') }}</h1>
    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('auth.ui.transaction_password_subtitle') }}</p>

    <form method="POST" action="{{ route('transaction-password.update') }}" class="space-y-4">
        @csrf
        @method('PUT')

        @if (auth()->user()->hasTransactionPassword())
            <x-input :label="__('auth.ui.current_transaction_password')" name="current_transaction_password" type="password" :error="field_error('current_transaction_password')" required autocomplete="off" />
        @endif

        <x-input :label="__('auth.ui.new_transaction_password')" name="transaction_password" type="password" :error="field_error('transaction_password')" required autocomplete="off" />
        <x-input :label="__('auth.ui.password_confirmation')" name="transaction_password_confirmation" type="password" :error="field_error('transaction_password_confirmation')" required autocomplete="off" />

        <x-button type="submit" class="w-full">{{ __('auth.ui.save') }}</x-button>
    </form>
@endsection
