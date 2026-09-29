@props([
    'message' => null,
])

{{-- Overlay de carregamento em TELA CHEIA. USO RESTRITO: somente para
     carregamento inicial de uma área inteira ou ações longas e raras
     (ex.: gerar relatório). Para todo o resto, use <x-skeleton> (conteúdo)
     ou spinner dentro do botão (submissões). Ver orientação no showcase. --}}
<div
    data-loading-overlay
    role="alert"
    aria-busy="true"
    {{ $attributes->merge(['class' => 'fixed inset-0 z-50 hidden items-center justify-center bg-white/80 backdrop-blur-sm dark:bg-gray-950/80']) }}
>
    <div class="flex flex-col items-center gap-3">
        <x-spinner size="lg" />
        @if ($message !== null)
            <p class="text-sm text-gray-600 dark:text-gray-300" data-loading-overlay-message>{{ $message }}</p>
        @endif
    </div>
</div>
