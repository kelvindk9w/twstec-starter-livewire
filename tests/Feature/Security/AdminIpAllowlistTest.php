<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Http\Middleware\Authenticate;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Security\AdminIpAllowlist;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;

// =============================================================================
// BARREIRA DE ORIGEM DAS SUPERFÍCIES ADMINISTRATIVAS (/admin e /horizon)
//
// O achado que originou estes testes: a allowlist de IP era FAIL-OPEN quando
// vazia — e vazia era o padrão, porque o docker-compose.prod.yml trazia
// `${PROD_ADMIN_ALLOWED_IPS:-}`. O projeto prometia em três lugares
// (.env.prod.example, config/security.php, AdminPanelProvider) que a allowlist
// é obrigatória em produção, e nada verificava isso em runtime: subir a stack
// sem definir a variável entregava o painel de super admin e o dashboard de
// filas sem a segunda barreira que a própria política exige.
//
// Mesmo bug do DemoSurface e do CriticalSecrets, superfície diferente:
// SILÊNCIO SIGNIFICANDO PERMITIDO.
// =============================================================================

/**
 * Finge que esta instalação é de produção — o sinal que o operador declara
 * sobre a própria instalação.
 *
 * Nome próprio (e não o `simulaProducao` do DemoSurfaceProductionTest) porque
 * o Pest carrega todos os arquivos de teste no mesmo processo e funções
 * globais homônimas colidem.
 */
function simulaProducaoAdmin(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

/**
 * Estado de partida: nenhuma restrição e nenhum opt-out declarado — exatamente
 * o que o compose de produção entregava.
 */
beforeEach(function (): void {
    config()->set('security.admin.allowed_ips', []);
    config()->set('security.admin.allow_any_ip', false);
});

// -----------------------------------------------------------------------------
// ITEM 1 — em produção, allowlist vazia NÃO significa "sem restrição"
// -----------------------------------------------------------------------------

it('produção sem allowlist RECUSA o /admin, mesmo para admin ativo', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('produção sem allowlist RECUSA o /horizon, mesmo para admin ativo', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();

    $this->actingAs($admin)->get('/horizon')->assertForbidden();
});

it('produção sem allowlist RECUSA também a tela de login do painel', function (): void {
    simulaProducaoAdmin();

    // A barreira é a PRIMEIRA da pilha: recusa antes de o painel ser montado,
    // então nem o formulário de login chega a existir para ser sondado.
    $this->get('/admin/login')->assertForbidden();
});

it('a recusa é confinada à superfície administrativa: o resto da aplicação atende', function (): void {
    simulaProducaoAdmin();

    $this->get('/')->assertOk();
    $this->get('/api/health')->assertOk();
    $this->get('/up')->assertOk();
});

it('o veredito de recusa é reconhecível fora do ciclo HTTP', function (): void {
    simulaProducaoAdmin();

    expect(AdminIpAllowlist::missingInProduction())->toBeTrue();

    config()->set('security.admin.allowed_ips', ['203.0.113.10']);

    expect(AdminIpAllowlist::missingInProduction())->toBeFalse();
});

// -----------------------------------------------------------------------------
// ITEM 1 — o opt-out explícito libera, e é reconhecido como decisão
// -----------------------------------------------------------------------------

it('ADMIN_ALLOW_ANY_IP libera o /admin e o /horizon em produção', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();
    config()->set('security.admin.allow_any_ip', true);

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($admin)->get('/horizon')->assertOk();
});

it('o opt-out é reconhecido como decisão explícita, para o aviso do log', function (): void {
    simulaProducaoAdmin();

    expect(AdminIpAllowlist::anyIpAllowedInProductionByOptOut())->toBeFalse();

    config()->set('security.admin.allow_any_ip', true);

    expect(AdminIpAllowlist::anyIpAllowedInProductionByOptOut())->toBeTrue();
});

