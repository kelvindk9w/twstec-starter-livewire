@php
    use Twstec\Kit\Accounts\Account\Enums\AccountRole;
    use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;

    $roleColor = fn (AccountRole $r): string => match ($r) {
        AccountRole::Owner => 'brand',
        AccountRole::Admin => 'blue',
        AccountRole::Member => 'gray',
    };
    $removing = $removingUuid !== null ? $members->firstWhere('uuid', $removingUuid) : null;
    $revoking = $revokingInvitationUuid !== null ? $invitations->firstWhere('uuid', $revokingInvitationUuid) : null;
@endphp

{{-- Página da conta ATUAL: dados, membros (tabela ou cartões), convites e
     os fluxos do dono. Cada ação aparece só para o papel que pode fazê-la
     (a mesma regra que a Action do pacote aplica — e recusa com 403). --}}
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-display text-h1">{{ __('panel.account.title') }}</h1>
            <p class="mt-1.5 max-w-2xl text-sm text-text-muted">{{ __('panel.account.subtitle') }}</p>
        </div>
        @if ($role !== null)
            <x-badge :color="$roleColor($role)" data-my-role>{{ __('panel.account.your_role', ['role' => $role->label()]) }}</x-badge>
        @endif
    </div>

    @if (session('account_status'))
        <x-alert type="success" data-account-status>{{ session('account_status') }}</x-alert>
    @endif

    {{-- Dados da conta --}}
    <x-card :title="__('panel.account.details')">
        @if ($canRename)
            <x-slot:actions>
                @unless ($editingName)
                    <x-icon-button icon="pencil-square" :label="__('panel.account.rename')" wire:click="startRename" data-action="rename" />
                @endunless
            </x-slot:actions>
        @endif

        @if ($editingName)
            <form wire:submit="rename" class="flex flex-wrap items-end gap-3">
                <x-input class="min-w-0 flex-1" :label="__('panel.common.name')" name="accountName" wire:model="name" maxlength="255" :error="$errors->first('name')" />
                <div class="flex gap-2">
                    <x-button type="submit">{{ __('panel.common.save') }}</x-button>
                    <x-button type="button" variant="secondary" wire:click="cancelRename">{{ __('panel.common.cancel') }}</x-button>
                </div>
            </form>
        @else
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-caption font-medium uppercase tracking-wide text-text-muted">{{ __('panel.common.name') }}</dt>
                    <dd class="mt-1 truncate font-medium text-gray-900 dark:text-gray-100" data-account-name>{{ $account->displayName() }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-caption font-medium uppercase tracking-wide text-text-muted">{{ __('panel.account.code') }}</dt>
                    <dd class="mt-1"><code class="font-mono text-caption text-gray-900 dark:text-gray-100">{{ $account->codigo_publico }}</code></dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-caption font-medium uppercase tracking-wide text-text-muted">{{ __('panel.account.type') }}</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $account->isPersonal() ? __('accounts.personal_account') : __('panel.account.type_company') }}</dd>
                </div>
            </dl>
            @if ($account->isPersonal())
                <p class="mt-4 text-caption text-text-muted">{{ __('panel.account.personal_hint') }}</p>
            @endif
        @endif
    </x-card>

    {{-- Membros --}}
    <section class="space-y-3" data-members>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-h2 font-display text-gray-900 dark:text-gray-100">{{ __('panel.account.members') }}</h2>
                <p class="mt-1 text-caption text-text-muted">{{ trans_choice('panel.account.members_count', $members->count(), ['count' => $members->count()]) }}</p>
            </div>
            {{-- O alternador escolhe o layout do desktop; no celular a
                 tabela do kit já vira cartões. --}}
            <div class="hidden sm:block"><x-view-toggle :current="$view" /></div>
        </div>

        @if ($view === 'table')
            <x-table :headers="[__('panel.account.person'), __('panel.account.role'), __('panel.account.since'), __('panel.common.actions')]">
                @foreach ($members as $m)
                    <x-table-row wire:key="member-{{ $m['uuid'] }}" data-member="{{ $m['email'] }}">
                        <x-table-cell :label="__('panel.account.person')">
                            <span class="flex min-w-0 items-center gap-3">
                                <x-avatar :user="$m['user']" size="sm" />
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">
                                        {{ $m['name'] }}
                                        @if ($m['self'])
                                            <span class="text-caption font-normal text-text-muted">({{ __('panel.account.you') }})</span>
                                        @endif
                                    </span>
                                    <span class="block truncate text-caption text-text-muted">{{ $m['email'] }}</span>
                                </span>
                            </span>
                        </x-table-cell>
                        <x-table-cell :label="__('panel.account.role')">
                            <x-badge :color="$roleColor($m['role'])" data-member-role>{{ $m['role']->label() }}</x-badge>
                        </x-table-cell>
                        <x-table-cell :label="__('panel.account.since')">
                            <span class="text-caption text-text-muted">{{ $m['joined']?->translatedFormat('d M Y') }}</span>
                        </x-table-cell>
                        <x-table-cell :label="__('panel.common.actions')" align="end">
                            @include('livewire.account.partials.member-actions', ['m' => $m])
                        </x-table-cell>
                    </x-table-row>
                @endforeach
            </x-table>
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-members-cards>
                @foreach ($members as $m)
                    <x-card wire:key="member-card-{{ $m['uuid'] }}" data-member="{{ $m['email'] }}">
                        <div class="mb-3 flex min-w-0 items-center gap-3">
                            <x-avatar :user="$m['user']" size="md" />
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900 dark:text-gray-100">
                                    {{ $m['name'] }}
                                    @if ($m['self'])
                                        <span class="text-caption font-normal text-text-muted">({{ __('panel.account.you') }})</span>
                                    @endif
                                </p>
                                <p class="truncate text-caption text-text-muted">{{ $m['email'] }}</p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <x-badge :color="$roleColor($m['role'])" data-member-role>{{ $m['role']->label() }}</x-badge>
                            @include('livewire.account.partials.member-actions', ['m' => $m])
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    </section>

    @if ($canInvite)
        {{-- Convidar --}}
        <x-card :title="__('panel.account.invite')" :description="__('panel.account.invite_hint')">
            <form wire:submit="invite" class="flex flex-wrap items-end gap-3" data-invite-form>
                <x-input class="min-w-0 flex-[2_1_16rem]" :label="__('panel.account.invite_email')" name="inviteEmail" type="email" wire:model="inviteEmail" maxlength="255" autocomplete="off" :error="$errors->first('inviteEmail')" />
                <x-select class="min-w-0 flex-[1_1_10rem]" :label="__('panel.account.role')" name="inviteRole" wire:model="inviteRole" :error="$errors->first('inviteRole')">
                    <option value="member">{{ AccountRole::Member->label() }}</option>
                    <option value="admin">{{ AccountRole::Admin->label() }}</option>
                </x-select>
                <x-button type="submit">
                    <x-ui-icon name="user-plus" class="h-4 w-4" />
                    {{ __('panel.account.invite_submit') }}
                </x-button>
            </form>
        </x-card>

        {{-- Convites em aberto --}}
        <section class="space-y-3" data-invitations>
            <h2 class="text-h2 font-display text-gray-900 dark:text-gray-100">{{ __('panel.account.invitations') }}</h2>

            @error('invitations')
                <x-alert type="error">{{ $message }}</x-alert>
            @enderror

            @if ($invitations->isEmpty())
                <p class="text-sm text-text-muted" data-no-invitations>{{ __('panel.account.no_invitations') }}</p>
            @else
                <x-table :headers="[__('panel.account.invite_email'), __('panel.account.role'), __('panel.common.status'), __('panel.account.expires'), __('panel.common.actions')]">
                    @foreach ($invitations as $invitation)
                        @php $status = $invitation->status(); @endphp
                        <x-table-row wire:key="invitation-{{ $invitation->uuid }}" data-invitation="{{ $invitation->email }}">
                            <x-table-cell :label="__('panel.account.invite_email')">
                                <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $invitation->email }}</span>
                                @if ($invitation->creator)
                                    <span class="block truncate text-caption text-text-muted">{{ __('panel.account.invited_by', ['name' => $invitation->creator->name]) }}</span>
                                @endif
                            </x-table-cell>
                            <x-table-cell :label="__('panel.account.role')">
                                <x-badge :color="$roleColor($invitation->role)">{{ $invitation->role->label() }}</x-badge>
                            </x-table-cell>
                            <x-table-cell :label="__('panel.common.status')">
                                <x-badge :color="$status === InvitationStatus::Pending ? 'yellow' : 'red'">{{ $status->label() }}</x-badge>
                            </x-table-cell>
                            <x-table-cell :label="__('panel.account.expires')">
                                <span class="text-caption text-text-muted">{{ $invitation->expires_at->translatedFormat('d M Y H:i') }}</span>
                            </x-table-cell>
                            <x-table-cell :label="__('panel.common.actions')" align="end">
                                <span class="flex items-center justify-end gap-2">
                                    <x-icon-button icon="paper-airplane" :label="__('panel.account.resend')" wire:click="resendInvitation('{{ $invitation->uuid }}')" data-action="resend" />
                                    <x-icon-button icon="x-mark" color="red" :label="__('panel.account.revoke')" wire:click="startRevokeInvitation('{{ $invitation->uuid }}')" data-action="revoke" />
                                </span>
                            </x-table-cell>
                        </x-table-row>
                    @endforeach
                </x-table>
            @endif
        </section>
    @endif

    {{-- Propriedade e saída --}}
    @if ($canTransfer || $canLeave || $canDelete)
        <x-card :title="__('panel.account.ownership')" :description="__('panel.account.ownership_hint')">
            <div class="space-y-6">
                @if ($canTransfer)
                    <div data-transfer>
                        <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('panel.account.transfer') }}</h4>
                        <p class="mt-1 text-sm text-text-muted">{{ __('panel.account.transfer_hint') }}</p>

                        @if ($transferCandidates->isEmpty())
                            <p class="mt-3 text-caption text-text-muted">{{ __('panel.account.transfer_nobody') }}</p>
                        @else
                            <form wire:submit="requestTransfer" class="mt-3 flex flex-wrap items-end gap-3">
                                <x-select class="min-w-0 flex-[1_1_16rem]" :label="__('panel.account.transfer_to')" name="transferTo" wire:model="transferTo" :error="$errors->first('transferTo')">
                                    <option value="">{{ __('panel.account.transfer_choose') }}</option>
                                    @foreach ($transferCandidates as $candidate)
                                        <option value="{{ $candidate['uuid'] }}">{{ $candidate['name'] }} — {{ $candidate['email'] }}</option>
                                    @endforeach
                                </x-select>
                                <x-button type="submit" variant="secondary">
                                    <x-ui-icon name="arrows-right-left" class="h-4 w-4" />
                                    {{ __('panel.account.transfer_submit') }}
                                </x-button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($canLeave)
                    <div class="flex flex-wrap items-center justify-between gap-3" data-leave>
                        <div class="min-w-0">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('panel.account.leave') }}</h4>
                            <p class="mt-1 text-sm text-text-muted">{{ __('panel.account.leave_hint') }}</p>
                        </div>
                        <x-button type="button" variant="secondary" wire:click="startLeave">
                            <x-ui-icon name="arrow-left-start-on-rectangle" class="h-4 w-4" />
                            {{ __('panel.account.leave') }}
                        </x-button>
                    </div>
                @endif

                @if ($canDelete)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-6" data-delete-account>
                        <div class="min-w-0">
                            <h4 class="font-medium text-red-700 dark:text-red-400">{{ __('panel.account.delete') }}</h4>
                            <p class="mt-1 text-sm text-text-muted">{{ __('panel.account.delete_hint') }}</p>
                            @error('deleteAccount')
                                <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                        <x-button type="button" variant="danger" wire:click="requestDelete">
                            <x-ui-icon name="trash" class="h-4 w-4" />
                            {{ __('panel.account.delete') }}
                        </x-button>
                    </div>
                @endif

                @unless ($hasTransactionPassword)
                    @if ($canTransfer || $canDelete)
                        <x-alert type="info">
                            {{ __('panel.account.sensitive_requires_password') }}
                            <a href="{{ route('transaction-password.edit') }}" class="font-medium underline">{{ __('panel.nav.transaction_password') }}</a>
                        </x-alert>
                    @endif
                @endunless
            </div>
        </x-card>
    @endif

    {{-- Confirmações --}}
    @if ($removing !== null)
        <x-modal id="remove-member" :open="true" dismiss="cancelRemove" :title="__('panel.account.remove_title')">
            <p class="text-sm text-text-muted">{{ __('panel.account.remove_warning', ['name' => $removing['name'], 'account' => $account->displayName()]) }}</p>
            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelRemove">{{ __('panel.common.cancel') }}</x-button>
                <x-button type="button" variant="danger" wire:click="removeMember" data-confirm="remove">{{ __('panel.account.remove') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($revoking !== null)
        <x-modal id="revoke-invitation" :open="true" dismiss="cancelRevokeInvitation" :title="__('panel.account.revoke_title')">
            <p class="text-sm text-text-muted">{{ __('panel.account.revoke_warning', ['email' => $revoking->email]) }}</p>
            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelRevokeInvitation">{{ __('panel.common.cancel') }}</x-button>
                <x-button type="button" variant="danger" wire:click="revokeInvitation" data-confirm="revoke">{{ __('panel.account.revoke') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($confirmingLeave)
        <x-modal id="leave-account" :open="true" dismiss="cancelLeave" :title="__('panel.account.leave_title')">
            <p class="text-sm text-text-muted">{{ __('panel.account.leave_warning', ['account' => $account->displayName()]) }}</p>
            <x-slot:footer>
                <x-button type="button" variant="secondary" wire:click="cancelLeave">{{ __('panel.common.cancel') }}</x-button>
                <x-button type="button" variant="danger" wire:click="leave" data-confirm="leave">{{ __('panel.account.leave') }}</x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    @include('livewire.partials.sensitive-action-modal')
</div>
