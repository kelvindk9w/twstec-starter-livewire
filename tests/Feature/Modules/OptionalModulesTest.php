<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Livewire\Support\AccountMenu;
use App\Livewire\Support\Navigation;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Composer\InstalledVersions;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Installer\Support\InstallPlan;

// =============================================================================
// MÓDULOS OPCIONAIS NO STARTER. twstec/kit-accounts (contas, chaves, projetos,
// convites), twstec/kit-uploads (foto de perfil) e twstec/kit-admin (o /admin)
// podem ficar de fora (`php artisan tws:install`). O starter pergunta ao ponto
// único de detecção (Kit::has) e, sem o módulo, a tela não é registrada (rota
// 404), o menu não a mostra e nenhuma outra tela a chama.
//
// Duas metades:
// - O ESTADO REAL: roda em toda combinação (o CI tira os módulos de verdade) e
//   confere que o que existe é exatamente o que está instalado;
// - O FAZ DE CONTA (Kit::pretendAbsent): na instalação completa, prova que
//   a detecção é quem decide — uma tela registrada sem perguntar reprova aqui,
//   antes de chegar ao CI das combinações. (Não descarrega classe: a prova de
//   que o aplicativo SOBE sem o pacote é a combinação de verdade.)
// =============================================================================

/**
 * Rotas do starter que pertencem a um módulo opcional.
 *
 * @return array<string, string> nome da rota => módulo
 */
function rotasDeModulo(): array
{
    return [
        'panel.api-keys' => 'accounts',
        'panel.projects' => 'accounts',
        'panel.account' => 'accounts',
        'panel.accounts.create' => 'accounts',
        'accounts.switch' => 'accounts',
        'accounts.open' => 'accounts',
        'invitations.show' => 'accounts',
        'invitations.accept' => 'accounts',
        'invitations.register' => 'accounts',
        'invitations.decline' => 'accounts',
        'settings.avatar' => 'uploads',
    ];
}

/**
 * As rotas que o routes/web.php registra, lido de novo num roteador limpo —
 * com a detecção valendo como está AGORA (inclusive o faz de conta).
 *
 * @return list<string>
 */
function rotasDoArquivoWeb(): array
{
    $original = Route::getFacadeRoot();
    $router = new Router(app('events'), app());

    Route::swap($router);

    try {
        require base_path('routes/web.php');
    } finally {
        Route::swap($original);
    }

    $router->getRoutes()->refreshNameLookups();

    return array_keys($router->getRoutes()->getRoutesByName());
}

/**
 * @return list<string>
 */
function itensDoMenu(): array
{
    return collect(Navigation::account())->flatMap(fn (array $grupo): array => array_column($grupo['items'], 'label'))->all();
}

// -----------------------------------------------------------------------------
// O estado real (toda combinação)
// -----------------------------------------------------------------------------

it('cada tela de módulo opcional existe só com o módulo instalado — e sem ele responde 404', function (): void {
    foreach (rotasDeModulo() as $rota => $modulo) {
        expect(Route::has($rota))->toBe(Kit::has($modulo), "rota {$rota} (módulo {$modulo})");
    }

    $user = User::factory()->create();

    foreach (['/api-keys' => 'accounts', '/projects' => 'accounts', '/account' => 'accounts', '/accounts/create' => 'accounts'] as $url => $modulo) {
        if (! Kit::has($modulo)) {
            $this->actingAs($user)->get($url)->assertNotFound();
        }
    }
});

it('o menu do painel mostra só as telas dos módulos instalados', function (): void {
    $itens = itensDoMenu();

    foreach (['api_keys', 'projects', 'account'] as $item) {
        expect(in_array(__("panel.nav.{$item}"), $itens, true))->toBe(Kit::has('accounts'), $item);
    }

    expect($itens)->toContain(__('panel.nav.dashboard'), __('panel.nav.profile'), __('panel.nav.notifications'), __('panel.nav.transaction_password'));
});

it('o /admin existe só com o painel instalado', function (): void {
    expect(app()->providerIsLoaded(AdminPanelProvider::class))->toBe(Kit::has('admin'))
        ->and(InstalledVersions::isInstalled('filament/filament'))->toBe(Kit::has('admin'));

    $this->get('/admin/login')->assertStatus(Kit::has('admin') ? 200 : 404);
});

it('o model de usuário usa as peças dos módulos instalados e as neutras dos ausentes', function (): void {
    $user = User::factory()->create();

    expect($user instanceof FilamentUser)->toBe(Kit::has('admin'))
        ->and(method_exists($user, 'canAccessPanel'))->toBe(Kit::has('admin'))
        ->and(method_exists($user, 'avatar'))->toBe(Kit::has('uploads'))
        // Sem foto (e, sem o módulo, nunca): as telas desenham as iniciais.
        ->and($user->avatarUrl())->toBeNull();
});

