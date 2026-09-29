<?php

declare(strict_types=1);

namespace App\Livewire\Support;

use Twstec\Kit\Foundation\Kit;

/**
 * Mapa de navegação do site — UMA verdade para todos os markups.
 *
 * O cabeçalho do site, o drawer do mobile e o menu lateral do painel
 * mostram os MESMOS itens em três geometrias diferentes. Enquanto cada
 * arquivo Blade declarava a própria lista, "adicionar uma tela" era mexer
 * em três lugares e esquecer um — foi assim que o painel e a landing
 * viraram dois produtos diferentes para o mesmo usuário.
 *
 * Não é um componente Livewire: é o único lugar do app/Livewire onde cabe
 * uma estrutura compartilhada pelas views do painel (as demais camadas —
 * Core, Http, Providers — pertencem ao kit e não conhecem telas).
 *
 * Formato de um grupo:
 *   ['label' => 'Conta', 'items' => [ ...itens... ]]
 *
 * Formato de um item:
 *   ['label' => 'Perfil', 'href' => '/profile', 'icon' => 'user-circle',
 *    'active' => bool, 'anchor' => 'perfil'|null]
 *
 * MÓDULOS OPCIONAIS: item de tela de um módulo opcional declara o módulo
 * (`'module' => 'accounts'`) e só aparece com ele instalado (Kit::has — a
 * mesma pergunta que decide se a rota existe, em routes/web.php). Grupo que
 * fica sem item some.
 */
final class Navigation
{
    /**
     * Links institucionais do site (cabeçalho público e drawer).
     *
     * O produto não tem páginas institucionais: os itens vêm das extensões
     * instaladas (SiteLinks, área `header`). Sem extensão, a lista é vazia.
     *
     * @return list<array{label: string, href: string}>
     */
    public static function site(): array
    {
        return app(SiteLinks::class)->links(SiteLinks::HEADER);
    }

    /**
     * Links do rodapé do site: o status da API (do produto) e os que as
     * extensões instaladas acrescentam (SiteLinks, área `footer`). O status
     * fica por último (sort 100); extensões entram antes dele.
     *
     * @return list<array{label: string, href: string}>
     */
    public static function footer(): array
    {
        return app(SiteLinks::class)->links(SiteLinks::FOOTER, [
            ['sort' => 100, 'link' => static fn (): array => ['label' => __('landing.footer.api_status'), 'href' => url('/api/health')]],
        ]);
    }

    /**
     * "Minha conta" — o menu lateral do painel, agrupado por assunto.
     *
     * @return list<array{label: string, items: list<array{label: string, href: string, icon: string, active: bool}>}>
     */
    public static function account(): array
    {
        $groups = [
            [
                'label' => __('panel.nav.groups.overview'),
                'items' => [
                    ['route' => 'dashboard', 'label' => __('panel.nav.dashboard'), 'icon' => 'squares-2x2'],
                ],
            ],
            [
                'label' => __('panel.nav.groups.development'),
                'items' => [
                    ['route' => 'panel.api-keys', 'label' => __('panel.nav.api_keys'), 'icon' => 'key', 'module' => 'accounts'],
                    ['route' => 'panel.projects', 'label' => __('panel.nav.projects'), 'icon' => 'folder', 'module' => 'accounts'],
                ],
            ],
            [
                'label' => __('panel.nav.groups.account'),
                'items' => [
                    ['route' => 'panel.account', 'label' => __('panel.nav.account'), 'icon' => 'user-group', 'module' => 'accounts'],
                    ['route' => 'panel.notifications', 'label' => __('panel.nav.notifications'), 'icon' => 'bell'],
                    ['route' => 'panel.profile', 'label' => __('panel.nav.profile'), 'icon' => 'user-circle'],
                    ['route' => 'transaction-password.edit', 'label' => __('panel.nav.transaction_password'), 'icon' => 'lock-closed'],
                ],
            ],
        ];

        $groups = array_map(static fn (array $group): array => [
            'label' => $group['label'],
            'items' => array_values(array_filter(
                $group['items'],
                static fn (array $item): bool => ! isset($item['module']) || Kit::has($item['module']),
            )),
        ], $groups);

        return array_values(array_map(static fn (array $group): array => [
            'label' => $group['label'],
            'items' => array_map(static fn (array $item): array => [
                'label' => $item['label'],
                'href' => route($item['route']),
                'icon' => $item['icon'],
                'active' => request()->routeIs($item['route']),
            ], $group['items']),
        ], array_filter($groups, static fn (array $group): bool => $group['items'] !== [])));
    }
}
