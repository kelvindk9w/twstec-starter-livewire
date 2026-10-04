<?php

declare(strict_types=1);

use App\Livewire\Support\Navigation;
use App\Livewire\Webhooks\Index;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Webhooks\Models\WebhookDeliveryAttempt;
use Twstec\Kit\Webhooks\Models\WebhookEndpoint;
use Twstec\Kit\Webhooks\Security\HostResolver;
use Twstec\Kit\Webhooks\Webhooks;

// =============================================================================
// Webhooks pela tela Livewire: a mesma tela do React, sobre as mesmas
// Actions do twstec/kit-webhooks. Aqui: o fluxo completo com a ação sensível
// de verdade (senha de transação + código do e-mail), o segredo UMA vez, o
// SSRF recusado antes de pedir o código, o papel (membro só vê), o log de
// entregas e o reenvio auditado. O DNS é de mentira e o envio, Http::fake —
// nada sai da máquina. Grupos `accounts` e `webhooks` (tests/Pest.php); nada
// declarado no topo do arquivo.
// =============================================================================

function codigoDoWebhook(): string
{
    /** @var VerificationCodeMail $mail */
    $mail = Mail::queued(VerificationCodeMail::class)->last();

    return $mail->code;
}

beforeEach(function () {
    Mail::fake();
    config()->set('auth.verification.resend_cooldown_seconds', 0);
    config()->set('webhooks.events', ['order.created', 'order.shipped']);

    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return match ($host) {
                'hooks.example.com' => ['93.184.215.14'],
                'interno.example.com' => ['192.168.0.20'],
                default => [],
            };
        }
    });
});

/**
 * Cria pela tela (com a confirmação) e devolve o componente.
 */
function criarWebhookNaTela(User $user, array $dados = []): mixed
{
    return Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', $dados['name'] ?? 'Receptor de pedidos')
        ->set('url', $dados['url'] ?? 'https://hooks.example.com/webhooks')
        ->set('events', $dados['events'] ?? ['order.created'])
        ->call('requestSave')
        ->assertHasNoErrors()
        ->assertSet('pendingAction', 'save')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->assertHasNoErrors()
        ->set('sensitiveCode', codigoDoWebhook())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors();
}

it('exige autenticação', function () {
    $this->get('/webhooks')->assertRedirect(route('login'));
});

it('a tela abre pelo menu e responde', function () {
    $user = User::factory()->create();

    $itens = collect(Navigation::account())->flatMap(fn (array $grupo): array => array_column($grupo['items'], 'label'))->all();

    expect($itens)->toContain(__('panel.nav.webhooks'));

    $this->actingAs($user)->get('/webhooks')->assertOk()->assertSee(__('webhooks.ui.title'));
});

it('cria com a confirmação sensível e mostra o segredo UMA vez (cifrado no banco)', function () {
    $user = User::factory()->withTransactionPassword()->create();

    $component = criarWebhookNaTela($user);

    $endpoint = Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->sole());
    $secret = $component->get('revealedSecret');

    expect($secret)->toStartWith('whsk_')
        ->and($endpoint->secret)->toBe($secret)
        ->and(DB::table('webhook_endpoints')->value('secret'))->not->toContain($secret)
        ->and(AuditEvent::query()->where('action', 'webhook_endpoint.created')->where('outcome', 'success')->exists())->toBeTrue();

    $component->assertSee($secret)->call('dismissSecret')->assertSet('revealedSecret', null)->assertDontSee($secret);

    Livewire::actingAs($user)->test(Index::class)->assertDontSee($secret)->assertSee('Receptor de pedidos');
});

it('SSRF: URL interna é recusada ANTES de pedir o código — e registrada', function (string $url) {
    $user = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('startCreate')
        ->set('name', 'Interno')
        ->set('url', $url)
        ->set('events', ['*'])
        ->call('requestSave')
        ->assertHasErrors('url')
        ->assertSet('pendingAction', null);

    Mail::assertNothingQueued();

    expect(AuditEvent::query()->where('action', 'webhook_endpoint.created')->where('outcome', 'denied')->exists())->toBeTrue()
        ->and(Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->count()))->toBe(0);
})->with([
    '127.0.0.1' => 'https://127.0.0.1/hook',
    '169.254.169.254' => 'https://169.254.169.254/latest/meta-data',
    '10/8' => 'https://10.20.30.40/hook',
    '::1' => 'https://[::1]/hook',
    'nome que resolve para IP privado' => 'https://interno.example.com/hook',
]);

