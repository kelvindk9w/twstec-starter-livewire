<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// Contenção da amplificação de escrita em `request_logs`: cada 404 de varredura anônima gerava INSERT + UPDATE. Agora, de
// cada cliente, só a PRIMEIRA requisição a rota inexistente por janela vai ao
// banco; o resto fica no log de arquivo. REGRA INEGOCIÁVEL:
// tentativa bloqueada pelo SecurityValidation e requisição a rota existente —
// autenticada ou não — continuam gravadas sempre.

beforeEach(function () {
    // Teto da borda alto: aqui se mede a trilha, não o 429.
    config()->set('security.rate_limit.web', 1000);
});

it('flood anônimo de rota inexistente grava UMA linha por cliente por janela, não uma por requisição', function () {
    for ($i = 0; $i < 25; $i++) {
        $this->get('/sonda-'.$i)->assertNotFound();
    }

    $log = RequestLog::query()->sole();

    expect($log->http_status_response)->toBe(404)
        ->and($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->endpoint)->toBe('[unmatched]:1');
});

it('método não permitido (405) também é tráfego de varredura amostrado', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->delete('/')->assertMethodNotAllowed();
    }

    expect(RequestLog::query()->count())->toBe(1);
});

it('as requisições amostradas para fora continuam no log de arquivo', function () {
    $eventos = [];

    $logger = Mockery::mock();
    $logger->shouldIgnoreMissing();
    $logger->shouldReceive('info')->andReturnUsing(function (string $mensagem, array $contexto = []) use (&$eventos): void {
        $eventos[] = [$mensagem, $contexto];
    });

    Log::shouldReceive('channel')->andReturn($logger);
    Log::getFacadeRoot()->shouldIgnoreMissing();

    for ($i = 0; $i < 5; $i++) {
        $this->get('/segredo-na-rota-morta/'.$i);
    }

    $amostradas = array_values(array_filter($eventos, fn (array $e): bool => $e[0] === 'request.unmatched.sampled_out'));

    expect($amostradas)->toHaveCount(4)
        ->and($amostradas[0][1])->toHaveKeys(['correlation_id', 'ip', 'method', 'endpoint'])
        ->and($amostradas[0][1]['endpoint'])->toBe('[unmatched]:2')
        ->and(json_encode($amostradas))->not->toContain('segredo-na-rota-morta');
});

it('a amostragem é por cliente: outro IP tem a sua própria primeira linha', function () {
    $this->get('/sonda')->assertNotFound();
    $this->get('/sonda')->assertNotFound();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/sonda')->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/sonda')->assertNotFound();

    expect(RequestLog::query()->pluck('ip')->sort()->values()->all())->toBe(['127.0.0.1', '198.51.100.7']);
});

it('depois da janela, a próxima sondagem do mesmo cliente volta a ser gravada', function () {
    $this->get('/sonda');
    $this->get('/sonda');

    $this->travel(61)->seconds();

    $this->get('/sonda');

    expect(RequestLog::query()->count())->toBe(2);
});

it('janela 0 desliga a amostragem: toda rota inexistente volta a gerar linha', function () {
    config()->set('security.request_logging.scan_sample_window_seconds', 0);

    for ($i = 0; $i < 5; $i++) {
        $this->get('/sonda');
    }

    expect(RequestLog::query()->count())->toBe(5);
});

it('tentativa de ataque BLOQUEADA é gravada SEMPRE — mesmo no meio de um flood de varredura', function () {
    // Modo `block` (o padrão é `observe` — ver ValidationMode).
    config()->set('security.validation.mode', 'block');
    for ($i = 0; $i < 10; $i++) {
        $this->get('/sonda-'.$i);
    }

    for ($i = 0; $i < 5; $i++) {
        $this->get('/sonda?q=<script>alert('.$i.')</script>')->assertUnprocessable();
        $this->get('/?q=<script>alert('.$i.')</script>')->assertUnprocessable();
    }

    expect(RequestLog::query()->where('status', RequestLogStatus::Bloqueada)->count())->toBe(10)
        ->and(RequestLog::query()->where('http_status_response', 404)->count())->toBe(1);
});

it('rota EXISTENTE anônima continua gravando uma linha por requisição', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->get('/')->assertOk();
    }

    expect(RequestLog::query()->count())->toBe(5);
});

it('requisição AUTENTICADA continua gravada sempre — inclusive quando termina em 404', function () {
    Route::middleware(['web', 'auth'])->get('/_test/recurso/{id}', fn () => abort(404));

    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)->get('/_test/recurso/'.$i)->assertNotFound();
    }

    $logs = RequestLog::query()->get();

    expect($logs)->toHaveCount(5)
        ->and($logs->pluck('http_status_response')->unique()->all())->toBe([404])
        ->and($logs->pluck('endpoint')->unique()->all())->toBe(['_test/recurso/{id}']);
});

it('o correlation_id segue gerado pelo servidor na requisição amostrada para fora (A2 não reabre)', function () {
    $this->get('/sonda');

    $response = $this->withHeader('X-Correlation-Id', '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000')->get('/sonda');

    expect($response->headers->get('X-Correlation-Id'))->not->toBe('0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000')
        ->and(Str::isUuid($response->headers->get('X-Correlation-Id')))->toBeTrue();
});
