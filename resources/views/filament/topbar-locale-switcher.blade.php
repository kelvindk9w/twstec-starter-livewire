{{-- Seletor de idioma do super admin (/admin — Filament 5), registrado via
     render hook TOPBAR_END no AdminPanelProvider.

     Mesma LINGUAGEM do <x-locale-switcher> do resto do kit (bandeira em SVG
     inline + nome do idioma + ✓ no ativo) e a mesma rota locale.switch — o
     SetLocale do painel resolve cookie/preferência igual ao app.

     A implementação, porém, é AUTOCONTIDA de propósito: o /admin carrega o
     bundle CSS próprio do Filament (não os utilitários do app) e não carrega
     o resources/js/ui.js. Por isso aqui o menu é um <details> nativo — abre,
     fecha e navega por teclado sem uma linha de JavaScript — pintado com as
     VARIÁVEIS do theme.css (que o filament.css também importa), nunca com
     utilitários `dark:` do app: o `.dark` do Filament redefine os tokens, e
     é isso que faz o painel acompanhar o tema sem depender do build do app.
     Estilo inline e <style> local são permitidos pela CSP do /admin
     ('unsafe-inline' em style-src — ver config/security.php).

     Emoji fora: 🇧🇷 depende da fonte de emoji do sistema (no Windows vira
     "BR", no Linux costuma sumir). O SVG é a mesma imagem em toda máquina. --}}
@php
    $currentLocale = app()->getLocale();
    $localeCodes = ['pt_BR' => 'PT', 'en' => 'EN', 'es' => 'ES'];
    $flagStyle = 'display:inline-flex;width:1.25rem;height:0.875rem;flex:none;overflow:hidden;border-radius:2px;box-shadow:0 0 0 1px rgb(0 0 0 / 0.1);';
@endphp

<style>
    /* Tokens do theme.css (importado pelo filament.css): o `.dark` do Filament
       redefine as variáveis e este menu acompanha sem nenhum utilitário. */
    .tws-locale { position: relative; }
    .tws-locale > summary {
        display: inline-flex; align-items: center; gap: 0.375rem;
        cursor: pointer; list-style: none;
        border: 1px solid var(--color-border); border-radius: 0.5rem;
        padding: 0.375rem 0.5rem; font-size: 0.875rem; color: inherit;
        min-height: 2.25rem;
    }
    .tws-locale > summary::-webkit-details-marker { display: none; }
    .tws-locale-menu {
        position: absolute; inset-inline-end: 0; z-index: 50; margin-top: 0.5rem;
        min-width: 13rem; overflow: hidden; padding: 0.25rem;
        border: 1px solid var(--color-border); border-radius: 0.75rem;
        background: var(--color-surface-raised);
        box-shadow: 0 10px 24px rgb(0 0 0 / 0.14);
    }
    .tws-locale-item {
        display: flex; align-items: center; gap: 0.625rem; min-height: 2.75rem;
        border-radius: 0.5rem; padding: 0.5rem 0.75rem;
        font-size: 0.875rem; color: inherit; text-decoration: none;
    }
    .tws-locale-item:hover { background: var(--color-surface-sunken); }
</style>

{{-- Alpine já vem com o Filament: fecha no Esc e no clique fora (abrir o
     menu do usuário do Filament conta como clique fora, e vice-versa —
     nunca ficam os dois abertos). --}}
<details
    class="tws-locale"
    x-data
    x-on:keydown.escape.window="$el.open = false"
    x-on:click.outside="$el.open = false"
>
    <summary
        aria-label="{{ __('ui.locale.label') }}"
        title="{{ __('ui.locale.label') }}"
        class="tws-locale-trigger"
    >
        <x-flag :locale="$currentLocale" style="{{ $flagStyle }}" />
        <span style="font-weight: 500;">{{ $localeCodes[$currentLocale] ?? strtoupper($currentLocale) }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:0.75rem;height:0.75rem;opacity:0.6;">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </summary>

    <div
        role="menu"
        class="tws-locale-menu"
    >
        @foreach (platform()->availableLocales as $locale)
            <a
                href="{{ route('locale.switch', $locale) }}"
                role="menuitem"
                @if ($currentLocale === $locale) aria-current="true" @endif
                class="tws-locale-item"
            >
                <x-flag :locale="$locale" style="{{ $flagStyle }}" />
                <span>{{ __("ui.locale.names.{$locale}") }}</span>
                @if ($currentLocale === $locale)
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:1rem;height:1rem;margin-inline-start:auto;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                @endif
            </a>
        @endforeach
    </div>
</details>
