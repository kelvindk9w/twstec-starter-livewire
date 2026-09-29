@props([
    'current' => 'table',
    'action' => 'setView',
])

{{-- Alternador TABELA / CARTÕES de uma lista do painel — o mesmo padrão do
     alternador do /admin (tabela clássica ou grade de cartões), em Livewire:
     <x-view-toggle :current="$view" action="setView" />.

     Chama a ação Livewire `action('table')` / `action('cards')`; quem guarda
     a escolha (na sessão, por lista) é o componente. No celular a tabela do
     kit já vira cartões sozinha — o alternador escolhe o layout do desktop. --}}
<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-lg border border-border bg-surface p-0.5']) }} role="group" aria-label="{{ __('ui.view_toggle.label') }}">
    @foreach (['table' => 'table-cells', 'cards' => 'squares-2x2'] as $mode => $icon)
        <button
            type="button"
            wire:click="{{ $action }}('{{ $mode }}')"
            aria-pressed="{{ $current === $mode ? 'true' : 'false' }}"
            aria-label="{{ __('ui.view_toggle.'.$mode) }}"
            data-view-mode="{{ $mode }}"
            class="group relative inline-flex h-11 w-11 items-center justify-center rounded-md transition-[background-color,color] duration-150 ease-(--ease-out) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:h-8 sm:w-8 {{ $current === $mode ? 'bg-surface-sunken text-gray-900 dark:text-gray-100' : 'text-text-muted hover:text-gray-900 dark:hover:text-gray-100' }}"
        >
            <x-ui-icon :name="$icon" class="h-4 w-4" />
            <span
                role="tooltip"
                class="pointer-events-none absolute bottom-full left-1/2 z-40 mb-2 -translate-x-1/2 whitespace-nowrap rounded-md bg-brand px-2 py-1 text-caption font-medium text-brand-foreground opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"
            >{{ __('ui.view_toggle.'.$mode) }}</span>
        </button>
    @endforeach
</div>
