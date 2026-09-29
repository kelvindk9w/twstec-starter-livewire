<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// Pipeline de logs de requisição: INICIADA imediato →
// CONCLUIDA/ERRO no terminate, correlation_id propagado, append-only.

beforeEach(function () {
    Route::post('/api/_test/echo', fn () => response()->json(['ok' => true]));
});

it('registra INICIADA→CONCLUÍDA e propaga o correlation_id na resposta', function () {
    $response = $this->postJson('/api/_test/echo', ['nome' => 'Kelvin']);

    $response->assertOk();
    $response->assertHeader('X-Correlation-Id');

    $correlationId = $response->headers->get('X-Correlation-Id');

    expect(Str::isUuid($correlationId))->toBeTrue();

    $log = RequestLog::query()->where('correlation_id', $correlationId)->sole();

    expect($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->http_status_response)->toBe(200)
        ->and($log->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($log->method)->toBe('POST')
        ->and($log->endpoint)->toBe('api/_test/echo')
        ->and($log->payload['nome'])->toBe('Kelvin')
        ->and($log->tenant_uuid)->toBeNull(); // o tenant só é vinculado quando uma chave de API válida é resolvida
});

it('NUNCA adota o X-Correlation-Id de entrada como id da trilha', function () {
    $id = (string) Str::uuid7();

    $response = $this->postJson('/api/_test/echo', ['nome' => 'x'], ['X-Correlation-Id' => $id]);

    // O header de resposta é o id do SERVIDOR; o do cliente vira só rótulo.
    expect($response->headers->get('X-Correlation-Id'))->not->toBe($id);

    $log = RequestLog::query()->sole();

    expect($log->correlation_id)->not->toBe($id)
        ->and($log->correlation_id)->toBe($response->headers->get('X-Correlation-Id'))
        ->and($log->client_correlation_id)->toBe($id);
});

it('gera correlation_id próprio quando não vem header algum', function () {
    $response = $this->postJson('/api/_test/echo', ['nome' => 'x']);

    $correlationId = $response->headers->get('X-Correlation-Id');

    expect(Str::isUuid($correlationId))->toBeTrue()
        ->and(RequestLog::query()->sole()->client_correlation_id)->toBeNull();
});

// AUDITORIA NÃO DESLIGÁVEL: antes, repetir o mesmo X-Correlation-Id fazia o
// INSERT seguinte violar o UNIQUE; a exceção era engolida e a requisição saía
// sem linha nenhuma — o atacante apagava o próprio rastro.
it('grava TODAS as requisições que repetem o mesmo X-Correlation-Id', function () {
    $id = '11111111-2222-4333-8444-555555555555';

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/_test/echo', ['nome' => 'x'], ['X-Correlation-Id' => $id])->assertOk();
    }

    $logs = RequestLog::query()->where('client_correlation_id', $id)->get();

    expect($logs)->toHaveCount(3)
        // Três ids internos DISTINTOS, uma única correlação de cliente.
        ->and($logs->pluck('correlation_id')->unique())->toHaveCount(3)
        ->and($logs->pluck('correlation_id')->contains($id))->toBeFalse();
});

it('saneia o X-Correlation-Id do cliente sem quebrar a gravação', function (string $enviado, ?string $esperado) {
    $this->postJson('/api/_test/echo', ['nome' => 'x'], ['X-Correlation-Id' => $enviado])->assertOk();

    expect(RequestLog::query()->sole()->client_correlation_id)->toBe($esperado);
})->with([
    // Lista branca: some tudo que poderia virar vetor de injeção.
    'html/script' => ['<script>alert(1)</script>', 'scriptalert1script'],
    'aspas e barra' => ["a'b\"c\\d", 'abcd'],
    'quebra de linha (envenenamento de log)' => ["abc\ndef", 'abcdef'],
    'só lixo' => ['<>"\'', null],
    'vazio' => ['', null],
    'traceparent do W3C sobrevive inteiro' => [
        '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    ],
]);

it('corta o X-Correlation-Id gigante no limite configurado', function () {
    $gigante = str_repeat('a', 5000);

    $this->postJson('/api/_test/echo', ['nome' => 'x'], ['X-Correlation-Id' => $gigante])->assertOk();

    $guardado = RequestLog::query()->sole()->client_correlation_id;

    expect(strlen((string) $guardado))
        ->toBe((int) config('security.request_logging.client_correlation_max_length'));
});

it('registra requisição para endpoint inexistente (sinal de varredura)', function () {
    $response = $this->get('/api/endpoint-que-nao-existe');

    $response->assertNotFound();

    $log = RequestLog::query()->sole();

    // Sem rota casada não há padrão a gravar — e o caminho bruto NÃO serve de
    // reserva (pode carregar segredo). Fica o marcador + profundidade.
    expect($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->http_status_response)->toBe(404)
        ->and($log->endpoint)->toBe('[unmatched]:2')
        ->and($log->tenant_uuid)->toBeNull();
});

it('registra navegação web pública (landing) com ciclo completo', function () {
    $response = $this->get('/');

    $response->assertOk();

    $log = RequestLog::query()->where('endpoint', '/')->sole();

    expect($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->http_status_response)->toBe(200)
        ->and($log->method)->toBe('GET')
        ->and($log->duration_ms)->not->toBeNull();
});

it('registra navegação web autenticada (painel do usuário)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $log = RequestLog::query()->where('endpoint', 'dashboard')->sole();

    expect($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->http_status_response)->toBe(200);
});

