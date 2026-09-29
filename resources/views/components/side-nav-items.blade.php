@props([
    'groups' => [],
    'collapsible' => false,
    'closeOnClick' => false,
    'spy' => false,
])

{{-- Itens do menu lateral — o markup COMPARTILHADO entre a coluna do desktop
     e a gaveta do mobile. Uma lista, dois lugares, zero divergência.

     $groups: [['label' => 'Conta', 'items' => [
         ['label' => 'Perfil', 'href' => '/profile', 'icon' => 'user-circle',
          'active' => true, 'anchor' => 'perfil'|null],
     ]]]

     `collapsible` embrulha cada grupo num <details> (mobile: o índice inteiro
     não cabe na tela; colapsar é HTML nativo — sem JS, sem estado a manter e
     funciona antes de o bundle carregar).
     `closeOnClick` marca os links com data-modal-close: navegar dentro da
     gaveta fecha a gaveta. --}}
@php
    // Um grupo nasce aberto quando contém o item atual. Sem item atual
    // (âncoras do /ui, onde quem decide é o scrollspy) todos abrem: um índice
    // fechado é um índice que ninguém lê.
    $activeGroups = [];
    $anyActive = false;

    foreach ($groups as $index => $group) {
        $activeGroups[$index] = false;

        foreach ($group['items'] as $item) {
            if ($item['active'] ?? false) {
                $activeGroups[$index] = true;
                $anyActive = true;
            }
        }
    }
@endphp

{{-- data-scrollspy vem por PROP, não por atributo solto: uma diretiva @if
     dentro da tag de um componente Blade impede o compilador de reconhecer a
     tag (ela sai como texto na página — foi o que aconteceu com a gaveta do
     /ui, que abria vazia). --}}
<div @if ($spy) data-scrollspy @endif {{ $attributes->merge(['class' => 'flex flex-col gap-5']) }}>
    @foreach ($groups as $index => $group)
        @if ($collapsible)
            <details class="side-nav-group" @if (! $anyActive || $activeGroups[$index]) open @endif>
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 rounded-lg px-3 text-caption font-semibold uppercase tracking-widest text-text-muted">
                    <span>{{ $group['label'] }}</span>
                    <x-ui-icon name="chevron-down" class="side-nav-chevron h-4 w-4 transition-transform duration-150 ease-(--ease-out) motion-reduce:transition-none" />
                </summary>
                <div class="mt-1">
                    @include('partials.side-nav-links', ['items' => $group['items'], 'closeOnClick' => $closeOnClick])
                </div>
            </details>
        @else
            <div>
                <p class="px-3 pb-2 text-caption font-semibold uppercase tracking-widest text-text-muted">{{ $group['label'] }}</p>
                @include('partials.side-nav-links', ['items' => $group['items'], 'closeOnClick' => $closeOnClick])
            </div>
        @endif
    @endforeach
</div>
