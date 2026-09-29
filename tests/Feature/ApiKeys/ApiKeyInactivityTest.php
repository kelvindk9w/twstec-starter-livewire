<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Mail\ApiKeyInactivityWarningMail;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;

// Expiração por inatividade: job diário desativa chaves sem uso
// há X meses (config) e envia e-mail de AVISO PRÉVIO Y dias antes — uma
// única vez por ciclo (flag inactivity_warning_sent_at).

beforeEach(function () {
    Mail::fake();
    config()->set('api_keys.inactivity.months', 3);
    config()->set('api_keys.inactivity.warning_days', 7);
});

/**
 * Força a "última atividade" da chave via banco (uso → last_used_at;
 * nunca usada → created_at).
 */
function envelhecerChave(ApiKey $key, DateTimeInterface $atividade, bool $usada = false): void
{
    comoSistema(fn () => ApiKey::query()->where('id', $key->id)->update(
        $usada
            ? ['created_at' => now()->subYear(), 'last_used_at' => $atividade]
            : ['created_at' => $atividade],
    ));
}

it('envia aviso prévio por e-mail dentro da janela e NÃO repete', function () {
    $user = User::factory()->create();
    ['api_key' => $key] = criarChave($user);

    // 4 dias antes de completar 3 meses sem uso → dentro da janela de 7 dias.
    envelhecerChave($key, now()->subMonthsNoOverflow(3)->addDays(4));

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    Mail::assertQueued(ApiKeyInactivityWarningMail::class, function (ApiKeyInactivityWarningMail $mail) use ($key): bool {
        return $mail->apiKey->uuid === $key->uuid && $mail->expiresInDays === 7;
    });

    // Flag registrada + chave continua ativa (ainda não cruzou o limite).
    expect($key->refresh()->inactivity_warning_sent_at)->not->toBeNull()
        ->and($key->status)->toBe(ApiKeyStatus::Active);

    // Segunda execução: SEM novo e-mail (a flag impede repetição).
    $this->artisan('api-keys:process-inactivity')->assertSuccessful();
    Mail::assertQueuedCount(1);
});

it('desativa a chave que cruzou o limite de inatividade', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    // 3 meses + 1 dia sem uso.
    envelhecerChave($key, now()->subMonthsNoOverflow(3)->subDay());

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    expect($key->refresh()->status)->toBe(ApiKeyStatus::ExpiredInactivity);

    // A chave desativada não autentica mais.
    $this->getJson('/api/v1/api-keys', headersApi($key, $secret))->assertUnauthorized();
});

it('respeita a última atividade real (last_used_at), não a criação', function () {
    $user = User::factory()->create();
    ['api_key' => $key] = criarChave($user);

    // Criada há 1 ano, mas usada ontem → NÃO expira.
    envelhecerChave($key, now()->subDay(), usada: true);

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    expect($key->refresh()->status)->toBe(ApiKeyStatus::Active);
    Mail::assertNothingQueued();
});

it('não toca em chaves ativas recentes nem em já encerradas', function () {
    $user = User::factory()->create();
    ['api_key' => $recente] = criarChave($user);
    ['api_key' => $revogada] = criarChave($user);

    envelhecerChave($revogada, now()->subMonthsNoOverflow(6));
    $revogada->forceFill(['status' => ApiKeyStatus::Revoked])->save();

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    expect($recente->refresh()->status)->toBe(ApiKeyStatus::Active)
        ->and($revogada->refresh()->status)->toBe(ApiKeyStatus::Revoked);

    Mail::assertNothingQueued();
});

it('fluxo completo com travel(): aviso aos 7 dias, expiração após os 3 meses', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    // Nasce dentro da janela de aviso (faltam 5 dias para os 3 meses).
    envelhecerChave($key, now()->subMonthsNoOverflow(3)->addDays(5));

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();
    Mail::assertQueuedCount(1);
    expect($key->refresh()->status)->toBe(ApiKeyStatus::Active);

    // 6 dias depois: cruzou o limite → expirada por inatividade.
    $this->travel(6)->days();
    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    expect($key->refresh()->status)->toBe(ApiKeyStatus::ExpiredInactivity);
    Mail::assertQueuedCount(1); // sem novo aviso

    $this->getJson('/api/v1/api-keys', headersApi($key, $secret))->assertUnauthorized();
});

it('usar a chave rearma o aviso (limpa a flag) para o próximo ciclo', function () {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    envelhecerChave($key, now()->subMonthsNoOverflow(3)->addDays(4));

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();
    expect($key->refresh()->inactivity_warning_sent_at)->not->toBeNull();

    // O dono usa a chave: o aviso é rearmado junto com o last_used_at.
    $this->getJson('/api/v1/api-keys', headersApi($key, $secret))->assertOk();

    expect($key->refresh()->inactivity_warning_sent_at)->toBeNull()
        ->and($key->last_used_at)->not->toBeNull();
});

it('respeita a chave de config que desliga a expiração por inatividade', function () {
    config()->set('api_keys.inactivity.enabled', false);

    $user = User::factory()->create();
    ['api_key' => $key] = criarChave($user);

    envelhecerChave($key, now()->subMonthsNoOverflow(12));

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    expect($key->refresh()->status)->toBe(ApiKeyStatus::Active);
});
