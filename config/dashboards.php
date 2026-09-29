<?php

declare(strict_types=1);
use Twstec\Kit\Admin\Dashboards\GrowthDashboard;
use Twstec\Kit\Admin\Dashboards\OverviewDashboard;

// =============================================================================
// Variantes de DASHBOARD do super admin (/admin).
//
// O kit entrega três dashboards NOMEADOS (como os temas de admin clássicos
// fazem com "Analytics / SaaS / E-commerce"): o desenvolvedor abre as três,
// escolhe a base que mais lhe agrada e adapta — em vez de receber uma tela
// única e ter que inventar a sua do zero.
//
// A variante PADRÃO responde em /admin; as demais em /admin/dashboards/{slug}.
// Todas aparecem no grupo "Dashboards" da navegação, cada uma com o seu ícone.
// Tirar um slug de DASHBOARD_ENABLED remove a variante do MENU e da ROTA (a
// página deixa de ser registrada no painel — não é só um item escondido).
//
// Nada aqui é hardcodado em código: quem lê esta config é o
// Twstec\Kit\Admin\Dashboards\DashboardRegistry, e é ele que o AdminPlugin
// consulta para registrar as páginas.
// =============================================================================

return [

    // Variantes ligadas, na ordem em que aparecem no menu (slugs separados
    // por vírgula). Vazio = nenhuma variante do kit; o painel cai no
    // Dashboard de fábrica do Filament para que /admin nunca dê 404. Slug sem
    // variante registrada é ignorado (uma extensão instalada registra as dela
    // em `variants` — ver abaixo).
    'enabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DASHBOARD_ENABLED', 'overview,growth')),
    ))),

    // Variante que responde em /admin. Se o slug não estiver habilitado, a
    // primeira variante habilitada assume o lugar (o painel nunca fica sem
    // home).
    'default' => env('DASHBOARD_DEFAULT', 'overview'),

    // Janela padrão do seletor de período, em dias. Precisa ser um dos
    // valores de 'periods'.
    'period' => (int) env('DASHBOARD_PERIOD', 30),

    // Opções do seletor de período (filtro central da página, propagado a
    // TODOS os widgets da variante via HasFiltersForm).
    'periods' => [7, 30, 90],

    // Metas de acompanhamento (widget de progresso da variante "Crescimento
    // & API"). Não existe tabela de metas de propósito: meta é parâmetro de
    // operação, não dado de domínio.
    'goals' => [
        'monthly_requests' => (int) env('DASHBOARD_GOAL_MONTHLY_REQUESTS', 1500),
    ],

    // Quantas linhas as tabelas de "últimos registros" mostram.
    'latest_records' => (int) env('DASHBOARD_LATEST_RECORDS', 6),

    // Catálogo das variantes que o produto traz. Uma variante nova entra aqui
    // (slug => página + ícone + ordem) e passa a existir assim que o slug
    // for incluído em DASHBOARD_ENABLED. Ícone como STRING para que a
    // config continue cacheável (`config:cache`).
    'variants' => [

        'overview' => [
            'page' => OverviewDashboard::class,
            'icon' => 'heroicon-o-squares-2x2',
            'sort' => 1,
        ],

        'growth' => [
            'page' => GrowthDashboard::class,
            'icon' => 'heroicon-o-arrow-trending-up',
            'sort' => 2,
        ],

    ],

    // Widgets que extensões instaladas acrescentam a uma variante do
    // produto, por slug: [['widget' => classe, 'before' => classe|null]].
    // Preenchido pelas extensões no register (ver DashboardRegistry::widgets).
    'widgets' => [],

];
