<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Support\BaseListRecords;
use Twstec\Kit\Admin\Support\BaseResource;
use Twstec\Kit\Admin\Support\CardActions;
use Twstec\Kit\Admin\Support\ViewMode;
use Twstec\Kit\Admin\Support\ViewModeToggle;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\FormSubmissionResource;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\Pages\ListFormSubmissions;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\ListProducts;
use Twstec\Kit\Demo\Filament\Resources\Products\ProductResource;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;

// =============================================================================
// Alternador tabela/cards das listagens do super admin.
//
// O que estes testes protegem:
// - o botão existe e troca de verdade o layout da listagem (contentGrid);
// - a escolha PERSISTE, por usuário (sessão) e por RECURSO — trocar
//   submissões para cards não mexe em produtos;
// - as duas telas renderizam com conteúdo nos dois modos (um alternador que
//   quebra a listagem num dos lados é pior que nenhum alternador);
// - todo Resource do painel herda da base (arquitetura).
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('começa em tabela: sem grade de cards e sem layout de card', function () {
    FormSubmission::factory()->count(3)->create();

    $table = Livewire::test(ListFormSubmissions::class)
        ->assertOk()
        ->instance()
        ->getTable();

    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Table)
        ->and($table->getContentGrid())->toBeNull();
})->group('demo');

it('o botão da barra da tabela troca para cards e a listagem renderiza em grade', function () {
    $submission = FormSubmission::factory()->create(['nickname' => 'visivel-em-cards']);

    Livewire::test(ListFormSubmissions::class)
        ->callTableAction('toggleViewMode')
        ->assertOk()
        ->assertCanSeeTableRecords([$submission])
        ->assertSee('visivel-em-cards');

    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Grid);

    // Numa requisição seguinte a preferência continua valendo e a tabela
    // nasce com a grade de cards configurada.
    $grid = Livewire::test(ListFormSubmissions::class)
        ->instance()
        ->getTable()
        ->getContentGrid();

    expect($grid)->toBeArray()->toHaveKey('md');
})->group('demo');

it('o alternador volta para a tabela no clique seguinte', function () {
    FormSubmission::factory()->create();

    Livewire::test(ListFormSubmissions::class)->callTableAction('toggleViewMode');
    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Grid);

    Livewire::test(ListFormSubmissions::class)->callTableAction('toggleViewMode');
    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Table);

    expect(Livewire::test(ListFormSubmissions::class)->instance()->getTable()->getContentGrid())
        ->toBeNull();
})->group('demo');

it('a preferência é POR RECURSO: cards em submissões não vira cards em produtos', function () {
    Livewire::test(ListFormSubmissions::class)->callTableAction('toggleViewMode');

    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Grid)
        ->and(ViewMode::for(ProductResource::class))->toBe(ViewMode::Table);

    expect(Livewire::test(ListProducts::class)->instance()->getTable()->getContentGrid())
        ->toBeNull();
})->group('demo');

it('a preferência é POR USUÁRIO: a sessão de outro admin nasce em tabela', function () {
    Livewire::test(ListFormSubmissions::class)->callTableAction('toggleViewMode');
    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Grid);

    // Sessão nova (outro operador, outra máquina) = padrão da plataforma.
    session()->flush();

    expect(ViewMode::for(FormSubmissionResource::class))->toBe(ViewMode::Table);
})->group('demo');

it('produtos também alternam e mostram o conteúdo nos dois modos', function () {
    $product = Product::factory()->create([
        'title' => 'Cafeteira de Prova',
        'price' => 12345,
        'description' => 'Item de teste do alternador.',
    ]);

    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$product])
        ->assertSee('Cafeteira de Prova');

    Livewire::test(ListProducts::class)
        ->callTableAction('toggleViewMode')
        ->assertOk()
        ->assertCanSeeTableRecords([$product])
        ->assertSee('Cafeteira de Prova');

    expect(ViewMode::for(ProductResource::class))->toBe(ViewMode::Grid);
})->group('demo');

// -----------------------------------------------------------------------------
// Arquitetura: a base é lei, não convenção
// -----------------------------------------------------------------------------

/**
 * Pasta dos resources do painel — do pacote twstec/kit-admin desde a 2.0
 * (antes, app/Filament/Resources).
 */
function adminPackageResourcesPath(string $relative): string
{
    return dirname((string) (new ReflectionClass(BaseResource::class))->getFileName(), 2).'/Resources/'.$relative;
}

it('todo Resource do painel estende a base do kit', function () {
    $resources = collect(glob(adminPackageResourcesPath('*/*Resource.php')) ?: [])
        ->map(fn (string $path): string => 'Twstec\\Kit\\Admin\\Resources\\'
            .str_replace('/', '\\', str_replace([adminPackageResourcesPath(''), '.php'], '', $path)))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Resource::class));

    expect($resources)->not->toBeEmpty();

    foreach ($resources as $class) {
        expect(is_subclass_of($class, BaseResource::class))
            ->toBeTrue("{$class} precisa estender App\\Filament\\Support\\BaseResource");
    }
});

it('toda página de listagem do painel estende a base (é de lá que vem o alternador)', function () {
    $pages = collect(glob(adminPackageResourcesPath('*/Pages/List*.php')) ?: [])
        ->map(fn (string $path): string => 'Twstec\\Kit\\Admin\\Resources\\'
            .str_replace('/', '\\', str_replace([adminPackageResourcesPath(''), '.php'], '', $path)));

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $class) {
        expect(is_subclass_of($class, BaseListRecords::class))
            ->toBeTrue("{$class} precisa estender App\\Filament\\Support\\BaseListRecords");
    }
});

