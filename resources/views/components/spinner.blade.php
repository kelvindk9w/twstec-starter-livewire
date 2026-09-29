@props(['size' => 'md', 'tone' => 'brand'])

{{-- Indicador de carregamento. <x-spinner size="sm" />
     tone="current" herda a cor do texto — é o que se usa DENTRO de um botão
     primário, onde um spinner cor-de-marca sobre fundo da marca some. --}}
@php
    $sizeClasses = match ($size) {
        'sm' => 'h-4 w-4',
        'lg' => 'h-8 w-8',
        default => 'h-6 w-6',
    };
@endphp

<svg {{ $attributes->merge(['class' => 'animate-spin '.($tone === 'current' ? 'text-current ' : 'text-brand ').$sizeClasses]) }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" role="status" aria-label="{{ __('showcase.components.spinner_label') }}">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Zm2 5.291A7.962 7.962 0 0 1 4 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647Z"></path>
</svg>
