<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Http\TrustedHosts;

// =============================================================================
// QUAIS VALORES DE `Host` A APLICAÇÃO ACEITA
//
// O achado que originou estes testes (PoC rodado contra o próprio kit):
//
//   curl -H "Host: evil.example.com" http://localhost:8180/dashboard
//   → Location: http://evil.example.com/login
//
// O Laravel monta toda URL absoluta a partir do `Host`, que é dado do cliente.
// Não era explorável entre sites — o navegador não deixa forjar o `Host` da
// vítima —, mas a peça irmã desta (TrustedProxies) existe PARA declarar proxies
// confiáveis, e é isso que transforma o reflexo do host em open redirect real
// atrás de CDN. As duas entram juntas.
// =============================================================================

/**
 * Nome próprio: o Pest carrega todos os arquivos no mesmo processo e funções
 * globais homônimas colidem (ver AdminIpAllowlistTest).
 */
function simulaProducaoHost(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

/*
 * COMO O HOST CHEGA NOS TESTES: o `Host` passado como header é SOBRESCRITO pelo
 * host da URL (o `Request::create` do Symfony monta `HTTP_HOST` a partir dela).
 * Por isso o host forjado vai na própria URL — `http://evil.example.com/...` —,
 * que produz exatamente o `HTTP_HOST` que o nginx repassa ao php-fpm no PoC.
 */

beforeEach(function (): void {
    config()->set('security.hosts.trusted', []);
    config()->set('app.url', 'https://app.example.com');
    TrustedHosts::flushAnnouncement();
});

/**
 * A lista de hosts confiáveis é estado ESTÁTICO do Symfony: sem esta limpeza
 * ela vazaria para os outros arquivos de teste da suíte.
 */
afterEach(function (): void {
    Request::setTrustedHosts([]);
});

// -----------------------------------------------------------------------------
// O PoC — `Host` forjado não contamina mais nada
// -----------------------------------------------------------------------------

it('PoC: Host forjado é RECUSADO com 400 e não sai refletido no Location do redirect', function (): void {
    // Página de erro de produção: a de debug cita o host na própria mensagem da
    // exceção, o que não é URL gerada — o que se verifica aqui é o que o
    // visitante recebe.
    config()->set('app.debug', false);

    $resposta = $this->get('http://evil.example.com/dashboard');

    $resposta->assertBadRequest();
    expect($resposta->headers->get('Location'))->toBeNull()
        ->and((string) $resposta->getContent())->not->toContain('evil.example.com');
});

it('o 400 de host recusado já sai com os headers de segurança', function (): void {
    $this->get('http://evil.example.com/dashboard')
        ->assertBadRequest()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('o host legítimo continua funcionando, e é ele que monta a URL do redirect', function (): void {
    $resposta = $this->get('https://app.example.com/dashboard');

    $resposta->assertRedirect('https://app.example.com/login');
});

it('o host da APP_URL entra com os subdomínios dele', function (string $host): void {
    Route::get('/_test/host', fn () => ['host' => request()->getHost()]);

    $this->get("https://{$host}/_test/host")
        ->assertOk()
        ->assertJsonPath('host', $host);
})->with([
    'o próprio host' => ['app.example.com'],
    'subdomínio' => ['painel.app.example.com'],
]);

it('parecido com o host da APP_URL não é o host da APP_URL', function (string $host): void {
    $this->get("https://{$host}/dashboard")->assertBadRequest();
})->with([
    'sufixo colado' => ['evilapp.example.com'],
    'domínio que termina igual' => ['app.example.com.evil.net'],
]);

it('Host forjado não contamina rota que gera URL absoluta', function (): void {
    Route::get('/_test/url', fn () => ['url' => url('/entrar')]);

    $this->get('http://evil.example.com/_test/url')->assertBadRequest();

    $this->get('https://app.example.com/_test/url')
        ->assertOk()
        ->assertJsonPath('url', 'https://app.example.com/entrar');
});

it('a recusa acontece na ENTRADA: rota que não gera URL também é barrada', function (): void {
    Route::get('/_test/sem-url', fn () => ['ok' => true]);

    $this->get('http://evil.example.com/_test/sem-url')->assertBadRequest();
});

// -----------------------------------------------------------------------------
// A LISTA declarada, e o loopback que é sempre aceito
// -----------------------------------------------------------------------------

it('TRUSTED_HOSTS aceita host exato e `*.dominio`', function (string $declarado, string $host): void {
    config()->set('security.hosts.trusted', [$declarado]);
    Route::get('/_test/host', fn () => ['host' => request()->getHost()]);

    $this->get("https://{$host}/_test/host")->assertOk()->assertJsonPath('host', $host);
})->with([
    'host exato' => ['staging.exemplo.com', 'staging.exemplo.com'],
    'domínio e subdomínios' => ['*.exemplo.com', 'www.exemplo.com'],
    'domínio raiz pelo curinga' => ['*.exemplo.com', 'exemplo.com'],
    'maiúsculas na declaração' => ['Staging.Exemplo.com', 'staging.exemplo.com'],
]);

it('host declarado como exato NÃO libera os subdomínios dele', function (): void {
    config()->set('security.hosts.trusted', ['staging.exemplo.com']);

    $this->get('https://evil.staging.exemplo.com/dashboard')->assertBadRequest();
});

it('/up responde por loopback (sondas e healthchecks)', function (string $url): void {
    $this->get($url)->assertOk();
})->with([
    'localhost' => ['http://localhost/up'],
    'localhost com porta (o dev do kit)' => ['http://localhost:8180/up'],
    'IPv4 de loopback' => ['http://127.0.0.1/up'],
]);

it('a lista entregue ao Symfony nunca é vazia (vazia significaria "sem restrição")', function (?string $url): void {
    config()->set('app.url', $url);

    expect(TrustedHosts::patterns())->not->toBe([]);
})->with([
    'APP_URL vazia' => [''],
    'APP_URL nula' => [null],
    'APP_URL de exemplo' => ['http://localhost'],
]);

// -----------------------------------------------------------------------------
// ONDE A VALIDAÇÃO VALE: em todo ambiente
// -----------------------------------------------------------------------------

it('vale também em `local`: o PoC não pode continuar reproduzível no ambiente de dev', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('app.url', 'http://localhost:8180');

    $this->get('http://evil.example.com/dashboard')->assertBadRequest();
    $this->get('http://localhost:8180/dashboard')->assertRedirect('http://localhost:8180/login');
});

// -----------------------------------------------------------------------------
// PRODUÇÃO COM APP_URL DE EXEMPLO — recusa, e diz no log por quê
// -----------------------------------------------------------------------------

it('produção com APP_URL de exemplo e sem TRUSTED_HOSTS recusa host externo e AVISA uma vez', function (): void {
    Log::spy();

    simulaProducaoHost();
    config()->set('app.url', 'http://localhost');

    $this->get('https://site-real.example.com/up')->assertBadRequest();
    $this->get('https://site-real.example.com/up')->assertBadRequest();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'APP_URL ainda aponta para localhost'))
        ->once();

    $this->get('http://localhost/up')->assertOk();
});

it('recusa com a configuração certa não gera o aviso de configuração', function (): void {
    Log::spy();

    simulaProducaoHost();

    $this->get('http://evil.example.com/up')->assertBadRequest();

    Log::shouldNotHaveReceived('warning');
});

// -----------------------------------------------------------------------------
// A JUNÇÃO COM OS PROXIES: o host resultante é validado, venha de onde vier
// -----------------------------------------------------------------------------

it('X-Forwarded-Host de proxy confiável também é validado quando ele passa a ser obedecido', function (): void {
    config()->set('security.proxies.trusted', ['10.0.0.0/8']);
    config()->set('security.proxies.trust_forwarded_host', true);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->get('https://app.example.com/dashboard', ['X-Forwarded-Host' => 'evil.example.com'])
        ->assertBadRequest();
});

it('X-Forwarded-Host de origem NÃO confiável é ignorado: vale o Host real', function (): void {
    $resposta = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
        ->get('https://app.example.com/dashboard', ['X-Forwarded-Host' => 'evil.example.com']);

    $resposta->assertRedirect('https://app.example.com/login');
});
