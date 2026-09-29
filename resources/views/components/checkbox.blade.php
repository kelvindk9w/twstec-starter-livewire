@props([
    'label' => null,
    'name' => null,
    'checked' => false,
    'disabled' => false,
])

{{-- Checkbox com label. <x-checkbox label="Lembrar de mim" name="remember" /> --}}
<label {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center gap-2.5 text-sm text-gray-700 sm:min-h-0 dark:text-gray-300 '.($disabled ? 'opacity-60' : '')]) }}>
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @checked($checked)
        @disabled($disabled)
        class="h-4 w-4 shrink-0 rounded border-border-strong text-brand accent-brand focus:ring-brand/50"
    >
    <span>{{ $label ?? $slot }}</span>
</label>
