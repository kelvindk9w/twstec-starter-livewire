<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Admin\Dashboards\DashboardRegistry;
use Twstec\Kit\Admin\Dashboards\GrowthDashboard;
use Twstec\Kit\Admin\Dashboards\OverviewDashboard;
use Twstec\Kit\Demo\Database\Seeders\DashboardHistorySeeder;
use Twstec\Kit\Demo\Database\Seeders\RequestLogSeeder;
use Twstec\Kit\Demo\Filament\Dashboards\ContentDashboard;

// =============================================================================
// As TRÊS variantes de dashboard do /admin (config/dashboards.php): o
// desenvolvedor abre as três, escolhe a base que mais lhe agrada e adapta.
//
// O que estes testes protegem: a variante padrão responde em /admin, as demais
// em /admin/dashboards/{slug}, todas aparecem no menu no grupo "Dashboards", e
// desligar um slug na config tira a página do painel — do menu E da rota.
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('a variante padrão responde em /admin e as demais em /admin/dashboards/{slug}', function () {
    expect(DashboardRegistry::default())->toBe('overview');

    $this->get('/admin')->assertOk()->assertSee(__('admin.dashboards.overview.title'));
    $this->get('/admin/dashboards/growth')->assertOk()->assertSee(__('admin.dashboards.growth.title'));
    $this->get('/admin/dashboards/content')->assertOk()->assertSee(__('admin.dashboards.content.title'));
})->group('demo');

it('cada variante carrega exatamente os seus widgets', function () {
    expect((new OverviewDashboard)->getWidgets())->toHaveCount(5)
        ->and((new GrowthDashboard)->getWidgets())->toHaveCount(5)
        ->and((new ContentDashboard)->getWidgets())->toHaveCount(5);

    // Nenhum widget é registrado no PAINEL: widget de painel apareceria em
    // todas as variantes de uma vez, que é o oposto de "escolha a sua".
    expect(filament()->getWidgets())->toBe([]);

    $html = $this->get('/admin')->assertOk()->getContent();

    expect($html)->toContain('OverviewStats')
        ->and($html)->toContain('RequestsTrendChart')
        ->and($html)->toContain('RequestsStatusChart')
        // O AccountWidget de fábrica ("Bem-vindo(a)") continua fora do painel.
        ->and($html)->not->toContain('AccountWidget');
})->group('demo');

it('as três variantes aparecem no menu, no grupo Dashboards', function () {
    $this->get('/admin')->assertOk();

    $itens = collect(filament()->getNavigation())
        ->flatMap(fn ($grupo) => $grupo->getItems())
        ->map(fn ($item) => $item->getLabel());

    expect($itens)->toContain(__('admin.dashboards.overview.nav'))
        ->toContain(__('admin.dashboards.growth.nav'))
        ->toContain(__('admin.dashboards.content.nav'));

    $grupos = collect(filament()->getNavigation())->map(fn ($grupo) => $grupo->getLabel());

    expect($grupos)->toContain(__('admin.nav.group_dashboards'));
})->group('demo');

it('desligar uma variante na config a tira do painel — do menu e da rota', function () {
    config()->set('dashboards.enabled', ['overview', 'content']);

    expect(DashboardRegistry::pages())->toBe([OverviewDashboard::class, ContentDashboard::class])
        ->and(DashboardRegistry::isEnabled('growth'))->toBeFalse();

    // O painel registra exatamente o que o registry devolve — é isso que faz
    // a config valer para a ROTA, e não só para o menu.
    config()->set('dashboards.enabled', ['overview', 'growth', 'content']);

    foreach (DashboardRegistry::pages() as $pagina) {
        expect(filament()->getPages())->toContain($pagina);
    }
})->group('demo');

it('a variante padrão troca com DASHBOARD_DEFAULT, e slug desligado cai na primeira habilitada', function () {
    config()->set('dashboards.default', 'growth');

    expect(DashboardRegistry::default())->toBe('growth')
        ->and(DashboardRegistry::isDefault('overview'))->toBeFalse()
        // A rota da variante padrão é a raiz do painel; as demais, o slug.
        ->and(GrowthDashboard::getRoutePath(filament()->getPanel('admin')))->toBe('/')
        ->and(OverviewDashboard::getRoutePath(filament()->getPanel('admin')))->toBe('/dashboards/overview');

    // DASHBOARD_DEFAULT apontando para variante desligada: o painel não fica
    // sem home — a primeira do MENU (ordem de `sort`) assume.
    config()->set('dashboards.default', 'growth');
    config()->set('dashboards.enabled', ['content', 'overview']);

    expect(DashboardRegistry::default())->toBe('overview');
});

it('sem nenhuma variante habilitada o painel volta ao dashboard de fábrica, nunca a 404', function () {
    config()->set('dashboards.enabled', []);

    expect(DashboardRegistry::variants())->toBe([])
        ->and(DashboardRegistry::default())->toBeNull()
        ->and(DashboardRegistry::pages())->toBe([Dashboard::class]);
});

it('slug inexistente não vira rota', function () {
    expect(Route::has('filament.admin.pages.dashboards.overview'))->toBeTrue()
        ->and(Route::has('filament.admin.pages.dashboards.growth'))->toBeTrue()
        ->and(Route::has('filament.admin.pages.dashboards.finance'))->toBeFalse();

    $this->get('/admin/dashboards/finance')->assertNotFound();
});

it('as três variantes renderizam com os dados que o kit semeia', function () {
    $this->seed(RequestLogSeeder::class);
    $this->seed(DashboardHistorySeeder::class);

    foreach (['/admin', '/admin/dashboards/growth', '/admin/dashboards/content'] as $url) {
        $this->get($url)->assertOk();
    }
})->group('demo')->group('slow');
