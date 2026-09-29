@props([
    'id',
    'title' => null,
    'description' => null,
    'open' => false,
    'dismiss' => null,
])

{{--
    Modal sem dependência de JS externo. Abertura: qualquer elemento com
    data-modal-open="<id>". Fechamento: data-modal-close, backdrop ou Esc.
    O foco entra no painel, fica PRESO nele enquanto aberto e volta para quem
    abriu ao fechar — tudo em resources/js/ui.js; a animação em app.css
    (.modal-backdrop/.modal-panel, transitions interruptíveis).

    MODAL CONTROLADO PELO SERVIDOR (Livewire): `:open="true"` renderiza já
    aberto e `dismiss="cancelAlgo"` liga o backdrop, o X e o Esc à ação
    Livewire que limpa o estado — sem isso o modal fecharia na tela e
    reabriria no próximo render, porque o servidor ainda o julga aberto.

    <x-button data-modal-open="confirmar">Abrir</x-button>
    <x-modal id="confirmar" title="…">conteúdo</x-modal>
--}}
<div
    id="{{ $id }}"
    data-modal
    class="fixed inset-0 z-50 {{ $open ? 'is-open flex' : 'hidden' }} items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    @if ($title !== null) aria-labelledby="{{ $id }}-title" @endif
>
    <div class="modal-backdrop absolute inset-0 bg-gray-950/60 backdrop-blur-sm" data-modal-close @if ($dismiss) wire:click="{{ $dismiss }}" @endif></div>
    <div tabindex="-1" {{ $attributes->merge(['class' => 'modal-panel relative max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-xl border border-border bg-surface-raised p-5 shadow-xl focus:outline-none']) }}>
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                @if ($title !== null)
                    <h2 id="{{ $id }}-title" class="text-h2 font-display text-gray-900 dark:text-gray-100">{{ $title }}</h2>
                @endif
                @if ($description !== null)
                    <p class="mt-1 text-caption text-text-muted">{{ $description }}</p>
                @endif
            </div>
            <button type="button" data-modal-close @if ($dismiss) data-modal-dismiss wire:click="{{ $dismiss }}" @endif aria-label="{{ __('panel.common.close') }}" class="-m-1 shrink-0 rounded-md p-2 text-gray-400 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-700 dark:hover:text-gray-200">
                <x-ui-icon name="x-mark" class="h-5 w-5" />
            </button>
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-300">{{ $slot }}</div>
        @isset($footer)
            <div class="mt-6 flex flex-wrap justify-end gap-2">{{ $footer }}</div>
        @endisset
    </div>
</div>
