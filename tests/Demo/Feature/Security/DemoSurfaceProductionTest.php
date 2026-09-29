<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Twstec\Kit\Demo\Accounts\DemoAccountGuard;
use Twstec\Kit\Demo\Database\Seeders\ApiKeySeeder;
use Twstec\Kit\Demo\Database\Seeders\DashboardHistorySeeder;
use Twstec\Kit\Demo\Database\Seeders\DemoAdminSeeder;
use Twstec\Kit\Demo\Database\Seeders\DemoUserSeeder;
use Twstec\Kit\Demo\Database\Seeders\FormSubmissionSeeder;
use Twstec\Kit\Demo\Database\Seeders\ProductSeeder;
use Twstec\Kit\Demo\Database\Seeders\RequestLogSeeder;
use Twstec\Kit\Demo\Database\Seeders\UserSeeder;
use Twstec\Kit\Demo\Support\DemoSurface;
use Twstec\Kit\Demo\Support\Exceptions\DemoSurfaceInProductionException;
use Twstec\Kit\Foundation\FoundationServiceProvider;

// =============================================================================
// SUPERFÍCIE DE DEMONSTRAÇÃO — FAIL-CLOSED EM PRODUÇÃO
//
// O achado que originou estes testes: a proteção da demo dependia INTEIRAMENTE
// de a pessoa lembrar de trocar uma flag, e o .env.example entregava a flag
// LIGADA. O caminho normal de um starter kit (`cp .env.example .env`, subir)
// levava para produção login demo de um clique, super admin com senha pública,
// vitrine de componentes e a galeria de todos os e-mails da plataforma.
//
// Por isso TODO teste daqui liga as flags de propósito: o que está sob teste é
// justamente que a flag ligada NÃO basta para abrir a demo em produção.
// =============================================================================

/**
 * Finge que esta instalação é de produção. É o sinal que o operador declara
 * sobre a própria instalação — o mesmo que o Laravel usa para HTTPS forçado.
 */