it('as configurações editáveis do /admin só levam as chaves dos módulos instalados', function (): void {
    $chaves = array_keys((array) config('settings.overrides'));

    expect(in_array('api_keys.inactivity.months', $chaves, true))->toBe(Kit::has('accounts'))
        ->and(in_array('uploads.types.image.max_kb', $chaves, true))->toBe(Kit::has('uploads'))
        ->and($chaves)->toContain('security.rate_limit.api');
});

it('o agendamento das chaves de API só existe com o pacote de contas', function (): void {
    $comandos = collect(app(Schedule::class)->events())
        ->map(fn ($evento): string => (string) $evento->command)
        ->filter(fn (string $comando): bool => str_contains($comando, 'api-keys:process-inactivity'));

    expect($comandos->isNotEmpty())->toBe(Kit::has('accounts'));
});

it('a API continua limitada e com o envelope de erro, com ou sem o pacote de contas', function (): void {
    config()->set('security.rate_limit.api', 1);

    $this->getJson('/api/health')->assertOk();
    $this->getJson('/api/health')
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'too_many_requests');
});

// -----------------------------------------------------------------------------
// O faz de conta (a detecção é quem decide)
// -----------------------------------------------------------------------------

it('sem os módulos, o routes/web.php não registra as telas deles — e registra as da base', function (): void {
    Kit::pretendAbsent('accounts', 'uploads');

    $rotas = rotasDoArquivoWeb();

    foreach (array_keys(rotasDeModulo()) as $rota) {
        expect($rotas)->not->toContain($rota);
    }

    expect($rotas)->toContain('dashboard', 'panel.profile', 'panel.notifications', 'transaction-password.edit', 'login', 'register', 'logout');
});

it('com os módulos, o routes/web.php registra as telas deles', function (): void {
    $rotas = rotasDoArquivoWeb();

    foreach (rotasDeModulo() as $rota => $modulo) {
        expect(in_array($rota, $rotas, true))->toBe(Kit::has($modulo), $rota);
    }
});

it('sem contas, o menu perde o grupo de desenvolvimento e a página da conta', function (): void {
    Kit::pretendAbsent('accounts', 'uploads');

    $grupos = array_column(Navigation::account(), 'label');
    $itens = itensDoMenu();

    expect($grupos)->toBe([__('panel.nav.groups.overview'), __('panel.nav.groups.account')])
        ->and($itens)->not->toContain(__('panel.nav.api_keys'))
        ->not->toContain(__('panel.nav.projects'))
        ->not->toContain(__('panel.nav.account'))
        ->and(AccountMenu::for(User::factory()->create()))->toBe(['current' => null, 'accounts' => []]);
});

it('sem contas, o painel abre com os atalhos da conta, sem chaves, projetos nem seletor de conta', function (): void {
    Kit::pretendAbsent('accounts', 'uploads');

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-dashboard-essentials', false)
        ->assertSee(__('panel.dashboard.essentials_title'))
        ->assertDontSee('data-account-switcher', false)
        ->assertDontSee(__('panel.dashboard.new_api_key'))
        ->assertDontSee(__('panel.dashboard.summary_projects'));
});

it('com contas, o painel mostra os números da conta e não os atalhos', function (): void {
    if (! Kit::has('accounts')) {
        $this->markTestSkipped('Módulo opcional accounts (twstec/kit-accounts) não instalado.');
    }

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(__('panel.dashboard.summary_projects'))
        ->assertSee('data-account-switcher', false)
        ->assertDontSee('data-dashboard-essentials', false);
});

it('sem uploads, o perfil não tem a foto e a ação de enviar não existe', function (): void {
    Kit::pretendAbsent('uploads');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertDontSee(__('panel.profile.avatar_heading'));

    Livewire::actingAs($user)->test(Profile::class)
        ->call('updateAvatar')
        ->assertNotFound();
});

it('sem o /admin, o /horizon fecha até para quem é admin (não há o critério do painel)', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    Kit::pretendAbsent('admin');

    $this->actingAs($admin)->get('/horizon')->assertForbidden();
});

it('sem o /admin, o aplicativo não registra o PanelProvider (nem o Filament é carregado)', function (): void {
    Kit::pretendAbsent('admin');

    // Aplicação nova só para ver o que o AppServiceProvider registra.
    $original = app();
    $nova = new Application(base_path());

    try {
        (new AppServiceProvider($nova))->register();

        expect($nova->providerIsLoaded(AdminPanelProvider::class))->toBeFalse();
    } finally {
        Application::setInstance($original);
    }
});

it('a demonstração exige exatamente os módulos que o instalador diz que ela exige', function (): void {
    if (! InstalledVersions::isInstalled('twstec/kit-demo') || ! class_exists(InstallPlan::class)) {
        $this->markTestSkipped('Precisa da demonstração e do instalador instalados.');
    }

    $demo = json_decode((string) file_get_contents(base_path('vendor/twstec/kit-demo/composer.json')), true);
    $exigidos = array_values(array_filter(Kit::OPTIONAL, fn (string $m): bool => array_key_exists(Kit::package($m), $demo['require'])));

    expect($exigidos)->toBe(InstallPlan::DEMO_REQUIRES);
})->group('demo');
