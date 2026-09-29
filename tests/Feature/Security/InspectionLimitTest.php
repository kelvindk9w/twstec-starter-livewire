<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Security\AttackDetector;

// Teto de bytes inspecionados pelo AttackDetector: a detecção roda
// antes da autenticação, então sem teto qualquer anônimo comprava regex sobre
// o corpo inteiro a cada requisição. O excedente é RECUSADO (413) e gravado
// como BLOQUEADA — nunca aceito sem inspeção.

beforeEach(function () {
    Route::post('/api/_test/echo', fn () => response()->json(['ok' => true]));
    config()->set('security.validation.max_inspected_bytes', 200);
});

it('aceita corpo dentro do teto', function () {
    $this->postJson('/api/_test/echo', ['texto' => str_repeat('a', 150)])->assertOk();
});

it('recusa com 413 o corpo acima do teto e grava BLOQUEADA sem copiar o conteúdo', function () {
    $response = $this->postJson('/api/_test/echo', ['texto' => str_repeat('conteudo-grande ', 40)]);

    $response->assertStatus(413)->assertHeader('X-Correlation-Id');

    $log = RequestLog::query()->sole();

    expect($log->status)->toBe(RequestLogStatus::Bloqueada)
        ->and($log->attack_type)->toBe('payload_too_large')
        ->and($log->http_status_response)->toBe(413)
        ->and($log->correlation_id)->toBe($response->headers->get('X-Correlation-Id'))
        ->and($log->payload['limite_bytes'])->toBe(200)
        ->and(json_encode($log->payload))->not->toContain('conteudo-grande');
});

it('o ataque escondido DEPOIS do teto não passa: a requisição inteira é recusada', function () {
    $this->postJson('/api/_test/echo', [
        'enchimento' => str_repeat('x', 500),
        'comment' => '<script>alert(1)</script>',
    ])->assertStatus(413);

    // Nada chegou à rota: nenhuma linha CONCLUIDA 200.
    expect(RequestLog::query()->where('http_status_response', 200)->exists())->toBeFalse();
});

it('conta a query string e as chaves, não só os valores do corpo', function () {
    $this->getJson('/api/health?'.str_repeat('k', 300).'=1')->assertStatus(413);
});

it('conteúdo de arquivo enviado não conta (só os metadados são inspecionados)', function () {
    Route::post('/api/_test/upload', fn () => response()->json(['ok' => true]));

    $this->post('/api/_test/upload', [
        'arquivo' => UploadedFile::fake()->create('grande.pdf', 2048, 'application/pdf'),
    ])->assertOk();
});

it('o detector para de contar assim que passa do teto', function () {
    $detector = new AttackDetector;

    // Cada item custa chave + valor + ITEM_OVERHEAD_BYTES (8).
    expect($detector->exceedsInspectionBudget(['a' => str_repeat('x', 10)], 19))->toBeFalse()
        ->and($detector->exceedsInspectionBudget(['a' => str_repeat('x', 10)], 18))->toBeTrue()
        ->and($detector->exceedsInspectionBudget(['nivel' => ['fundo' => [str_repeat('x', 50)]]], 40))->toBeTrue()
        ->and($detector->exceedsInspectionBudget(['n' => 123456789, 'b' => true], 18))->toBeFalse()
        ->and($detector->exceedsInspectionBudget(['n' => 123456789, 'b' => true], 17))->toBeTrue();
});

// R4: sem custo por item, um corpo de milhões de strings vazias (ou números)
// somava zero bytes e comprava uma varredura inteira por item — o teto não
// limitava o trabalho que ele existe para limitar.
it('itens vazios contam no teto: muitos itens sem texto também passam do limite', function () {
    $detector = new AttackDetector;

    expect($detector->exceedsInspectionBudget(array_fill(0, 200000, ''), 1048576))->toBeTrue()
        ->and($detector->exceedsInspectionBudget(array_fill(0, 200000, 0), 1048576))->toBeTrue()
        ->and($detector->exceedsInspectionBudget(array_fill(0, 1000, ''), 1048576))->toBeFalse();
});

it('corpo JSON com itens vazios demais é recusado com 413', function () {
    config()->set('security.validation.max_inspected_bytes', 4000);

    $this->postJson('/api/_test/echo', ['itens' => array_fill(0, 1000, '')])->assertStatus(413);
});

it('o teto vale também no modo observe (a recusa 413 não depende do modo)', function () {
    config()->set('security.validation.mode', 'observe');

    $this->postJson('/api/_test/echo', ['texto' => str_repeat('conteudo-grande ', 40)])->assertStatus(413);

    expect(RequestLog::query()->sole()->attack_type)->toBe('payload_too_large');
});
