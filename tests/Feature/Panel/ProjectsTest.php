<?php

declare(strict_types=1);

use App\Livewire\Projects\Index;
use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

// =============================================================================
// Projetos pela UI (Livewire): CRUD só com nome, tudo na
// mesma tela (criar/editar/excluir inline). Consome o model/invariantes de
// Tenancy — nada duplicado.
// =============================================================================

it('exige autenticação (deny-by-default)', function () {
    $this->get('/projects')->assertRedirect(route('login'));
});

it('lista apenas os projetos do próprio usuário', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    projetoDe($user, 'Meu Projeto');
    projetoDe($outro, 'Projeto Alheio');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertOk()
        ->assertSee('Meu Projeto')
        ->assertDontSee('Projeto Alheio');
});

it('cria projeto pela UI com código público gerado (PRJ-)', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Loja Virtual')
        ->call('create')
        ->assertHasNoErrors();

    $project = comoSistema(fn () => Project::query()->sole());

    expect($project->name)->toBe('Loja Virtual')
        ->and($project->account_id)->toBe(contaPessoal($user)->id)
        ->and($project->created_by)->toBe($user->id)
        ->and($project->codigo_publico)->toStartWith('PRJ-')
        ->and($project->uuid)->not->toBeEmpty();
});

it('valida o nome obrigatório na criação', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', '')
        ->call('create')
        ->assertHasErrors(['name']);

    expect(comoSistema(fn () => Project::query()->count()))->toBe(0);
});

it('edita o nome inline', function () {
    $user = User::factory()->create();
    $project = projetoDe($user, 'Nome Antigo');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startEdit', $project->uuid)
        ->set('editingName', 'Nome Novo')
        ->call('update')
        ->assertHasNoErrors();

    expect($project->fresh()->name)->toBe('Nome Novo');
});

it('exclui com confirmação inline', function () {
    $user = User::factory()->create();
    $project = projetoDe($user, 'Vai Sair');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startDelete', $project->uuid)
        ->assertSet('confirmingDeleteUuid', $project->uuid)
        ->call('removeProject');

    expect(comoSistema(fn () => Project::query()->count()))->toBe(0);
});

it('não toca em projeto de outro tenant (404 uniforme — anti-IDOR)', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();
    $alheio = projetoDe($outro, 'Alheio');

    // findOwned() usa firstOrFail → ModelNotFoundException → 404. Desde o
    // Livewire 4.4.6 o harness de teste trata a exceção como a request real
    // (resposta 404) em vez de propagá-la.
    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startDelete', $alheio->uuid)
        ->assertNotFound();

    expect($alheio->fresh())->not->toBeNull();
});
