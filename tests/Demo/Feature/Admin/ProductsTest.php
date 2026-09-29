<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Demo\Database\Seeders\ProductSeeder;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\CreateProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\EditProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\ListProducts;
use Twstec\Kit\Foundation\Money\Money;

use function Pest\Livewire\livewire;

// CRUD de Produtos (super admin — vitrine): listar/criar/editar/excluir,
// valor monetário em centavos (nunca float), foto (upload ou URL externa),
// paginação de 10 e paginação/filtros refletidos na query string.

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('lista produtos com foto, título e valor formatado', function () {
    $product = Product::factory()->create(['title' => 'Teclado de Teste', 'price' => 34990]);

    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$product])
        ->assertSee('Teclado de Teste')
        ->assertSee(Money::format(34990));
});

it('cria produto convertendo o valor formatado para centavos', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'title' => 'Mouse Novo',
            'price' => '1.234,56',
            'description' => 'Descrição do mouse.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('title', 'Mouse Novo')->sole();

    expect($product->price)->toBe(123456); // inteiro em centavos, nunca float
});

it('rejeita valor monetário inválido', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['title' => 'X', 'price' => 'abc'])
        ->call('create')
        ->assertHasFormErrors(['price']);

    expect(Product::query()->count())->toBe(0);
});

it('edita título e valor pela tela de edição', function () {
    $product = Product::factory()->create(['title' => 'Antes', 'price' => 1000]);

    Livewire::test(EditProduct::class, ['record' => $product->uuid])
        ->assertFormSet(['price' => Money::format(1000)])
        ->fillForm(['title' => 'Depois', 'price' => '25,00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->title)->toBe('Depois')
        ->and($product->fresh()->price)->toBe(2500);
});

it('faz upload da foto no disk public', function () {
    Storage::fake('public');

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'title' => 'Com Foto',
            'price' => '10,00',
            'image' => UploadedFile::fake()->image('produto.jpg', 600, 400),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('title', 'Com Foto')->sole();

    expect($product->image)->toStartWith('products/')
        ->and(Storage::disk('public')->exists($product->image))->toBeTrue()
        ->and($product->imageUrl())->toContain('/storage/products/');
});

it('resolve URL externa de imagem direto (seeder da demo)', function () {
    $product = Product::factory()->create(['image' => 'https://picsum.photos/seed/x/600/400']);

    expect($product->imageUrl())->toBe('https://picsum.photos/seed/x/600/400');
});

it('exclui produto pela ação da tabela', function () {
    $product = Product::factory()->create();

    Livewire::test(ListProducts::class)
        ->callTableAction('delete', $product);

    expect(Product::query()->whereKey($product->id)->exists())->toBeFalse();
});

it('pagina de 10 em 10 e a página vem da query string (?page=N)', function () {
    // created_at explícito: ordenação estável (o default sort é created_at desc).
    $products = collect(range(0, 24))->map(
        fn (int $i): Product => Product::factory()->create(['created_at' => now()->subMinutes($i)]),
    );

    // Página 1 (padrão): os 10 mais recentes.
    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords($products->slice(0, 10)->all())
        ->assertCanNotSeeTableRecords($products->slice(10, 10)->all());

    // A página 2 chega PELA URL (query string nativa do Livewire) e mostra
    // os 10 seguintes — ?page=2 é compartilhável por link.
    Livewire::withQueryParams(['page' => 2])
        ->test(ListProducts::class)
        ->assertCanSeeTableRecords($products->slice(10, 10)->all())
        ->assertCanNotSeeTableRecords($products->slice(0, 10)->all());
});

it('o filtro vem da query string (?filters[price_range]=…)', function () {
    $baratos = Product::factory()->count(2)->create(['price' => 5000]);
    $medios = Product::factory()->count(2)->create(['price' => 30000]);
    $caros = Product::factory()->count(2)->create(['price' => 90000]);

    Livewire::withQueryParams(['filters' => ['price_range' => ['value' => 'ate_100']]])
        ->test(ListProducts::class)
        ->assertCanSeeTableRecords($baratos->all())
        ->assertCanNotSeeTableRecords($medios->all())
        ->assertCanNotSeeTableRecords($caros->all());

    Livewire::withQueryParams(['filters' => ['price_range' => ['value' => 'acima_500']]])
        ->test(ListProducts::class)
        ->assertCanSeeTableRecords($caros->all())
        ->assertCanNotSeeTableRecords($baratos->all());
});

it('a propriedade de filtros é sincronizada na URL (#[Url])', function () {
    $property = new ReflectionProperty(ListProducts::class, 'tableFilters');

    $attributes = array_map(
        fn (ReflectionAttribute $attribute): string => $attribute->getName(),
        $property->getAttributes(),
    );

    expect($attributes)->toContain(Url::class);
});

it('o seeder da demo cadastra 30+ produtos variados', function () {
    $this->seed(ProductSeeder::class);

    expect(Product::query()->count())->toBeGreaterThanOrEqual(30)
        ->and(Product::query()->whereNull('image')->count())->toBe(0)
        ->and(Product::query()->distinct('title')->count('title'))->toBe(Product::query()->count());
});

// Bug de QA #3 — o catálogo aceitava valor negativo. Regra: valor > 0.

it('rejeita valor negativo no catálogo', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['title' => 'Produto Negativo', 'price' => '-10,00'])
        ->call('create')
        ->assertHasFormErrors(['price']);

    expect(Product::query()->where('title', 'Produto Negativo')->exists())->toBeFalse();
});

it('rejeita valor zero no catálogo', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['title' => 'Produto Zero', 'price' => '0,00'])
        ->call('create')
        ->assertHasFormErrors(['price']);

    expect(Product::query()->where('title', 'Produto Zero')->exists())->toBeFalse();
});

it('aceita o menor valor válido do catálogo (1 centavo)', function () {
    Livewire::test(CreateProduct::class)
        ->fillForm(['title' => 'Produto Centavo', 'price' => '0,01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('title', 'Produto Centavo')->sole()->price)->toBe(1);
});

it('recusa valor negativo também na edição, com a mensagem traduzida', function () {
    $product = Product::factory()->create(['price' => 5000]);

    app()->setLocale('pt_BR');

    Livewire::test(EditProduct::class, ['record' => $product->uuid])
        ->fillForm(['price' => '-1,00'])
        ->call('save')
        ->assertHasFormErrors(['price' => __('admin.products.price_positive')]);

    expect($product->fresh()->price)->toBe(5000);
});
