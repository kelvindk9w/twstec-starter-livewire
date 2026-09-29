@props([
    'type' => 'info',
    'title' => null,
])

{{-- Alerta de feedback. <x-alert type="success" title="…">mensagem</x-alert> --}}
@php
    $styles = match ($type) {
        'success' => ['border-green-500/50 bg-green-50 text-green-800 dark:bg-green-950/40 dark:text-green-300', 'check-circle'],
        'warning' => ['border-yellow-500/50 bg-yellow-50 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300', 'exclamation-triangle'],
        'error' => ['border-red-500/50 bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300', 'x-circle'],
        default => ['border-sky-500/50 bg-sky-50 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300', 'information-circle'],
    };
@endphp

<div role="alert" {{ $attributes->merge(['class' => 'flex gap-3 rounded-lg border p-4 text-sm '.$styles[0]]) }}>
    <x-ui-icon :name="$styles[1]" class="mt-0.5 h-5 w-5 shrink-0" />
    <div>
        @if ($title !== null)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
