@props([
    'label',
    'value',
    'icon' => null,
    'hint' => null,
    'href' => null,
])

{{-- Métrica de dashboard. <x-stat label="…" value="12" icon="key" />

     O número é o herói: display grande, tabular-nums para os dígitos não
     dançarem entre linhas; o rótulo é caption. Com `href` o cartão inteiro
     vira o alvo de clique (área grande > link de 3 palavras). --}}
@php
    $tag = $href !== null ? 'a' : 'div';
    $interactive = $href !== null
        ? ' transition-colors duration-150 ease-(--ease-out) hover:border-border-strong'
        : '';
@endphp

<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'flex flex-col justify-between rounded-xl border border-border bg-surface p-5 shadow-sm'.$interactive]) }}
>
    <div class="flex items-start justify-between gap-3">
        <p class="text-caption font-medium uppercase tracking-wide text-text-muted">{{ $label }}</p>
        @if ($icon !== null)
            <x-ui-icon :name="$icon" class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
        @endif
    </div>
    <p class="mt-3 font-display text-3xl font-semibold tabular-nums tracking-[-0.02em] text-gray-900 dark:text-gray-100">{{ $value }}</p>
    @if ($hint !== null)
        <p class="mt-1 text-caption text-text-muted">{{ $hint }}</p>
    @endif
</{{ $tag }}>
