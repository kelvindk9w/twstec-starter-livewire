<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Tenancy\Deletion\ProjectDeletionImpediments;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectStatus;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

// CRUD de projetos da API v1: camada organizacional, nasce só com
// nome. Isolamento de tenant em TODAS as operações (o tenant A nunca
// enxerga dados do tenant B).

it('executa o ciclo completo: criar, listar, detalhar, atualizar e remover', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $headers = headersApi($key, $secret);

    // Criar — só com nome.
    $response = $this->postJson('/api/v1/projects', ['name' => 'Loja Virtual'], $headers);

    $response->assertCreated()
        ->assertJsonPath('message', __('api_keys.projects.created'))
        ->assertJsonPath('data.name', 'Loja Virtual')
        ->assertJsonPath('data.status', ProjectStatus::Active->value);

    $uuid = $response->json('data.uuid');

    expect($response->json('data.codigo_publico'))->toStartWith('PRJ-');

    // Listar.
    $this->getJson('/api/v1/projects', $headers)
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $uuid)
        ->assertJsonPath('data.0.name', 'Loja Virtual');

    // Detalhar.
    $this->getJson("/api/v1/projects/{$uuid}", $headers)
        ->assertOk()
        ->assertJsonPath('data.uuid', $uuid);

    // Atualizar nome e status.
    $this->putJson("/api/v1/projects/{$uuid}", [
        'name' => 'Loja Virtual LTDA',
        'status' => ProjectStatus::Archived->value,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('message', __('api_keys.projects.updated'))
        ->assertJsonPath('data.name', 'Loja Virtual LTDA')
        ->assertJsonPath('data.status', ProjectStatus::Archived->value);

    // Remover.
    $this->deleteJson("/api/v1/projects/{$uuid}", [], $headers)
        ->assertOk()
        ->assertJsonPath('message', __('api_keys.projects.deleted'));

    expect(comoSistema(fn () => Project::query()->where('uuid', $uuid)->exists()))->toBeFalse();
});

it('isola projetos por tenant: dados de um tenant são invisíveis a outro', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    ['api_key' => $keyA, 'secret_key' => $secretA] = criarChave($userA);
    ['api_key' => $keyB, 'secret_key' => $secretB] = criarChave($userB);

    $projetoB = projetoDe($userB, 'Projeto de B');

    // A lista de A não mostra o projeto de B.
    $response = $this->getJson('/api/v1/projects', headersApi($keyA, $secretA));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);

    // Detalhe/edição/remoção do projeto de B pela chave de A: 404 uniforme
    // (nunca 403 — não revela que o UUID existe para outro tenant).
    $this->getJson("/api/v1/projects/{$projetoB->uuid}", headersApi($keyA, $secretA))->assertNotFound();
    $this->putJson("/api/v1/projects/{$projetoB->uuid}", ['name' => 'invasão'], headersApi($keyA, $secretA))->assertNotFound();
    $this->deleteJson("/api/v1/projects/{$projetoB->uuid}", [], headersApi($keyA, $secretA))->assertNotFound();

    // Intacto para o dono.
    $this->getJson("/api/v1/projects/{$projetoB->uuid}", headersApi($keyB, $secretB))
        ->assertOk()
        ->assertJsonPath('data.name', 'Projeto de B');
});

it('exige scope projects:* por operação', function (string $metodo, string $rota, string $scopeExigido) {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user, ['scopes' => ['customers:read']]);

    $this->json($metodo, $rota, ['name' => 'X'], headersApi($key, $secret))
        ->assertForbidden()
        ->assertJsonPath('error.message', __('api_keys.scopes.denied', ['scope' => $scopeExigido]));
})->with([
    'listar' => ['GET', '/api/v1/projects', 'projects:read'],
    'criar' => ['POST', '/api/v1/projects', 'projects:create'],
]);

it('valida o nome na criação', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $this->postJson('/api/v1/projects', [], headersApi($key, $secret))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['errors' => ['name']]]);
});

it('regra do aplicativo impede a exclusão: 409 project_deletion_refused com o motivo, e o projeto fica', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $projeto = projetoDe($user, 'Com cobranças');
    app(ProjectDeletionImpediments::class)->register(fn (Project $alvo): array => $alvo->is($projeto)
        ? [new DeletionImpediment('project_has_charges', 'O projeto tem cobranças e não pode ser excluído.')]
        : []);

    $this->deleteJson("/api/v1/projects/{$projeto->uuid}", [], headersApi($key, $secret))
        ->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'correlation_id']])
        ->assertJsonPath('error.code', 'project_deletion_refused')
        ->assertJsonPath('error.message', 'O projeto tem cobranças e não pode ser excluído.');

    expect(comoSistema(fn () => Project::query()->whereKey($projeto->getKey())->exists()))->toBeTrue();
});

it('registro do aplicativo com chave estrangeira RESTRICT para o projeto: 409 conflict genérico, nunca 500', function () {
    Schema::create('pedidos_de_teste', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
    });

    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $projeto = projetoDe($user, 'Referenciado');
    DB::table('pedidos_de_teste')->insert(['project_id' => $projeto->getKey()]);

    $this->deleteJson("/api/v1/projects/{$projeto->uuid}", [], headersApi($key, $secret))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.message', __('api_keys.projects.deletion_referenced'));

    // A conexão segue utilizável (no PostgreSQL, sem o savepoint, a transação
    // do teste ficaria abortada) e nada saiu.
    expect(DB::table('pedidos_de_teste')->count())->toBe(1)
        ->and(comoSistema(fn () => Project::query()->whereKey($projeto->getKey())->exists()))->toBeTrue();
});
