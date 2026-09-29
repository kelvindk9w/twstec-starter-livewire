{{-- Ações de um membro (linha da tabela ou cartão): só ícone colorido +
     tooltip. Cada botão aparece só quando QUEM ESTÁ VENDO pode fazer aquilo
     com esta pessoa (App\Livewire\Account\Show → MemberRules, a mesma regra
     que a Action do pacote aplica no servidor).

     Espera: $m (a linha montada por Show::members()). --}}
<span class="flex items-center justify-end gap-2">
    @if ($m['canPromote'])
        <x-icon-button icon="arrow-up-circle" color="green" :label="__('panel.account.promote')" wire:click="changeRole('{{ $m['uuid'] }}', 'admin')" data-action="promote" />
    @endif
    @if ($m['canDemote'])
        <x-icon-button icon="arrow-down-circle" color="amber" :label="__('panel.account.demote')" wire:click="changeRole('{{ $m['uuid'] }}', 'member')" data-action="demote" />
    @endif
    @if ($m['canRemove'])
        <x-icon-button icon="user-minus" color="red" :label="__('panel.account.remove')" wire:click="startRemove('{{ $m['uuid'] }}')" data-action="remove" />
    @endif
    @unless ($m['canPromote'] || $m['canDemote'] || $m['canRemove'])
        <span class="text-caption text-text-muted" aria-hidden="true">—</span>
    @endunless
</span>
