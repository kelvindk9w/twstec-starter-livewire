@props([
    'label' => null,
    'name' => null,
    'checked' => false,
    'disabled' => false,
])

{{-- Interruptor (toggle) — checkbox estilizado. <x-toggle label="Notificações" name="notifications" />

     O KNOB É SEMPRE BRANCO, nos dois temas. Usar --color-brand-foreground o
     deixava PRETO no tema escuro (bolinha preta sobre trilho branco): todo
     usuário treinado em iOS/Android lê isso como DESLIGADO. Um controle que
     mente sobre o próprio estado é pior do que nenhum controle.

     LIGADO É VERDE, NÃO É A MARCA. A primária do kit é monocromática — quase
     -branca no tema escuro —, então pintar o trilho ligado com a cor da marca
     dava um trilho branco com um knob branco em cima: o estado ligado ficava
     indistinguível do desligado justo no tema em que mais gente trabalha.
     Estado se diz com COR DE ESTADO (--color-success), não com a cor da
     marca; o cinza do desligado (--color-switch-off) tem contraste >= 3:1
     nos dois temas.

     O anel de 1px no knob é o que o mantém visível sobre o verde e sobre o
     cinza — branco sobre cor só se lê pela borda. --}}
{{-- Atributos extras (wire:model, @change, aria-*) vão para o INPUT — é ele
     que carrega o estado; a label só carrega a classe. --}}
<label {{ $attributes->only('class')->merge(['class' => 'inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm text-gray-700 sm:min-h-0 dark:text-gray-300 '.($disabled ? 'cursor-not-allowed opacity-60' : '')]) }}>
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->except('class')->merge(['class' => 'peer sr-only']) }}
    >
    <span class="relative inline-flex h-6 w-11 shrink-0 rounded-full bg-switch-off transition-colors duration-150 ease-(--ease-out) peer-checked:bg-success peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50 peer-disabled:opacity-60 after:absolute after:start-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:ring-1 after:ring-black/15 after:transition-transform after:duration-150 after:ease-(--ease-out) peer-checked:after:translate-x-5 motion-reduce:transition-none motion-reduce:after:transition-none"></span>
    @if ($label !== null || ! $slot->isEmpty())
        <span>{{ $label ?? $slot }}</span>
    @endif
</label>
