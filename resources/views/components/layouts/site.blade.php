@props([
    'title' => null,
    'background' => 'bg-surface',
    'width' => 'max-w-6xl',
    'bodyClass' => '',
    // Mundo padrão da TELA quando o visitante não escolheu claro/escuro:
    // normalmente é a preferência da conta (ou 'system'). Uma tela com
    // direção de arte própria — a landing /v2 — pode declarar o seu.
    'themeDefault' => null,
    'head' => null,
    'headerExtra' => null,
])

{{-- Esqueleto ÚNICO do site — <x-layouts.site>. Landing, showcase, telas de
     auth e painel do usuário passam por aqui: um <head>, um cabeçalho, um
     rodapé. Quando existiam três esqueletos, o painel tinha outra marca,
     outra largura e outro rodapé — e o cliente logado sentia que tinha
     saído do site (é a decisão do dono que este arquivo materializa).

     `background` existe porque o painel usa a superfície REBAIXADA (os
     cartões precisam flutuar sobre algo) e a landing usa a superfície normal.
     Os dois vêm dos tokens semânticos: nunca bg-white/dark:bg-gray-950.

     `width` alinha cabeçalho, conteúdo e rodapé na MESMA coluna. A landing
     lê a 1152px (texto longo pede medida curta); o painel usa 1280px, porque
     ali a coluna do menu lateral come 240px e as quatro métricas do dashboard
     não podem quebrar o rótulo em duas linhas. Marca, menu e conteúdo
     continuam começando no mesmo x. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-default="{{ $themeDefault ?? auth()->user()?->theme ?? 'system' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? platform()->name }}</title>

    {{-- Branding 100% via platform(): nome, logo e cor primária
         vêm do .env (config/platform.php). Nada hardcoded. --}}
    {{-- Override de marca opcional (.env PLATFORM_PRIMARY_COLOR). Vazio =
         identidade monocromática dos tokens (theme.css) — ver docs/interface.md. --}}
    @if (platform()->primaryColor !== null)
        <style>:root { --brand: {{ platform()->primaryColor }}; }</style>
    @endif

    {{-- Tema claro/escuro/sistema: aplica a classe ANTES do primeiro paint
         (sem flash). Default = preferência do SO; escolha persiste em
         localStorage (dispositivo) e na conta (users.theme). --}}
    @include('partials.theme-script')

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Assets EXTRAS de uma tela específica (ex.: o bundle próprio da
         landing /v2). Fica no <head> para não custar um segundo paint;
         quem não passa o slot não carrega nada a mais. --}}
    {{ $head }}
</head>
<body @auth data-authenticated @endauth class="flex min-h-screen flex-col {{ $background }} text-gray-900 antialiased dark:text-gray-100 {{ $bodyClass }}">
    {{-- `headerExtra` = controle EXTRA da tela ao lado do seletor de idioma
         (ex.: o botão de som da landing /v2). O cabeçalho continua UM só. --}}
    <x-site-header :width="$width">{{ $headerExtra }}</x-site-header>

    {{ $slot }}

    <x-site-footer :width="$width" />

    {{-- Flash de sessão (ex.: conta criada, contato enviado) → toast do kit. --}}
    <x-flash-toast />
</body>
</html>
