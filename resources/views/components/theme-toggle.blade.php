{{-- Seletor de tema (Sistema · Claro · Escuro). <x-theme-toggle />

     Era um ícone que CICLAVA os 3 estados sem rótulo: para descobrir onde se
     está era preciso clicar às cegas. Agora é o <x-dropdown> do kit com os
     três estados NOMEADOS e o ativo marcado — o mesmo padrão que o Perfil já
     usava como segmented control.

     O estado inicial, a aplicação e a persistência (localStorage + conta)
     continuam em resources/js/ui.js ([data-theme-set]); os ícones ficam no
     markup para o JS alternar sem depender de rede. --}}
<x-dropdown {{ $attributes }} width="w-48">
    <x-slot:trigger>
        <button
            type="button"
            title="{{ __('ui.theme.label') }}"
            aria-label="{{ __('ui.theme.label') }}"
            class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-border px-2.5 py-2 text-gray-600 transition-[transform,background-color,border-color,color] duration-150 ease-(--ease-out) hover:bg-surface-sunken active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100 sm:min-h-0 sm:py-1.5 dark:text-gray-300"
        >
            <span data-theme-icon="system"><x-ui-icon name="computer-desktop" class="h-4 w-4" /></span>
            <span data-theme-icon="light" class="hidden"><x-ui-icon name="sun" class="h-4 w-4" /></span>
            <span data-theme-icon="dark" class="hidden"><x-ui-icon name="moon" class="h-4 w-4" /></span>
            <x-ui-icon name="chevron-down" class="h-3.5 w-3.5 text-gray-400" />
        </button>
    </x-slot:trigger>

    @foreach (['system' => 'computer-desktop', 'light' => 'sun', 'dark' => 'moon'] as $themeValue => $themeIcon)
        <x-dropdown-item data-theme-set="{{ $themeValue }}" aria-pressed="false">
            <x-ui-icon :name="$themeIcon" class="h-4 w-4 text-text-muted" />
            <span>{{ __("ui.theme.{$themeValue}") }}</span>
            <x-ui-icon name="check" data-theme-check="{{ $themeValue }}" class="ms-auto hidden h-4 w-4 text-brand" />
        </x-dropdown-item>
    @endforeach
</x-dropdown>
