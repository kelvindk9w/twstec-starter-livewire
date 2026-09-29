<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Http\TrustedProxies;
use Twstec\Kit\Foundation\Security\AdminIpAllowlist;

// =============================================================================
// QUEM PODE DIZER QUEM É O CLIENTE (proxies confiáveis)
//
// O achado que originou estes testes: o kit não declarava proxy confiável
// nenhum. Atrás de CDN/load balancer isso quebrava quatro coisas de uma vez e
// em silêncio — a allowlist de IP do /admin comparava o endereço do PROXY, o
// rate limiting agrupava o mundo inteiro num balde só, a trilha de auditoria
// gravava sempre o mesmo `ip` e o HTTPS deixava de ser detectado.
//
// Os dois lados são testados, porque o conserto tem um jeito errado famoso: o
// header obedecido quando o proxy é confiável, e IGNORADO quando não é. Se o
// segundo falhar, qualquer cliente se declara qualquer IP.
// =============================================================================

/**
 * Nome próprio: o Pest carrega todos os arquivos de teste no mesmo processo e
 * funções globais homônimas colidem (ver AdminIpAllowlistTest).
 */
function simulaProducaoProxy(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

/**
 * Rota mínima que devolve o que a aplicação ENTENDEU da requisição.
 */
function rotaDeInspecao(): void
{
    Route::get('/api/_test/origem', fn () => [
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
        'host' => request()->getHost(),
    ]);
}

beforeEach(function (): void {
    config()->set('security.proxies.trusted', []);
    config()->set('security.proxies.trust_forwarded_host', false);
    config()->set('security.admin.allowed_ips', []);
    config()->set('security.admin.allow_any_ip', false);
});

// -----------------------------------------------------------------------------
// COM PROXY CONFIÁVEL DECLARADO — o header é obedecido
// -----------------------------------------------------------------------------

it('com proxy confiável, o ip() passa a ser o do cliente e não o do proxy', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/origem', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.7');
});

it('a palavra `private` cobre a rede interna do compose, onde o IP do nginx é atribuído pelo Docker', function (string $ipDoProxy): void {
    config()->set('security.proxies.trusted', ['private']);
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => $ipDoProxy])
        ->get('/api/_test/origem', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.7');
})->with([
    'rede padrão do Docker' => ['172.20.0.3'],
    'RFC 1918 /8' => ['10.1.2.3'],
    'RFC 1918 /16' => ['192.168.96.1'],
]);

it('EFEITO NA ALLOWLIST DO ADMIN: com proxy confiável, a lista volta a comparar quem administra', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoProxy();
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    config()->set('security.admin.allowed_ips', ['203.0.113.7']);

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/admin', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();
})->group('admin');

it('EFEITO NO RATE LIMITING: com proxy confiável, cada cliente tem o seu balde', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    config()->set('security.rate_limit.api', 1);

    $primeiro = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/health', ['X-Forwarded-For' => '203.0.113.7']);

    $segundo = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/health', ['X-Forwarded-For' => '198.51.100.20']);

    $primeiro->assertOk();
    $segundo->assertOk();
});

it('EFEITO NA TRILHA DE AUDITORIA: o `ip` gravado é o do cliente, não o do proxy', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();

    $this->assertDatabaseHas('request_logs', ['ip' => '203.0.113.7']);
    $this->assertDatabaseMissing('request_logs', ['ip' => '10.0.0.9']);
});

it('HTTPS é detectado por X-Forwarded-Proto de proxy confiável (cookie Secure e HSTS dependem disso)', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/origem', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJsonPath('secure', true);
});

// -----------------------------------------------------------------------------
// SEM PROXY CONFIÁVEL — o header é ignorado, e é isso que impede o spoofing
// -----------------------------------------------------------------------------

it('sem proxy confiável, X-Forwarded-For é IGNORADO (não há spoofing de IP)', function (): void {
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/origem', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('ip', '10.0.0.9');
});

it('proxy FORA da lista declarada não é obedecido', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/api/_test/origem', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('ip', '192.0.2.50');
});

it('a allowlist do admin não pode ser burlada por X-Forwarded-For de origem não confiável', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    simulaProducaoProxy();
    config()->set('security.admin.allowed_ips', ['203.0.113.7']);

    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/admin', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertForbidden();
})->group('admin');

it('o rate limiting não pode ser zerado trocando de X-Forwarded-For quando ninguém é confiável', function (): void {
    config()->set('security.rate_limit.api', 1);

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/api/health', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/api/health', ['X-Forwarded-For' => '198.51.100.20'])
        ->assertTooManyRequests();
});

it('HTTPS NÃO é aceito de origem não confiável (cliente não decide o esquema)', function (): void {
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/api/_test/origem', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJsonPath('secure', false);
});

