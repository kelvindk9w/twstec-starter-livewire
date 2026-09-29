<?php

declare(strict_types=1);

use App\Livewire\ApiKeys\Index as ApiKeysIndex;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

// =============================================================================
// VÍNCULO CHAVE DE API ↔ PROJETO — a regra, provada ponta a ponta.
//
//   - Chave SEM vínculo = conta toda: vê e gerencia todos os projetos do dono,
//     cria projetos e gerencia chaves (se os scopes permitirem).
//   - Chave VINCULADA = restrita: só enxerga os projetos vinculados (os demais,
//     mesmo do mesmo dono, são 404 uniforme) e não faz operação de CONTA
//     (criar projeto, listar/criar/revogar/rotacionar/vincular chaves) — 403,
//     qualquer que seja o scope. O vínculo limita o scope, nunca o contrário.
//   - A restrição é da CHAVE, não da lista: excluir o último projeto vinculado
//     deixa a chave restrita a nada (fail-closed), nunca "promovida" à conta
//     toda. Só uma ação explícita de quem gerencia a conta volta a abri-la.
// =============================================================================

/**
 * Dono com dois projetos e uma chave vinculada só ao primeiro.
 *
 * @return array{dono: User, a: Project, b: Project, chave: ApiKey, headers: array<string, string>}
 */
function cenarioChaveRestrita(): array
{
    $dono = User::factory()->create();
    $a = projetoDe($dono, 'Projeto A');
    $b = projetoDe($dono, 'Projeto B');

    ['api_key' => $chave, 'secret_key' => $segredo] = criarChave($dono, ['project_uuids' => [$a->uuid]]);

    return ['dono' => $dono, 'a' => $a, 'b' => $b, 'chave' => $chave, 'headers' => headersApi($chave, $segredo)];
}

it('chave vinculada ao projeto A lista só o projeto A', function () {
    ['a' => $a, 'headers' => $headers] = cenarioChaveRestrita();

    $response = $this->getJson('/api/v1/projects', $headers)->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.uuid'))->toBe($a->uuid);
});

