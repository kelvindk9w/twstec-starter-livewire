<?php

declare(strict_types=1);

use App\Support\ViteDevServerCsp;

// A CSP durante o `npm run dev`: a origem do servidor do Vite entra só em
// APP_ENV=local, só com o public/hot presente e só com uma origem válida.

const BASE_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

function hotFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($path, $contents);

    return $path;
}

it('em local, com o servidor do Vite, libera só a origem dele e o WebSocket do HMR', function () {
    $csp = ViteDevServerCsp::extend(BASE_CSP, 'local', hotFile('http://localhost:5173'));

    expect($csp)->toContain("script-src 'self' 'unsafe-inline' http://localhost:5173")
        ->toContain("style-src 'self' 'unsafe-inline' http://localhost:5173")
        ->toContain("font-src 'self' data: http://localhost:5173")
        ->toContain("img-src 'self' data: https: http://localhost:5173")
        ->toContain("connect-src 'self' http://localhost:5173 ws://localhost:5173")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-eval');
});

it('fora de local a CSP não muda, mesmo com o public/hot', function (string $env) {
    expect(ViteDevServerCsp::extend(BASE_CSP, $env, hotFile('http://localhost:5173')))->toBe(BASE_CSP);
})->with(['production', 'testing', 'staging']);

it('sem o public/hot a CSP não muda', function () {
    expect(ViteDevServerCsp::extend(BASE_CSP, 'local', '/nao/existe/hot'))->toBe(BASE_CSP);
});

it('conteúdo estranho no public/hot é ignorado (só origem http/https)', function (string $contents) {
    expect(ViteDevServerCsp::extend(BASE_CSP, 'local', hotFile($contents)))->toBe(BASE_CSP);
})->with([
    'http://localhost:5173/; script-src *',
    'javascript:alert(1)',
    "http://evil.example 'unsafe-eval'",
    '*',
    '',
]);
