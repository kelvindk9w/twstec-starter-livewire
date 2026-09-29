@props([
    'label' => null,
    'align' => 'start',
    'density' => 'default',
])

{{-- Célula de <x-table>. `label` é o rótulo mostrado SÓ no modo cartão
     (abaixo de sm:), onde não há cabeçalho de coluna para dar o contexto. --}}
@php
    $padding = $density === 'compact' ? 'sm:px-4 sm:py-2' : 'sm:px-5 sm:py-3';
    $alignClasses = $align === 'end' ? 'sm:text-right' : '';
@endphp

<td {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3 py-1 align-middle sm:table-cell sm:py-3 '.$padding.' '.$alignClasses]) }}>
    @if ($label !== null)
        <span class="text-caption font-medium text-text-muted sm:hidden">{{ $label }}</span>
    @endif
    <span class="min-w-0 sm:contents">{{ $slot }}</span>
</td>
