<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Admin\Approvals\Approvals;
use Twstec\Kit\Admin\Approvals\ApprovalService;
use Twstec\Kit\Admin\Approvals\Enums\ApprovalStatus;
use Twstec\Kit\Admin\Approvals\Models\ApprovalRequest;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Demo\Catalog\Approvals\RepriceProduct;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\EditProduct;
use Twstec\Kit\Demo\Filament\Resources\Products\Pages\ListProducts;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// REAJUSTAR PREÇO na demonstração: a aprovação em dois passos do /admin
// sempre ligada (RepriceProduct::alwaysRequiresApproval). O valor novo e o
// motivo viram pedido; o preço só muda quando OUTRA pessoa aprova.

beforeEach(function () {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);

    $this->pede = User::factory()->create(['is_admin' => true]);
    $this->aprova = User::factory()->withTransactionPassword()->create(['is_admin' => true]);
});

it('é sempre exigida, sem config: reajustar pela tela vira pedido e o preço não muda', function () {
    config()->set('admin.approvals.actions', []);
    $produto = Product::factory()->create(['price' => 10000]);
    $this->actingAs($this->pede);

    expect(Approvals::requires(RepriceProduct::KEY))->toBeTrue();

    Livewire::test(ListProducts::class)
        ->callTableAction('reprice', $produto, data: ['price' => '150,00', 'approval_reason' => 'Novo fornecedor'])
        ->assertHasNoTableActionErrors();

    $pedido = ApprovalRequest::query()->sole();

    expect($produto->fresh()->price)->toBe(10000)
        ->and($pedido->status)->toBe(ApprovalStatus::Pending)
        ->and($pedido->action)->toBe('products.reprice')
        ->and($pedido->payload)->toBe(['price' => 15000])
        ->and($pedido->summary['price']['after'])->toContain('150,00');
});

it('valor inválido não vira pedido (mesma regra do cadastro)', function () {
    $produto = Product::factory()->create(['price' => 10000]);
    $this->actingAs($this->pede);

    Livewire::test(EditProduct::class, ['record' => $produto->uuid])
        ->callAction('reprice', data: ['price' => '0,00', 'approval_reason' => 'x'])
        ->assertHasActionErrors(['price']);

    expect(ApprovalRequest::query()->count())->toBe(0);
});

it('OUTRA pessoa aprova (ação sensível) e o preço muda — `product.repriced` na trilha', function () {
    $produto = Product::factory()->create(['price' => 10000]);
    $pedido = app(ApprovalService::class)->request(RepriceProduct::KEY, $produto, ['price' => 15000], 'Novo fornecedor', $this->pede);

    app(SensitiveActionService::class)->sendCode($this->aprova, 'Trans4cao!Segura');
    $codigo = Mail::queued(VerificationCodeMail::class)
        ->filter(fn (VerificationCodeMail $m): bool => $m->purpose === VerificationPurpose::SensitiveAction)
        ->last()->code;
    $token = app(SensitiveActionService::class)->confirmCode($this->aprova, $codigo)['token'];

    $resultado = app(ApprovalService::class)->approve($pedido, $this->aprova, $token);

    expect($resultado->status)->toBe(ApprovalStatus::Executed)
        ->and($produto->fresh()->price)->toBe(15000)
        ->and(AuditEvent::query()->where('action', 'product.repriced')->sole()->actor_uuid)->toBe($this->aprova->uuid);
});
