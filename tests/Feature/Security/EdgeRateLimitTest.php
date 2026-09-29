<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// Limite de requisições da BORDA: antes, só o grupo
// `api` tinha throttle — 20 de 20 `GET /` davam 200 e um flood anônimo de 404
// virava INSERT + UPDATE por requisição em `request_logs`. O EdgeRateLimit é
// global, por cliente (IP ou prefixo IPv6), e roda antes da varredura de
// ataque e da trilha em banco.

/**
 * Dispara N requisições GET e devolve a lista de status HTTP.
 *
 * @return list<int>
 */
function floodGet(object $test, string $uri, int $times, array $server = []): array
{
    $statuses = [];

    for ($i = 0; $i < $times; $i++) {
        $statuses[] = $test->withServerVariables($server)->get($uri)->getStatusCode();
    }

    return $statuses;
}

it('limita as páginas web por IP: passa do teto, recebe 429', function () {
    config()->set('security.rate_limit.web', 3);

    expect(floodGet($this, '/', 3))->toBe([200, 200, 200]);

    $this->get('/')->assertTooManyRequests();
});

it('limita também o flood de rota INEXISTENTE (404 de varredura)', function () {
    config()->set('security.rate_limit.web', 3);

    expect(floodGet($this, '/wp-login.php', 3))->toBe([404, 404, 404]);

    $this->get('/wp-login.php')->assertTooManyRequests();
});

it('cobre o /up, que é registrado fora dos grupos de rota', function () {
    config()->set('security.rate_limit.web', 2);

    expect(floodGet($this, '/up', 2))->toBe([200, 200]);

    $this->get('/up')->assertTooManyRequests();
});

it('o 429 da web sai com os headers de segurança, Retry-After e página traduzida', function () {
    config()->set('security.rate_limit.web', 1);

    $this->withHeader('Accept-Language', 'pt-BR')->get('/');

    $response = $this->withHeader('Accept-Language', 'pt-BR')->get('/');

    $response->assertTooManyRequests()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertSee('Muitas requisições')
        ->assertSee('<html lang="pt-BR">', false);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('a página 429 respeita o idioma do navegador (en/es) antes da sessão existir', function (string $acceptLanguage, string $expected, string $htmlLang) {
    config()->set('security.rate_limit.web', 1);

    $this->withHeader('Accept-Language', $acceptLanguage)->get('/');

    $this->withHeader('Accept-Language', $acceptLanguage)->get('/')
        ->assertTooManyRequests()
        ->assertSee($expected)
        ->assertSee('<html lang="'.$htmlLang.'">', false);
})->with([
    'inglês' => ['en-US,en;q=0.9', 'Too many requests', 'en'],
    'espanhol' => ['es-ES,es;q=0.9', 'Demasiadas solicitudes', 'es'],
    'português' => ['pt-BR,pt;q=0.9', 'Muitas requisições', 'pt-BR'],
]);

it('a página 429 respeita o cookie de idioma do visitante (cifrado) acima do navegador', function () {
    config()->set('security.rate_limit.web', 1);

    $this->withCookie('locale', 'en')->withHeader('Accept-Language', 'es')->get('/');

    $this->withCookie('locale', 'en')->withHeader('Accept-Language', 'es')->get('/')
        ->assertTooManyRequests()
        ->assertSee('Too many requests');
});

it('cookie de idioma adulterado (não cifrado pela aplicação) é ignorado', function () {
    config()->set('security.rate_limit.web', 1);

    $this->withUnencryptedCookie('locale', 'en')->withHeader('Accept-Language', 'es')->get('/');

    $this->withUnencryptedCookie('locale', 'en')->withHeader('Accept-Language', 'es')->get('/')
        ->assertTooManyRequests()
        ->assertSee('Demasiadas solicitudes');
});

it('na API o 429 da borda sai no envelope de erro padrão', function () {
    config()->set('security.rate_limit.web', 1);
    config()->set('security.rate_limit.api', 100);

    $this->getJson('/api/health')->assertOk();

    $this->getJson('/api/health')
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('não quebra o throttle:api — ele continua sendo o limite mais estreito da API', function () {
    config()->set('security.rate_limit.api', 2);

    $this->get('/api/health')->assertOk();
    $this->get('/api/health')->assertOk();
    $this->get('/api/health')->assertTooManyRequests();

    // A borda tem orçamento próprio: a web do mesmo IP segue livre.
    $this->get('/')->assertOk();
});

it('não quebra o throttle:sensitive — ele continua valendo abaixo do teto da borda', function () {
    config()->set('security.rate_limit.sensitive', 2);

    Route::post('/_test/sensivel-web', fn () => response('ok'))->middleware('throttle:sensitive');

    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->post('/_test/sensivel-web')->assertOk();
    $this->post('/_test/sensivel-web')->assertOk();
    $this->post('/_test/sensivel-web')->assertTooManyRequests()->assertSee('Muitas requisições');
});

it('isola o orçamento por IP: outro cliente não é afetado', function () {
    config()->set('security.rate_limit.web', 1);

    $this->get('/')->assertOk();
    $this->get('/')->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/')->assertOk();
});

it('conta IPv6 pelo prefixo /64: trocar de endereço dentro da mesma rede não dá orçamento novo', function () {
    config()->set('security.rate_limit.web', 2);

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::1'])->get('/')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::aaaa'])->get('/')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff:ffff:ffff:ffff'])->get('/')->assertTooManyRequests();

    // Outro /64 é outro cliente.
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get('/')->assertOk();
});

it('o limite zera depois da janela', function () {
    config()->set('security.rate_limit.web', 1);

    $this->get('/')->assertOk();
    $this->get('/')->assertTooManyRequests();

    $this->travel(61)->seconds();

    $this->get('/')->assertOk();
});

it('recusa acima do limite NÃO escreve uma linha por requisição: só a primeira recusa da janela', function () {
    config()->set('security.rate_limit.web', 2);

    floodGet($this, '/', 30);

    $throttled = RequestLog::query()->where('http_status_response', 429)->get();

    // 2 páginas servidas + 1 registro da recusa (as outras 27 não tocam o banco).
    expect(RequestLog::query()->count())->toBe(3)
        ->and($throttled)->toHaveCount(1)
        ->and($throttled->first()->payload)->toMatchArray(['_resumo' => 'rate_limited', 'limite' => 2])
        ->and($throttled->first()->endpoint)->toBe('/');
});

it('a recusa acima do limite não lê nem varre o corpo (o ataque nem chega ao detector)', function () {
    config()->set('security.rate_limit.web', 1);

    Route::post('/api/_test/echo', fn () => response()->json(['ok' => true]));

    $this->postJson('/api/_test/echo', ['ok' => 'limpo'])->assertOk();

    $this->postJson('/api/_test/echo', ['comment' => '<script>alert(1)</script>'])->assertTooManyRequests();

    $throttled = RequestLog::query()->where('http_status_response', 429)->sole();

    expect(json_encode($throttled->payload))->not->toContain('script');
});