it('faixa universal na própria lista também conta como opt-out, para não escapar do aviso', function (string $faixa): void {
    simulaProducaoAdmin();
    config()->set('security.admin.allowed_ips', [$faixa]);

    expect(AdminIpAllowlist::anyIpAllowedInProductionByOptOut())->toBeTrue()
        ->and(AdminIpAllowlist::universalEntries())->toBe([$faixa]);
})->with(['0.0.0.0/0', '::/0']);

it('silêncio nunca significa permitido: o opt-out não tem padrão verdadeiro', function (): void {
    simulaProducaoAdmin();
    config()->set('security.admin.allow_any_ip', null);

    expect(AdminIpAllowlist::anyIpAllowedByDeclaration())->toBeFalse()
        ->and(AdminIpAllowlist::missingInProduction())->toBeTrue();
});

it('a lista declarada VENCE o opt-out: ele só responde o que significa lista vazia', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();
    config()->set('security.admin.allowed_ips', ['10.10.10.10']);
    config()->set('security.admin.allow_any_ip', true);

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

// -----------------------------------------------------------------------------
// ITEM 6 — IP exato, faixa CIDR e IPv6
// -----------------------------------------------------------------------------

it('aceita IP exato, faixa CIDR e IPv6 — dentro da lista passa', function (string $entrada, string $ip): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();
    config()->set('security.admin.allowed_ips', [$entrada]);

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/admin')
        ->assertOk();
})->with([
    'IPv4 exato' => ['203.0.113.10', '203.0.113.10'],
    'faixa CIDR IPv4' => ['198.51.100.0/24', '198.51.100.77'],
    'IPv6 exato' => ['2001:db8::1', '2001:db8::1'],
    'prefixo IPv6' => ['2001:db8::/32', '2001:db8:abcd::99'],
]);

it('IP fora da lista é recusado, em IPv4 e IPv6', function (string $entrada, string $ip): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();
    config()->set('security.admin.allowed_ips', [$entrada]);

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/admin')
        ->assertForbidden();
})->with([
    'IPv4 vizinho' => ['203.0.113.10', '203.0.113.11'],
    'fora da faixa CIDR' => ['198.51.100.0/24', '198.51.101.1'],
    'IPv6 vizinho' => ['2001:db8::1', '2001:db8::2'],
    'fora do prefixo IPv6' => ['2001:db8::/32', '2001:dead::1'],
]);

it('espaços na lista do .env não invalidam a entrada em silêncio', function (): void {
    // `ADMIN_ALLOWED_IPS=10.0.0.1, 10.0.0.2` é como uma pessoa escreve uma
    // lista. O segundo valor chegava com espaço na frente e o IpUtils o
    // rejeitava sem dizer nada — allowlist que tranca quem está listado é
    // allowlist que a operação desliga.
    config()->set('security.admin.allowed_ips', ['10.0.0.1', ' 10.0.0.2', '   ']);

    expect(AdminIpAllowlist::entries())->toBe(['10.0.0.1', '10.0.0.2'])
        ->and(AdminIpAllowlist::permits('10.0.0.2'))->toBeTrue();
});

