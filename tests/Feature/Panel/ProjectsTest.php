<?php

declare(strict_types=1);

use App\Livewire\Projects\Index;
use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Tenancy\Deletion\ProjectDeletionImpediments;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

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

it('exclusão recusada por uma regra do aplicativo: a tela mostra o motivo (não o sucesso), nada sai e a recusa fica na trilha', function () {
    $user = User::factory()->create();
    $project = projetoDe($user, 'Com cobranças');
    app(ProjectDeletionImpediments::class)->register(fn (Project $projeto): array => [
        new DeletionImpediment('project_has_charges', 'O projeto tem cobranças e não pode ser excluído.'),
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startDelete', $project->uuid)
        ->call('removeProject')
        ->assertHasErrors('project')
        ->assertSet('confirmingDeleteUuid', null)
        ->assertSee('O projeto tem cobranças e não pode ser excluído.')
        ->assertDontSee(__('panel.projects.deleted'));

    expect(comoSistema(fn () => Project::query()->count()))->toBe(1);

    $recusa = comoSistema(fn () => AuditEvent::query()->where('action', 'project.deleted')->sole());
    expect($recusa->outcome->value)->toBe('denied')
        ->and($recusa->reason)->toBe('O projeto tem cobranças e não pode ser excluído.')
        ->and($recusa->subject_uuid)->toBe((string) $project->uuid);
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
