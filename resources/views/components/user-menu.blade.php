{{-- Menu da conta — <x-user-menu />. Só faz sentido autenticado.

     O gatilho é o AVATAR (foto ou iniciais): num cabeçalho que é o mesmo do
     site, ele é o único elemento que diz "esta é a SUA sessão". Dentro:
     quem está logado, o atalho do perfil, o tema (os 3 estados nomeados, com
     ✓ no atual), a volta ao site e a saída.

     Teclado, Esc e clique fora vêm do <x-dropdown> do kit; o tema usa os
     MESMOS [data-theme-set]/[data-theme-check] do <x-theme-toggle> —
     resources/js/ui.js aplica em todas as instâncias da página. --}}
@php
    $user = auth()->user();
@endphp

@if ($user !== null)
    <x-dropdown {{ $attributes }} width="w-64">
        <x-slot:trigger>
            <button
                type="button"
                aria-label="{{ __('ui.nav.account_menu') }}"
                class="inline-flex h-11 w-11 items-center justify-center rounded-full transition-[transform,background-color] duration-150 ease-(--ease-out) hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100"
            >
                <x-avatar :user="$user" size="sm" />
            </button>
        </x-slot:trigger>

        <div class="border-b border-border px-3 pb-2.5 pt-2">
            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</p>
            <p class="truncate text-caption text-text-muted">{{ $user->email }}</p>
        </div>

        <div class="py-1">
            {{-- "Painel" primeiro: fora do painel (landing, /ui, telas de auth)
                 este menu é a ÚNICA porta de volta para a conta no desktop —
                 a gaveta com o "Minha conta" só existe abaixo de sm:. --}}
            <x-dropdown-item :href="route('dashboard')">
                <x-ui-icon name="squares-2x2" class="h-4 w-4 text-text-muted" />
                <span>{{ __('panel.nav.dashboard') }}</span>
            </x-dropdown-item>

            <x-dropdown-item :href="route('panel.profile')">
                <x-ui-icon name="user-circle" class="h-4 w-4 text-text-muted" />
                <span>{{ __('panel.nav.profile') }}</span>
            </x-dropdown-item>
        </div>

        <div class="border-t border-border pt-1">
            <p class="px-3 py-1 text-caption font-semibold uppercase tracking-widest text-text-muted">{{ __('ui.theme.label') }}</p>
            @foreach (['system' => 'computer-desktop', 'light' => 'sun', 'dark' => 'moon'] as $themeValue => $themeIcon)
                <x-dropdown-item data-theme-set="{{ $themeValue }}" aria-pressed="false">
                    <x-ui-icon :name="$themeIcon" class="h-4 w-4 text-text-muted" />
                    <span>{{ __("ui.theme.{$themeValue}") }}</span>
                    <x-ui-icon name="check" data-theme-check="{{ $themeValue }}" class="ms-auto hidden h-4 w-4 text-brand" />
                </x-dropdown-item>
            @endforeach
        </div>

        <div class="border-t border-border pt-1">
            <x-dropdown-item :href="url('/')">
                <x-ui-icon name="arrow-top-right-on-square" class="h-4 w-4 text-text-muted" />
                <span>{{ __('ui.nav.back_to_site') }}</span>
            </x-dropdown-item>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-dropdown-item type="submit">
                    <x-ui-icon name="arrow-right-on-rectangle" class="h-4 w-4 text-text-muted" />
                    <span>{{ __('auth.ui.logout') }}</span>
                </x-dropdown-item>
            </form>
        </div>
    </x-dropdown>
@endif
