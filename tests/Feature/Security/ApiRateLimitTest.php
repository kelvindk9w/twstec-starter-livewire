<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\Tenancy\Middleware\ResolveTenant;

// =============================================================================
// LIMITE DA API POR CHAVE, NÃO POR IP (Twstec\Kit\Foundation\Security\ApiRateLimit).
//
// Antes, o `throttle:api` rodava antes do `resolve.tenant` e contava sempre
// por IP: duas integrações atrás do mesmo NAT dividiam o orçamento, e a mesma
// chave ganhava orçamento novo a cada IP. Agora:
//
//   - requisição autenticada conta pela CHAVE (ou pelo tenant, se configurado);
//   - falha de autenticação conta por IP + chave pública, com um teto por IP
//     mais alto que não barra chave já autenticada daquele IP — um laço de
//     chaves inválidas não escapa, e o erro de um vizinho de NAT não derruba
//     a integração legítima;
//   - todo 429 sai no envelope de erro padrão da API, com Retry-After.
// =============================================================================

const ROTA_API = '/api/v1/projects';

it('duas chaves diferentes do MESMO IP têm orçamentos independentes', function () {
    config()->set('security.rate_limit.api', 2);

    ['api_key' => $chaveA, 'secret_key' => $segredoA] = criarChave(User::factory()->create());
    ['api_key' => $chaveB, 'secret_key' => $segredoB] = criarChave(User::factory()->create());

    $this->getJson(ROTA_API, headersApi($chaveA, $segredoA))->assertOk();
    $this->getJson(ROTA_API, headersApi($chaveA, $segredoA))->assertOk();
    $this->getJson(ROTA_API, headersApi($chaveA, $segredoA))->assertTooManyRequests();

    // A chave B, do mesmo IP, não foi tocada pelo estouro da A.
    $this->getJson(ROTA_API, headersApi($chaveB, $segredoB))->assertOk();
    $this->getJson(ROTA_API, headersApi($chaveB, $segredoB))->assertOk();
});

it('o limite acompanha a CHAVE: trocar de IP não dá orçamento novo', function () {
    config()->set('security.rate_limit.api', 2);

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave(User::factory()->create());

    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();
    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->getJson(ROTA_API, headersApi($chave, $segredo))
        ->assertTooManyRequests();
});

it('o 429 por chave sai no envelope de erro padrão da API, traduzido e com Retry-After', function () {
    config()->set('security.rate_limit.api', 1);

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave(User::factory()->create());

    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();

    $response = $this->getJson(ROTA_API, headersApi($chave, $segredo));

    assertErroApi($response, 429, 'too_many_requests')
        ->assertJsonPath('error.message', __('api.errors.too_many_requests'))
        ->assertHeader('Retry-After');
});

it('com RATE_LIMIT_API_BY=tenant as chaves do mesmo dono somam', function () {
    config()->set('security.rate_limit.api', 2);
    config()->set('security.rate_limit.api_by', 'tenant');

    $dono = User::factory()->create();
    ['api_key' => $chaveA, 'secret_key' => $segredoA] = criarChave($dono);
    ['api_key' => $chaveB, 'secret_key' => $segredoB] = criarChave($dono);

    $this->getJson(ROTA_API, headersApi($chaveA, $segredoA))->assertOk();
    $this->getJson(ROTA_API, headersApi($chaveB, $segredoB))->assertOk();
    $this->getJson(ROTA_API, headersApi($chaveA, $segredoA))->assertTooManyRequests();

    // Outro dono segue com orçamento próprio.
    ['api_key' => $outra, 'secret_key' => $outroSegredo] = criarChave(User::factory()->create());
    $this->getJson(ROTA_API, headersApi($outra, $outroSegredo))->assertOk();
});

