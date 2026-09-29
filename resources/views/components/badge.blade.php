@props(['color' => 'gray'])

{{-- Etiqueta de status. <x-badge color="green">Ativo</x-badge> --}}
@php
    $colorClasses = match ($color) {
        'green' => 'bg-green-100 text-green-800 dark:bg-green-950/60 dark:text-green-300',
        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/60 dark:text-yellow-300',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
        'blue' => 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
        'brand' => 'bg-brand/10 text-brand',
        default => 'bg-surface-sunken text-gray-700 ring-1 ring-inset ring-border dark:text-gray-300',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium '.$colorClasses]) }}>{{ $slot }}</span>
