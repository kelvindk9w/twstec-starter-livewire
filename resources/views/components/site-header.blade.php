@props([
    'width' => 'max-w-6xl',
    'variant' => 'bar',
])

@php
    use App\Livewire\Support\Navigation;

    $siteNav = Navigation::site();
    $user = auth()->user();
    $accountNav = $user !== null ? Navigation::account() : [];

    // VARIANTE 'floating' (landing oficial, rota /): a MESMA barra — mesma
    // marca, mesma nav, mesmo menu da conta, mesma gaveta — dentro de uma
    // pílula que flutua sobre o céu, em vez de colada no topo com uma linha
    // embaixo. Muda só o invólucro: nenhum item, nenhuma regra e nenhum estado
    // do cabeçalho do produto sabe que ela existe (as classes sky-nav* moram
    // no CSS da landing, que só ela carrega — pacote twstec/kit-demo).
    $floating = $variant === 'floating';

    $shellClasses = $floating
        ? 'sky-nav px-4 pt-3'
        : 'sticky top-0 z-40 border-b border-border bg-surface/85 backdrop-blur';

    $innerClasses = $floating
        ? 'sky-nav-shell mx-auto flex h-14 '.$width.' items-center gap-3 px-4 sm:gap-6'
        : 'mx-auto flex h-16 '.$width.' items-center gap-3 px-4 sm:gap-6';
@endphp

{{-- Cabeçalho do site — <x-site-header />. UM cabeçalho para landing, /ui,
     telas de auth e painel.

     Decisão do dono: o cliente logado NUNCA deve sentir que saiu do site.
     Antes havia dois cabeçalhos (marca, cores, itens e animação diferentes) e
     entrar na conta parecia trocar de produto. Agora muda só o que precisa
     mudar: deslogado aparecem Entrar/Criar conta; logado, o AVATAR com o menu
     da conta (e o tema mora lá dentro, para o cabeçalho não virar uma barra
     de ferramentas).

     Altura fixa (h-16): a barra compacta do <x-side-nav> e o scroll-mt das
     âncoras dependem de um número, não de um palpite. --}}
<header class="{{ $shellClasses }}">
    <div class="{{ $innerClasses }}">
        <x-brand :href="url('/')" />

        <nav aria-label="{{ __('ui.nav.site') }}" class="hidden flex-1 items-center gap-1 text-sm sm:flex">
            @foreach ($siteNav as $item)
                <a href="{{ $item['href'] }}" class="rounded-md px-3 py-1.5 text-gray-600 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-2 sm:ms-0">
            {{-- Seletor de idioma VISÍVEL nos dois estados (decisão do dono):
                 trocar de idioma não pode custar dois cliques num menu. --}}
            <div class="hidden sm:flex sm:items-center sm:gap-2">
                <x-locale-switcher />
                {{-- Controle extra da tela (slot). Vazio na maioria das
                     telas; a landing /v2 põe aqui o botão de som. --}}
                {{ $slot }}
            </div>

            @auth
                <x-user-menu />
            @else
                <div class="hidden sm:block"><x-theme-toggle /></div>
                <a href="{{ route('login') }}" class="hidden rounded-md px-3 py-1.5 text-sm text-gray-600 underline-offset-4 transition-colors duration-150 ease-(--ease-out) hover:text-gray-900 hover:underline sm:inline-block dark:text-gray-300 dark:hover:text-white">{{ __('landing.nav.login') }}</a>
                <x-button :href="route('register')" size="sm" class="hidden sm:inline-flex!">{{ __('landing.nav.register') }}</x-button>
            @endauth

            {{-- Hambúrguer: só no mobile (a nav do site e, se logado, o
                 "Minha conta" inteiro vão para a gaveta). --}}
            <button
                type="button"
                data-modal-open="site-menu"
                aria-haspopup="dialog"
                aria-label="{{ __('ui.nav.open_menu') }}"
                class="-me-2 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-600 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken sm:hidden dark:text-gray-300"
            >
                <x-ui-icon name="bars-3" class="h-6 w-6" />
            </button>
        </div>
    </div>
</header>

{{-- Gaveta do mobile: os itens do site e, quando logado, a MESMA lista do menu
     lateral sob "Minha conta". Uma gaveta só — duas seriam duas navegações
     concorrentes no mesmo polegar. --}}
<x-drawer id="site-menu" :title="__('ui.nav.menu')" class="sm:hidden">
    <nav aria-label="{{ __('ui.nav.site') }}" class="flex flex-col gap-0.5">
        @foreach ($siteNav as $item)
            <a href="{{ $item['href'] }}" data-modal-close class="side-nav-item">{{ $item['label'] }}</a>
        @endforeach
    </nav>

    @auth
        <div class="mt-6 border-t border-border pt-5">
            {{-- Título da seção em caixa normal: os rótulos DOS GRUPOS já são
                 caption em versalete, e dois níveis de maiúsculas seguidos
                 viram uma parede sem hierarquia. --}}
            <p class="px-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ui.nav.account') }}</p>

            <div class="mb-4 mt-3 flex items-center gap-3 px-3">
                <x-avatar :user="$user" size="md" />
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</p>
                    <p class="truncate text-caption text-text-muted">{{ $user->email }}</p>
                </div>
            </div>

            <x-side-nav-items :groups="$accountNav" close-on-click />
        </div>
    @endauth

    <x-slot:footer>
        <div class="flex flex-col gap-3">
            @guest
                <x-button :href="route('register')" class="w-full">{{ __('landing.nav.register') }}</x-button>
                <x-button :href="route('login')" variant="secondary" class="w-full">{{ __('landing.nav.login') }}</x-button>
            @else
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" variant="secondary" class="w-full">
                        <x-ui-icon name="arrow-right-on-rectangle" class="h-4 w-4" />
                        {{ __('auth.ui.logout') }}
                    </x-button>
                </form>
            @endguest

            <div class="flex items-center justify-between gap-3 pt-1">
                <x-locale-switcher />
                <div class="flex items-center gap-2">
                    {{ $slot }}
                    <x-theme-toggle />
                </div>
            </div>
        </div>
    </x-slot:footer>
</x-drawer>
