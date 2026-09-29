<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Notifications\SendQueuedNotifications;
use Twstec\Kit\Auth\Notifications\ResetPasswordNotification;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// O NOME ANTIGO DO MODEL DE USUÁRIO CONTINUA SENDO LIDO.
//
// Até a 1.x o model era App\Core\Auth\Models\User; agora é App\Models\User.
// Onde esse nome fica GRAVADO e precisa continuar valendo:
//
//   - payload de fila: a notificação enfileirada leva o destinatário como
//     referência de model COM O NOME DA CLASSE — um job que estava na fila no
//     deploy é desserializado pelo worker novo;
//   - AUTH_MODEL num .env antigo.
//
// A trilha de auditoria NÃO grava o nome da classe (subject_type é o nome
// curto estável, `user`): as linhas antigas continuam filtráveis.
// =============================================================================

it('o nome antigo resolve para o model do aplicativo — a mesma classe', function (): void {
    expect(class_exists('App\\Core\\Auth\\Models\\User'))->toBeTrue()
        ->and((new ReflectionClass('App\\Core\\Auth\\Models\\User'))->getName())->toBe(User::class)
        ->and(new User)->toBeInstanceOf('App\\Core\\Auth\\Models\\User');
});

it('notificação enfileirada com o nome antigo do model volta com o usuário certo', function (): void {
    $user = User::factory()->create();

    $job = new SendQueuedNotifications($user, new ResetPasswordNotification('token-antigo'), ['mail']);
    $serializado = serialize($job);

    // O payload como a 1.x o gravou: os nomes antigos do model e da notificação.
    $antigo = str_replace(
        [
            sprintf('s:%d:"%s"', strlen(User::class), User::class),
            sprintf('O:%d:"%s"', strlen(ResetPasswordNotification::class), ResetPasswordNotification::class),
        ],
        [
            sprintf('s:%d:"%s"', strlen('App\\Core\\Auth\\Models\\User'), 'App\\Core\\Auth\\Models\\User'),
            sprintf('O:%d:"%s"', strlen('App\\Core\\Auth\\Notifications\\ResetPasswordNotification'), 'App\\Core\\Auth\\Notifications\\ResetPasswordNotification'),
        ],
        $serializado,
    );

    expect($antigo)->not->toBe($serializado)
        ->and($antigo)->toContain('App\\Core\\Auth\\Models\\User');

    $restaurado = unserialize($antigo);

    expect($restaurado->notifiables)->toHaveCount(1)
        ->and($restaurado->notifiables->first())->toBeInstanceOf(User::class)
        ->and($restaurado->notifiables->first()->getKey())->toBe($user->getKey())
        ->and($restaurado->notification)->toBeInstanceOf(ResetPasswordNotification::class)
        ->and($restaurado->notification->token)->toBe('token-antigo');
});

it('AUTH_MODEL antigo continua achando os usuários', function (): void {
    $user = User::factory()->create();

    config(['auth.providers.users.model' => 'App\\Core\\Auth\\Models\\User']);

    expect(UserModel::query()->find($user->getKey()))->toBeInstanceOf(User::class)
        ->and(auth()->getProvider()->retrieveById($user->getKey())?->getKey())->toBe($user->getKey());
});

it('a trilha de auditoria guarda o nome curto estável, e as linhas antigas seguem filtráveis', function (): void {
    $user = User::factory()->create();

    // Linha como a 1.x gravou (o nome curto já era `user`).
    AuditEvent::query()->create([
        'context' => AuditContext::Console,
        'action' => 'user.admin_granted',
        'outcome' => AuditOutcome::Success,
        'subject_type' => 'user',
        'subject_uuid' => $user->uuid,
    ]);

    expect(AuditTrail::subjectType($user))->toBe('user')
        ->and(AuditTrail::subjectType('App\\Core\\Auth\\Models\\User'))->toBe('user')
        ->and(AuditEvent::query()->where('subject_type', AuditTrail::subjectType($user))->where('subject_uuid', $user->uuid)->count())->toBe(1);
});
