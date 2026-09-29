@php
    use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;

    $timezone = platform()->displayTimezone;
    $revokingKey = $revokingKeyUuid !== null ? $keys->firstWhere('uuid', $revokingKeyUuid) : null;
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-h1">{{ __('panel.api_keys.title') }}</h1>
            <p class="mt-1.5 max-w-2xl text-sm text-text-muted">{{ __('panel.api_keys.subtitle') }}</p>
        </div>
        {{-- Gerir chaves: owner ou admin da conta (AccountAbility::ManageApiKeys). --}}
        @if ($canManageKeys && ! $showCreateForm)
            <x-button type="button" wire:click="startCreate" size="sm">
                <x-ui-icon name="plus" class="h-4 w-4" />
                {{ __('panel.api_keys.new') }}
            </x-button>
        @endif
    </div>

    @if (session('keys_status'))
        <x-alert type="success">{{ session('keys_status') }}</x-alert>
    @endif

    {{-- =====================================================================
         VISUALIZAÇÃO ÚNICA DA SECRETA: exibida 1x, sem recuperação.
         O peso visual é proposital — é a única tela do kit em que perder a
         atenção do usuário custa uma credencial.
         ==================================================================== --}}
    @if ($revealedSecretKey)
        <section class="rounded-xl border-2 border-amber-400 bg-amber-50 p-5 dark:border-amber-500 dark:bg-amber-950/40">
            <h2 class="font-display text-h2 text-amber-900 dark:text-amber-100">{{ __('panel.api_keys.secret_heading') }}</h2>
            <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-200">{{ __('panel.api_keys.secret_warning') }}</p>

            <dl class="mt-4 space-y-3">
                <div>
                    <dt class="text-caption font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('panel.api_keys.public_key') }}</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2">
                        <code class="break-all rounded bg-surface px-2 py-1 text-sm" data-testid="revealed-public-key">{{ $revealedPublicKey }}</code>
                        <x-button
                            type="button"
                            variant="secondary"
                            size="sm"
                            data-copy="{{ $revealedPublicKey }}"
                            data-copied-text="{{ __('panel.api_keys.copied') }}"
                        >
                            <x-ui-icon name="clipboard-document" class="h-4 w-4" />
                            <span data-copy-label>{{ __('panel.api_keys.copy') }}</span>
                        </x-button>
                    </dd>
                </div>
                <div>
                    <dt class="text-caption font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('panel.api_keys.secret_key') }}</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2">
                        <code class="break-all rounded bg-surface px-2 py-1 text-sm font-semibold" data-testid="revealed-secret-key">{{ $revealedSecretKey }}</code>
                        <x-button
                            type="button"
                            size="sm"
                            data-copy="{{ $revealedSecretKey }}"
                            data-copied-text="{{ __('panel.api_keys.copied') }}"
                        >
                            <x-ui-icon name="clipboard-document" class="h-4 w-4" />
                            <span data-copy-label>{{ __('panel.api_keys.copy') }}</span>
                        </x-button>
                    </dd>
                </div>
            </dl>

            <x-button type="button" variant="secondary" class="mt-5" wire:click="dismissSecret">
                {{ __('panel.api_keys.secret_done') }}
            </x-button>
        </section>
    @endif

    {{-- =====================================================================
         FORMULÁRIO DE CRIAÇÃO (inline — mesma tela)
         ==================================================================== --}}
    @if ($showCreateForm)
        <x-card :title="__('panel.api_keys.create_heading')">
            <form wire:submit="requestCreate" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input
                        :label="__('panel.common.name')"
                        name="keyName"
                        wire:model="name"
                        maxlength="100"
                        :placeholder="__('panel.api_keys.name_placeholder')"
                        :error="$errors->first('name')"
                    />
                    <x-input
                        :label="__('panel.api_keys.expires_at').' ('.__('panel.common.optional').')'"
                        name="expiresAt"
                        type="datetime-local"
                        wire:model="expiresAt"
                        :hint="__('panel.api_keys.expires_hint')"
                        :error="$errors->first('expiresAt')"
                    />
                </div>

                {{-- Scopes: toggle "todas" (padrão) + seleção granular --}}
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('panel.api_keys.scopes_heading') }}</legend>

                    <div class="mt-2 rounded-lg border border-border bg-surface-sunken p-4">
                        <x-toggle wire:model.live="allScopes" :checked="$allScopes">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ __('panel.api_keys.scopes_all') }}</span>
                        </x-toggle>
                        <p class="mt-1 text-caption text-text-muted">{{ __('panel.api_keys.scopes_all_hint') }}</p>
                    </div>

                    @unless ($allScopes)
                        <p class="mt-3 text-caption text-text-muted">{{ __('panel.api_keys.scopes_hint') }}</p>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($scopesCatalog as $resource => $actions)
                                <div class="rounded-lg border border-border p-3">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('panel.api_keys.scope_resource_'.$resource) }}</p>
                                    <div class="mt-1.5 flex flex-col gap-1">
                                        @foreach ($actions as $action)
                                            <x-checkbox
                                                wire:model="selectedScopes"
                                                value="{{ $resource }}:{{ $action }}"
                                                :label="__('panel.api_keys.scope_action_'.$action)"
                                            />
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @error('selectedScopes')
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    @endunless
                </fieldset>

                {{-- Vínculo N:N com projetos (vazio = conta toda) --}}
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('panel.api_keys.projects_heading') }}</legend>
                    <p class="mt-1 text-caption text-text-muted">{{ __('panel.api_keys.projects_hint') }}</p>
                    @if ($projects->isEmpty())
                        <p class="mt-2 text-sm text-text-muted">{{ __('panel.api_keys.projects_empty') }}</p>
                    @else
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($projects as $project)
                                <x-checkbox
                                    class="rounded-lg border border-border px-3"
                                    wire:model="selectedProjectUuids"
                                    value="{{ $project->uuid }}"
                                    :label="$project->name"
                                />
                            @endforeach
                        </div>
                    @endif
                    @error('selectedProjectUuids')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div class="flex flex-wrap gap-2">
                    <x-button type="submit">{{ __('panel.common.create') }}</x-button>
                    <x-button type="button" variant="secondary" wire:click="$set('showCreateForm', false)">{{ __('panel.common.cancel') }}</x-button>
                </div>
            </form>
        </x-card>
    @endif

    {{-- =====================================================================
         LISTAGEM (cada chave com status, último uso, validade e ações)
         ==================================================================== --}}
    @if ($keys->isEmpty())
        @unless ($showCreateForm)
            <x-empty-state
                icon="key"
                :title="__('panel.api_keys.empty_title')"
                :description="__('panel.api_keys.empty')"
            >
                @if ($canManageKeys)
                    <x-button type="button" wire:click="startCreate">{{ __('panel.api_keys.new') }}</x-button>
                @endif
            </x-empty-state>
        @endunless
    @else
        <x-table :headers="[
            __('panel.common.name'),
            __('panel.api_keys.public_key'),
            __('panel.api_keys.last_used'),
            __('panel.api_keys.expires_at'),
            __('panel.common.actions'),
        ]">
            @foreach ($keys as $key)
                @php
                    $statusLabel = match ($key->status) {
                        ApiKeyStatus::Active => __('panel.api_keys.status_active'),
                        ApiKeyStatus::Revoked => __('panel.api_keys.status_revoked'),
                        ApiKeyStatus::Expired => __('panel.api_keys.status_expired'),
                        ApiKeyStatus::ExpiredInactivity => __('panel.api_keys.status_expired_inactivity'),
                        ApiKeyStatus::Rotated => __('panel.api_keys.status_rotated'),
                    };
                    $inGrace = $key->grace_ends_at !== null && $key->grace_ends_at->isFuture();
                @endphp
                <x-table-row>
                    <x-table-cell :label="__('panel.common.name')">
                        <span class="flex min-w-0 flex-wrap items-center gap-2">
                            <span class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $key->name }}</span>
                            <x-badge :color="$key->isUsable() ? 'green' : 'gray'">{{ $inGrace ? __('panel.api_keys.status_grace') : $statusLabel }}</x-badge>
                        </span>
                        <span class="mt-1 block text-caption text-text-muted sm:mt-0.5">
                            {{ __('panel.api_keys.projects_heading') }}:
                            {{ ! $key->isRestrictedToProjects() ? __('panel.api_keys.whole_account') : ($key->projects->isEmpty() ? __('panel.api_keys.no_projects') : $key->projects->pluck('name')->implode(', ')) }}
                        </span>
                    </x-table-cell>

                    <x-table-cell :label="__('panel.api_keys.public_key')">
                        {{-- A chave pública é feita para ser COLADA num
                             cliente HTTP: selecionar 40 caracteres à mão não
                             é uma interface. O comportamento data-copy já
                             existia no kit; faltava usá-lo aqui. --}}
                        <span class="flex min-w-0 items-center gap-1.5">
                            <code class="truncate font-mono text-caption text-text-muted">{{ $key->public_key }}</code>
                            <button
                                type="button"
                                data-copy="{{ $key->public_key }}"
                                data-copy-toast="clipboard-toast|{{ __('panel.api_keys.copied_public') }}"
                                aria-label="{{ __('panel.api_keys.copy_public') }}"
                                title="{{ __('panel.api_keys.copy_public') }}"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-400 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-700 dark:hover:text-gray-200"
                            >
                                <x-ui-icon name="clipboard-document" class="h-4 w-4" />
                            </button>
                        </span>
                    </x-table-cell>

                    <x-table-cell :label="__('panel.api_keys.last_used')">
                        <span class="text-caption text-text-muted">{{ $key->last_used_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? __('panel.common.never') }}</span>
                    </x-table-cell>

                    <x-table-cell :label="__('panel.api_keys.expires_at')">
                        <span class="text-caption text-text-muted">{{ $key->expires_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? __('panel.api_keys.no_expiration') }}</span>
                    </x-table-cell>

                    <x-table-cell :label="__('panel.common.actions')" align="end">
                        @if ($key->isUsable() && $canManageKeys)
                            {{-- Ações de ROTINA como secundárias pequenas;
                                 REVOGAR (irreversível) vai para o menu de
                                 overflow. Antes as três tinham o mesmo peso
                                 e ficavam encostadas: mirar "Rotacionar" e
                                 acertar "Revogar" destruía uma credencial. --}}
                            <span class="flex items-center justify-end gap-2">
                                <x-button type="button" variant="secondary" size="sm" wire:click="startEditProjects('{{ $key->uuid }}')">{{ __('panel.api_keys.edit_projects') }}</x-button>
                                <x-button type="button" variant="secondary" size="sm" wire:click="startRotate('{{ $key->uuid }}')">{{ __('panel.api_keys.rotate') }}</x-button>

                                <x-dropdown>
                                    <x-slot:trigger>
                                        <button
                                            type="button"
                                            aria-label="{{ __('panel.common.more_actions') }}"
                                            class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-border text-gray-500 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken hover:text-gray-900 sm:h-8 sm:w-8 dark:text-gray-400 dark:hover:text-gray-100"
                                        >
                                            <x-ui-icon name="ellipsis-horizontal" class="h-4 w-4" />
                                        </button>
                                    </x-slot:trigger>
                                    <x-dropdown-item danger wire:click="startRevoke('{{ $key->uuid }}')">
                                        <x-ui-icon name="trash" class="h-4 w-4" />
                                        {{ __('panel.api_keys.revoke') }}
                                    </x-dropdown-item>
                                </x-dropdown>
                            </span>
                        @else
                            <span class="text-caption text-text-muted">—</span>
                        @endif
                    </x-table-cell>
                </x-table-row>
            @endforeach
        </x-table>
    @endif

    {{-- Toast do botão de copiar da chave pública (o botão é só ícone: não
         há rótulo para trocar, então o feedback vem daqui). --}}
    <div class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col items-end gap-2">
        <x-toast id="clipboard-toast" class="hidden">{{ __('panel.api_keys.copied_public') }}</x-toast>
    </div>

    {{-- =====================================================================
         MODAL: rotação (escolha do grace period)

         `! $pendingAction` fecha ESTE modal quando a confirmação de segurança
         abre: os dois ficavam empilhados na tela, o de trás visível através
         do backdrop do da frente.
         ==================================================================== --}}
    @if ($rotatingKeyUuid && ! $pendingAction)
        <x-modal
            id="rotate-key"
            :open="true"
            dismiss="cancelRotate"
            :title="__('panel.api_keys.rotate_title')"
            :description="__('panel.api_keys.rotate_warning')"
        >
            <p class="text-caption text-text-muted">{{ __('panel.api_keys.grace_hint') }}</p>

            <div class="mt-4 flex flex-col gap-2">
                @foreach ([0 => 'grace_immediate', 60 => 'grace_1h', 1440 => 'grace_24h', 10080 => 'grace_7d'] as $minutes => $labelKey)
                    <label class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border border-border px-3 py-2 text-sm text-gray-700 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken dark:text-gray-200">
                        <input type="radio" wire:model="gracePeriodMinutes" value="{{ $minutes }}" class="h-4 w-4 border-border-strong text-brand accent-brand">
                        {{ __('panel.api_keys.'.$labelKey) }}
                    </label>
                @endforeach
            </div>
            @error('gracePeriodMinutes')
                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelRotate">{{ __('panel.common.cancel') }}</x-button>
                <x-button type="button" wire:click="requestRotate">{{ __('panel.common.confirm') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- =====================================================================
         MODAL: revogação (irreversível)
         ==================================================================== --}}
    @if ($revokingKey !== null)
        <x-modal
            id="revoke-key"
            :open="true"
            dismiss="cancelRevoke"
            :title="__('panel.api_keys.revoke_title')"
        >
            {{ __('panel.api_keys.revoke_warning', ['name' => $revokingKey->name]) }}

            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelRevoke">{{ __('panel.common.cancel') }}</x-button>
                <x-button type="button" variant="danger" wire:click="revoke">{{ __('panel.api_keys.revoke') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- =====================================================================
         MODAL: vínculo N:N chave ↔ projetos
         ==================================================================== --}}
    @if ($editingProjectsKeyUuid)
        <x-modal
            id="edit-key-projects"
            :open="true"
            dismiss="cancelEditProjects"
            :title="__('panel.api_keys.projects_heading')"
            :description="__('panel.api_keys.projects_hint')"
        >
            <div class="flex flex-col gap-2">
                @forelse ($projects as $project)
                    <x-checkbox
                        class="rounded-lg border border-border px-3"
                        wire:model="editingProjectsSelection"
                        value="{{ $project->uuid }}"
                        :label="$project->name"
                    />
                @empty
                    <p class="text-sm text-text-muted">{{ __('panel.api_keys.projects_empty') }}</p>
                @endforelse
            </div>

            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelEditProjects">{{ __('panel.common.cancel') }}</x-button>
                <x-button type="button" wire:click="saveProjects">{{ __('panel.common.save') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    {{-- =====================================================================
         MODAL: AÇÃO SENSÍVEL (senha de transação → código por e-mail → confirma)
         Partial compartilhado com o Perfil (trait ConfirmsSensitiveAction).
         ==================================================================== --}}
    @include('livewire.partials.sensitive-action-modal')
</div>
