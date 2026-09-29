<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// Validação de segurança global no modo `block`: payload malicioso
// é bloqueado, registrado SANITIZADO/escapado (nunca executável) +
// metadados, e a resposta não revela o que foi detectado. O modo padrão
// (`observe`) está em ObserveModeTest.

beforeEach(function () {
    config()->set('security.validation.mode', 'block');
    Route::post('/api/_test/echo', fn () => response()->json(['ok' => true]));
});

it('bloqueia payload XSS com 422, sem devolver script executável', function () {
    $response = $this->postJson('/api/_test/echo', ['comment' => '<script>alert(1)</script>']);

    $response->assertUnprocessable();
    $response->assertHeader('X-Correlation-Id');

    // Resposta genérica: não revela o tipo de ataque nem ecoa o payload.
    expect($response->getContent())->not->toContain('<script>')
        ->and($response->json('message'))->not->toContain('xss');
});

it('registra a tentativa XSS sanitizada/escapada com metadados', function () {
    $this->postJson('/api/_test/echo', ['comment' => '<script>alert(1)</script>']);

    $log = RequestLog::query()->sole();

    expect($log->status)->toBe(RequestLogStatus::Bloqueada)
        ->and($log->attack_type)->toBe('xss')
        ->and($log->http_status_response)->toBe(422)
        ->and($log->ip)->not->toBeNull()
        ->and($log->endpoint)->toContain('api/_test/echo')
        // Payload persistido NUNCA executável:
        ->and($log->payload['comment'])->toContain('&lt;script&gt;')
        ->and($log->payload['comment'])->not->toContain('<script>');
});

it('bloqueia SQL injection e registra o tipo do ataque', function (string $payload) {
    $this->postJson('/api/_test/echo', ['filtro' => $payload])->assertUnprocessable();

    expect(RequestLog::query()->sole()->attack_type)->toBe('sqli');
})->with([
    'union select' => ['1 UNION SELECT password FROM users'],
    'tautologia' => ["1' OR '1'='1"],
    'drop table' => ['x; DROP TABLE users'],
]);

it('bloqueia null byte e path traversal', function (string $payload, string $tipo) {
    $this->postJson('/api/_test/echo', ['arquivo' => $payload])->assertUnprocessable();

    expect(RequestLog::query()->sole()->attack_type)->toBe($tipo);
})->with([
    'null byte' => ["foto\0.php", 'null_byte'],
    'path traversal' => ['../../etc/passwd', 'path_traversal'],
]);

it('aplica redaction também no payload malicioso persistido (dupla camada)', function () {
    $this->postJson('/api/_test/echo', [
        'comment' => '<script>alert(1)</script>',
        'password' => 'senha-super-secreta',
        'cpf' => '123.456.789-09',
    ]);

    $payload = RequestLog::query()->sole()->payload;

    expect($payload['password'])->toBe('[REDACTED]')
        ->and($payload['cpf'])->toBe('123.***.***-09')
        ->and(json_encode($payload))->not->toContain('senha-super-secreta');
});

it('deixa passar payload limpo sem marcar ataque', function () {
    $response = $this->postJson('/api/_test/echo', [
        'nome' => 'Maria Silva',
        'mensagem' => 'Atenção: cobrança não paga será cancelada!',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);

    expect(RequestLog::query()->sole()->attack_type)->toBeNull();
});

// A evidência do ataque não pode ser desligada pelo atacante: antes, repetir
// um X-Correlation-Id já usado fazia a linha BLOQUEADA colidir no UNIQUE e
// sumir silenciosamente da trilha.
it('registra a linha BLOQUEADA mesmo com X-Correlation-Id repetido', function () {
    $id = '11111111-2222-4333-8444-555555555555';

    // Primeira requisição, legítima: "queima" o valor na trilha.
    $this->postJson('/api/_test/echo', ['nome' => 'x'], ['X-Correlation-Id' => $id])->assertOk();

    $ataque = $this->postJson(
        '/api/_test/echo',
        ['filtro' => "1' OR '1'='1"],
        ['X-Correlation-Id' => $id],
    );

    $ataque->assertUnprocessable();

    $bloqueada = RequestLog::query()->where('status', RequestLogStatus::Bloqueada)->sole();

    expect($bloqueada->attack_type)->toBe('sqli')
        ->and($bloqueada->client_correlation_id)->toBe($id)
        ->and($bloqueada->correlation_id)->not->toBe($id)
        // O envelope e o header entregam o id do SERVIDOR (o que o suporte usa).
        ->and($ataque->json('correlation_id'))->toBe($bloqueada->correlation_id)
        ->and($ataque->headers->get('X-Correlation-Id'))->toBe($bloqueada->correlation_id);
});
