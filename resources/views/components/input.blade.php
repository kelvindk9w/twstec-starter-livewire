@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'error' => null,
    'hint' => null,
    'disabled' => false,
])

{{-- Campo de texto com label, hint e estado de erro. <x-input label="E-mail" name="email" />
     type="password" embute o botão "olho" (revelar/ocultar) à direita —
     comportamento em resources/js/ui.js ([data-password-toggle]).

     Acessibilidade: em erro o campo recebe aria-invalid e aponta a mensagem
     com aria-describedby; o hint também é anunciado. Sem isso, um leitor de
     tela lê o campo como válido e nunca chega ao motivo da recusa. --}}
@php
    $isPassword = $type === 'password';
    $hasError = ! empty($error);
    $describedBy = $hasError ? $name.'-error' : ($hint !== null ? $name.'-hint' : null);
    $inputClasses =
        'block w-full rounded-lg border bg-surface px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 transition-[border-color,background-color] duration-150 ease-(--ease-out) focus:outline-none focus:ring-2 focus:ring-brand/50 disabled:cursor-not-allowed disabled:bg-surface-disabled disabled:text-text-muted sm:py-2 dark:text-gray-100 '
        .($isPassword ? 'pr-10 ' : '')
        .($hasError
            ? 'border-red-500 focus:border-red-500'
            : 'border-border-strong focus:border-brand');
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label !== null)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</label>
    @endif
    @if ($isPassword)
        <div class="relative">
            <input
                id="{{ $name }}"
                name="{{ $name }}"
                type="password"
                @disabled($disabled)
                @if ($hasError) aria-invalid="true" @endif
                @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                {{ $attributes->except('class')->merge(['class' => $inputClasses]) }}
            >
            <button
                type="button"
                data-password-toggle
                aria-label="{{ __('ui.password.show') }}"
                data-label-show="{{ __('ui.password.show') }}"
                data-label-hide="{{ __('ui.password.hide') }}"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 transition-colors duration-150 ease-(--ease-out) hover:text-gray-600 dark:hover:text-gray-300"
            >
                <span data-password-icon="show"><x-ui-icon name="eye" class="h-4 w-4" /></span>
                <span data-password-icon="hide" class="hidden"><x-ui-icon name="eye-slash" class="h-4 w-4" /></span>
            </button>
        </div>
    @else
        <input
            id="{{ $name }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @disabled($disabled)
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class')->merge(['class' => $inputClasses]) }}
        >
    @endif
    @if ($hasError)
        <p id="{{ $name }}-error" class="mt-1.5 flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
            <x-ui-icon name="x-circle" class="h-4 w-4 shrink-0" />
            <span>{{ $error }}</span>
        </p>
    @elseif ($hint !== null)
        <p id="{{ $name }}-hint" class="mt-1.5 text-caption text-text-muted">{{ $hint }}</p>
    @endif
</div>
