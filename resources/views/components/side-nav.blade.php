@props([
    'id',
    'title',
    'groups' => [],
    'spy' => false,
    'mobile' => 'bar',
])

{{-- Menu lateral do kit — <x-side-nav id="ui" :title="…" :groups="…" spy />.

     Serve as DUAS superfícies com índice longo: o "Minha conta" do painel
     (itens de rota) e o índice do /ui (âncoras + scrollspy). Comportamento
     inspirado nas docs do Next.js:

     - DESKTOP: coluna fixa à esquerda, com rolagem PRÓPRIA (o índice não
       arrasta a página) e grupos rotulados sempre visíveis.
     - MOBILE: uma barra compacta grudada abaixo do cabeçalho, dizendo em que
       seção o leitor está, e um toque abre o índice inteiro como gaveta —
       grupos colapsáveis, item atual marcado, navegar fecha. Antes, o índice
       era uma nuvem de 13 pílulas empilhadas ANTES do conteúdo: a página
       começava com um menu do tamanho da tela.

     `<x-slot:header>` (opcional) fica no topo da coluna do desktop, acima
     dos grupos — no painel, o seletor de conta.

     `mobile="none"` desliga a barra: no painel o índice mobile vive dentro da
     gaveta do cabeçalho, junto dos links do site (decisão do dono — uma
     gaveta só).

     A raiz é `display: contents` para que a barra, a coluna e a gaveta sejam
     filhas diretas do container da página: no mobile ele é bloco (a barra
     ocupa a largura), no desktop é flex (a coluna vira a primeira coluna). --}}
@php
    $current = null;
    $first = null;

    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $first ??= $item['label'];

            if ($item['active'] ?? false) {
                $current ??= $item['label'];
            }
        }
    }

    $currentLabel = $current ?? $first ?? $title;
@endphp

<div {{ $attributes->merge(['class' => 'contents']) }}>
    @if ($mobile === 'bar')
        {{-- As margens negativas anulam o padding do container: a barra encosta
             no cabeçalho e sangra até as bordas, como uma barra de ferramentas. --}}
        <div class="sticky top-16 z-30 -mx-4 -mt-8 mb-6 border-b border-border bg-surface/90 px-4 backdrop-blur lg:hidden">
            <button
                type="button"
                data-modal-open="{{ $id }}-drawer"
                aria-haspopup="dialog"
                class="flex min-h-11 w-full items-center gap-2 py-2 text-sm text-gray-700 dark:text-gray-200"
            >
                <x-ui-icon name="queue-list" class="h-4 w-4 shrink-0 text-text-muted" />
                <span class="text-caption text-text-muted">{{ $title }}</span>
                <x-ui-icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 text-text-muted" />
                <span class="truncate font-medium" data-side-nav-current>{{ $currentLabel }}</span>
                <x-ui-icon name="chevron-down" class="ms-auto h-4 w-4 shrink-0 text-text-muted" />
            </button>
        </div>
    @endif

    <aside class="hidden shrink-0 lg:block lg:w-60">
        @isset($header)
            {{-- Cabeçalho da coluna (no painel, o seletor de conta): fica FORA
                 do <nav> que rola — um menu ancorado dentro de um contêiner
                 com overflow seria recortado por ele. --}}
            <div class="sticky top-20">
                <div class="mb-4 pe-2">{{ $header }}</div>
                <nav
                    aria-label="{{ $title }}"
                    @if ($spy) data-scrollspy @endif
                    class="max-h-[calc(100vh-11rem)] overflow-y-auto overscroll-contain pb-6 pe-2"
                >
                    <x-side-nav-items :groups="$groups" />
                </nav>
            </div>
        @else
            <nav
                aria-label="{{ $title }}"
                @if ($spy) data-scrollspy @endif
                class="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto overscroll-contain pb-6 pe-2"
            >
                <x-side-nav-items :groups="$groups" />
            </nav>
        @endisset
    </aside>

    {{-- A gaveta acompanha a barra: com mobile="none" ela não existe (no
         painel o índice mobile mora na gaveta do cabeçalho). Uma gaveta
         inalcançável ainda é markup duplicado — e duplicata de navegação é
         o começo de duas navegações diferentes. --}}
    @if ($mobile === 'bar')
        <x-drawer id="{{ $id }}-drawer" :title="$title" side="left" class="lg:hidden">
            <x-side-nav-items :groups="$groups" collapsible close-on-click :spy="$spy" />
        </x-drawer>
    @endif
</div>
