@props([
    'user' => null,
    'name' => null,
    'src' => null,
    'size' => 'md',
])

{{-- Avatar do usuário — <x-avatar :user="$user" size="sm" />.

     Foto quando existe; senão as INICIAIS do nome sobre fundo neutro. Nunca
     um ícone genérico: num menu de conta, o boneco cinza é a mesma imagem para
     todo mundo, e o que o usuário precisa reconhecer ali é a própria conta.

     Fonte da foto: User::avatarUrl() (URL assinada do upload validado). Sem `alt` decorativo: o nome já é texto no menu, e um alt
     repetido é ruído para o leitor de tela.

     Tamanhos: sm (32px, cabeçalho) · md (40px) · lg (64px, perfil). --}}
@php
    $displayName = $name ?? $user?->name ?? '';
    $photo = $src ?? $user?->avatarUrl();

    // Iniciais: primeira letra do primeiro e do último nome (mb_* por causa
    // dos acentos — "Ângela" começa com Â, não com um byte quebrado).
    $words = preg_split('/\s+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = $words === []
        ? '?'
        : mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));

    $sizes = [
        'sm' => 'h-8 w-8 text-caption',
        'md' => 'h-10 w-10 text-sm',
        'lg' => 'h-16 w-16 text-h2',
    ];

    $sizeClasses = $sizes[$size] ?? $sizes['md'];
@endphp

@if ($photo)
    <img
        src="{{ $photo }}"
        alt=""
        {{ $attributes->merge(['class' => 'shrink-0 rounded-full object-cover ring-1 ring-border '.$sizeClasses]) }}
    >
@else
    <span
        aria-hidden="true"
        {{ $attributes->merge(['class' => 'inline-flex shrink-0 select-none items-center justify-center rounded-full bg-surface-sunken font-medium text-gray-700 ring-1 ring-border dark:text-gray-200 '.$sizeClasses]) }}
    >{{ $initials }}</span>
@endif
