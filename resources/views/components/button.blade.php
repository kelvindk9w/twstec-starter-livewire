@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

{{-- Botão padrão do kit. <x-button>…</x-button> ou <x-button href="…"> (link).
     Motion: transitions com propriedades explícitas (nunca `all`), :active
     scale(0.97) = feedback instantâneo de pressão; desligado com reduced-motion.

     Hierarquia (regra do kit, documentada no /ui): no máximo UM primário por
     bloco; `secondary` para as ações de rotina; `ghost` para ação terciária
     dentro de uma superfície; `danger` só para o que destrói dado. --}}
@php
    $variantClasses = match ($variant) {
        // Hover com COR declarada (--color-brand-hover): clarear um quase-preto
        // com brightness não produz diferença visível em nenhum dos 2 temas.
        'primary' => 'bg-brand text-brand-foreground hover:bg-brand-hover',
        'secondary' => 'border border-border bg-surface text-gray-900 hover:bg-surface-sunken dark:text-gray-100',
        // Ghost com borda TRANSPARENTE que aparece no hover: sem isso a
        // variante some no escuro (era texto cinza sem forma nenhuma).
        'ghost' => 'border border-transparent text-gray-700 hover:border-border hover:bg-surface-sunken dark:text-gray-300',
        'outline' => 'border border-brand bg-transparent text-brand hover:bg-brand/10',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        default => 'bg-brand text-brand-foreground hover:bg-brand-hover',
    };
    // Alvo de toque: nunca abaixo de 44px de altura no mobile (WCAG 2.5.8 /
    // iOS HIG). `sm` encolhe só a partir de sm: — no celular todo botão é dedo.
    $sizeClasses = match ($size) {
        'sm' => 'min-h-11 px-3 py-2.5 text-sm sm:min-h-0 sm:py-1.5',
        'lg' => 'min-h-11 px-6 py-3 text-base',
        default => 'min-h-11 px-4 py-2.5 text-sm sm:min-h-0 sm:py-2',
    };
    $classes = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium'
        .' transition-[transform,background-color,border-color,color] duration-150 ease-(--ease-out)'
        .' active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100'
        .' disabled:cursor-not-allowed disabled:opacity-50 '.$sizeClasses.' '.$variantClasses;
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
