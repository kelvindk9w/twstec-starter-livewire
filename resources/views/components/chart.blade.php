@props([
    'labels' => [],
    'values' => [],
    'label' => '',
    'height' => 'h-56',
    'emptyTitle' => null,
    'emptyDescription' => null,
])

{{-- Gráfico de linha (Chart.js via Vite — resources/js/chart.js).

     As cores saem dos tokens do tema em runtime, então o gráfico acompanha
     claro/escuro e um rebrand em theme.css sem tocar em JS.

     ESTADO VAZIO DESENHADO: quando não há dado (conta nova, nenhuma chamada
     ainda), um gráfico de eixos zerados não informa nada e parece defeito —
     o componente troca o canvas por um estado vazio explícito. --}}
@php
    $hasData = $values !== [] && array_sum(array_map('intval', $values)) > 0;
@endphp

{{-- min-w-0 + relative: num item de grid/flex, o canvas do Chart.js
     herda a largura intrínseca e estoura a página no mobile sem isso. --}}
<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    @if ($hasData)
        <div class="relative {{ $height }} w-full min-w-0 overflow-hidden">
            <canvas
                data-chart="line"
                data-chart-label="{{ $label }}"
                data-chart-labels="{{ json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) }}"
                data-chart-values="{{ json_encode(array_values($values)) }}"
                role="img"
                aria-label="{{ $label }}"
            ></canvas>
        </div>
    @else
        <div class="{{ $height }} flex w-full flex-col items-center justify-center rounded-lg border border-dashed border-border-strong text-center">
            <x-ui-icon name="chart-bar" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
            <p class="mt-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $emptyTitle ?? __('ui.chart.empty_title') }}</p>
            @if ($emptyDescription !== null)
                <p class="mt-1 max-w-xs text-caption text-text-muted">{{ $emptyDescription }}</p>
            @endif
        </div>
    @endif
</div>
