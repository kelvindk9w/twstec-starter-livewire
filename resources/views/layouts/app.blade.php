@php
    use App\Livewire\Support\Navigation;
    use Twstec\Kit\Auth\Contracts\AuthUser;
    use Twstec\Kit\Auth\Support\TwoFactorRequirement;

    // Segundo fator obrigatório na CARÊNCIA (AUTH_TWO_FACTOR_GRACE_DAYS): até
    // quando a conta pode adiar. Fora dela, o pacote já leva à configuração.
    $twoFactorGrace = auth()->user() instanceof AuthUser
        ? app(TwoFactorRequirement::class)->graceEndsAt(auth()->user())
        : null;
@endphp

{{-- Painel do usuário. Mesmo cabeçalho, mesmo rodapé e mesma marca do site
     (decisão do dono: quem entra na conta NÃO sai do site) — o que muda é
     que aparece um menu lateral "Minha conta" à esquerda do conteúdo, no
     mesmo estilo do índice do /ui.

     A antiga barra horizontal do painel não escalava: cada tela nova
     empurrava a próxima para fora, e no mobile o menu virava um bloco de
     29% da altura da tela. Na coluna, "adicionar uma tela" é acrescentar uma
     linha ao mapa (App\Livewire\Support\Navigation). --}}
<x-layouts.site :title="($title ?? __('panel.nav.dashboard')).' — '.platform()->name" background="bg-surface-sunken" width="max-w-7xl">
    <div class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 lg:flex lg:gap-10">
        {{-- mobile="none": no painel o índice do mobile mora na gaveta do
             cabeçalho, junto dos links do site — uma gaveta só. --}}
        <x-side-nav
            id="account"
            :title="__('ui.nav.account')"
            :groups="Navigation::account()"
            mobile="none"
        >
            {{-- A conta atual em todo o painel, e a troca de conta — só com o
                 pacote de contas (twstec/kit-accounts) instalado. --}}
            @kit('accounts')
                <x-slot:header>
                    <x-account-switcher />
                </x-slot:header>
            @endkit
        </x-side-nav>

        <main class="min-w-0 flex-1">
            {{-- No celular a coluna some: o seletor abre o conteúdo. --}}
            @kit('accounts')
                <x-account-switcher class="mb-6 lg:hidden" />
            @endkit

            @if ($twoFactorGrace !== null)
                <x-alert type="warning" class="mb-6" data-two-factor-grace>
                    {{ __('panel.profile.two_factor_grace', ['date' => $twoFactorGrace->translatedFormat(__('auth.two_factor_setup.date_format'))]) }}
                    <a href="{{ route('two-factor.setup') }}" class="ml-1 font-medium underline">{{ __('panel.profile.two_factor_grace_action') }}</a>
                </x-alert>
            @endif

            {{ $slot }}
        </main>
    </div>
</x-layouts.site>
