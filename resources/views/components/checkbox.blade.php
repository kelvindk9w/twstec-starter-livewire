@props([
    'label' => null,
    'name' => null,
    'checked' => false,
    'disabled' => false,
    'value' => '1',
])

{{-- Checkbox com label.

     Caixa única de formulário: <x-checkbox label="Lembrar de mim" name="remember" />
     (envia "1" marcada).

     LISTA ligada ao Livewire: cada caixa leva o PRÓPRIO valor e o mesmo
     wire:model, que vira a lista dos marcados:
         <x-checkbox wire:model="selectedProjectUuids" value="{{ $project->uuid }}" :label="$project->name" />

     Os atributos extras (wire:model, value, data-*, aria-*, @change) vão para
     o INPUT — é ele que carrega o estado; a label só carrega a classe. (Até a
     2.0.0-beta.14 eles iam para a label: o wire:model e o valor nunca
     chegavam ao input, e uma lista de caixas não sincronizava no navegador.) --}}
<label {{ $attributes->only('class')->merge(['class' => 'inline-flex min-h-11 items-center gap-2.5 text-sm text-gray-700 sm:min-h-0 dark:text-gray-300 '.($disabled ? 'opacity-60' : '')]) }}>
    <input
        type="checkbox"
        @if ($name !== null) name="{{ $name }}" @endif
        value="{{ $value }}"
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->except('class')->merge(['class' => 'h-4 w-4 shrink-0 rounded border-border-strong text-brand accent-brand focus:ring-brand/50']) }}
    >
    <span>{{ $label ?? $slot }}</span>
</label>