// -----------------------------------------------------------------------------
// X-Forwarded-Host fica FORA por padrão — é ele que reescreve o host
// -----------------------------------------------------------------------------

it('X-Forwarded-Host NÃO é obedecido por padrão, nem vindo de proxy confiável', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/origem', ['X-Forwarded-Host' => 'evil.example.com'])
        ->assertOk()
        ->assertJsonPath('host', 'localhost');
});

it('X-Forwarded-Host só passa a valer com a segunda decisão declarada', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    config()->set('security.proxies.trust_forwarded_host', true);
    // O host resultante continua passando pelo TrustedHosts: precisa estar declarado.
    config()->set('security.hosts.trusted', ['outro.example.com']);
    rotaDeInspecao();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/origem', ['X-Forwarded-Host' => 'outro.example.com'])
        ->assertOk()
        ->assertJsonPath('host', 'outro.example.com');
});

it('o conjunto de headers obedecidos inclui o Host só quando declarado', function (): void {
    expect(TrustedProxies::headers() & Request::HEADER_X_FORWARDED_HOST)->toBe(0)
        ->and(TrustedProxies::headers() & Request::HEADER_X_FORWARDED_FOR)->not->toBe(0)
        ->and(TrustedProxies::headers() & Request::HEADER_X_FORWARDED_PROTO)->not->toBe(0);

    config()->set('security.proxies.trust_forwarded_host', true);

    expect(TrustedProxies::headers() & Request::HEADER_X_FORWARDED_HOST)->not->toBe(0);
});

// -----------------------------------------------------------------------------
// O OPT-OUT (`*`) é declarado, nomeado e barulhento
// -----------------------------------------------------------------------------

it('`*` confia em qualquer origem, e é reconhecido como decisão que merece aviso', function (): void {
    config()->set('security.proxies.trusted', ['*']);
    rotaDeInspecao();

    expect(TrustedProxies::trustsEverything())->toBeTrue()
        ->and(TrustedProxies::at())->toBe('*');

    simulaProducaoProxy();
    expect(TrustedProxies::trustsEverythingInProduction())->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('/api/_test/origem', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.7');
});

it('silêncio nunca significa permitido: sem declaração, ninguém é confiável e nada é assumido', function (): void {
    expect(TrustedProxies::declared())->toBeFalse()
        ->and(TrustedProxies::trustsEverything())->toBeFalse()
        ->and(TrustedProxies::at())->toBe([])
        ->and(TrustedProxies::trustsEverythingInProduction())->toBeFalse();
});

// -----------------------------------------------------------------------------
// FIOS SOLTOS DO LOTE 1 que esta peça amarra
// -----------------------------------------------------------------------------

it('proxyBlind() se apaga sozinho quando a declaração de proxies passa a existir', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    rotaDeInspecao();

    // A detecção depende do estado que o middleware instala na requisição, e
    // por isso é medida DENTRO de uma requisição real.
    Route::get('/api/_test/cego', fn () => [
        'cego' => AdminIpAllowlist::proxyBlind(request()),
        'confiaveis' => Request::getTrustedProxies(),
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/cego', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('cego', false);
});

it('proxyBlind() continua denunciando quando há header de proxy e nenhum proxy declarado', function (): void {
    Route::get('/api/_test/cego', fn () => ['cego' => AdminIpAllowlist::proxyBlind(request())]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('/api/_test/cego', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk()
        ->assertJsonPath('cego', true);
});

it('o conserto intuitivo errado é reconhecido: IP de proxy escrito na allowlist do admin', function (): void {
    simulaProducaoProxy();
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    config()->set('security.admin.allowed_ips', ['10.0.0.9', '203.0.113.7']);

    expect(AdminIpAllowlist::proxyEntries())->toBe(['10.0.0.9'])
        ->and(AdminIpAllowlist::proxyEntriesInProduction())->toBeTrue();
});

it('allowlist só com IPs de gente não dispara o aviso de barreira aberta', function (): void {
    simulaProducaoProxy();
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    config()->set('security.admin.allowed_ips', ['203.0.113.7', '198.51.100.0/24']);

    expect(AdminIpAllowlist::proxyEntries())->toBe([])
        ->and(AdminIpAllowlist::proxyEntriesInProduction())->toBeFalse();
});

// -----------------------------------------------------------------------------
// O QUE NÃO PODE QUEBRAR
// -----------------------------------------------------------------------------

it('/up continua respondendo, com e sem proxy declarado', function (): void {
    $this->get('/up')->assertOk();

    config()->set('security.proxies.trusted', ['private']);

    $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.3'])->get('/up')->assertOk();
});