it('chave inválida em laço recebe 429 (não escapa do limite por rodar antes do throttle)', function () {
    config()->set('security.rate_limit.api_auth_failures', 3);
    // Limite por chave folgado: prova que o 429 vem do balde de FALHAS.
    config()->set('security.rate_limit.api', 1000);

    $invalida = ['X-Api-Key' => 'pk_test_inexistente', 'Authorization' => 'Bearer sk_test_errada'];

    for ($i = 0; $i < 3; $i++) {
        $this->getJson(ROTA_API, $invalida)->assertUnauthorized();
    }

    $response = $this->getJson(ROTA_API, $invalida);

    assertErroApi($response, 429, 'too_many_requests')
        ->assertJsonPath('error.message', __('api.errors.too_many_requests'))
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('sem credencial nenhuma também conta como falha', function () {
    config()->set('security.rate_limit.api_auth_failures', 2);

    $this->getJson(ROTA_API)->assertUnauthorized();
    $this->getJson(ROTA_API)->assertUnauthorized();
    $this->getJson(ROTA_API)->assertTooManyRequests();
});

it('credencial que errou demais é recusada até com a secreta certa; outras chaves do mesmo IP seguem', function () {
    config()->set('security.rate_limit.api_auth_failures', 2);

    ['api_key' => $alvo, 'secret_key' => $segredoAlvo] = criarChave(User::factory()->create());
    ['api_key' => $vizinha, 'secret_key' => $segredoVizinha] = criarChave(User::factory()->create());

    $errada = ['X-Api-Key' => $alvo->public_key, 'Authorization' => 'Bearer sk_test_errada'];

    $this->getJson(ROTA_API, $errada)->assertUnauthorized();
    $this->getJson(ROTA_API, $errada)->assertUnauthorized();

    // A secreta certa, depois, não zera o balde daquela credencial naquele IP.
    $this->getJson(ROTA_API, headersApi($alvo, $segredoAlvo))->assertTooManyRequests();

    // Outra chave, do MESMO IP, não é afetada pelo erro de terceiros.
    $this->getJson(ROTA_API, headersApi($vizinha, $segredoVizinha))->assertOk();

    // A mesma credencial, de outro IP, também não.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
        ->getJson(ROTA_API, headersApi($alvo, $segredoAlvo))
        ->assertOk();
});

it('NAT compartilhado: um vizinho esgotando o teto do IP não derruba chave válida já conhecida daquele IP', function () {
    config()->set('security.rate_limit.api_auth_failures', 2);
    config()->set('security.rate_limit.api_auth_failures_per_ip', 5);

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave(User::factory()->create());

    // A integração legítima já trabalha a partir deste IP.
    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();

    // Um vizinho de NAT erra com chaves públicas sempre diferentes até
    // estourar o teto do IP (cada uma com balde por credencial novo).
    for ($i = 0; $i < 5; $i++) {
        $this->getJson(ROTA_API, ['X-Api-Key' => "pk_test_vizinho{$i}", 'Authorization' => 'Bearer sk_test_y'])
            ->assertUnauthorized();
    }

    // Acima do teto, chave pública desconhecida deste IP nem é verificada...
    $this->getJson(ROTA_API, ['X-Api-Key' => 'pk_test_vizinho_novo', 'Authorization' => 'Bearer sk_test_y'])
        ->assertTooManyRequests();

    // ...mas a chave válida já conhecida deste IP segue funcionando.
    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();
    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();
});

it('acima do teto do IP, chave válida que nunca autenticou daquele IP espera a janela (troca documentada)', function () {
    config()->set('security.rate_limit.api_auth_failures_per_ip', 3);

    ['api_key' => $nova, 'secret_key' => $segredo] = criarChave(User::factory()->create());

    for ($i = 0; $i < 3; $i++) {
        $this->getJson(ROTA_API, ['X-Api-Key' => "pk_test_rodizio{$i}", 'Authorization' => 'Bearer sk_test_y'])
            ->assertUnauthorized();
    }

    $response = $this->getJson(ROTA_API, headersApi($nova, $segredo));

    assertErroApi($response, 429, 'too_many_requests')->assertHeader('Retry-After');

    // De outro IP, normal; e, passada a janela, deste também.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.78'])
        ->getJson(ROTA_API, headersApi($nova, $segredo))
        ->assertOk();

    $this->travel(61)->seconds();

    $this->getJson(ROTA_API, headersApi($nova, $segredo))->assertOk();
});

it('inventar uma chave pública por tentativa não escapa: o teto do IP pega o rodízio', function () {
    config()->set('security.rate_limit.api_auth_failures', 1000);
    config()->set('security.rate_limit.api_auth_failures_per_ip', 4);

    for ($i = 0; $i < 4; $i++) {
        $this->getJson(ROTA_API, ['X-Api-Key' => "pk_test_r{$i}", 'Authorization' => 'Bearer sk_test_y'])
            ->assertUnauthorized();
    }

    $this->getJson(ROTA_API, ['X-Api-Key' => 'pk_test_r99', 'Authorization' => 'Bearer sk_test_y'])
        ->assertTooManyRequests();
});

it('a marca de cliente conhecido expira: depois do TTL a chave volta a depender do teto do IP', function () {
    config()->set('security.rate_limit.api_auth_failures_per_ip', 2);
    config()->set('security.rate_limit.api_auth_known_client_ttl_seconds', 120);

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave(User::factory()->create());

    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();

    $this->travel(121)->seconds();

    $this->getJson(ROTA_API, ['X-Api-Key' => 'pk_test_a', 'Authorization' => 'Bearer sk_test_y'])->assertUnauthorized();
    $this->getJson(ROTA_API, ['X-Api-Key' => 'pk_test_b', 'Authorization' => 'Bearer sk_test_y'])->assertUnauthorized();

    $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertTooManyRequests();
});

it('os contadores de falha zeram sozinhos ao fim da janela', function () {
    config()->set('security.rate_limit.api_auth_failures', 1);

    $invalida = ['X-Api-Key' => 'pk_test_x', 'Authorization' => 'Bearer sk_test_y'];

    $this->getJson(ROTA_API, $invalida)->assertUnauthorized();
    $this->getJson(ROTA_API, $invalida)->assertTooManyRequests();

    $this->travel(61)->seconds();

    $this->getJson(ROTA_API, $invalida)->assertUnauthorized();
});

it('rota da API sem autenticação (/api/health) também tem limite, por IP', function () {
    config()->set('security.rate_limit.api', 2);

    $this->getJson('/api/health')->assertOk();
    $this->getJson('/api/health')->assertOk();

    assertErroApi($this->getJson('/api/health'), 429, 'too_many_requests');

    // Outro IP tem orçamento próprio.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])->getJson('/api/health')->assertOk();
});

it('requisições autenticadas com sucesso não gastam o balde de falhas', function () {
    config()->set('security.rate_limit.api_auth_failures', 2);

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave(User::factory()->create());

    for ($i = 0; $i < 5; $i++) {
        $this->getJson(ROTA_API, headersApi($chave, $segredo))->assertOk();
    }
});

it('em toda rota com resolve.tenant, o throttle roda DEPOIS dele', function () {
    $rotas = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $rota): bool => in_array('resolve.tenant', $rota->gatherMiddleware(), true));

    expect($rotas)->not->toBeEmpty();

    foreach ($rotas as $rota) {
        $ordem = array_values(array_map(
            fn (string $middleware): string => explode(':', $middleware, 2)[0],
            array_filter(app('router')->gatherRouteMiddleware($rota), 'is_string'),
        ));

        $tenant = array_search(ResolveTenant::class, $ordem, true);
        $throttle = array_search(ThrottleRequests::class, $ordem, true);

        expect($tenant)->not->toBeFalse()
            ->and($throttle)->not->toBeFalse()
            ->and($tenant)->toBeLessThan($throttle, "Rota {$rota->uri()}: throttle antes do resolve.tenant");
    }
});
