<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Enums\VerificationResult;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\VerificationCode;
use Twstec\Kit\Auth\Services\VerificationCodes;

// Motor comum dos códigos de 6 dígitos (ação sensível e segundo fator do
// login): só hash no banco, uso único com consumo condicional, tentativa
// reservada antes da conferência.

beforeEach(fn () => Mail::fake());

function engineIssue(User $user): string
{
    app(VerificationCodes::class)->issue($user, VerificationPurpose::LoginChallenge);

    return Mail::queued(VerificationCodeMail::class)->last()->code;
}

it('código certo vale uma vez; a segunda conferência já encontra o código consumido', function () {
    $user = User::factory()->create();
    $code = engineIssue($user);
    $codes = app(VerificationCodes::class);

    expect($codes->verify($user, VerificationPurpose::LoginChallenge, $code))->toBe(VerificationResult::Valid)
        ->and($codes->verify($user, VerificationPurpose::LoginChallenge, $code))->toBe(VerificationResult::Expired);
});

it('a tentativa é reservada no banco: código no limite não é conferido nem com o valor certo', function () {
    config()->set('auth.verification.max_attempts', 3);
    $user = User::factory()->create();
    $code = engineIssue($user);

    // Simula requisições concorrentes que já gastaram as tentativas sem que
    // nenhuma tenha chegado a marcar o código como consumido.
    VerificationCode::query()->update(['attempts' => 3]);

    expect(app(VerificationCodes::class)->verify($user, VerificationPurpose::LoginChallenge, $code))
        ->toBe(VerificationResult::Expired);
});

it('código de outra conta não vale', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $code = engineIssue($owner);
    engineIssue($other);

    $otherCode = Mail::queued(VerificationCodeMail::class)->last()->code;

    if ($otherCode !== $code) {
        expect(app(VerificationCodes::class)->verify($other, VerificationPurpose::LoginChallenge, $code))
            ->toBe(VerificationResult::Invalid);
    }

    expect(app(VerificationCodes::class)->verify($owner, VerificationPurpose::LoginChallenge, $code))
        ->toBe(VerificationResult::Valid);
});

it('no banco fica só o hash', function () {
    $user = User::factory()->create();
    $code = engineIssue($user);

    $record = VerificationCode::query()->sole();

    expect($record->code_hash)->not->toContain($code)
        ->and(password_verify($code, $record->code_hash))->toBeTrue();
});