function simulaProducao(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

/**
 * Demo ligada no .env, como vem do .env.example.
 */
beforeEach(function (): void {
    config()->set('ui.demo_login.enabled', true);
    config()->set('ui.showcase_enabled', true);
    config()->set('ui.demo.allow_in_production', false);
});

// -----------------------------------------------------------------------------
// Seeders: recusa ALTA (exceção) quando chamados diretamente
// -----------------------------------------------------------------------------

it('recusa cada seeder de dado fictício em produção, mesmo com DEMO_LOGIN_ENABLED ligado', function (string $seeder): void {
    simulaProducao();

    expect(fn () => app($seeder)->run())
        ->toThrow(DemoSurfaceInProductionException::class);
})->with([
    DemoUserSeeder::class,
    DemoAdminSeeder::class,
    UserSeeder::class,
    ProductSeeder::class,
    FormSubmissionSeeder::class,
    RequestLogSeeder::class,
    ApiKeySeeder::class,
    DashboardHistorySeeder::class,
]);

it('não cria nenhuma conta demo em produção', function (): void {
    simulaProducao();

    try {
        app(DemoAdminSeeder::class)->run();
    } catch (DemoSurfaceInProductionException) {
        // A recusa é o comportamento esperado; o que importa é o banco.
    }

    expect(User::query()->where('email', config('ui.demo_admin.email'))->exists())->toBeFalse();
});

it('a mensagem da recusa ensina o caminho do desbloqueio', function (): void {
    simulaProducao();

    $excecao = null;

    try {
        app(DemoAdminSeeder::class)->run();
    } catch (DemoSurfaceInProductionException $e) {
        $excecao = $e;
    }

    expect($excecao)->not->toBeNull()
        ->and($excecao->surface)->toBe(DemoAdminSeeder::class)
        ->and($excecao->getMessage())->toContain('DEMO_ALLOW_IN_PRODUCTION');
});

// -----------------------------------------------------------------------------
// Agregador: recusa BAIXA (avisa e passa) — é o que um deploy roda
// -----------------------------------------------------------------------------

it('o DatabaseSeeder não estoura em produção: pula a demonstração e segue', function (): void {
    simulaProducao();

    app(DatabaseSeeder::class)->run();

    expect(User::query()->whereIn('email', DemoAccountGuard::emails())->exists())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Rotas: 404 (nunca 403 — 403 confirmaria que a rota existe)
// -----------------------------------------------------------------------------

it('as rotas de demonstração respondem 404 em produção, mesmo com as flags ligadas', function (string $rota): void {
    simulaProducao();

    $this->get($rota)->assertNotFound();
})->with([
    '/ui',
    '/mail-preview',
    '/mail-preview/verification-code',
]);

it('o demo de formulário da vitrine responde 404 em produção', function (): void {
    simulaProducao();

    // Token de CSRF de verdade na sessão: fingir produção REATIVA a verificação
    // (ela só é dispensada em APP_ENV=testing), e sem o token a requisição
    // morreria em 419 antes de chegar ao portão que está sob teste. O 419 é
    // proteção legítima; o que precisa ser provado aqui é o 404 do portão.
    $this->withSession(['_token' => 'token-de-teste'])
        ->post(route('ui.form-demo'), [
            '_token' => 'token-de-teste',
            'classic_nickname' => 'Pessoa',
            'classic_subject' => 'suggestion',
            'classic_message' => 'Mensagem suficientemente longa para validar.',
        ])->assertNotFound();
});

it('a rota da vitrine continua REGISTRADA em produção (o rodapé do site gera o link)', function (): void {
    simulaProducao();

    // Se a rota fosse desregistrada em vez de responder 404, a home inteira
    // cairia com RouteNotFoundException ao montar o rodapé e o menu.
    expect(route('ui.showcase'))->toBeString();

    $this->get('/')->assertOk();
});

// -----------------------------------------------------------------------------
// Credenciais demo nas telas de login
// -----------------------------------------------------------------------------

it('o login não imprime nem pré-preenche credenciais demo em produção', function (): void {
    simulaProducao();

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(config('ui.demo_login.password'))
        ->assertDontSee(config('ui.demo_login.email'))
        ->assertDontSee(__('auth.ui.demo_notice'));
});

it('o login do super admin não pré-preenche credenciais demo em produção', function (): void {
    simulaProducao();

    // Produção BEM CONFIGURADA: o que está sob teste aqui são as credenciais
    // demo na tela, não a barreira de origem. Sem allowlist declarada, o
    // /admin recusa antes de renderizar qualquer tela (ver
    // Twstec\Kit\Foundation\Security\AdminIpAllowlist e AdminIpAllowlistTest) — e então
    // este teste passaria por um 403, provando outra coisa.
    config()->set('security.admin.allowed_ips', ['127.0.0.1']);

    $this->get('/admin/login')
        ->assertOk()
        ->assertDontSee(config('ui.demo_admin.password'));
});

// -----------------------------------------------------------------------------
// ESCAPE HATCH: o opt-out declarado libera de verdade
// -----------------------------------------------------------------------------

it('DEMO_ALLOW_IN_PRODUCTION libera a demonstração em produção', function (): void {
    simulaProducao();
    config()->set('ui.demo.allow_in_production', true);

    expect(DemoSurface::allowed())->toBeTrue()
        ->and(DemoSurface::loginEnabled())->toBeTrue()
        ->and(DemoSurface::showcaseEnabled())->toBeTrue();

    $this->get('/ui')->assertOk();
    $this->get('/mail-preview')->assertOk();

    app(DemoUserSeeder::class)->run();

    expect(User::query()->where('email', config('ui.demo_login.email'))->exists())->toBeTrue();
});

it('o opt-out é reconhecido como decisão explícita, para o aviso do log', function (): void {
    simulaProducao();
    config()->set('ui.demo.allow_in_production', true);

    expect(DemoSurface::allowedInProductionByOptOut())->toBeTrue();

    config()->set('ui.demo.allow_in_production', false);

    expect(DemoSurface::allowedInProductionByOptOut())->toBeFalse();
});

it('silêncio nunca significa permitido: sem a variável, produção recusa', function (): void {
    simulaProducao();
    config()->set('ui.demo.allow_in_production', null);

    expect(DemoSurface::allowed())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Fora de produção: nada mudou
// -----------------------------------------------------------------------------

it('fora de produção a demonstração continua funcionando como antes', function (): void {
    expect(app()->isProduction())->toBeFalse()
        ->and(DemoSurface::allowed())->toBeTrue()
        ->and(DemoSurface::loginEnabled())->toBeTrue();

    $this->get('/ui')->assertOk();
    $this->get('/mail-preview')->assertOk();

    app(DemoUserSeeder::class)->run();
    app(DemoAdminSeeder::class)->run();

    expect(User::query()->where('email', config('ui.demo_admin.email'))->where('is_admin', true)->exists())->toBeTrue();
});

it('fora de produção a flag desligada continua sendo obedecida', function (): void {
    config()->set('ui.demo_login.enabled', false);
    config()->set('ui.showcase_enabled', false);

    expect(DemoSurface::loginEnabled())->toBeFalse()
        ->and(DemoSurface::showcaseEnabled())->toBeFalse();

    $this->get('/ui')->assertNotFound();
    $this->get('/mail-preview')->assertNotFound();
});

// -----------------------------------------------------------------------------
// A garantia que já existia (commit ad12e7e) continua valendo
// -----------------------------------------------------------------------------

it('a proteção das contas demo continua independente do ambiente de quem roda a suíte', function (): void {
    // O teste antigo liga a flag explicitamente para valer igual no CI; o
    // fail-closed não pode ter mudado isso fora de produção.
    expect(DemoAccountGuard::isEnabled())->toBeTrue();

    config()->set('ui.demo_login.enabled', false);

    expect(DemoAccountGuard::isEnabled())->toBeFalse();
});

it('em produção as contas demo deixam de ser intocáveis (têm de poder ser apagadas)', function (): void {
    simulaProducao();

    // A blindagem existe para proteger a DEMO de visitantes. Num banco de
    // produção onde alguém deixou a flag ligada por engano, ela não pode
    // impedir o operador de apagar a conta demo.
    expect(DemoAccountGuard::isEnabled())->toBeFalse();
});

// -----------------------------------------------------------------------------
// APP_DEBUG em produção
// -----------------------------------------------------------------------------

it('força APP_DEBUG desligado quando o ambiente é produção', function (): void {
    simulaProducao();
    config()->set('app.debug', true);

    (new FoundationServiceProvider(app()))->boot();

    expect(config('app.debug'))->toBeFalse();
});
