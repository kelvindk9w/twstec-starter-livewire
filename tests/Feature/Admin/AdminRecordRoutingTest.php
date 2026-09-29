<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Admin\Resources\Users\UserResource;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Demo\Filament\Resources\Products\ProductResource;

// =============================================================================
// Bug de QA #2 — links de Visualizar/Editar do /admin davam 404.
//
// Causa: no Filament 5 o $recordRouteKeyName do Resource só RESOLVE o
// registro vindo da URL; a GERAÇÃO da URL passa por route(), que usa
// Model::getRouteKey() (id). Corrigido com a trait RoutesByUuid nos models.
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

it('gera a URL de visualização de usuário com o uuid, nunca com o id', function () {
    $user = User::factory()->create();

    $url = UserResource::getUrl('view', ['record' => $user]);

    expect($url)->toContain($user->uuid)
        ->and($url)->not->toContain('/users/'.$user->id);
});

it('abre a visualização de usuário pela URL com uuid', function () {
    $user = User::factory()->create(['email' => 'visivel@example.com']);

    $this->get(UserResource::getUrl('view', ['record' => $user]))
        ->assertOk()
        ->assertSee('visivel@example.com')
        ->assertSee($user->codigo_publico);
});

it('gera a URL de edição de produto com o uuid, nunca com o id', function () {
    $product = Product::factory()->create();

    $url = ProductResource::getUrl('edit', ['record' => $product]);

    expect($url)->toContain($product->uuid)
        ->and($url)->not->toContain('/products/'.$product->id.'/edit');
})->group('demo');

it('abre a edição de produto pela URL com uuid', function () {
    $product = Product::factory()->create(['title' => 'Produto Roteado']);

    $this->get(ProductResource::getUrl('edit', ['record' => $product]))
        ->assertOk()
        ->assertSee('Produto Roteado');
})->group('demo');
