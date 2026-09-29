@props(['locale'])

{{-- Bandeiras em SVG INLINE (Brasil, Estados Unidos, Espanha).

     Por que não emoji 🇧🇷: o glifo depende da fonte de emoji do sistema —
     no Windows aparece como duas letras ("BR"), no Linux costuma não existir,
     e o tamanho muda em cada plataforma. Um SVG de 20×14 é a mesma imagem em
     todo lugar e escala com o texto.

     A bandeira é um APOIO visual; o nome do idioma ao lado é a informação
     (bandeira ≠ idioma: 🇺🇸 não representa o inglês britânico). --}}
<span {{ $attributes->merge(['class' => 'inline-flex h-3.5 w-5 shrink-0 overflow-hidden rounded-[2px] ring-1 ring-black/10 dark:ring-white/15']) }} aria-hidden="true">
    @switch($locale)
        @case('pt_BR')
            <svg viewBox="0 0 20 14" class="h-full w-full" xmlns="http://www.w3.org/2000/svg">
                <rect width="20" height="14" fill="#009B3A" />
                <path d="M10 1.6 18.4 7 10 12.4 1.6 7Z" fill="#FEDF00" />
                <circle cx="10" cy="7" r="3.1" fill="#002776" />
                <path d="M7.1 6.1a6.6 6.6 0 0 1 5.9 1.2" stroke="#fff" stroke-width=".8" fill="none" />
            </svg>
            @break

        @case('en')
            <svg viewBox="0 0 20 14" class="h-full w-full" xmlns="http://www.w3.org/2000/svg">
                <rect width="20" height="14" fill="#fff" />
                <g fill="#B22234">
                    <rect width="20" height="2" y="0" />
                    <rect width="20" height="2" y="4" />
                    <rect width="20" height="2" y="8" />
                    <rect width="20" height="2" y="12" />
                </g>
                <rect width="9" height="8" fill="#3C3B6E" />
                <g fill="#fff">
                    <circle cx="2" cy="2" r=".7" />
                    <circle cx="4.5" cy="2" r=".7" />
                    <circle cx="7" cy="2" r=".7" />
                    <circle cx="3.25" cy="4" r=".7" />
                    <circle cx="5.75" cy="4" r=".7" />
                    <circle cx="2" cy="6" r=".7" />
                    <circle cx="4.5" cy="6" r=".7" />
                    <circle cx="7" cy="6" r=".7" />
                </g>
            </svg>
            @break

        @case('es')
            <svg viewBox="0 0 20 14" class="h-full w-full" xmlns="http://www.w3.org/2000/svg">
                <rect width="20" height="14" fill="#AA151B" />
                <rect width="20" height="7" y="3.5" fill="#F1BF00" />
                <rect x="3.2" y="5.2" width="2.6" height="3.6" rx=".4" fill="#AA151B" />
            </svg>
            @break

        @default
            <svg viewBox="0 0 24 24" class="h-full w-full text-text-muted" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="9" />
                <path d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18" />
            </svg>
    @endswitch
</span>
