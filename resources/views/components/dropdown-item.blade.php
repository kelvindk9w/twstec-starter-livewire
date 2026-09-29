@props([
    'href' => null,
    'active' => false,
    'danger' => false,
    'type' => 'button',
])

{{-- Item de <x-dropdown>. Link (href) ou botão. `active` marca o estado atual
     com um ✓ à direita — reconhecer em vez de lembrar. --}}
@php
    $classes = 'flex w-full min-h-11 items-center gap-2.5 rounded-lg px-3 py-2.5 text-left text-sm transition-colors duration-150 ease-(--ease-out) sm:min-h-0 sm:py-2 '
        .($danger
            ? 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40'
            : 'text-gray-700 hover:bg-surface-sunken dark:text-gray-200');
@endphp

@if ($href !== null)
    <a href="{{ $href }}" role="menuitem" @if ($active) aria-current="true" @endif {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
        @if ($active)
            <x-ui-icon name="check" class="ms-auto h-4 w-4 text-brand" />
        @endif
    </a>
@else
    <button type="{{ $type }}" role="menuitem" @if ($active) aria-pressed="true" @endif {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
        @if ($active)
            <x-ui-icon name="check" class="ms-auto h-4 w-4 text-brand" />
        @endif
    </button>
@endif
