@props([
    'href' => null,
    'showName' => true,
])

{{-- Marca da plataforma (logo + nome) — <x-brand /> ou <x-brand :href="url('/')" />.

     Branding 100% via platform(): nome e logo vêm do .env. Sem logo, a
     marca de reserva é a inicial do nome no quadrado da cor da marca — nada
     do kit, e trocar o nome troca a marca.

     Existe para que cabeçalho do site, rodapé e telas de auth mostrem a MESMA
     marca — três markups iguais é uma marca esperando para divergir.

     Nada de {!! !!} aqui (regra do kit): o markup interno se repete nos dois
     ramos porque a alternativa seria montar HTML em string. --}}
@php
    $classes = $attributes->merge([
        'class' => 'flex min-w-0 items-center gap-2 font-display font-semibold tracking-tight text-gray-900 dark:text-gray-100',
    ]);
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $classes }}>
        @if (platform()->logoUrl)
            <img src="{{ platform()->logoUrl }}" alt="{{ platform()->name }}" class="h-8 w-auto">
        @else
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand text-sm font-bold text-brand-foreground" aria-hidden="true" data-brand-initial>{{ platform()->initial() }}</span>
        @endif
        @if ($showName)
            <span class="truncate">{{ platform()->name }}</span>
        @endif
    </a>
@else
    <div {{ $classes }}>
        @if (platform()->logoUrl)
            <img src="{{ platform()->logoUrl }}" alt="{{ platform()->name }}" class="h-8 w-auto">
        @else
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand text-sm font-bold text-brand-foreground" aria-hidden="true" data-brand-initial>{{ platform()->initial() }}</span>
        @endif
        @if ($showName)
            <span class="truncate">{{ platform()->name }}</span>
        @endif
    </div>
@endif
