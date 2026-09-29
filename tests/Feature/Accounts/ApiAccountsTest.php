<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// =============================================================================
// A API v1 com contas: a chave autentica a CONTA. Respostas, códigos e
// envelopes iguais aos da 1.x; o request log leva o uuid da conta; a chave
// continua valendo quando quem a criou sai da conta.
// =============================================================================

it('pessoa em duas contas: cada chave vê só a sua conta, e o request log leva o uuid da conta', function (): void {
    $dona = User::factory()->create();
    $pessoa = User::factory()->create();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(AccountService::class)->addMember($empresa, $pessoa, AccountRole::Admin);

    projetoDe($pessoa, 'Pessoal');
    $daEmpresa = Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $pessoa);

    ['api_key' => $pessoal, 'secret_key' => $s1] = criarChave($pessoa);
    ['api_key' => $corporativa, 'secret_key' => $s2] = criarChave($pessoa, [], $empresa);

    $r1 = $this->getJson('/api/v1/projects', headersApi($pessoal, $s1))->assertOk();
    $r2 = $this->getJson('/api/v1/projects', headersApi($corporativa, $s2))->assertOk();

    expect(collect($r1->json('data'))->pluck('name')->all())->toBe(['Pessoal'])
        ->and(collect($r2->json('data'))->pluck('name')->all())->toBe(['Da empresa']);

    assertErroApi($this->getJson("/api/v1/projects/{$daEmpresa->uuid}", headersApi($pessoal, $s1)), 404, 'not_found');

    $log = fn ($r) => RequestLog::query()->where('correlation_id', $r->headers->get('X-Correlation-Id'))->sole()->tenant_uuid;

    expect($log($r1))->toBe($pessoa->uuid)
        ->and($log($r2))->toBe($empresa->uuid);
});

it('a chave continua valendo depois que quem a criou sai da conta — e a conta segue sendo a mesma', function (): void {
    $dona = User::factory()->create();
    $admin = User::factory()->create();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(AccountService::class)->addMember($empresa, $admin, AccountRole::Admin);
    Accounts::actingAs($empresa, fn () => Project::createWithPublicCodeRetry(['name' => 'Da empresa']), $dona);

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave($admin, [], $empresa);

    app(AccountService::class)->removeMember($empresa, $admin);

    $this->getJson('/api/v1/projects', headersApi($chave, $segredo))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Da empresa');

    $admin->delete();

    $this->getJson('/api/v1/projects', headersApi($chave, $segredo))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Da empresa');
});
