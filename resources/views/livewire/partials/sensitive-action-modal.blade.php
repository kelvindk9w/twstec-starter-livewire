{{-- Confirmação de AÇÃO SENSÍVEL do painel: senha de transação → código por
     e-mail → executa. Um modal só para todas as telas que pedem a confirmação
     (chaves de API, verificação em duas etapas no Perfil); o estado e as ações
     vêm do trait App\Livewire\Concerns\ConfirmsSensitiveAction.

     `$sensitiveDescription` (opcional) diz O QUE está sendo confirmado. --}}
@if ($pendingAction)
    <x-modal
        id="sensitive-action"
        :open="true"
        dismiss="cancelSensitiveAction"
        :title="__('panel.sensitive.heading')"
        :description="$sensitiveDescription ?? null"
    >
        @unless ($codeSent)
            <form wire:submit="sendSensitiveCode" class="space-y-4">
                <p class="text-sm text-text-muted">{{ __('panel.sensitive.password_hint') }}</p>

                <x-input
                    :label="__('auth.ui.transaction_password_title')"
                    name="sensitivePassword"
                    type="password"
                    wire:model="sensitivePassword"
                    autocomplete="off"
                    :error="$errors->first('sensitivePassword')"
                />

                <div class="flex flex-wrap justify-end gap-2">
                    <x-button type="button" variant="secondary" wire:click="cancelSensitiveAction">{{ __('panel.common.cancel') }}</x-button>
                    <x-button type="submit">{{ __('panel.sensitive.send_code') }}</x-button>
                </div>
            </form>
        @else
            <form wire:submit="confirmSensitiveAction" class="space-y-4">
                <p class="text-sm text-text-muted">{{ __('panel.sensitive.code_hint') }}</p>

                <x-input
                    class="text-center"
                    :label="__('panel.sensitive.code')"
                    name="sensitiveCode"
                    wire:model="sensitiveCode"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="one-time-code"
                    :error="$errors->first('sensitiveCode')"
                />

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <x-button type="button" variant="ghost" wire:click="sendSensitiveCode" :disabled="$this->resendCooldown() > 0">
                        @if ($this->resendCooldown() > 0)
                            {{ __('panel.sensitive.resend_in', ['seconds' => $this->resendCooldown()]) }}
                        @else
                            {{ __('panel.sensitive.send_code') }}
                        @endif
                    </x-button>
                    <x-button type="button" variant="secondary" wire:click="cancelSensitiveAction">{{ __('panel.common.cancel') }}</x-button>
                    <x-button type="submit">{{ __('panel.sensitive.confirm') }}</x-button>
                </div>
            </form>
        @endunless
    </x-modal>
@endif
