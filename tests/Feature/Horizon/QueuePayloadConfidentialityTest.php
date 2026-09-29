<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Notifications\ResetPasswordNotification;
use Twstec\Kit\Demo\Contact\Mail\ContactMessageMail;

// =============================================================================
// O QUE A FILA GUARDA, POR QUANTO TEMPO, E QUEM VÊ
//
// O achado: o job de e-mail carrega, serializado, tudo que o e-mail vai dizer —
// destinatário, código de verificação (2FA), token de redefinição de senha,
// mensagem do formulário de contato. Esse payload ficava EM CLARO no Redis
// enquanto o job esperava e depois dele: o Horizon guarda payload de job
// concluído (1 h) e falho (7 dias) e o exibe no /horizon; a tabela
// `failed_jobs` guardava para sempre, sem poda nenhuma.
//
// O contrato: payload de e-mail e notificação sai criptografado com a APP_KEY
// (ilegível no Redis, na `failed_jobs` e no dashboard); a `failed_jobs` é podada
// na mesma janela do Horizon; e a API do Horizon — que é de onde o dashboard lê
// os payloads — tem as mesmas barreiras do dashboard.
// =============================================================================

/**
 * Enfileira na conexão `database` (banco da suíte) e devolve o payload bruto
 * exatamente como ele ficaria guardado na fila.
 */
function payloadGuardadoNaFila(callable $enfileira): string
{
    config()->set('queue.default', 'database');

    $enfileira();

    return (string) DB::table('jobs')->latest('id')->value('payload');
}

it('o código de verificação e o destinatário não ficam em claro no payload do job', function (): void {
    $payload = payloadGuardadoNaFila(fn () => Mail::to('titular@example.com')
        ->queue(new VerificationCodeMail('731902', VerificationPurpose::SensitiveAction)));

    expect($payload)->not->toBe('')
        ->and($payload)->not->toContain('731902')
        ->and($payload)->not->toContain('titular@example.com');

    // Continua sendo um job que o worker abre: o comando é o texto cifrado,
    // decifrável com a APP_KEY, e dentro dele está o e-mail original.
    $comando = json_decode($payload, true)['data']['command'];

    expect($comando)->not->toStartWith('O:')
        ->and(unserialize(decrypt($comando))->mailable->code)->toBe('731902');
});

it('a mensagem do formulário de contato não fica em claro no payload do job', function (): void {
    $payload = payloadGuardadoNaFila(fn () => Mail::to('time@example.com')
        ->queue(new ContactMessageMail('Maria Titular', 'maria@example.com', 'general', 'meu CPF é 123')));

    expect($payload)->not->toContain('maria@example.com')
        ->and($payload)->not->toContain('Maria Titular')
        ->and($payload)->not->toContain('meu CPF é 123');
})->group('demo');

it('o token de redefinição de senha não fica em claro no payload do job', function (): void {
    $user = User::factory()->create(['email' => 'dona@example.com']);
    $token = str_repeat('f00dcafe', 8);

    $payload = payloadGuardadoNaFila(fn () => $user->notify(new ResetPasswordNotification($token)));

    expect($payload)->not->toBe('')
        ->and($payload)->not->toContain($token);
});

it('todo Mailable e Notification enfileirável do app criptografa o payload', function (): void {
    // Regra para o que ainda não existe: o próximo e-mail que alguém criar
    // estendendo Mailable direto (e não o KitMailable) cai aqui.
    $semCriptografia = [];

    $arquivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($arquivos as $arquivo) {
        if ($arquivo->getExtension() !== 'php') {
            continue;
        }

        $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($arquivo->getPathname(), strlen(app_path()) + 1));

        // Classe que só carrega com um módulo opcional instalado (o
        // PanelProvider do /admin estende o Filament) — ver TestCase.
        if (! TestCase::appClassLoadable($classe)) {
            continue;
        }

        if (! class_exists($classe)) {
            continue;
        }

        $reflexao = new ReflectionClass($classe);

        $carregaMensagem = $reflexao->isSubclassOf(Mailable::class) || $reflexao->isSubclassOf(Notification::class);

        if ($carregaMensagem && $reflexao->implementsInterface(ShouldQueue::class)
            && ! $reflexao->implementsInterface(ShouldBeEncrypted::class)) {
            $semCriptografia[] = $classe;
        }
    }

    expect($semCriptografia)->toBe([]);
});

// -----------------------------------------------------------------------------
// Retenção
// -----------------------------------------------------------------------------

it('a tabela failed_jobs é podada diariamente na janela configurada', function (): void {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($evento): bool => str_contains((string) $evento->command, 'queue:prune-failed'));

    expect($evento)->not->toBeNull('poda de failed_jobs não agendada')
        ->and((string) $evento->command)->toContain('--hours=168')
        ->and($evento->expression)->toBe('0 0 * * *')
        ->and($evento->onOneServer)->toBeTrue();
});

it('a retenção do Horizon é finita e a de job falho acompanha a da failed_jobs', function (): void {
    $trim = config('horizon.trim');

    expect($trim['completed'])->toBe(60)
        ->and($trim['recent'])->toBe(60)
        ->and($trim['failed'])->toBe(10080)
        // 10080 min = 168 h: a mesma janela da poda da tabela.
        ->and($trim['failed'])->toBe(config('queue.failed.retention_hours') * 60);
});

// -----------------------------------------------------------------------------
// A API do Horizon (de onde o dashboard lê os payloads) tem as barreiras dele
// -----------------------------------------------------------------------------

dataset('rotas da api do horizon', [
    'jobs falhos' => ['/horizon/api/jobs/failed'],
    'jobs concluídos' => ['/horizon/api/jobs/completed'],
    'jobs pendentes' => ['/horizon/api/jobs/pending'],
    'estatísticas' => ['/horizon/api/stats'],
]);

it('a API do Horizon recusa visitante sem sessão', function (string $rota): void {
    $this->getJson($rota)->assertForbidden();
})->with('rotas da api do horizon');

it('a API do Horizon recusa usuário comum', function (string $rota): void {
    $this->actingAs(User::factory()->create())->getJson($rota)->assertForbidden();
})->with('rotas da api do horizon');

it('a API do Horizon recusa admin com a conta desativada', function (string $rota, UserStatus $status): void {
    $admin = User::factory()->create(['is_admin' => true, 'status' => $status]);

    // 401 ou 403 conforme a camada que recusa primeiro: a conta inativa é
    // deslogada na requisição (EnsureAccountIsActive) e, se passasse dali, o
    // gate viewHorizon exige conta ativa. O que importa é não haver dado.
    $resposta = $this->actingAs($admin)->getJson($rota);

    expect($resposta->status())->toBeIn([401, 403])
        ->and($resposta->getContent())->not->toContain('"jobs"');
})->with('rotas da api do horizon')->with([
    'bloqueada' => [UserStatus::Blocked],
    'pendente' => [UserStatus::Pending],
]);

it('a API do Horizon recusa admin ativo fora da allowlist de IP', function (string $rota): void {
    config(['security.admin.allowed_ips' => ['10.10.10.10']]);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->getJson($rota)->assertForbidden();
})->with('rotas da api do horizon');
