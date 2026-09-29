@props([
    'align' => 'left',
])

{{-- SELETOR DE CONTA — <x-account-switcher />. Só faz sentido autenticado.

     Diz em que conta a pessoa está (o nome e o papel dela) em todo o painel e
     troca de conta: cada conta da lista é um formulário POST para a rota do
     pacote de contas (`accounts.switch`), que só aceita conta de que a pessoa
     é membro — a tela volta para a MESMA página, agora com os dados da conta
     escolhida. No fim, os atalhos para a página da conta e para criar uma
     conta de empresa.

     Teclado, Esc e clique fora vêm do <x-dropdown> do kit. Os dados vêm de
     App\Livewire\Support\AccountMenu (uma consulta). --}}
@php
    $menu = \App\Livewire\Support\AccountMenu::for(auth()->user());
    $current = $menu['current'];
@endphp

@if ($current !== null)
    <x-dropdown :align="$align" width="w-72" {{ $attributes->merge(['class' => 'w-full']) }} data-account-switcher>
        <x-slot:trigger>
            <button
                type="button"
                aria-label="{{ __('ui.account_switcher.label', ['account' => $current['name']]) }}"
                class="flex min-h-11 w-full items-center gap-3 rounded-lg border border-border bg-surface px-3 py-2 text-left transition-[background-color,border-color] duration-150 ease-(--ease-out) hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
            >
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-surface-sunken text-text-muted">
                    <x-ui-icon :name="$current['personal'] ? 'user-circle' : 'building-office'" class="h-4 w-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" data-current-account>{{ $current['name'] }}</span>
                    <span class="block truncate text-caption text-text-muted">{{ $current['personal'] ? __('accounts.personal_account') : $current['role'] }}</span>
                </span>
                <x-ui-icon name="chevron-up-down" class="h-4 w-4 shrink-0 text-text-muted" />
            </button>
        </x-slot:trigger>

        <p class="px-3 pb-1 pt-2 text-caption font-semibold uppercase tracking-widest text-text-muted">{{ __('ui.account_switcher.heading') }}</p>

        <div class="max-h-72 overflow-y-auto py-1">
            @foreach ($menu['accounts'] as $account)
                <form method="POST" action="{{ route('accounts.switch', $account['uuid']) }}">
                    @csrf
                    <x-dropdown-item type="submit" :aria-current="$account['current'] ? 'true' : null" data-account-option="{{ $account['uuid'] }}">
                        <x-ui-icon :name="$account['personal'] ? 'user-circle' : 'building-office'" class="h-4 w-4 text-text-muted" />
                        <span class="min-w-0 flex-1 text-left">
                            <span class="block truncate">{{ $account['name'] }}</span>
                            <span class="block truncate text-caption text-text-muted">{{ $account['personal'] ? __('accounts.personal_account').' · '.$account['role'] : $account['role'] }}</span>
                        </span>
                        @if ($account['current'])
                            <x-ui-icon name="check" class="h-4 w-4 shrink-0 text-brand" />
                        @endif
                    </x-dropdown-item>
                </form>
            @endforeach
        </div>

        <div class="border-t border-border pt-1">
            <x-dropdown-item :href="route('panel.account')">
                <x-ui-icon name="user-group" class="h-4 w-4 text-text-muted" />
                <span>{{ __('ui.account_switcher.manage') }}</span>
            </x-dropdown-item>
            <x-dropdown-item :href="route('panel.accounts.create')">
                <x-ui-icon name="plus" class="h-4 w-4 text-text-muted" />
                <span>{{ __('ui.account_switcher.create') }}</span>
            </x-dropdown-item>
        </div>
    </x-dropdown>
@endif
