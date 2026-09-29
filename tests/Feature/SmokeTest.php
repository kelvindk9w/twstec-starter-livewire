<?php

declare(strict_types=1);

// Teste de fumaça: valida CONTEÚDO da resposta, não apenas status.

it('página inicial responde 200 e exibe o nome da plataforma vindo da config', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(platform()->name);
});

it('helper platform() expõe a configuração centralizada', function () {
    expect(platform()->name)->toBe(config('platform.name'))
        ->and(platform()->officialUrl)->toBe(config('platform.official_url'))
        ->and(platform()->locale)->toBe('pt_BR')
        ->and(platform()->currency)->toBe('BRL');
});