it('registra navegação do super admin (/admin)', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get('/admin')->assertOk();

    expect(RequestLog::query()->where('endpoint', 'admin')->exists())->toBeTrue();
})->group('admin');

it('não registra assets estáticos nem health checks (config excluded_paths)', function () {
    Route::get('/build/_test/app.css', fn () => response('/* css */', 200, ['Content-Type' => 'text/css']));
    Route::get('/storage/_test/avatar.png', fn () => response('img', 200, ['Content-Type' => 'image/png']));

    $this->get('/build/_test/app.css')->assertOk();
    // /storage/* é rota assinada dos uploads (403 sem assinatura) — o que
    // importa aqui é a ausência de log, não o status.
    $this->get('/storage/_test/avatar.png');
    $this->get('/up');
    $this->get('/api/health');
    $this->get('/favicon.ico');

    expect(RequestLog::query()->count())->toBe(0);
});

it('registra updates genéricos do Livewire com payload RESUMIDO', function () {
    $snapshot = json_encode(['memo' => ['name' => 'contact-form'], 'data' => ['message' => 'segredo-que-nao-deve-vazar']]);

    $response = $this->postJson('/livewire/update', [
        '_token' => 'csrf-token',
        'components' => [
            ['snapshot' => $snapshot, 'updates' => ['message' => 'texto'], 'calls' => []],
        ],
    ]);

    // A rota do Livewire pode não estar registrada no ambiente de teste (404),
    // e aí o endpoint é o marcador de rota não casada — o resumo do payload não
    // depende disso: é decidido pelo padrão de caminho (summarized_paths).
    $log = RequestLog::query()->sole();

    expect($log->payload['_resumo'])->toBe('livewire.update')
        ->and($log->payload['componentes'])->toBe(['contact-form']);

    $raw = (string) DB::table('request_logs')->value('payload');

    expect($raw)->not->toContain('segredo-que-nao-deve-vazar');
});

it('marca ERRO com a mensagem quando a rota lança exceção', function () {
    Route::get('/api/_test/boom', function () {
        throw new RuntimeException('falha proposital do teste');
    });

    $response = $this->get('/api/_test/boom');

    $response->assertServerError();

    $log = RequestLog::query()->where('endpoint', 'api/_test/boom')->sole();

    expect($log->status)->toBe(RequestLogStatus::Erro)
        ->and($log->http_status_response)->toBe(500)
        ->and($log->error_message)->toContain('falha proposital do teste')
        ->and($log->duration_ms)->not->toBeNull();
});

it('mascara segredos, CPF e e-mail antes de persistir (LGPD)', function () {
    $this->postJson('/api/_test/echo', [
        'password' => 'senha-super-secreta',
        'webhook_token' => 'tok_live_123456',
        'cpf' => '123.456.789-09',
        'contato' => 'kelvin@example.com',
        'aninhado' => ['client_secret' => 'shhh-secret'],
    ])->assertOk();

    $payload = RequestLog::query()->sole()->payload;

    expect($payload['password'])->toBe('[REDACTED]')
        ->and($payload['webhook_token'])->toBe('[REDACTED]')
        ->and($payload['aninhado']['client_secret'])->toBe('[REDACTED]')
        ->and($payload['cpf'])->toBe('123.***.***-09')
        ->and($payload['contato'])->toBe('k***@example.com');

    // Garantia no nível do JSON cru persistido (conteúdo, não só cast).
    $raw = (string) DB::table('request_logs')->value('payload');

    expect($raw)->not->toContain('senha-super-secreta')
        ->and($raw)->not->toContain('tok_live_123456')
        ->and($raw)->not->toContain('shhh-secret')
        ->and($raw)->not->toContain('456.789')
        ->and($raw)->not->toContain('kelvin@');
});

