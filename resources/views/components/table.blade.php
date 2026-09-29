@props([
    'headers' => [],
    'density' => 'default',
])

{{-- Tabela do kit — o componente que faltava no sistema.

     Por que existe: sem tabela, toda listagem do painel virava uma pilha de
     cartões com metadados que NÃO ALINHAM entre linhas — o olho não consegue
     comparar duas chaves de API sem reler as duas.

     ABAIXO DE sm: a tabela vira CARTÕES (cada <td> mostra o próprio rótulo à
     esquerda). Não há scroll horizontal escondido nem coluna cortada: no
     celular a linha simplesmente muda de forma.

     <x-table :headers="[__('…'), __('…'), '']">
         <x-table-row>
             <x-table-cell :label="__('…')">…</x-table-cell>
         </x-table-row>
     </x-table>

     `density="compact"` aperta a altura da linha para listagens longas.

     Sem overflow-hidden no invólucro (de propósito): a linha hospeda menus
     ancorados (⋯) que seriam RECORTADOS por ele. Os cantos arredondados são
     feitos célula a célula. --}}
@php
    $headPadding = $density === 'compact' ? 'px-4 py-2' : 'px-5 py-3';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-border bg-surface']) }}>
    {{-- `block sm:table`: no modo cartão o <table> PRECISA deixar de ser
         tabela. Um display:table cresce até o min-content da célula mais
         larga (uma chave pública de 40 caracteres), e a página inteira ganha
         scroll lateral mesmo com todas as células em w-full. --}}
    <table class="block w-full table-fixed text-left text-sm sm:table" data-density="{{ $density }}">
        @if ($headers !== [])
            <thead class="hidden border-b border-border bg-surface-sunken sm:table-header-group">
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" class="{{ $headPadding }} text-caption font-medium uppercase tracking-wide text-text-muted first:rounded-tl-xl last:rounded-tr-xl">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="block divide-y divide-border sm:table-row-group sm:[&>tr:last-child>td:first-child]:rounded-bl-xl sm:[&>tr:last-child>td:last-child]:rounded-br-xl">
            {{ $slot }}
        </tbody>
    </table>
</div>
