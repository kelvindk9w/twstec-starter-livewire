<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

// =============================================================================
// Helpers para exercitar o ENDPOINT REAL de atualização do Livewire.
//
// `Livewire::test()` não serve para testar barreira de acesso: ele chama o
// componente direto, sem passar pela rota de atualização, e o mecanismo de
// middleware persistente do Livewire só roda quando a requisição chega pela
// rota de verdade. Estes helpers fazem o que o navegador faz: renderizam a
// página, pegam o snapshot ASSINADO que o servidor entregou e o devolvem ao
// endpoint de atualização com uma chamada de método.
//
// Arquivo sem testes — apenas funções usadas pelos demais arquivos da suíte.
// =============================================================================

/**
 * Snapshot (JSON assinado) do componente cujo nome contém `$componentName`,
 * extraído do HTML renderizado de uma página.
 */
function livewireSnapshotFrom(string $html, string $componentName): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
        $decoded = json_decode($snapshot, true);

        if (is_array($decoded) && str_contains((string) ($decoded['memo']['name'] ?? ''), $componentName)) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Componente Livewire [{$componentName}] não encontrado na página.");
}

/**
 * Envia ao endpoint de atualização do Livewire uma chamada de método sobre um
 * snapshot, do jeito que o cliente JS do Livewire envia.
 *
 * @param  array<string, mixed>  $updates
 * @param  array<int, mixed>  $params
 */
function livewireCall(TestCase $test, string $snapshot, string $method, array $updates = [], array $params = [], string $ip = '127.0.0.1'): TestResponse
{
    // Mesmos headers do cliente JS do Livewire: corpo JSON + X-Livewire, e
    // SEM `Accept: application/json` (o postJson() do Laravel mandaria, e o
    // servidor trataria a chamada como de API — não é o que o navegador faz).
    $payload = json_encode([
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [['method' => $method, 'params' => $params]],
        ]],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', EndpointResolver::updatePath(), [], [], [], [
        'REMOTE_ADDR' => $ip,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_LIVEWIRE' => '1',
    ], $payload);
}
