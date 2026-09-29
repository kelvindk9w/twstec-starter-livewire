{{-- Página inicial do PRODUTO (rota `home`, em "/").

     Só existe quando nenhuma extensão instalada responde por "/" — com a
     demonstração do kit, quem responde é a landing dela (ver routes/web.php).
     Sem página institucional própria, o produto mostra o essencial: o nome da
     plataforma e o caminho para entrar (ou voltar ao painel). Tudo no
     esqueleto único do site e com os componentes do kit. --}}
<x-layouts.site>
    <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-start justify-center gap-6 px-4 py-24">
        <h1 class="font-display text-4xl font-semibold tracking-[-0.02em]">{{ platform()->name }}</h1>
        <p class="max-w-xl text-lg text-text-muted">{{ __('landing.footer.tagline') }}</p>

        <div class="flex flex-wrap gap-3">
            @auth
                <x-button :href="route('dashboard')">{{ __('panel.nav.dashboard') }}</x-button>
            @else
                <x-button :href="route('register')">{{ __('landing.nav.register') }}</x-button>
                <x-button :href="route('login')" variant="secondary">{{ __('landing.nav.login') }}</x-button>
            @endauth
        </div>
    </main>
</x-layouts.site>
