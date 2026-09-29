@props([
    'icon',
    'label',
    'color' => 'gray',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
])

{{-- Ação de ÍCONE com tooltip — <x-icon-button icon="trash" color="red" :label="__('…')" wire:click="…" />.

     Para ações de linha e de cartão (padrão do painel: só o ícone, colorido
     pelo tipo da ação, com o nome no tooltip). O nome é também o
     aria-label: leitor de tela e teclado não dependem do tooltip.

     Cores (o ícone e o fundo do hover; a borda é a neutra do kit):
       gray  — ação neutra (abrir, reenviar)
       green — promover / aprovar
       amber — rebaixar / reverter
       red   — remover / revogar (destrutiva)
       blue  — informação

     Alvo de toque: 44px no celular (WCAG 2.5.8), 32px a partir de sm:. O
     tooltip aparece no hover e no foco do teclado (focus-visible), acima do
     botão, e some com prefers-reduced-motion sem animação. --}}
@php
    $colorClasses = match ($color) {
        'green' => 'text-green-600 hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-950/50',
        'amber' => 'text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/50',
        'red' => 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/50',
        'blue' => 'text-sky-600 hover:bg-sky-50 dark:text-sky-400 dark:hover:bg-sky-950/50',
        default => 'text-gray-600 hover:bg-surface-sunken dark:text-gray-300',
    };
    $classes = 'group relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-border'
        .' transition-[transform,background-color,color] duration-150 ease-(--ease-out) active:scale-[0.97]'
        .' focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand'
        .' disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none sm:h-8 sm:w-8 '.$colorClasses;
@endphp

@if ($href !== null)
    <a href="{{ $href }}" aria-label="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>
@else
    <button type="{{ $type }}" aria-label="{{ $label }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>
@endif
    <x-ui-icon :name="$icon" class="h-4 w-4" />
    <span
        role="tooltip"
        class="pointer-events-none absolute bottom-full left-1/2 z-40 mb-2 -translate-x-1/2 whitespace-nowrap rounded-md bg-brand px-2 py-1 text-caption font-medium text-brand-foreground opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"
    >{{ $label }}</span>
@if ($href !== null)
    </a>
@else
    </button>
@endif
