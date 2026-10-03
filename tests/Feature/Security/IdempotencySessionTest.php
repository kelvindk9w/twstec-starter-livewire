<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;

// =============================================================================
// O middleware `idempotent` numa rota do APLICATIVO com sessão — em qualquer
// combinação de módulos. Com o módulo de contas, a chave vale por conta +
// pessoa; só com a base, por pessoa. Nos dois casos: uma escrita, replay
// idêntico, e duas pessoas com a mesma chave não colidem.
// =============================================================================

beforeEach(function (): void {
    Schema::create('pedidos_de_teste', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('item');
    });

    Route::middleware(['web', 'auth', 'idempotent:required'])->post('/_teste/pedidos', function () {
        $id = DB::table('pedidos_de_teste')->insertGetId(['user_id' => auth()->id(), 'item' => (string) request('item')]);

        return response()->json(['data' => ['id' => $id, 'nonce' => bin2hex(random_bytes(8))]], 201);
    });
});

it('rota com sessão: uma escrita por chave e por pessoa, replay idêntico', function (): void {
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    $headers = ['Idempotency-Key' => 'pedido-sessao-0001-abcdef'];

    $primeira = $this->actingAs($ana)->postJson('/_teste/pedidos', ['item' => 'caderno'], $headers);
    $repeticao = $this->actingAs($ana)->postJson('/_teste/pedidos', ['item' => 'caderno'], $headers);
    $outraPessoa = $this->actingAs($bia)->postJson('/_teste/pedidos', ['item' => 'caderno'], $headers);

    $primeira->assertCreated();
    $repeticao->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    $outraPessoa->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect($repeticao->getContent())->toBe($primeira->getContent())
        ->and(DB::table('pedidos_de_teste')->count())->toBe(2)
        ->and(DB::table(IdempotencyStore::TABLE)->count())->toBe(2);
});

it('rota que exige a chave recusa sem ela, e ninguém autenticado não chega à idempotência', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/_teste/pedidos', ['item' => 'caderno'])
        ->assertStatus(400);

    auth()->logout();

    $this->postJson('/_teste/pedidos', ['item' => 'caderno'], ['Idempotency-Key' => 'pedido-sessao-0002-abcdef'])
        ->assertUnauthorized();

    expect(DB::table('pedidos_de_teste')->count())->toBe(0);
});
