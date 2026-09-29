{{-- Lista de links de um grupo do menu lateral. Incluída pelos DOIS ramos do
     <x-side-nav-items> (grupo aberto no desktop, <details> no mobile) — o
     item é desenhado num lugar só.

     Espera: $items (list), $closeOnClick (bool). --}}
<div class="flex flex-col gap-0.5">
    @foreach ($items as $item)
        <a
            href="{{ $item['href'] }}"
            @if ($closeOnClick ?? false) data-modal-close @endif
            @if ($item['active'] ?? false) aria-current="page" @endif
            class="side-nav-item"
        >
            @if (isset($item['icon']))
                <x-ui-icon :name="$item['icon']" class="h-4 w-4 shrink-0 opacity-70" />
            @endif
            <span class="truncate">{{ $item['label'] }}</span>
        </a>
    @endforeach
</div>
