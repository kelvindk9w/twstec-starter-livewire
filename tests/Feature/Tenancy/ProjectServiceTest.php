<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Tenancy\Enums\ProjectStatus;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\Services\ProjectService;

// =============================================================================
// ProjectService — a regra ÚNICA do CRUD de projetos (painel e API v1).
//
// Posse: o projeto é da CONTA; o serviço trabalha na conta atual (no painel,
// a da pessoa; na API, a da chave — com o vínculo chave ↔ projeto e a marca
// de restrição por cima). Fora do recorte é sempre a mesma exceção (404
// uniforme). Aqui cada chamada roda na conta pessoal de quem age (naConta). Os testes de ponta a ponta das telas e da API
// continuam em ProjectsTest (Panel e Tenancy) e ApiKeyProjectBindingTest; que
// a tela e a API não leem nem gravam Project por fora do serviço é trava do
// teste de arquitetura (BusinessLogicPlacementTest).
// =============================================================================

function projectService(): ProjectService
{
    return app(ProjectService::class);
}

/**
 * Cria pelo serviço, na conta pessoal da pessoa (como o painel dela).
 */
function criarProjetoPeloServico(User $user, string $nome): Project
{
    return naConta($user, fn (): Project => projectService()->create($user, $nome));
}

it('lista só os projetos da conta da pessoa, mais novos primeiro, com a contagem de chaves', function () {
    $dono = User::factory()->create();
    $outro = User::factory()->create();

    $antigo = criarProjetoPeloServico($dono, 'Antigo');
    $antigo->forceFill(['created_at' => now()->subDay()])->save();
    $novo = criarProjetoPeloServico($dono, 'Novo');
    criarProjetoPeloServico($outro, 'Alheio');

    criarChave($dono, ['project_uuids' => [$novo->uuid]]);

    $lista = naConta($dono, fn () => projectService()->list());

    expect($lista->pluck('name')->all())->toBe(['Novo', 'Antigo'])
        ->and($lista->first()->api_keys_count)->toBe(1)
        ->and($lista->last()->api_keys_count)->toBe(0);
});

it('acha o projeto da pessoa pelo uuid e recusa o de outro dono com a mesma exceção de inexistente', function () {
    $dono = User::factory()->create();
    $outro = User::factory()->create();
    $meu = criarProjetoPeloServico($dono, 'Meu');
    $alheio = criarProjetoPeloServico($outro, 'Alheio');

    expect(naConta($dono, fn () => projectService()->find($meu->uuid))->is($meu))->toBeTrue();

    expect(fn () => naConta($dono, fn () => projectService()->find($alheio->uuid)))->toThrow(ModelNotFoundException::class)
        ->and(fn () => naConta($dono, fn () => projectService()->find((string) str()->uuid())))->toThrow(ModelNotFoundException::class);
});

it('pela chave de conta enxerga todos os projetos do dono e nenhum de outro', function () {
    $dono = User::factory()->create();
    $outro = User::factory()->create();
    $a = criarProjetoPeloServico($dono, 'A');
    $b = criarProjetoPeloServico($dono, 'B');
    $alheio = criarProjetoPeloServico($outro, 'Alheio');

    ['api_key' => $chave] = criarChave($dono);

    expect(naConta($dono, fn () => projectService()->paginateForApiKey($chave, 15))->pluck('uuid')->sort()->values()->all())
        ->toBe(collect([$a->uuid, $b->uuid])->sort()->values()->all());

    expect(fn () => naConta($dono, fn () => projectService()->findForApiKey($chave, $alheio->uuid)))->toThrow(ModelNotFoundException::class);
});

it('pela chave restrita enxerga só os vinculados; fora do vínculo é a mesma exceção', function () {
    $dono = User::factory()->create();
    $a = criarProjetoPeloServico($dono, 'A');
    $b = criarProjetoPeloServico($dono, 'B');

    ['api_key' => $chave] = criarChave($dono, ['project_uuids' => [$a->uuid]]);

    expect(naConta($dono, fn () => projectService()->paginateForApiKey($chave, 15))->pluck('uuid')->all())->toBe([$a->uuid])
        ->and(naConta($dono, fn () => projectService()->findForApiKey($chave, $a->uuid))->is($a))->toBeTrue();

    expect(fn () => naConta($dono, fn () => projectService()->findForApiKey($chave, $b->uuid)))->toThrow(ModelNotFoundException::class);
});

it('respeita o tamanho de página pedido', function () {
    $dono = User::factory()->create();
    foreach (range(1, 3) as $i) {
        criarProjetoPeloServico($dono, "P{$i}");
    }

    ['api_key' => $chave] = criarChave($dono);

    $pagina = naConta($dono, fn () => projectService()->paginateForApiKey($chave, 2));

    expect($pagina->count())->toBe(2)
        ->and($pagina->total())->toBe(3);
});

it('cria o projeto só com nome, na conta atual e por quem criou, com código público PRJ-', function () {
    $dono = User::factory()->create();

    $projeto = criarProjetoPeloServico($dono, 'Loja');

    expect($projeto->exists)->toBeTrue()
        ->and($projeto->account_id)->toBe(contaPessoal($dono)->id)
        ->and($projeto->created_by)->toBe($dono->id)
        ->and($projeto->name)->toBe('Loja')
        ->and($projeto->status)->toBe(ProjectStatus::Active)
        ->and($projeto->codigo_publico)->toStartWith('PRJ-');
});

it('atualiza só nome e status — conta, quem criou e identificadores não mudam por aqui', function () {
    $dono = User::factory()->create();
    $outro = User::factory()->create();
    $projeto = criarProjetoPeloServico($dono, 'Loja');
    $uuid = $projeto->uuid;

    naConta($dono, fn () => projectService()->update($projeto, [
        'name' => 'Loja 2',
        'status' => ProjectStatus::Archived->value,
        'account_id' => contaPessoal($outro)->id,
        'created_by' => $outro->id,
        'uuid' => (string) str()->uuid(),
    ]));

    $projeto->refresh();

    expect($projeto->name)->toBe('Loja 2')
        ->and($projeto->status)->toBe(ProjectStatus::Archived)
        ->and($projeto->account_id)->toBe(contaPessoal($dono)->id)
        ->and($projeto->created_by)->toBe($dono->id)
        ->and($projeto->uuid)->toBe($uuid);
});

it('excluir derruba o vínculo e deixa a chave restrita a nenhum projeto (fail-closed)', function () {
    $dono = User::factory()->create();
    $a = criarProjetoPeloServico($dono, 'A');
    criarProjetoPeloServico($dono, 'B');

    ['api_key' => $chave] = criarChave($dono, ['project_uuids' => [$a->uuid]]);

    naConta($dono, fn () => projectService()->delete($a));

    expect(comoSistema(fn () => Project::query()->whereKey($a->getKey())->exists()))->toBeFalse()
        ->and(DB::table('api_key_project')->where('project_id', $a->getKey())->count())->toBe(0)
        ->and($chave->fresh()?->isRestrictedToProjects())->toBeTrue()
        ->and(naConta($dono, fn () => projectService()->paginateForApiKey($chave->fresh(), 15))->total())->toBe(0);
});
