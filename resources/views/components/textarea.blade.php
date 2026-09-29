@props([
    'label' => null,
    'name' => null,
    'error' => null,
    'hint' => null,
    'rows' => 4,
    'disabled' => false,
])

{{-- Área de texto com label, hint e estado de erro (mesma linguagem do
     <x-input>). <x-textarea label="Mensagem" name="message" /> --}}
@php
    $hasError = ! empty($error);
    $describedBy = $hasError ? $name.'-error' : ($hint !== null ? $name.'-hint' : null);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label !== null)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</label>
    @endif
    <textarea
        id="{{ $name }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @disabled($disabled)
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class')->merge(['class' =>
            'block w-full rounded-lg border bg-surface px-3 py-2 text-sm text-gray-900 placeholder-gray-400 transition-[border-color,background-color] duration-150 ease-(--ease-out) focus:outline-none focus:ring-2 focus:ring-brand/50 disabled:cursor-not-allowed disabled:bg-surface-disabled disabled:text-text-muted dark:text-gray-100 '
            .($hasError
                ? 'border-red-500 focus:border-red-500'
                : 'border-border-strong focus:border-brand'),
        ]) }}
    >{{ $slot }}</textarea>
    @if ($hasError)
        <p id="{{ $name }}-error" class="mt-1.5 flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
            <x-ui-icon name="x-circle" class="h-4 w-4 shrink-0" />
            <span>{{ $error }}</span>
        </p>
    @elseif ($hint !== null)
        <p id="{{ $name }}-hint" class="mt-1.5 text-caption text-text-muted">{{ $hint }}</p>
    @endif
</div>
