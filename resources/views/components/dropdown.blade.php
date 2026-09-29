@props([
    'align' => 'right',
    'width' => 'w-56',
])

{{-- Menu ancorado no gatilho: seletor de idioma, seletor de tema e menu de
     ações (⋯). Comportamento em resources/js/ui.js ([data-dropdown]):
     clique fora, Esc, setas/Home/End no teclado e fechamento ao escolher.

     <x-dropdown>
         <x-slot:trigger><x-button variant="secondary" size="sm">…</x-button></x-slot:trigger>
         <x-dropdown-item href="…">…</x-dropdown-item>
     </x-dropdown>

     O gatilho recebe data-dropdown-trigger automaticamente: quem usa o
     componente não precisa saber o nome do atributo. --}}
@php
    $alignClasses = $align === 'left' ? 'left-0 [--dropdown-origin:top_left]' : 'right-0 [--dropdown-origin:top_right]';
@endphp

<div data-dropdown {{ $attributes->merge(['class' => 'relative inline-block text-left']) }}>
    <div data-dropdown-trigger aria-haspopup="true" aria-expanded="false" class="contents">{{ $trigger }}</div>

    <div
        data-dropdown-menu
        role="menu"
        class="dropdown-menu absolute z-50 mt-2 {{ $width }} overflow-hidden rounded-xl border border-border bg-surface-raised p-1 shadow-lg {{ $alignClasses }}"
    >
        {{ $slot }}
    </div>
</div>
