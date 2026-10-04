@php
    $timezone = platform()->displayTimezone;
    $when = fn (?string $iso): string => $iso !== null ? \Illuminate\Support\Carbon::parse($iso)->setTimezone($timezone)->format('d/m/Y H:i') : '—';
    $deleting = $deletingUuid !== null ? collect($endpoints)->firstWhere('uuid', $deletingUuid) : null;
    $deliveriesEndpoint = $deliveriesFor !== null ? collect($endpoints)->firstWhere('uuid', $deliveriesFor) : null;
    $statusColor = ['succeeded' => 'green', 'failed' => 'red', 'retrying' => 'amber', 'pending' => 'gray', 'delivering' => 'blue'];
@endphp

<div class="space-y-6" data-testid="webhooks-page">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-h1">{{ __('webhooks.ui.title') }}</h1>
            <p class="mt-1.5 max-w-2xl text-sm text-text-muted">{{ __('webhooks.ui.subtitle') }}</p>
        </div>
        @if ($canManage && ! $showForm)
            <x-button type="button" wire:click="startCreate" size="sm" data-testid="webhook-new">
                <x-ui-icon name="plus" class="h-4 w-4" />
                {{ __('webhooks.ui.new') }}
            </x-button>
        @endif
    </div>

    @if (session('webhooks_status'))
        <x-alert type="success">{{ session('webhooks_status') }}</x-alert>
    @endif

    @error('webhooks')
        <x-alert type="error" data-testid="webhooks-error">{{ $message }}</x-alert>
    @enderror

    @unless ($canManage)
        <x-alert type="info">{{ __('webhooks.ui.read_only') }}</x-alert>
    @endunless

    @if ($canManage && ! $hasTransactionPassword)
        <x-alert type="warning">{{ __('webhooks.ui.sensitive_requires_password') }}</x-alert>
    @endif

    {{-- =====================================================================
         SEGREDO MOSTRADO UMA VEZ (criação, revelação ou rotação): guardado
         cifrado no servidor; aqui só até a pessoa confirmar que guardou.
         ==================================================================== --}}
    @if ($revealedSecret)
        <section class="rounded-xl border-2 border-amber-400 bg-amber-50 p-5 dark:border-amber-500 dark:bg-amber-950/40" data-testid="webhook-secret-panel">
            <h2 class="font-display text-h2 text-amber-900 dark:text-amber-100">{{ __('webhooks.ui.secret_title') }} — {{ $revealedFor }}</h2>
            <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-200">{{ __('webhooks.ui.secret_once') }}</p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <code class="break-all rounded bg-surface px-2 py-1 text-sm font-semibold" data-testid="webhook-secret">{{ $revealedSecret }}</code>
                <x-button type="button" size="sm" data-copy="{{ $revealedSecret }}" data-copied-text="{{ __('webhooks.ui.copied') }}">
                    <x-ui-icon name="clipboard-document" class="h-4 w-4" />
                    <span data-copy-label>{{ __('webhooks.ui.copy') }}</span>
                </x-button>
            </div>

            <p class="mt-4 text-caption text-amber-800 dark:text-amber-200">{{ __('webhooks.ui.signature_help') }}</p>

            <x-button type="button" variant="secondary" class="mt-5" wire:click="dismissSecret" data-testid="webhook-secret-done">
                {{ __('webhooks.ui.secret_saved') }}
            </x-button>
        </section>
    @endif

    {{-- =====================================================================
         FORMULÁRIO (criar e editar — mesma tela)
         ==================================================================== --}}
    @if ($showForm)
        <x-card :title="$editingUuid === null ? __('webhooks.ui.new') : __('webhooks.ui.edit')">
            <form wire:submit="requestSave" class="space-y-6" data-testid="webhook-form">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input
                        :label="__('webhooks.ui.name')"
                        name="webhookName"
                        wire:model="name"
                        maxlength="100"
                        :error="$errors->first('name')"
                    />
                    <x-input
                        :label="__('webhooks.ui.url')"
                        name="webhookUrl"
                        type="url"
                        wire:model="url"
                        placeholder="https://"
                        :hint="__('webhooks.ui.url_hint')"
                        :error="$errors->first('url')"
                    />
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('webhooks.ui.events') }}</legend>
                    <p class="mt-1 text-caption text-text-muted">{{ __('webhooks.ui.events_hint') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($eventOptions as $option)
                            <x-checkbox
                                class="rounded-lg border border-border px-3"
                                wire:model="events"
                                value="{{ $option['value'] }}"
                                data-webhook-event="{{ $option['value'] }}"
                                :label="$option['label']"
                            />
                        @endforeach
                    </div>
                    @if ($errors->has('events') || $errors->has('events.*'))
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $errors->first('events') ?: $errors->first('events.*') }}</p>
                    @endif
                </fieldset>

                <x-select :label="__('webhooks.ui.project')" name="webhookProject" wire:model="project" :error="$errors->first('project')">
                    <option value="">{{ __('webhooks.ui.project_all') }}</option>
                    @foreach ($projectOptions as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </x-select>

                <div class="flex flex-wrap gap-2">
                    <x-button type="submit" data-testid="webhook-save">{{ __('webhooks.ui.save') }}</x-button>
                    <x-button type="button" variant="secondary" wire:click="cancelForm">{{ __('webhooks.ui.cancel') }}</x-button>
                </div>
            </form>
        </x-card>
    @endif

    {{-- =====================================================================
         ENDPOINTS
         ==================================================================== --}}
    @if ($endpoints === [])
        @unless ($showForm)
            <x-empty-state icon="bolt" :title="__('webhooks.ui.empty')" :description="__('webhooks.ui.empty_hint')">
                @if ($canManage)
                    <x-button type="button" wire:click="startCreate">{{ __('webhooks.ui.new') }}</x-button>
                @endif
            </x-empty-state>
        @endunless
    @else
        <x-table :headers="[__('webhooks.ui.name'), __('webhooks.ui.events'), __('webhooks.ui.status'), '']">
            @foreach ($endpoints as $endpoint)
                <x-table-row data-testid="webhook-endpoint" data-endpoint="{{ $endpoint['uuid'] }}">
                    <x-table-cell :label="__('webhooks.ui.name')">
                        <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $endpoint['name'] }}</span>
                        <code class="mt-0.5 block truncate font-mono text-caption text-text-muted">{{ $endpoint['url'] }}</code>
                        @if ($endpoint['project'])
                            <span class="mt-0.5 block text-caption text-text-muted">{{ __('webhooks.ui.project') }}: {{ $endpoint['project']['name'] }}</span>
                        @endif
                        @if ($endpoint['previous_secret_expires_at'])
                            <span class="mt-0.5 block text-caption text-amber-700 dark:text-amber-300">{{ __('webhooks.ui.previous_secret_until', ['date' => $when($endpoint['previous_secret_expires_at'])]) }}</span>
                        @endif
                    </x-table-cell>

                    <x-table-cell :label="__('webhooks.ui.events')">
                        <span class="text-caption text-text-muted">{{ implode(', ', $endpoint['event_labels']) }}</span>
                    </x-table-cell>

                    <x-table-cell :label="__('webhooks.ui.status')">
                        <x-badge :color="$endpoint['active'] ? 'green' : 'gray'" data-testid="webhook-status">{{ $endpoint['status_label'] }}</x-badge>
                        @if ($endpoint['disabled_reason'] === 'failures')
                            <span class="mt-1 block text-caption text-red-700 dark:text-red-300">{{ __('webhooks.ui.disabled_by_failures', ['count' => $endpoint['consecutive_failures']]) }}</span>
                        @elseif ($endpoint['consecutive_failures'] > 0)
                            <span class="mt-1 block text-caption text-amber-700 dark:text-amber-300">{{ __('webhooks.ui.failures', ['count' => $endpoint['consecutive_failures']]) }}</span>
                        @endif
                        @if ($endpoint['last_success_at'])
                            <span class="mt-1 block text-caption text-text-muted">{{ __('webhooks.ui.last_success', ['date' => $when($endpoint['last_success_at'])]) }}</span>
                        @endif
                    </x-table-cell>

                    <x-table-cell label="" align="end">
                        <span class="flex flex-wrap items-center justify-end gap-2">
                            <x-button type="button" variant="secondary" size="sm" wire:click="showDeliveries('{{ $endpoint['uuid'] }}')" data-testid="webhook-show-deliveries">{{ __('webhooks.ui.show_deliveries') }}</x-button>

                            @if ($canManage)
                                @if ($endpoint['active'])
                                    <x-button type="button" variant="secondary" size="sm" wire:click="sendTest('{{ $endpoint['uuid'] }}')" data-testid="webhook-send-test">
                                        <x-ui-icon name="paper-airplane" class="h-4 w-4" />
                                        {{ __('webhooks.ui.send_test') }}
                                    </x-button>
                                @endif

                                <x-dropdown>
                                    <x-slot:trigger>
                                        <button
                                            type="button"
                                            aria-label="{{ __('panel.common.more_actions') }}"
                                            data-testid="webhook-more"
                                            class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border text-gray-500 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-900 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:text-gray-100"
                                        >
                                            <x-ui-icon name="ellipsis-horizontal" class="h-4 w-4" />
                                        </button>
                                    </x-slot:trigger>
                                    <x-dropdown-item wire:click="startEdit('{{ $endpoint['uuid'] }}')">
                                        <x-ui-icon name="pencil-square" class="h-4 w-4" />
                                        {{ __('webhooks.ui.edit') }}
                                    </x-dropdown-item>
                                    <x-dropdown-item wire:click="startReveal('{{ $endpoint['uuid'] }}')" data-testid="webhook-reveal">
                                        <x-ui-icon name="eye" class="h-4 w-4" />
                                        {{ __('webhooks.ui.reveal') }}
                                    </x-dropdown-item>
                                    <x-dropdown-item wire:click="startRotate('{{ $endpoint['uuid'] }}')" data-testid="webhook-rotate">
                                        <x-ui-icon name="arrow-path" class="h-4 w-4" />
                                        {{ __('webhooks.ui.rotate') }}
                                    </x-dropdown-item>
                                    <x-dropdown-item wire:click="toggleStatus('{{ $endpoint['uuid'] }}', {{ $endpoint['active'] ? 'false' : 'true' }})" data-testid="webhook-toggle">
                                        <x-ui-icon name="{{ $endpoint['active'] ? 'x-circle' : 'check-circle' }}" class="h-4 w-4" />
                                        {{ $endpoint['active'] ? __('webhooks.ui.disable') : __('webhooks.ui.enable') }}
                                    </x-dropdown-item>
                                    <x-dropdown-item danger wire:click="startDelete('{{ $endpoint['uuid'] }}')" data-testid="webhook-delete">
                                        <x-ui-icon name="trash" class="h-4 w-4" />
                                        {{ __('webhooks.ui.delete') }}
                                    </x-dropdown-item>
                                </x-dropdown>
                            @endif
                        </span>
                    </x-table-cell>
                </x-table-row>
            @endforeach
        </x-table>
    @endif

    {{-- =====================================================================
         LOG DE ENTREGAS (com as tentativas e o reenvio)
         ==================================================================== --}}
    @if ($endpoints !== [])
        <section class="space-y-3" data-testid="webhook-deliveries">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display text-h2">
                    {{ __('webhooks.ui.deliveries') }}
                    <span class="text-sm font-normal text-text-muted">— {{ $deliveriesEndpoint['name'] ?? __('webhooks.ui.all_endpoints') }}</span>
                </h2>
                @if ($deliveriesFor !== null)
                    <x-button type="button" variant="secondary" size="sm" wire:click="showDeliveries">{{ __('webhooks.ui.all_endpoints') }}</x-button>
                @endif
            </div>

            @forelse ($deliveries as $delivery)
                <details class="rounded-lg border border-border bg-surface p-3" data-testid="webhook-delivery" data-status="{{ $delivery['status'] }}">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                        <x-badge :color="$statusColor[$delivery['status']] ?? 'gray'">{{ $delivery['status_label'] }}</x-badge>
                        <code class="font-mono text-caption">{{ $delivery['event']['type'] }}</code>
                        <span class="text-caption text-text-muted">{{ $delivery['endpoint']['name'] }}</span>
                        <span class="text-caption text-text-muted">· {{ __('webhooks.ui.attempts') }}: {{ $delivery['attempts'] }}</span>
                        @if ($delivery['response_status'])
                            <span class="text-caption text-text-muted">· HTTP {{ $delivery['response_status'] }}</span>
                        @endif
                        @if ($delivery['duration_ms'] !== null)
                            <span class="text-caption text-text-muted">· {{ __('webhooks.ui.duration', ['ms' => $delivery['duration_ms']]) }}</span>
                        @endif
                        <span class="text-caption text-text-muted">· {{ $when($delivery['created_at']) }}</span>
                        @if ($canManage)
                            <x-button type="button" variant="secondary" size="sm" class="ml-auto" wire:click="resend('{{ $delivery['uuid'] }}')" data-testid="webhook-resend">
                                <x-ui-icon name="arrow-path" class="h-4 w-4" />
                                {{ __('webhooks.ui.resend') }}
                            </x-button>
                        @endif
                    </summary>

                    <div class="mt-3 space-y-2 text-caption">
                        <p class="text-text-muted">{{ __('webhooks.ui.event') }}: <code>{{ $delivery['event']['uuid'] }}</code></p>
                        @if ($delivery['next_attempt_at'])
                            <p class="text-text-muted">{{ __('webhooks.ui.next_attempt', ['date' => $when($delivery['next_attempt_at'])]) }}</p>
                        @endif
                        @foreach ($delivery['attempt_log'] as $attempt)
                            <div class="rounded border border-border p-2" data-testid="webhook-attempt">
                                <p class="font-medium">
                                    {{ __('webhooks.ui.attempt', ['number' => $attempt['attempt']]) }}
                                    @if ($attempt['manual']) <span class="text-text-muted">({{ __('webhooks.ui.manual') }})</span> @endif
                                    — {{ $attempt['outcome_label'] }}
                                    @if ($attempt['response_status']) · HTTP {{ $attempt['response_status'] }} @endif
                                    @if ($attempt['duration_ms'] !== null) · {{ __('webhooks.ui.duration', ['ms' => $attempt['duration_ms']]) }} @endif
                                    · {{ $when($attempt['created_at']) }}
                                </p>
                                @if ($attempt['error'])
                                    <p class="mt-1 text-red-700 dark:text-red-300">{{ $attempt['error'] }}</p>
                                @endif
                                @if ($attempt['response_excerpt'])
                                    <p class="mt-1 text-text-muted">{{ __('webhooks.ui.response') }}:</p>
                                    <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all rounded bg-surface-sunken p-2 font-mono">{{ $attempt['response_excerpt'] }}</pre>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>
            @empty
                <p class="text-sm text-text-muted">{{ __('webhooks.ui.deliveries_empty') }}</p>
            @endforelse
        </section>
    @endif

    {{-- =====================================================================
         MODAL: rotação (convivência dos dois segredos)
         ==================================================================== --}}
    @if ($rotatingUuid && ! $pendingAction)
        <x-modal id="rotate-webhook-secret" :open="true" dismiss="cancelRotate" :title="__('webhooks.ui.rotate')" :description="__('webhooks.ui.rotate_hint')">
            <x-input
                :label="__('webhooks.ui.rotate_overlap')"
                name="overlapMinutes"
                type="number"
                min="0"
                wire:model="overlapMinutes"
                :error="$errors->first('overlapMinutes')"
            />

            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelRotate">{{ __('webhooks.ui.cancel') }}</x-button>
                <x-button type="button" wire:click="requestRotate" data-testid="webhook-rotate-confirm">{{ __('panel.common.confirm') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- =====================================================================
         MODAL: exclusão
         ==================================================================== --}}
    @if ($deleting !== null)
        <x-modal id="delete-webhook" :open="true" dismiss="cancelDelete" :title="__('webhooks.ui.delete')">
            {{ __('webhooks.ui.delete_confirm') }}
            <p class="mt-2 font-medium">{{ $deleting['name'] }}</p>

            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelDelete">{{ __('webhooks.ui.cancel') }}</x-button>
                <x-button type="button" variant="danger" wire:click="removeEndpoint" data-testid="webhook-delete-confirm">{{ __('webhooks.ui.delete') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    @include('livewire.partials.sensitive-action-modal')
</div>