it('todo resource declara rótulo e grupo de navegação traduzidos (nada de chave crua na tela)', function () {
    $resources = collect(glob(adminPackageResourcesPath('*/*Resource.php')) ?: [])
        ->map(fn (string $path): string => 'Twstec\\Kit\\Admin\\Resources\\'
            .str_replace('/', '\\', str_replace([adminPackageResourcesPath(''), '.php'], '', $path)))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, BaseResource::class));

    foreach ($resources as $class) {
        expect($class::getModelLabel())->not->toContain('admin.')
            ->and($class::getPluralModelLabel())->not->toContain('admin.')
            ->and($class::getNavigationGroup())->not->toContain('admin.');
    }
});

// -----------------------------------------------------------------------------
// O alternador mudou de lugar e de forma: barra da tabela, só ícone
// -----------------------------------------------------------------------------

it('o alternador é ação da BARRA da tabela, não do cabeçalho da página', function () {
    $page = Livewire::test(ListUsers::class)->instance();

    $barra = collect($page->getTable()->getToolbarActions())
        ->map(fn ($action): string => $action->getName());

    expect($barra)->toContain(ViewModeToggle::NAME);

    // O cabeçalho fica só com as ações do recurso ("Novo usuário"): o
    // alternador não disputa mais espaço com a ação primária da tela.
    $cabecalho = collect((fn () => $this->getHeaderActions())->call($page))
        ->map(fn ($action): string => $action->getName());

    expect($cabecalho)->not->toContain(ViewModeToggle::NAME)
        ->and($cabecalho)->toContain('create');
});

it('o alternador é só ícone, com o nome no hover — como o botão de filtros', function () {
    $acao = collect(Livewire::test(ListUsers::class)->instance()->getTable()->getToolbarActions())
        ->firstWhere(fn ($action): bool => $action->getName() === ViewModeToggle::NAME);

    expect($acao->isIconButton())->toBeTrue()
        ->and($acao->getLabel())->toBe(__('admin.common.view_as_cards'))
        // Nome no hover: sem tooltip, um botão só de ícone é um enigma.
        ->and($acao->getTooltip())->toBe(__('admin.common.view_as_cards'))
        ->and($acao->getIcon())->toBe(Heroicon::OutlinedSquares2x2);
});

it('o ícone mostra o DESTINO do clique: em cards, ele oferece a tabela', function () {
    Livewire::test(ListUsers::class)->callTableAction(ViewModeToggle::NAME);

    $acao = collect(Livewire::test(ListUsers::class)->instance()->getTable()->getToolbarActions())
        ->firstWhere(fn ($action): bool => $action->getName() === ViewModeToggle::NAME);

    expect($acao->getIcon())->toBe(Heroicon::OutlinedTableCells)
        ->and($acao->getTooltip())->toBe(__('admin.common.view_as_table'));
});

// -----------------------------------------------------------------------------
// Ações no MODO CARDS: só ícone, cor com significado, nome no hover
// -----------------------------------------------------------------------------

it('no modo tabela as ações continuam como antes: link com rótulo', function () {
    User::factory()->create();

    $acoes = collect(Livewire::test(ListUsers::class)->instance()->getTable()->getFlatRecordActions());

    expect($acoes->get('view')->isIconButton())->toBeFalse()
        ->and($acoes->get('edit')->isIconButton())->toBeFalse();
});

it('no modo cards toda ação de registro vira botão de ícone com tooltip', function () {
    User::factory()->create();

    Livewire::test(ListUsers::class)->callTableAction(ViewModeToggle::NAME);

    $acoes = collect(Livewire::test(ListUsers::class)->instance()->getTable()->getFlatRecordActions());

    expect($acoes)->not->toBeEmpty();

    foreach ($acoes as $nome => $acao) {
        expect($acao->isIconButton())->toBeTrue("A ação [{$nome}] deveria ser só ícone no modo cards")
            ->and($acao->getTooltip())->toBe($acao->getLabel())
            ->and($acao->getIcon())->not->toBeNull();
    }
});

it('a cor da ação no card diz o que ela faz (semântica, não decoração)', function () {
    User::factory()->create();

    Livewire::test(ListUsers::class)->callTableAction(ViewModeToggle::NAME);

    $acoes = collect(Livewire::test(ListUsers::class)->instance()->getTable()->getFlatRecordActions());

    expect($acoes->get('view')->getColor())->toBe('info')
        ->and($acoes->get('edit')->getColor())->toBe('success')
        ->and($acoes->get('block')->getColor())->toBe('danger')
        ->and($acoes->get('unblock')->getColor())->toBe('success')
        ->and($acoes->get('delete')->getColor())->toBe('danger');
});

it('o mapa de semântica é o contrato único das cores do card', function () {
    expect(CardActions::colorFor('view'))->toBe('info')
        ->and(CardActions::colorFor('edit'))->toBe('success')
        ->and(CardActions::colorFor('block'))->toBe('danger')
        ->and(CardActions::colorFor('unblock'))->toBe('success')
        ->and(CardActions::colorFor('delete'))->toBe('danger')
        ->and(CardActions::colorFor('rotate'))->toBe('warning')
        // Ação desconhecida não é adivinhada: nasce neutra, com a cor que o
        // resource declarou.
        ->and(CardActions::colorFor('acao-que-nao-existe'))->toBeNull();
});

it('a listagem em cards renderiza as ações sem quebrar', function () {
    $user = User::factory()->create(['email' => 'card@example.com']);

    Livewire::test(ListUsers::class)
        ->callTableAction(ViewModeToggle::NAME)
        ->assertOk()
        ->assertCanSeeTableRecords([$user])
        ->assertTableActionVisible('view', $user)
        ->assertTableActionVisible('delete', $user);
});