it('membro vê, mas não gere (403) — e a tela não mostra os botões', function () {
    $dono = User::factory()->withTransactionPassword()->create();
    $membro = User::factory()->withTransactionPassword()->create();
    $empresa = app(AccountService::class)->createAccount('Equipe SA', $dono);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    Accounts::actingAs($empresa, fn () => criarWebhookNaTela($dono), $dono);
    $endpoint = Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->sole());

    Accounts::actingAs($empresa, function () use ($membro, $endpoint): void {
        Livewire::actingAs($membro)->test(Index::class)
            ->assertSee(__('webhooks.ui.read_only'))
            ->assertDontSee(__('webhooks.ui.new'));

        Livewire::actingAs($membro)->test(Index::class)->call('sendTest', $endpoint->uuid)->assertForbidden();
        Livewire::actingAs($membro)->test(Index::class)->call('toggleStatus', $endpoint->uuid, false)->assertForbidden();
        Livewire::actingAs($membro)->test(Index::class)->set('deletingUuid', $endpoint->uuid)->call('removeEndpoint')->assertForbidden();
    }, $membro);
});

it('enviar teste, ver a entrega no log e REENVIAR (auditado)', function () {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('{"erro":true}', 500)->push('{"ok":true}', 200)]);

    $user = User::factory()->withTransactionPassword()->create();
    criarWebhookNaTela($user);
    $endpoint = Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->sole());

    $tela = Livewire::actingAs($user)->test(Index::class)
        ->call('sendTest', $endpoint->uuid)
        ->assertHasNoErrors()
        ->assertSet('deliveriesFor', $endpoint->uuid)
        ->assertSee('webhook.ping')
        ->assertSee('HTTP 500');

    $delivery = Accounts::asSystem('teste', fn () => $endpoint->deliveries()->sole());

    $tela->call('resend', $delivery->uuid)->assertHasNoErrors();

    $tentativas = Accounts::asSystem('teste', fn () => WebhookDeliveryAttempt::query()->orderBy('attempt')->get());

    expect($tentativas)->toHaveCount(2)
        ->and($tentativas[1]->manual)->toBeTrue()
        ->and($tentativas[1]->created_by)->toBe($user->id)
        ->and(AuditEvent::query()->where('action', 'webhook_delivery.resent')->where('actor_uuid', $user->uuid)->exists())->toBeTrue();
});

it('endpoint de outra conta: 404 (o mesmo do inexistente)', function () {
    $dono = User::factory()->withTransactionPassword()->create();
    criarWebhookNaTela($dono);
    $endpoint = Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->sole());

    $intruso = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($intruso)->test(Index::class)->call('sendTest', $endpoint->uuid)->assertNotFound();
    Livewire::actingAs($intruso)->test(Index::class)->set('deletingUuid', $endpoint->uuid)->call('removeEndpoint')->assertNotFound();

    expect(Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->count()))->toBe(1);
});

it('revelar e rotacionar pedem a confirmação sensível', function () {
    $user = User::factory()->withTransactionPassword()->create();
    $secret = criarWebhookNaTela($user)->get('revealedSecret');
    $endpoint = Accounts::asSystem('teste', fn () => WebhookEndpoint::query()->sole());

    $revelado = Livewire::actingAs($user)->test(Index::class)
        ->call('startReveal', $endpoint->uuid)
        ->assertSet('pendingAction', 'reveal')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->set('sensitiveCode', codigoDoWebhook())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors()
        ->get('revealedSecret');

    $novo = Livewire::actingAs($user)->test(Index::class)
        ->call('startRotate', $endpoint->uuid)
        ->set('overlapMinutes', 60)
        ->call('requestRotate')
        ->assertSet('pendingAction', 'rotate')
        ->set('sensitivePassword', 'Trans4cao!Segura')
        ->call('sendSensitiveCode')
        ->set('sensitiveCode', codigoDoWebhook())
        ->call('confirmSensitiveAction')
        ->assertHasNoErrors()
        ->get('revealedSecret');

    expect($revelado)->toBe($secret)
        ->and($novo)->not->toBe($secret)
        ->and(Accounts::asSystem('teste', fn () => $endpoint->fresh()->signingSecrets()))->toBe([$novo, $secret]);
});

it('o evento do aplicativo vai para o log (o corpo dele não aparece na tela)', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $user = User::factory()->withTransactionPassword()->create();
    criarWebhookNaTela($user);

    Webhooks::dispatch(app(AccountService::class)->personalAccountOf($user), 'order.created', ['order' => ['id' => 'ped-secreto-1']]);

    Livewire::actingAs($user)->test(Index::class)
        ->assertSee('order.created')
        ->assertSee(__('webhooks.delivery_status.succeeded'))
        ->assertDontSee('ped-secreto-1');
});
