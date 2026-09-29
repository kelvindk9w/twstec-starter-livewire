<?php

declare(strict_types=1);

// Headers de segurança OWASP — presentes em TODAS as
// respostas, inclusive bloqueios e erros (middleware mais externo da cadeia).

it('respostas normais carregam os headers de segurança', function () {
    $response = $this->get('/api/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'");
});

it('resposta de bloqueio (422) também carrega os headers', function () {
    // Modo `block` (o padrão é `observe` — ver ValidationMode).
    config()->set('security.validation.mode', 'block');
    Route::post('/api/_test/echo', fn () => response()->json(['ok' => true]));

    $response = $this->postJson('/api/_test/echo', ['x' => '<script>alert(1)</script>']);

    $response->assertUnprocessable();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
});

it('resposta de erro 404 também carrega os headers', function () {
    $response = $this->get('/api/nao-existe');

    $response->assertNotFound();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('não envia HSTS fora de HTTPS/produção', function () {
    $response = $this->get('/api/health');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('CORS não libera origem nenhuma por padrão (restritivo)', function () {
    $response = $this->get('/api/health', ['Origin' => 'https://site-malicioso.example']);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('CSP estrita (sem unsafe-eval) nas rotas comuns — Livewire roda CSP-safe', function () {
    $response = $this->get('/login');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('unsafe-eval');
});

it('CSP do /admin inclui unsafe-eval (Filament 5 exige — decisão documentada)', function () {
    $response = $this->get('/admin/login');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'unsafe-eval'")
        ->and($csp)->toContain("frame-ancestors 'none'");
});

it('CSP customizada do admin (SECURITY_CSP_ADMIN) prevalece quando definida', function () {
    config()->set('security.headers.content_security_policy_admin', "default-src 'none'");

    $response = $this->get('/admin/login');

    expect((string) $response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});
