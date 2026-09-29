<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Admin\Http\Middleware\OperateAdminPanelAsSystem;

// =============================================================================
// O /admin opera em MODO SISTEMA — pela requisição DE VERDADE (pilha HTTP do
// painel e endpoint de atualização do Livewire), não pelo atalho de teste.
//
// Os testes de telas do /admin (tests/Feature/Admin, com Livewire::test)
// declaram o modo sistema no próprio teste (tests/Pest.php), porque o
// Livewire::test não passa pela pilha do painel. Aqui a prova é de que, em
// produção, quem declara é o middleware persistente do plugin — e de que o
// painel do USUÁRIO continua filtrado pela conta.
// =============================================================================

beforeEach(function (): void {
    config()->set('security.admin.allow_any_ip', true);
});

it('GET do painel: o /admin vê projetos e chaves de TODAS as contas, com a conta de cada linha', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ana = User::factory()->create();
    $bruno = User::factory()->create();

    projetoDe($ana, 'Projeto da Ana');
    projetoDe($bruno, 'Projeto do Bruno');
    criarChave($ana, ['name' => 'Chave da Ana']);
    criarChave($bruno, ['name' => 'Chave do Bruno']);

    $this->actingAs($admin)->get('/admin/projects')
        ->assertOk()
        ->assertSee('Projeto da Ana')
        ->assertSee('Projeto do Bruno')
        ->assertSee(contaPessoal($ana)->codigo_publico)
        ->assertSee(contaPessoal($bruno)->codigo_publico)
        ->assertSee($ana->email)
        ->assertSee($bruno->email);

    $this->actingAs($admin)->get('/admin/api-keys')
        ->assertOk()
        ->assertSee('Chave da Ana')
        ->assertSee('Chave do Bruno');

    // O modo sistema não sobra depois da requisição.
    expect(Accounts::inSystemMode())->toBeFalse();
});

it('endpoint do Livewire: a atualização de um componente do /admin roda em modo sistema', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    criarChave(User::factory()->create(), ['name' => 'Chave da Ana']);
    criarChave(User::factory()->create(), ['name' => 'Chave do Bruno']);

    $html = $this->actingAs($admin)->get('/admin/api-keys')->assertOk()->getContent();

    // A busca da tabela chega pelo endpoint do Livewire, fora da pilha do
    // painel: o modo sistema vale ali pelo middleware persistente.
    $resposta = livewireCall($this, livewireSnapshotFrom((string) $html, 'ListApiKeys'), '$refresh', ['tableSearch' => 'Bruno'])
        ->assertOk();

    expect((string) $resposta->json('components.0.effects.html'))->toContain('Chave do Bruno')->not->toContain('Chave da Ana')
        ->and(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(OperateAdminPanelAsSystem::class)
        ->and(Accounts::inSystemMode())->toBeFalse();
});

it('o painel do USUÁRIO (mesma pessoa admin) continua filtrado pela conta dela, sem modo sistema', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ana = User::factory()->create();

    projetoDe($admin, 'Meu projeto de admin');
    projetoDe($ana, 'Projeto da Ana');

    $this->actingAs($admin)->get('/projects')
        ->assertOk()
        ->assertSee('Meu projeto de admin')
        ->assertDontSee('Projeto da Ana');
});

it('status revogado pelo /admin aparece na conta dona da chave', function (): void {
    $ana = User::factory()->create();
    $chave = criarChave($ana)['api_key'];

    Accounts::asSystem('teste', fn () => app(ApiKeyService::class)->revoke($chave));

    expect(naConta($ana, fn () => $chave->fresh()->status))->toBe(ApiKeyStatus::Revoked);
});