it('request_logs é append-only: update e delete via Eloquent lançam exceção', function () {
    $this->postJson('/api/_test/echo', ['nome' => 'x']);

    $log = RequestLog::query()->sole();

    // Nota: o update precisa alterar um atributo de fato — Eloquent não
    // dispara save/eventos quando não há nada "sujo".
    expect(fn () => $log->update(['endpoint' => 'api/adulterado']))
        ->toThrow(AppendOnlyViolationException::class);

    expect(fn () => $log->delete())
        ->toThrow(AppendOnlyViolationException::class);

    expect(fn () => RequestLog::query()->sole()->forceFill(['ip' => '1.2.3.4'])->save())
        ->toThrow(AppendOnlyViolationException::class);
});

it('permite as transições controladas: markFinished e bindTenant', function () {
    $log = RequestLog::query()->create([
        'correlation_id' => (string) Str::uuid7(),
        'ip' => '127.0.0.1',
        'method' => 'GET',
        'endpoint' => 'api/_test/manual',
        'status' => RequestLogStatus::Iniciada,
    ]);

    $log->markFinished(RequestLogStatus::Concluida, 200, null, 42);

    expect($log->fresh()->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->fresh()->duration_ms)->toBe(42);

    $tenantUuid = (string) Str::uuid7();

    expect(RequestLog::bindTenantByCorrelationId($log->correlation_id, $tenantUuid))->toBeTrue()
        ->and($log->fresh()->tenant_uuid)->toBe($tenantUuid)
        ->and(RequestLog::bindTenantByCorrelationId((string) Str::uuid7(), $tenantUuid))->toBeFalse();
});

it('markFinished recusa status fora do ciclo de vida', function () {
    $log = RequestLog::query()->create([
        'correlation_id' => (string) Str::uuid7(),
        'method' => 'GET',
        'endpoint' => 'api/_test/manual',
        'status' => RequestLogStatus::Iniciada,
    ]);

    expect(fn () => $log->markFinished(RequestLogStatus::Iniciada))
        ->toThrow(InvalidArgumentException::class);
});

// --- Segredo posicional no caminho da URL (achado de segurança) --------------
// O link de recuperação de senha leva o token no PATH. O banco só guarda o
// HASH do token: se o caminho real fosse gravado na trilha, o log viraria a
// via de tomada de conta (visível no /admin, nos arquivos e nos backups).

it('NÃO grava o token de recuperação de senha no endpoint — grava o padrão da rota', function () {
    $token = 'TOKEN-EM-CLARO-NAO-PODE-VAZAR';

    $this->get('/reset-password/'.$token.'?email=kelvin@example.com');

    $log = RequestLog::query()->where('method', 'GET')->sole();

    expect($log->endpoint)->toBe('reset-password/{token}')
        ->and($log->endpoint)->not->toContain($token);

    // Garantia no nível da linha crua: o token não aparece em NENHUMA coluna
    // (nem no endpoint, nem no payload vindo da query string).
    $linha = (string) json_encode((array) DB::table('request_logs')->first());

    expect($linha)->not->toContain($token)
        ->and($linha)->not->toContain('kelvin@example.com');
});

it('NÃO grava o token de recuperação de senha no canal de arquivo request_log', function () {
    $token = 'TOKEN-DO-ARQUIVO-NAO-PODE-VAZAR';

    $contextos = [];

    $logger = Mockery::mock();
    $logger->shouldIgnoreMissing();
    $logger->shouldReceive('info')->andReturnUsing(function (string $mensagem, array $contexto = []) use (&$contextos): void {
        $contextos[$mensagem] = $contexto;
    });

    Log::shouldReceive('channel')->andReturn($logger);
    Log::getFacadeRoot()->shouldIgnoreMissing();

    $this->get('/reset-password/'.$token.'?email=kelvin@example.com');

    expect($contextos)->toHaveKey('request.started')
        ->and($contextos['request.started']['endpoint'])->toBe('reset-password/{token}')
        ->and(json_encode($contextos))->not->toContain($token);
});

it('rota inexistente com segredo no caminho não vaza o caminho bruto no arquivo', function () {
    $segredo = 'SEGREDO-EM-ROTA-MORTA';

    $contextos = [];

    $logger = Mockery::mock();
    $logger->shouldIgnoreMissing();
    $logger->shouldReceive('info')->andReturnUsing(function (string $mensagem, array $contexto = []) use (&$contextos): void {
        $contextos[$mensagem] = $contexto;
    });

    Log::shouldReceive('channel')->andReturn($logger);
    Log::getFacadeRoot()->shouldIgnoreMissing();

    $this->get('/rota-que-nao-existe/'.$segredo);

    expect($contextos['request.started']['endpoint'])->toBe('[unmatched]:2')
        ->and(json_encode($contextos))->not->toContain($segredo);
});

it('marcador de rota não casada preserva a profundidade do caminho para diagnóstico de varredura', function () {
    $this->get('/a/b/c/d');

    expect(RequestLog::query()->sole()->endpoint)->toBe('[unmatched]:4');
});
