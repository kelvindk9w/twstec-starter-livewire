@props([
    'lines' => 3,
])

{{-- Placeholder de carregamento com shimmer sutil. <x-skeleton :lines="3" />
     ou slot livre para formas (cards, avatar). Combinar com wire:loading no
     painel. O shimmer desliga com prefers-reduced-motion (ver .skeleton no
     app.css). Prefira skeleton a spinner: mostra a ESTRUTURA que vem aí. --}}
<div role="status" aria-label="{{ __('showcase.components.loading_label') }}" {{ $attributes->merge(['class' => 'space-y-2']) }}>
    @if (trim((string) $slot) !== '')
        {{ $slot }}
    @else
        @foreach (range(1, max(1, (int) $lines)) as $line)
            <div @class(['skeleton h-3 rounded', 'w-2/3' => $line === (int) $lines && (int) $lines > 1, 'w-full' => $line !== (int) $lines || (int) $lines === 1])></div>
        @endforeach
    @endif
</div>
