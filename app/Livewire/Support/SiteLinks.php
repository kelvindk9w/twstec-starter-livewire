<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use Closure;

/**
 * Links do SITE que vêm de fora do produto — ponto de extensão do cabeçalho e
 * do rodapé públicos.
 *
 * O produto não tem páginas institucionais próprias: o cabeçalho e o rodapé
 * mostram só o que é dele (entrar, criar conta, status da API). Uma extensão
 * instalada acrescenta os seus links aqui — a demonstração do kit acrescenta
 * as âncoras da landing, a vitrine /ui e o contato. Sem extensão, as listas
 * ficam só com os itens do produto.
 *
 * Singleton no container (registrado no AppServiceProvider): cada aplicação
 * tem a sua lista, e nada vaza entre testes. Os links são closures porque
 * rótulo (idioma da requisição) e URL (`route()`) só existem na hora de
 * renderizar.
 */
final class SiteLinks
{
    /**
     * Área do cabeçalho do site (e da gaveta do mobile).
     */
    public const HEADER = 'header';

    /**
     * Área de links do rodapé do site.
     */
    public const FOOTER = 'footer';

    /**
     * @var array<string, list<array{sort: int, link: Closure(): array{label: string, href: string}}>>
     */
    private array $links = [];

    /**
     * Acrescenta um link a uma área. Menor `sort` vem primeiro; empate
     * mantém a ordem de registro.
     *
     * @param  Closure(): array{label: string, href: string}  $link
     */
    public function add(string $area, int $sort, Closure $link): void
    {
        $this->links[$area][] = ['sort' => $sort, 'link' => $link];
    }

    /**
     * Links de uma área, somados aos que o produto passa, na ordem de `sort`.
     *
     * @param  list<array{sort: int, link: Closure(): array{label: string, href: string}}>  $own
     * @return list<array{label: string, href: string}>
     */
    public function links(string $area, array $own = []): array
    {
        $entries = [...$own, ...($this->links[$area] ?? [])];

        // usort é estável desde o PHP 8.0: empate mantém a ordem de registro.
        usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $entry): array => ($entry['link'])(), $entries);
    }
}