it('sem endereço de origem nenhuma allowlist é satisfeita', function (): void {
    config()->set('security.admin.allowed_ips', ['10.0.0.1']);

    expect(AdminIpAllowlist::permits(null))->toBeFalse()
        ->and(AdminIpAllowlist::permits(''))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ITEM 4 — como o IP do cliente é obtido (ver TrustedProxies)
// -----------------------------------------------------------------------------

it('sem proxies confiáveis o header de proxy é ignorado: não há bypass por X-Forwarded-For', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoAdmin();
    config()->set('security.admin.allowed_ips', ['203.0.113.10']);

    // Cliente em 198.51.100.5 alegando ser o IP permitido. O Laravel só olha
    // X-Forwarded-For quando há proxy confiável declarado — e o kit ainda não
    // declara nenhum (ver TrustedProxies).
    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
        ->get('/admin', ['X-Forwarded-For' => '203.0.113.10'])
        ->assertForbidden();
});

it('reconhece a cegueira de proxy — o sintoma de allowlist comparada com o endereço errado', function (): void {
    expect(Request::getTrustedProxies())->toBe([]);

    $comHeader = Request::create('/admin', 'GET', server: ['HTTP_X_FORWARDED_FOR' => '203.0.113.10']);
    $semHeader = Request::create('/admin');

    expect(AdminIpAllowlist::proxyBlind($comHeader))->toBeTrue()
        ->and(AdminIpAllowlist::proxyBlind($semHeader))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ITEM 2 — fora de produção, nada muda
// -----------------------------------------------------------------------------

it('fora de produção a lista vazia continua liberando', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    expect(app()->isProduction())->toBeFalse()
        ->and(AdminIpAllowlist::missingInProduction())->toBeFalse();

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($admin)->get('/horizon')->assertOk();
});

it('fora de produção a lista preenchida continua sendo obedecida', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    config()->set('security.admin.allowed_ips', ['10.10.10.10']);

    $this->actingAs($admin)->get('/admin')->assertForbidden();

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->get('/admin')
        ->assertOk();
});

// -----------------------------------------------------------------------------
// ITEM 5 — a MESMA barreira nos downloads de export do painel
// -----------------------------------------------------------------------------

it('os downloads de export/import do Filament estão atrás da barreira de origem', function (): void {
    // O pacote registra esse grupo com ['web'] apenas, FORA do painel: o
    // arquivo gerado a partir do /admin (tabela de usuários, logs de
    // requisição) era entregue por uma rota que a barreira do painel não
    // cobria. Mesma superfície administrativa, mesma barreira.
    expect(app('router')->getMiddlewareGroups()['filament.actions'] ?? [])
        ->toContain(EnsureAdminIpAllowed::class);
});

// -----------------------------------------------------------------------------
// ITEM 7 — revogar acesso revoga em TODAS as superfícies
// -----------------------------------------------------------------------------

it('admin desativado com sessão viva perde o /horizon, não só o /admin', function (UserStatus $status): void {
    // O gate do Horizon olhava apenas is_admin. Bloquear a conta tirava o
    // /admin (o Filament consulta canAccessPanel a cada requisição) e NÃO
    // tirava o /horizon: com a sessão ainda viva, o administrador recém
    // desativado continuava operando a fila — retry de job, payload de job
    // falho, métricas. Revogação que não revoga em todo lugar não é revogação.
    $admin = User::factory()->create(['is_admin' => true, 'status' => $status]);

    $this->actingAs($admin)->get('/admin')->assertForbidden();

    // O /horizon está no grupo `web`, onde o EnsureAccountIsActive encerra a
    // sessão de conta não ativa antes até do gate: o admin desativado sai
    // deslogado e vai ao login. O gate (exige conta ativa) continua sendo a
    // segunda camada — coberto isoladamente no teste seguinte.
    $this->actingAs($admin)->get('/horizon')->assertRedirect(route('login'));
    $this->assertGuest();
})->with([
    'bloqueado' => UserStatus::Blocked,
    'pendente' => UserStatus::Pending,
]);

it('o gate viewHorizon exige is_admin E conta ativa', function (): void {
    $ativo = User::factory()->create(['is_admin' => true]);
    $bloqueado = User::factory()->create(['is_admin' => true, 'status' => UserStatus::Blocked]);
    $comum = User::factory()->create();

    expect(Gate::forUser($ativo)->check('viewHorizon', [$ativo]))->toBeTrue()
        ->and(Gate::forUser($bloqueado)->check('viewHorizon', [$bloqueado]))->toBeFalse()
        ->and(Gate::forUser($comum)->check('viewHorizon', [$comum]))->toBeFalse();
});

it('o Authenticate do Horizon está declarado no grupo de rotas, não só no vendor', function (): void {
    // A autorização do dashboard dependia de o pacote aplicar o middleware de
    // dentro do construtor do controller base dele. Declarado aqui, o gate
    // vale por contrato do kit.
    expect(config('horizon.middleware'))
        ->toContain(EnsureAdminIpAllowed::class)
        ->toContain(Authenticate::class);
});
