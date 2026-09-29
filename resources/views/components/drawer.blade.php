@props([
    'id',
    'title' => null,
    'side' => 'right',
])

{{--
    Drawer (gaveta lateral) — o MESMO motor do <x-modal> ([data-modal] +
    .is-open em resources/js/ui.js: abre por data-modal-open, fecha no Esc, no
    backdrop e no data-modal-close, com foco preso e devolvido). Muda só a
    geometria do painel: colado na borda, entrando por deslize.

    É a navegação mobile do kit — o menu do painel e o da landing abaixo de sm:.

    <x-button data-modal-open="menu"><x-ui-icon name="bars-3" /></x-button>
    <x-drawer id="menu" title="Menu">…</x-drawer>
--}}
@php
    // --drawer-offset é de onde o painel entra (ver app.css).
    $sideClasses = $side === 'left'
        ? 'left-0 [--drawer-offset:-100%] border-r'
        : 'right-0 [--drawer-offset:100%] border-l';
@endphp

<div
    id="{{ $id }}"
    data-modal
    class="fixed inset-0 z-50 hidden"
    role="dialog"
    aria-modal="true"
    @if ($title !== null) aria-labelledby="{{ $id }}-title" @endif
>
    <div class="modal-backdrop absolute inset-0 bg-gray-950/60 backdrop-blur-sm" data-modal-close></div>
    <div tabindex="-1" {{ $attributes->merge(['class' => 'drawer-panel absolute inset-y-0 flex w-[min(20rem,85vw)] flex-col border-border bg-surface shadow-xl focus:outline-none '.$sideClasses]) }}>
        <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-3">
            @if ($title !== null)
                <h2 id="{{ $id }}-title" class="text-h2 font-display text-gray-900 dark:text-gray-100">{{ $title }}</h2>
            @else
                <span></span>
            @endif
            <button type="button" data-modal-close aria-label="{{ __('panel.common.close') }}" class="-m-1 rounded-md p-2.5 text-gray-400 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-700 dark:hover:text-gray-200">
                <x-ui-icon name="x-mark" class="h-5 w-5" />
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-4">{{ $slot }}</div>
        @isset($footer)
            <div class="border-t border-border p-4">{{ $footer }}</div>
        @endisset
    </div>
</div>
