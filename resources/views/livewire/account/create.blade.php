{{-- Criar uma conta de EMPRESA: só o nome. Quem cria vira o dono e passa a
     trabalhar nela (a página da conta abre em seguida, para convidar a
     equipe). --}}
<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="font-display text-h1">{{ __('panel.account.create_title') }}</h1>
        <p class="mt-1.5 text-sm text-text-muted">{{ __('panel.account.create_subtitle') }}</p>
    </div>

    <x-card>
        <form wire:submit="create" class="space-y-4" data-create-account>
            <x-input :label="__('panel.account.create_name')" name="accountName" wire:model="name" maxlength="255" :placeholder="__('panel.account.create_placeholder')" :error="$errors->first('name')" autofocus />
            <div class="flex flex-wrap gap-2">
                <x-button type="submit">{{ __('panel.account.create_submit') }}</x-button>
                <x-button :href="route('panel.account')" variant="secondary">{{ __('panel.common.cancel') }}</x-button>
            </div>
        </form>
    </x-card>
</div>
