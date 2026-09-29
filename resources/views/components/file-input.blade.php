@props([
    'name' => null,
    'label' => null,
    'accept' => null,
    'buttonLabel' => null,
    'emptyText' => null,
    'error' => null,
    'hint' => null,
])

{{-- Seletor de arquivo do kit. <x-file-input name="avatar" accept="image/*" />

     O <input type="file"> nativo desenha o próprio botão ("Choose File / No
     file chosen") com o texto do SISTEMA OPERACIONAL — em um kit pt-BR/en/es
     ele é a única coisa em inglês na tela, e a única superfície que ignora o
     design system. Aqui o input real fica escondido (acessível, no tab order
     via o botão) e quem aparece é o botão do kit + o nome do arquivo.

     Comportamento em resources/js/ui.js ([data-file-input]). --}}
@php
    $hasError = ! empty($error);
    $empty = $emptyText ?? __('ui.file.empty');
@endphp

<div data-file-input {{ $attributes->only('class') }}>
    @if ($label !== null)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $label }}</label>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <x-button type="button" variant="secondary" size="sm" data-file-trigger>
            <x-ui-icon name="arrow-up-tray" class="h-4 w-4" />
            {{ $buttonLabel ?? __('ui.file.choose') }}
        </x-button>
        <span data-file-name data-empty-text="{{ $empty }}" class="min-w-0 truncate text-caption text-text-muted">{{ $empty }}</span>
    </div>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="file"
        class="sr-only"
        @if ($accept) accept="{{ $accept }}" @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->except('class') }}
    >

    @if ($hasError)
        <p id="{{ $name }}-error" class="mt-1.5 flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
            <x-ui-icon name="x-circle" class="h-4 w-4 shrink-0" />
            <span>{{ $error }}</span>
        </p>
    @elseif ($hint !== null)
        <p class="mt-1.5 text-caption text-text-muted">{{ $hint }}</p>
    @endif
</div>
