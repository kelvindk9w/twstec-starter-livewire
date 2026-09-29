@props([
    'title' => null,
    'description' => null,
    'padding' => 'default',
])

{{-- Cartão de conteúdo. <x-card title="…">corpo</x-card> + <x-slot:footer> opcional.

     PADDING ÚNICO no kit: p-5 no corpo, px-5 py-4 no cabeçalho, px-5 py-3 no
     rodapé. Quatro paddings diferentes de cartão numa mesma aplicação é a
     forma mais barata de parecer que cada tela foi feita por outra pessoa.
     `padding="none"` existe só para o que ocupa o cartão inteiro (tabela). --}}
@php
    $bodyPadding = $padding === 'none' ? '' : 'p-5';
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0 rounded-xl border border-border bg-surface shadow-sm']) }}>
    @if ($title !== null)
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <h3 class="text-h2 font-display text-gray-900 dark:text-gray-100">{{ $title }}</h3>
                @if ($description !== null)
                    <p class="mt-1 text-caption text-text-muted">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="{{ $bodyPadding }} text-sm text-gray-600 dark:text-gray-300">{{ $slot }}</div>
    @isset($footer)
        <div class="border-t border-border px-5 py-3">{{ $footer }}</div>
    @endisset
</div>
