<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Demo\Contact\Mail\ContactMessageMail;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Security\ValidationMode;

// Modo `observe` do filtro de ataques (R4 — padrão): a tentativa é detectada
// e gravada na trilha com o tipo e o payload NEUTRALIZADO, e a requisição
// SEGUE. A defesa primária é o framework; o filtro é telemetria e defesa em
// profundidade. O modo `block` está em SecurityValidationTest.

beforeEach(function () {
    Route::post('/api/_test/echo', fn () => response()->json(['ok' => true]));
});

it('o padrão é observe, inclusive sem configuração nenhuma', function () {
    expect(config('security.validation.mode'))->toBe('observe')
        ->and(ValidationMode::current())->toBe(ValidationMode::Observe);

    config()->set('security.validation.mode', null);
    expect(ValidationMode::current())->toBe(ValidationMode::Observe);

    config()->set('security.validation.mode', '');
    expect(ValidationMode::current())->toBe(ValidationMode::Observe);
});

it('valor desconhecido vira block (o lado estreito), com tolerância a caixa e espaços', function (string $valor, ValidationMode $esperado) {
    config()->set('security.validation.mode', $valor);

    expect(ValidationMode::current())->toBe($esperado);
})->with([
    'block' => ['block', ValidationMode::Block],
    'BLOCK com espaço' => [' BLOCK ', ValidationMode::Block],
    'Observe' => ['Observe', ValidationMode::Observe],
    'erro de digitação' => ['blok', ValidationMode::Block],
]);

it('deixa a tentativa seguir e grava a linha marcada com o tipo e o payload neutralizado', function () {
    $response = $this->postJson('/api/_test/echo', ['comment' => '<script>alert(1)</script>']);

    $response->assertOk()->assertJson(['ok' => true]);

    $log = RequestLog::query()->sole();

    expect($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->attack_type)->toBe('xss')
        ->and($log->http_status_response)->toBe(200)
        ->and($log->correlation_id)->toBe($response->headers->get('X-Correlation-Id'))
        ->and($log->error_message)->toBe(__('security.observed_log', ['type' => 'xss']))
        // Evidência NUNCA executável, mesmo com a requisição tendo seguido.
        ->and($log->payload['comment'])->toContain('&lt;script&gt;')
        ->and(json_encode($log->payload))->not->toContain('<script>');
});

it('a evidência observada também é redigida (dado sensível nunca cru)', function () {
    $this->postJson('/api/_test/echo', [
        'comment' => "1' OR '1'='1",
        'password' => 'senha-super-secreta',
    ])->assertOk();

    $log = RequestLog::query()->sole();

    expect($log->attack_type)->toBe('sqli')
        ->and($log->payload['password'])->toBe('[REDACTED]')
        ->and(json_encode($log->payload))->not->toContain('senha-super-secreta');
});

it('frase legítima passa sem marca nos dois modos', function (string $mode) {
    config()->set('security.validation.mode', $mode);

    $this->postJson('/api/_test/echo', [
        'mensagem' => 'Please select a plan from the list below',
        'nota' => 'Selecione um plano da lista; drop table tennis on Friday',
    ])->assertOk();

    expect(RequestLog::query()->sole()->attack_type)->toBeNull();
})->with(['observe', 'block']);

it('rota excluída da trilha (health check) ainda grava a tentativa observada', function () {
    $this->get('/api/health?redirect=javascript:alert(1)')->assertOk();

    $log = RequestLog::query()->sole();

    expect($log->attack_type)->toBe('xss')
        ->and($log->status)->toBe(RequestLogStatus::Concluida);
});

it('a amostragem de varredura não esconde tentativa observada', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->get('/sonda-'.$i);
    }

    for ($i = 0; $i < 5; $i++) {
        $this->get('/sonda?q=<script>alert('.$i.')</script>')->assertNotFound();
    }

    expect(RequestLog::query()->whereNotNull('attack_type')->count())->toBe(5)
        // Das 404 limpas, só a primeira foi ao banco (amostragem intacta).
        ->and(RequestLog::query()->whereNull('attack_type')->count())->toBe(1);
});

it('a rota delegada grava a linha da trilha marcada e neutralizada (antes ia crua e sem marca)', function (string $mode) {
    config()->set('security.validation.mode', $mode);
    config()->set('ui.showcase_enabled', true);

    $this->post(route('ui.form-demo'), [
        'classic_nickname' => 'x',
        'classic_subject' => 'other',
        'classic_message' => '<script>alert(1)</script> payload delegado',
    ])->assertRedirect();

    $log = RequestLog::query()->sole();

    expect($log->attack_type)->toBe('xss')
        ->and($log->status)->toBe(RequestLogStatus::Concluida)
        ->and(json_encode($log->payload))->not->toContain('<script>')
        ->and($log->payload['classic_message'])->toContain('&lt;script&gt;')
        // A vitrine segue registrando a tentativa na camada do formulário.
        ->and(FormSubmission::query()->sole()->attack_type)->toBe('xss');
})->group('demo')->with(['observe', 'block']);

it('no contato da landing, a tentativa segue até o formulário, que a registra com selo e não envia e-mail', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'dono@example.com');

    $this->post(route('contact.store'), [
        'name' => 'Visitante',
        'email' => 'visitante@example.com',
        'subject' => 'other',
        'message' => "<script>alert('ola')</script> mensagem de teste",
    ])->assertRedirect()->assertSessionHas('contact_status');

    $submission = FormSubmission::query()->sole();

    expect($submission->isBlocked())->toBeTrue()
        ->and($submission->attack_type)->toBe('xss')
        ->and(RequestLog::query()->sole()->attack_type)->toBe('xss');

    Mail::assertNothingQueued();
})->group('demo');

it('no contato da landing, a frase legítima que antes era barrada chega ao dono', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'dono@example.com');

    $this->post(route('contact.store'), [
        'name' => 'Visitante',
        'email' => 'visitante@example.com',
        'subject' => 'other',
        'message' => 'Hi! I want to select a plan from the list, but which one fits?',
    ])->assertRedirect();

    expect(FormSubmission::query()->sole()->isBlocked())->toBeFalse();

    Mail::assertQueued(ContactMessageMail::class);
})->group('demo');
