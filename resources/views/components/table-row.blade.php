{{-- Linha de <x-table>. Abaixo de sm: é um bloco (cartão); a partir de sm:
     volta a ser uma linha de tabela de verdade. --}}
<tr {{ $attributes->merge(['class' => 'block p-4 transition-colors duration-150 ease-(--ease-out) hover:bg-surface-sunken sm:table-row sm:p-0']) }}>
    {{ $slot }}
</tr>
