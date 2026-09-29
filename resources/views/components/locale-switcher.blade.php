{{-- Seletor de idioma do kit. <x-locale-switcher />

     Era um <select> NATIVO com bandeira em emoji, ao lado de dois botões
     desenhados: a lista de opções era renderizada pelo sistema operacional
     (aparência diferente em cada máquina) e o emoji dependia da fonte
     instalada. Agora é o <x-dropdown> do kit: bandeira em SVG inline + nome
     do idioma por extenso + ✓ no ativo, acessível por teclado nos 2 temas.

     Cada item é um LINK real para a rota locale.switch (cookie + preferência
     da conta) — funciona sem JS de formulário e abre em nova aba se o usuário
     quiser. O mesmo componente é usado na landing, no painel e na topbar do
     /admin (via render hook do Filament). --}}
@php
    $currentLocale = app()->getLocale();
    // Sigla curta exibida no gatilho (a lista traz o nome por extenso).
    $localeCodes = ['pt_BR' => 'PT', 'en' => 'EN', 'es' => 'ES'];
@endphp

<x-dropdown {{ $attributes }} width="w-52">
    <x-slot:trigger>
        <button
            type="button"
            aria-label="{{ __('ui.locale.label') }}"
            title="{{ __('ui.locale.label') }}"
            class="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-border px-2.5 py-2 text-sm text-gray-600 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken sm:min-h-0 sm:py-1.5 dark:text-gray-300"
        >
            <x-flag :locale="$currentLocale" />
            <span class="font-medium">{{ $localeCodes[$currentLocale] ?? strtoupper($currentLocale) }}</span>
            <x-ui-icon name="chevron-down" class="h-3.5 w-3.5 text-gray-400" />
        </button>
    </x-slot:trigger>

    @foreach (platform()->availableLocales as $locale)
        <x-dropdown-item :href="route('locale.switch', $locale)" :active="$currentLocale === $locale">
            <x-flag :locale="$locale" />
            <span>{{ __("ui.locale.names.{$locale}") }}</span>
        </x-dropdown-item>
    @endforeach
</x-dropdown>
