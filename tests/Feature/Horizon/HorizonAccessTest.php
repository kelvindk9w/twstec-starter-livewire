<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;

// =============================================================================
// Acesso ao dashboard do Horizon (/horizon): SÓ
// is_admin (gate viewHorizon), com a MESMA IP allowlist do /admin
// (EnsureAdminIpAllowed nas rotas do Horizon). Em ambiente local o pacote
// libera geral (apenas desenvolvimento); aqui o ambiente é `testing`, então
// o gate vale de verdade.
// =============================================================================

it('nega com 403 guest (gate viewHorizon fora do ambiente local)', function () {
    $this->get('/horizon')->assertForbidden();
});

it('nega com 403 usuário autenticado SEM a flag admin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/horizon')->assertForbidden();
});

it('permite admin no dashboard', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/horizon')->assertOk();
})->group('admin');

it('rotas do Horizon respeitam a IP allowlist do admin (EnsureAdminIpAllowed)', function () {
    expect(config('horizon.middleware'))->toContain(EnsureAdminIpAllowed::class);

    config(['security.admin.allowed_ips' => ['10.10.10.10']]);

    $admin = User::factory()->create(['is_admin' => true]);

    // IP de teste (127.0.0.1) fora da allowlist → 403, mesmo sendo admin.
    $this->actingAs($admin)->get('/horizon')->assertForbidden();
});

it('CSP do Horizon libera unsafe-eval e fonts.bunny.net SÓ nas rotas dele', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $horizon = $this->actingAs($admin)->get('/horizon');
    $horizonCsp = (string) $horizon->headers->get('Content-Security-Policy');

    expect($horizonCsp)->toContain('unsafe-eval')
        ->and($horizonCsp)->toContain('https://fonts.bunny.net');

    // O resto da aplicação segue com a CSP estrita (decisão documentada).
    $home = $this->actingAs($admin)->get('/');
    $homeCsp = (string) $home->headers->get('Content-Security-Policy');

    expect($homeCsp)->not->toContain('unsafe-eval');
});

it('supervisores do Horizon configurados para local e produção', function () {
    expect(config('horizon.environments.production.supervisor-1.maxProcesses'))->toBeInt()
        ->and(config('horizon.environments.local.supervisor-1.maxProcesses'))->toBeInt()
        ->and(config('horizon.environments.testing.supervisor-1.maxProcesses'))->toBe(1)
        // Fintech: tries baixo por padrão — nunca retry cego.
        ->and(config('horizon.defaults.supervisor-1.tries'))->toBe(1);
});
