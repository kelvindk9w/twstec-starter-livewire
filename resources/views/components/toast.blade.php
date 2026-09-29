@props(['type' => 'success'])

{{-- Notificação flutuante. <x-toast type="success">mensagem</x-toast>
     Animação (desliza de fora, ease-out) via .toast-item em app.css; o
     comportamento (data-toast-show / timeout) vive em resources/js/ui.js. --}}
@php
    $styles = match ($type) {
        'success' => ['border-green-500/50 text-green-800 dark:text-green-300', 'check-circle'],
        'warning' => ['border-yellow-500/50 text-yellow-800 dark:text-yellow-300', 'exclamation-triangle'],
        'error' => ['border-red-500/50 text-red-800 dark:text-red-300', 'x-circle'],
        default => ['border-sky-500/50 text-sky-800 dark:text-sky-300', 'information-circle'],
    };
@endphp

<div role="status" data-toast {{ $attributes->merge(['class' => 'toast-item flex items-center gap-3 rounded-lg border bg-white p-4 text-sm shadow-lg dark:bg-gray-900 '.$styles[0]]) }}>
    <x-ui-icon :name="$styles[1]" class="h-5 w-5 shrink-0" />
    <div class="text-gray-700 dark:text-gray-200" data-toast-message>{{ $slot }}</div>
</div>