it('chave vinculada ao projeto A não lê, altera nem exclui o projeto B do mesmo dono (404 uniforme)', function () {
    ['a' => $a, 'b' => $b, 'headers' => $headers] = cenarioChaveRestrita();

    $this->getJson("/api/v1/projects/{$b->uuid}", $headers)->assertNotFound();
    $this->putJson("/api/v1/projects/{$b->uuid}", ['name' => 'Alterado'], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/projects/{$b->uuid}", [], $headers)->assertNotFound();

    expect($b->fresh()?->name)->toBe('Projeto B');

    // O projeto vinculado segue plenamente acessível.
    $this->getJson("/api/v1/projects/{$a->uuid}", $headers)->assertOk();
    $this->putJson("/api/v1/projects/{$a->uuid}", ['name' => 'A renomeado'], $headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'A renomeado');
});

it('chave vinculada não cria projeto, mesmo com o scope projects:create', function () {
    ['dono' => $dono, 'headers' => $headers] = cenarioChaveRestrita();

    $response = $this->postJson('/api/v1/projects', ['name' => 'Novo'], $headers);

    assertErroApi($response, 403, 'forbidden')
        ->assertJsonPath('error.message', __('api_keys.projects.account_key_required'));

    expect(comoSistema(fn () => Project::query()->where('account_id', contaPessoal($dono)->id)->count()))->toBe(2);
});

it('chave vinculada não gerencia chaves: não lista, não revoga, não rotaciona, não cria', function () {
    ['dono' => $dono, 'chave' => $chave, 'headers' => $headers] = cenarioChaveRestrita();
    $outra = criarChave($dono)['api_key'];

    foreach ([
        $this->getJson('/api/v1/api-keys', $headers),
        $this->deleteJson("/api/v1/api-keys/{$outra->uuid}", [], $headers),
        $this->postJson("/api/v1/api-keys/{$outra->uuid}/rotate", [], $headers),
        $this->postJson('/api/v1/api-keys', ['name' => 'Escalada'], $headers),
    ] as $response) {
        assertErroApi($response, 403, 'forbidden')
            ->assertJsonPath('error.message', __('api_keys.projects.account_key_required'));
    }

    expect($outra->fresh()?->status->value)->toBe('active')
        ->and(comoSistema(fn () => ApiKey::query()->where('account_id', contaPessoal($dono)->id)->count()))->toBe(2);
});

it('chave vinculada não se desvincula sozinha para ganhar a conta toda', function () {
    ['a' => $a, 'chave' => $chave, 'headers' => $headers] = cenarioChaveRestrita();

    $response = $this->putJson("/api/v1/api-keys/{$chave->uuid}/projects", ['project_uuids' => []], $headers);

    assertErroApi($response, 403, 'forbidden');

    $chave->refresh();

    expect($chave->isRestrictedToProjects())->toBeTrue()
        ->and(comoSistema(fn () => $chave->projects->pluck('id')->all()))->toBe([$a->id]);
});

it('excluir o último projeto vinculado deixa a chave restrita a NADA, não à conta toda', function () {
    ['dono' => $dono, 'a' => $a, 'b' => $b, 'chave' => $chave, 'headers' => $headers] = cenarioChaveRestrita();

    // Excluído pelo painel (o caminho comum).
    Livewire::actingAs($dono)
        ->test(ProjectsIndex::class)
        ->call('startDelete', $a->uuid)
        ->call('removeProject');

    // O vínculo foi limpo (sem linha órfã na tabela pivô)...
    expect(DB::table('api_key_project')->where('api_key_id', $chave->id)->count())->toBe(0);

    // ...e a chave NÃO passou a enxergar a conta toda.
    $response = $this->getJson('/api/v1/projects', $headers)->assertOk();

    expect($response->json('data'))->toHaveCount(0);

    $this->getJson("/api/v1/projects/{$b->uuid}", $headers)->assertNotFound();
    assertErroApi($this->postJson('/api/v1/projects', ['name' => 'Novo'], $headers), 403, 'forbidden');
    expect($chave->fresh()?->isRestrictedToProjects())->toBeTrue();
});

it('excluir pela API o projeto vinculado também não promove a chave', function () {
    ['dono' => $dono, 'a' => $a, 'b' => $b, 'headers' => $headers] = cenarioChaveRestrita();

    $this->deleteJson("/api/v1/projects/{$a->uuid}", [], $headers)->assertOk();

    expect($this->getJson('/api/v1/projects', $headers)->json('data'))->toHaveCount(0);
    $this->getJson("/api/v1/projects/{$b->uuid}", $headers)->assertNotFound();
});

it('rotação herda a restrição, inclusive a restrição a nenhum projeto', function () {
    ['dono' => $dono, 'a' => $a, 'chave' => $chave] = cenarioChaveRestrita();

    $a->delete();

    $nova = naConta($dono, fn () => app(ApiKeyService::class)->rotate($chave->fresh(), null)['api_key']);

    expect($nova->isRestrictedToProjects())->toBeTrue()
        ->and(comoSistema(fn () => $nova->projects))->toHaveCount(0);
});

it('chave de conta, com lista vazia EXPLÍCITA, abre outra chave para a conta toda', function () {
    ['dono' => $dono, 'b' => $b, 'chave' => $restrita] = cenarioChaveRestrita();
    ['api_key' => $conta, 'secret_key' => $segredo] = criarChave($dono);

    $this->putJson("/api/v1/api-keys/{$restrita->uuid}/projects", ['project_uuids' => []], headersApi($conta, $segredo))
        ->assertOk()
        ->assertJsonPath('data.project_access', 'account');

    expect($restrita->fresh()?->isRestrictedToProjects())->toBeFalse();
});

it('a chave de conta vincula outra chave e ela passa a ser restrita', function () {
    ['dono' => $dono, 'b' => $b] = cenarioChaveRestrita();
    ['api_key' => $conta, 'secret_key' => $segredo] = criarChave($dono);
    $alvo = criarChave($dono)['api_key'];

    $this->putJson("/api/v1/api-keys/{$alvo->uuid}/projects", ['project_uuids' => [$b->uuid]], headersApi($conta, $segredo))
        ->assertOk()
        ->assertJsonPath('data.project_access', 'projects');

    expect($alvo->fresh()?->isRestrictedToProjects())->toBeTrue();
});

it('chave sem vínculo segue vendo a conta toda e criando projetos', function () {
    ['dono' => $dono] = cenarioChaveRestrita();
    ['api_key' => $conta, 'secret_key' => $segredo] = criarChave($dono);

    expect($this->getJson('/api/v1/projects', headersApi($conta, $segredo))->json('data'))->toHaveCount(2);

    $this->postJson('/api/v1/projects', ['name' => 'Novo'], headersApi($conta, $segredo))->assertCreated();
});

it('painel: esvaziar a seleção abre a chave para a conta toda; marcar restringe', function () {
    ['dono' => $dono, 'a' => $a, 'chave' => $chave] = cenarioChaveRestrita();

    Livewire::actingAs($dono)
        ->test(ApiKeysIndex::class)
        ->call('startEditProjects', $chave->uuid)
        ->set('editingProjectsSelection', [])
        ->call('saveProjects')
        ->assertHasNoErrors();

    expect($chave->fresh()?->isRestrictedToProjects())->toBeFalse();

    Livewire::actingAs($dono)
        ->test(ApiKeysIndex::class)
        ->call('startEditProjects', $chave->uuid)
        ->set('editingProjectsSelection', [$a->uuid])
        ->call('saveProjects')
        ->assertHasNoErrors();

    expect($chave->fresh()?->isRestrictedToProjects())->toBeTrue();
});

it('painel: projeto de outro usuário não é vinculado e a chave não muda de estado', function () {
    ['chave' => $chave, 'dono' => $dono, 'a' => $a] = cenarioChaveRestrita();
    $alheio = projetoDe(User::factory()->create(), 'Alheio');

    Livewire::actingAs($dono)
        ->test(ApiKeysIndex::class)
        ->call('startEditProjects', $chave->uuid)
        ->set('editingProjectsSelection', [$alheio->uuid])
        ->call('saveProjects')
        ->assertHasErrors(['editingProjectsSelection']);

    $chave->refresh();

    expect($chave->isRestrictedToProjects())->toBeTrue()
        ->and(comoSistema(fn () => $chave->projects->pluck('id')->all()))->toBe([$a->id]);
});

it('painel: chave restrita a nenhum projeto aparece como tal, não como "conta toda"', function () {
    ['dono' => $dono, 'a' => $a] = cenarioChaveRestrita();

    $a->delete();

    Livewire::actingAs($dono)
        ->test(ApiKeysIndex::class)
        ->assertSee(__('panel.api_keys.no_projects'));
});

it('chave vinculada pode revogar a si mesma (kill switch de credencial vazada)', function () {
    ['chave' => $chave, 'headers' => $headers] = cenarioChaveRestrita();

    $this->deleteJson("/api/v1/api-keys/{$chave->uuid}", [], $headers)->assertOk();

    expect($chave->fresh()?->status->value)->toBe('revoked');
});
