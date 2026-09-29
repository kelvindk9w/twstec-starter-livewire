@php
    use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;

    $timezone = platform()->displayTimezone;
@endphp

<div class="space-y-6">
    {{-- Cabeçalho ------------------------------------------------------- --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="font-display text-h1">{{ __('panel.dashboard.greeting', ['name' => $user->name]) }}</h1>
            <p class="mt-1.5 flex flex-wrap items-center gap-2 text-caption text-text-muted">
                <span>{{ __('panel.dashboard.user_code') }}:</span>
                <code class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-gray-700 dark:text-gray-300">{{ $user->codigo_publico }}</code>
                <button
                    type="button"
                    data-copy="{{ $user->codigo_publico }}"
                    data-copied-text="{{ __('panel.common.copied') }}"
                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-caption text-text-muted transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-900 dark:hover:text-gray-100"
                >
                    <x-ui-icon name="clipboard-document" class="h-3.5 w-3.5" />
                    <span data-copy-label>{{ __('panel.common.copy') }}</span>
                </button>
            </p>
        </div>

        @kit('accounts')
        <div class="flex flex-wrap gap-2">
            <x-button :href="route('panel.api-keys')" size="sm">
                <x-ui-icon name="plus" class="h-4 w-4" />
                {{ __('panel.dashboard.new_api_key') }}
            </x-button>
            <x-button :href="route('panel.projects')" variant="secondary" size="sm">{{ __('panel.dashboard.new_project') }}</x-button>
        </div>
        @endkit
    </div>

    @kit('accounts')

    {{-- Métricas -------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat
            :label="__('panel.dashboard.summary_keys')"
            :value="$activeKeysCount"
            icon="key"
            :href="route('panel.api-keys')"
        />
        <x-stat
            :label="__('panel.dashboard.summary_projects')"
            :value="$projectsCount"
            icon="folder"
            :href="route('panel.projects')"
        />
        <x-stat
            :label="__('panel.dashboard.summary_requests', ['days' => $recentRequestsDays])"
            :value="number_format($recentRequestsCount, 0, ',', '.')"
            icon="signal"
        />
        <x-stat
            :label="__('panel.dashboard.summary_last_key_use')"
            :value="$lastKeyUsedAt ? \Illuminate\Support\Carbon::parse($lastKeyUsedAt)->setTimezone($timezone)->format('d/m H:i') : __('panel.common.never')"
            icon="clock"
            :hint="$lastKeyUsedAt ? \Illuminate\Support\Carbon::parse($lastKeyUsedAt)->diffForHumans() : __('panel.dashboard.last_key_use_empty')"
        />
    </div>

    {{-- Requisições por dia --------------------------------------------- --}}
    <x-card
        :title="__('panel.dashboard.chart_title', ['days' => $chartDays])"
        :description="__('panel.dashboard.chart_hint')"
    >
        <x-chart
            :labels="$chart['labels']"
            :values="$chart['values']"
            :label="__('panel.dashboard.chart_series')"
            :empty-title="__('panel.dashboard.chart_empty_title')"
            :empty-description="__('panel.dashboard.chart_empty_description')"
        />
    </x-card>

    {{-- Últimas chamadas da API ------------------------------------------ --}}
    <section class="space-y-3">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="font-display text-h2">{{ __('panel.dashboard.recent_calls_title') }}</h2>
            <p class="text-caption text-text-muted">{{ __('panel.dashboard.recent_calls_hint') }}</p>
        </div>

        @if ($recentCalls->isEmpty())
            <x-empty-state
                icon="signal"
                :title="__('panel.dashboard.recent_calls_empty_title')"
                :description="__('panel.dashboard.recent_calls_empty_description')"
            >
                <x-button :href="route('panel.api-keys')" size="sm">{{ __('panel.dashboard.new_api_key') }}</x-button>
            </x-empty-state>
        @else
            <x-table :headers="[
                __('panel.dashboard.call_endpoint'),
                __('panel.common.status'),
                __('panel.dashboard.call_when'),
            ]">
                @foreach ($recentCalls as $call)
                    @php
                        $badgeColor = match (true) {
                            $call->status === RequestLogStatus::Bloqueada, $call->status === RequestLogStatus::Erro => 'red',
                            $call->status === RequestLogStatus::Iniciada => 'yellow',
                            ($call->http_status_response ?? 200) >= 400 => 'yellow',
                            default => 'green',
                        };
                    @endphp
                    <x-table-row>
                        <x-table-cell :label="__('panel.dashboard.call_endpoint')">
                            <span class="flex min-w-0 items-center gap-2">
                                <x-badge class="shrink-0 font-mono">{{ $call->method }}</x-badge>
                                <code class="truncate font-mono text-caption text-gray-700 dark:text-gray-300">{{ $call->endpoint }}</code>
                            </span>
                        </x-table-cell>
                        <x-table-cell :label="__('panel.common.status')">
                            <x-badge :color="$badgeColor">{{ $call->http_status_response ?? $call->status->value }}</x-badge>
                        </x-table-cell>
                        <x-table-cell :label="__('panel.dashboard.call_when')">
                            <span class="text-caption text-text-muted">{{ $call->created_at?->setTimezone($timezone)->format('d/m/Y H:i') }}</span>
                        </x-table-cell>
                    </x-table-row>
                @endforeach
            </x-table>
        @endif
    </section>
    @else
    {{-- Sem o pacote de contas (twstec/kit-accounts): não há chaves, projetos
         nem API. A tela abre com os atalhos da conta da pessoa. --}}
    <x-card :title="__('panel.dashboard.essentials_title')" :description="__('panel.dashboard.essentials_hint')">
        <ul class="grid gap-3 sm:grid-cols-3" data-dashboard-essentials>
            @foreach ([
                ['route' => 'panel.profile', 'icon' => 'user-circle', 'label' => __('panel.nav.profile'), 'hint' => __('panel.dashboard.essentials_profile')],
                ['route' => 'transaction-password.edit', 'icon' => 'lock-closed', 'label' => __('panel.nav.transaction_password'), 'hint' => __('panel.dashboard.essentials_transaction_password')],
                ['route' => 'panel.notifications', 'icon' => 'bell', 'label' => __('panel.nav.notifications'), 'hint' => __('panel.dashboard.essentials_notifications')],
            ] as $atalho)
                <li>
                    <a
                        href="{{ route($atalho['route']) }}"
                        class="flex h-full items-start gap-3 rounded-lg border border-border bg-surface p-4 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                    >
                        <x-ui-icon :name="$atalho['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-text-muted" />
                        <span class="min-w-0">
                            <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $atalho['label'] }}</span>
                            <span class="mt-1 block text-caption text-text-muted">{{ $atalho['hint'] }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-card>
    @endkit
</div>
