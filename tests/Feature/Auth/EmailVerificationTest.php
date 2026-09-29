<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Notifications\ResetPasswordNotification;
use Twstec\Kit\Auth\Notifications\VerifyEmailNotification;
use Twstec\Kit\Auth\Support\EmailVerification;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;

// =============================================================================
// VERIFICAÇÃO DE E-MAIL NO CADASTRO
//
// Decisão do dono para a 1.0: quem se cadastra recebe um e-mail com link
// assinado e só opera o painel (páginas, formulários e ações Livewire) e a API
// depois de clicar nele. Até lá, vê só a tela de aviso, com "reenviar" e
// "sair". A exigência é LIGADA por padrão e desligável por
// AUTH_EMAIL_VERIFICATION_REQUIRED. A regra mora em
// Twstec\Kit\Auth\Support\EmailVerification.
// =============================================================================

/**
 * @return array<string, string>
 */
function rotasDoPainel(): array
{
    return [
        'dashboard' => '/dashboard',
        // Telas do pacote de contas (twstec/kit-accounts, opcional): só com ele.
        ...(Kit::has('accounts') ? ['chaves de API' => '/api-keys', 'projetos' => '/projects'] : []),
        'perfil' => '/profile',
        'notificações' => '/notifications',
        'senha de transação' => '/settings/transaction-password',
    ];
}

function dadosDeCadastro(string $email = 'nova@example.com'): array
{
    return [
        'name' => 'Nova Pessoa',
        'email' => $email,
        'password' => 'SenhaForte123',
        'password_confirmation' => 'SenhaForte123',
    ];
}

// -----------------------------------------------------------------------------
// Cadastro
// -----------------------------------------------------------------------------

it('o cadastro envia o e-mail de verificação e leva à tela de aviso, não ao painel', function (): void {
    Notification::fake();

    $this->post('/register', dadosDeCadastro())
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', __('auth.email_verification.registered'));

    $user = User::query()->where('email', 'nova@example.com')->sole();

    expect($user)->toBeInstanceOf(MustVerifyEmail::class)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
    Notification::assertCount(1);
    $this->assertAuthenticatedAs($user);
});

it('a conta nasce no idioma em que a pessoa se cadastrou (o e-mail sai nele)', function (): void {
    Notification::fake();

    $this->withCookie(SetLocale::COOKIE, 'es')->post('/register', dadosDeCadastro());

    expect(User::query()->where('email', 'nova@example.com')->sole()->preferredLocale())->toBe('es');
});

it('o e-mail real sai no layout do kit, no idioma do destinatário e com o link ancorado em APP_URL', function (string $locale): void {
    config(['app.url' => 'https://app.example.com']);

    // Mesmo que as URLs da requisição estivessem sendo montadas com outro host
    // (o `Host` é dado do cliente), o link do e-mail não segue esse host.
    URL::forceRootUrl('http://evil.example.com');

    // Transporte em memória (o ambiente do container pode apontar o mailer
    // padrão para o Mailpit) e fila síncrona: o e-mail é montado de verdade.
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
    app('mail.manager')->forgetMailers();

    $user = User::factory()->unverified()->create(['locale' => $locale]);
    $user->sendEmailVerificationNotification();

    $mensagens = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($mensagens)->toHaveCount(1);

    $email = $mensagens->first()->getOriginalMessage();
    $html = (string) $email->getHtmlBody();

    expect($email->getSubject())->toBe(trans('mail.email_verification.subject', ['platform' => platform()->name], $locale))
        ->and($html)->toContain(trans('mail.email_verification.heading', [], $locale))
        ->and($html)->toContain('https://app.example.com/email/verify/'.$user->uuid.'/')
        ->and($html)->not->toContain('evil.example.com')
        // Layout único do kit + versão em texto puro com o link por extenso.
        ->and($html)->toContain(trans('mail.footer.transactional', [], $locale))
        ->and($html)->toContain('max-width:600px')
        ->and((string) $email->getTextBody())->toContain('https://app.example.com/email/verify/');
})->with(['pt_BR', 'en', 'es']);

it('o payload do e-mail de verificação vai criptografado para a fila', function (): void {
    config()->set('queue.default', 'database');

    $user = User::factory()->unverified()->create(['email' => 'titular-verificacao@example.com']);
    $user->sendEmailVerificationNotification();

    $payload = (string) DB::table('jobs')->latest('id')->value('payload');
    $comando = json_decode($payload, true)['data']['command'];

    expect($payload)->not->toBe('')
        ->and($comando)->not->toStartWith('O:')
        ->and($payload)->not->toContain('titular-verificacao@example.com');
});

// -----------------------------------------------------------------------------
// O painel fica fechado até verificar
// -----------------------------------------------------------------------------

it('conta sem e-mail confirmado não abre as páginas do painel: vai ao aviso', function (string $url): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get($url)->assertRedirect(route('verification.notice'));
})->with(rotasDoPainel());

it('conta com e-mail confirmado abre as páginas do painel', function (string $url): void {
    $this->actingAs(User::factory()->create())->get($url)->assertOk();
})->with(rotasDoPainel());

it('conta sem e-mail confirmado não usa os formulários do painel', function (): void {
    $user = User::factory()->unverified()->withTransactionPassword()->create();

    $this->actingAs($user)
        ->put('/settings/transaction-password', [
            'current_transaction_password' => 'Trans4cao!Segura',
            'transaction_password' => 'Nova4Senha9Tx',
            'transaction_password_confirmation' => 'Nova4Senha9Tx',
        ])
        ->assertRedirect(route('verification.notice'));

    $this->actingAs($user)
        ->postJson('/sensitive-actions/code', ['transaction_password' => 'Trans4cao!Segura'])
        ->assertForbidden()
        ->assertJsonPath('message', __('auth.email_verification.not_verified'));

    expect(Hash::check('Trans4cao!Segura', (string) $user->fresh()->transaction_password))->toBeTrue();
});

it('conta sem e-mail confirmado não executa ação Livewire do painel pelo endpoint real', function (): void {
    $user = User::factory()->create();

    // Snapshot obtido de uma página aberta (a aba que ficou aberta); depois a
    // conta passa a estar pendente de verificação.
    $html = $this->actingAs($user)->get('/projects')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'projects');

    $user->forceFill(['email_verified_at' => null])->save();

    livewireCall($this, $snapshot, 'create', ['name' => 'Projeto sem verificação'])
        ->assertRedirect(route('verification.notice'));

    expect(comoSistema(fn () => Project::query()->where('account_id', contaPessoal($user)->id)->exists()))->toBeFalse();
})->group('accounts');

it('conta sem e-mail confirmado não executa ação Livewire do perfil pelo endpoint real (em qualquer combinação de módulos)', function (): void {
    $user = User::factory()->create(['name' => 'Nome antes']);

    $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();
    $snapshot = livewireSnapshotFrom((string) $html, 'profile');

    $user->forceFill(['email_verified_at' => null])->save();

    livewireCall($this, $snapshot, 'updateProfile', ['name' => 'Nome sem verificação'])
        ->assertRedirect(route('verification.notice'));

    expect($user->fresh()->name)->toBe('Nome antes');
});

it('a mensagem de JSON sai no idioma da conta', function (): void {
    $user = User::factory()->unverified()->create(['locale' => 'en']);

    $this->actingAs($user)->getJson('/dashboard')
        ->assertForbidden()
        ->assertJsonPath('message', trans('auth.email_verification.not_verified', [], 'en'));
});

// -----------------------------------------------------------------------------
// Tela de aviso e reenvio
// -----------------------------------------------------------------------------

it('a tela de aviso mostra o e-mail, o reenvio e a saída, no layout do site', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'aviso@example.com']);

    $this->actingAs($user)->get(route('verification.notice'))
        ->assertOk()
        ->assertSee(__('auth.email_verification.title'))
        ->assertSee('aviso@example.com')
        ->assertSee(__('auth.email_verification.resend'))
        ->assertSee('action="'.route('verification.send').'"', false)
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('<header', false);
});

it('a tela de aviso sai no idioma da conta', function (): void {
    $user = User::factory()->unverified()->create(['locale' => 'es']);

    $this->actingAs($user)->get(route('verification.notice'))
        ->assertOk()
        ->assertSee(trans('auth.email_verification.title', [], 'es'));
});

it('quem já confirmou não fica na tela de aviso', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('verification.notice'))
        ->assertRedirect(route('dashboard'));
});

it('visitante não vê a tela de aviso nem reenvia', function (): void {
    $this->get(route('verification.notice'))->assertRedirect(route('login'));
    $this->post(route('verification.send'))->assertRedirect(route('login'));
});

it('reenviar manda um novo e-mail e respeita o intervalo mínimo entre envios', function (): void {
    Notification::fake();
    config(['auth.email_verification.resend_cooldown_seconds' => 60]);

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', __('auth.email_verification.sent'));

    // Segundo pedido logo em seguida: recusado com o tempo que falta.
    $this->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('verification_error');

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

    $this->travel(61)->seconds();

    $this->post(route('verification.send'))->assertSessionHas('status', __('auth.email_verification.sent'));

    Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2);
});

it('o envio do cadastro já conta para o intervalo do reenvio', function (): void {
    Notification::fake();

    $this->post('/register', dadosDeCadastro());
    $this->post(route('verification.send'))->assertSessionHas('verification_error');

    Notification::assertCount(1);
});

it('a tela de aviso mostra o motivo de um reenvio recusado', function (): void {
    Notification::fake();

    $this->post('/register', dadosDeCadastro());

    $this->followingRedirects()
        ->post(route('verification.send'))
        ->assertOk()
        ->assertSee('data-verification-error', false);
});

it('o reenvio tem o limite de rotas sensíveis', function (): void {
    Notification::fake();
    config(['security.rate_limit.sensitive' => 2]);

    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $this->post(route('verification.send'));
    $this->post(route('verification.send'));
    $this->post(route('verification.send'))->assertStatus(429);
});

// -----------------------------------------------------------------------------
// O link do e-mail
// -----------------------------------------------------------------------------

it('o link confirma o e-mail, dispara Verified e libera o painel', function (): void {
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(EmailVerification::verificationUrl($user))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', __('auth.email_verification.verified'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);

    $this->get('/dashboard')->assertOk();
});

it('depois de confirmar, volta para a página que a pessoa tentou abrir', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/notifications')->assertRedirect(route('verification.notice'));

    $this->get(EmailVerification::verificationUrl($user))->assertRedirect(url('/notifications'));
});

it('destino guardado fora da aplicação não é obedecido: cai no painel (SafeRedirect)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->withSession(['url.intended' => 'https://evil.example.com/phishing'])
        ->get(EmailVerification::verificationUrl($user))
        ->assertRedirect(route('dashboard'));
});

it('o link é assinado sobre o caminho e a origem vem de APP_URL', function (): void {
    config(['app.url' => 'https://app.example.com']);
    URL::forceRootUrl('http://evil.example.com');

    $user = User::factory()->unverified()->create();
    $url = EmailVerification::verificationUrl($user);

    expect($url)->toStartWith('https://app.example.com/email/verify/'.$user->uuid.'/')
        ->and($url)->toContain('expires=')
        ->and($url)->toContain('signature=')
        ->and($url)->not->toContain('evil.example.com');
});

it('link adulterado não confirma', function (string $caso): void {
    $user = User::factory()->unverified()->create();
    $url = EmailVerification::verificationUrl($user);

    $adulterado = match ($caso) {
        'assinatura' => preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url),
        'expiração' => preg_replace('/expires=(\d+)/', 'expires='.(now()->addYear()->getTimestamp()), $url),
        'hash do e-mail' => str_replace('/'.sha1($user->email).'?', '/'.sha1('outro@example.com').'?', $url),
        'sem assinatura' => strtok($url, '?'),
    };

    expect($adulterado)->not->toBe($url);

    $this->actingAs($user)->get($adulterado)
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('verification_error', __('auth.email_verification.invalid_link'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['assinatura', 'expiração', 'hash do e-mail', 'sem assinatura']);

it('link vencido não confirma', function (): void {
    config(['auth.email_verification.link_ttl_minutes' => 60]);

    $user = User::factory()->unverified()->create();
    $url = EmailVerification::verificationUrl($user);

    $this->travel(61)->minutes();

    $this->actingAs($user)->get($url)
        ->assertSessionHas('verification_error', __('auth.email_verification.invalid_link'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('link de outra conta não confirma nenhuma das duas', function (): void {
    $dona = User::factory()->unverified()->create();
    $outra = User::factory()->unverified()->create();

    $this->actingAs($outra)->get(EmailVerification::verificationUrl($dona))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('verification_error', __('auth.email_verification.wrong_account'));

    expect($dona->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($outra->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('link aberto sem sessão passa pelo login e volta ao link', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'volta@example.com']);
    $url = EmailVerification::verificationUrl($user);

    $this->get($url)->assertRedirect(route('login'));

    $this->post('/login', ['email' => 'volta@example.com', 'password' => 'password'])
        ->assertRedirect($url);

    $this->get($url)->assertRedirect(route('dashboard'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('trocar o e-mail da conta invalida o link enviado para o anterior', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'antigo@example.com']);
    $url = EmailVerification::verificationUrl($user);

    $user->forceFill(['email' => 'novo@example.com'])->save();

    $this->actingAs($user)->get($url)
        ->assertSessionHas('verification_error', __('auth.email_verification.invalid_link'));

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

// -----------------------------------------------------------------------------
// API v1
// -----------------------------------------------------------------------------

it('chave de API de conta sem e-mail confirmado não autentica', function (): void {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $this->getJson('/api/v1/projects', headersApi($key, $secret))->assertOk();

    $user->forceFill(['email_verified_at' => null])->save();

    $this->getJson('/api/v1/projects', headersApi($key, $secret))
        ->assertUnauthorized()
        ->assertJsonPath('error.message', __('api_keys.auth.invalid'));
})->group('accounts');

it('conta sem e-mail confirmado não chega à tela de criar chave', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/api-keys')->assertRedirect(route('verification.notice'));
})->group('accounts');

// -----------------------------------------------------------------------------
// Flag desligada
// -----------------------------------------------------------------------------

it('com a exigência desligada, o cadastro vai direto ao painel e nenhum e-mail sai', function (): void {
    Notification::fake();
    config(['auth.email_verification.required' => false]);

    $this->post('/register', dadosDeCadastro())->assertRedirect(route('dashboard'));

    Notification::assertNothingSent();
    $this->get('/dashboard')->assertOk();
});

it('com a exigência desligada, conta sem e-mail confirmado opera painel e API', function (): void {
    config(['auth.email_verification.required' => false]);

    $user = User::factory()->unverified()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);

    $this->actingAs($user)->get('/dashboard')->assertOk();
    $this->actingAs($user)->get(route('verification.notice'))->assertRedirect(route('dashboard'));
    $this->getJson('/api/v1/projects', headersApi($key, $secret))->assertOk();
})->group('accounts');

it('a exigência vem ligada por padrão', function (): void {
    expect(config('auth.email_verification.required'))->toBeTrue()
        ->and(EmailVerification::required())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Contas demo, admin e contas existentes
// -----------------------------------------------------------------------------

it('conta demo protegida conta como verificada mesmo com a coluna zerada', function (): void {
    config(['ui.demo_login.enabled' => true]);

    $demo = User::factory()->unverified()->create(['email' => config('ui.demo_login.email')]);

    expect($demo->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($demo)->get('/dashboard')->assertOk();
    $this->actingAs($demo)->get(route('verification.notice'))->assertRedirect(route('dashboard'));
})->group('demo');

it('confirmar o e-mail de conta demo não é bloqueado pela blindagem', function (): void {
    config(['ui.demo_login.enabled' => true]);

    $demo = User::factory()->unverified()->create(['email' => config('ui.demo_login.email')]);

    expect($demo->markEmailAsVerified())->toBeTrue()
        ->and($demo->fresh()->email_verified_at)->not->toBeNull();
})->group('demo');

it('com o modo demo desligado, a conta demo é uma conta comum (sem atalho)', function (): void {
    config(['ui.demo_login.enabled' => false]);

    $demo = User::factory()->unverified()->create(['email' => config('ui.demo_login.email')]);

    expect($demo->hasVerifiedEmail())->toBeFalse();
    $this->actingAs($demo)->get('/dashboard')->assertRedirect(route('verification.notice'));
})->group('demo');

it('user:make-admin marca o e-mail como confirmado ao promover', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'promovido@example.com']);

    $this->artisan('user:make-admin', ['email' => 'promovido@example.com'])->assertSuccessful();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // Rebaixar não desfaz a confirmação.
    $this->artisan('user:make-admin', ['email' => 'promovido@example.com', '--remove' => true])->assertSuccessful();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
})->group('admin');

it('o /admin não trava por verificação de e-mail', function (): void {
    $admin = User::factory()->unverified()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/admin')->assertOk();
})->group('admin');

it('a migration marca como confirmadas as contas que já existiam', function (): void {
    $antiga = User::factory()->unverified()->create();
    $confirmada = User::factory()->create(['email_verified_at' => now()->subYear()]);
    $original = $confirmada->email_verified_at->getTimestamp();

    $migration = require base_path('vendor/twstec/kit-auth/database/migrations/2026_09_24_000001_mark_existing_users_email_as_verified.php');
    $migration->up();

    expect($antiga->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($confirmada->fresh()->email_verified_at->getTimestamp())->toBe($original);
});

// -----------------------------------------------------------------------------
// Login e recuperação de senha continuam valendo para conta pendente
// -----------------------------------------------------------------------------

it('conta pendente de verificação faz login e cai na tela de aviso', function (): void {
    User::factory()->unverified()->create(['email' => 'pendente@example.com']);

    $this->post('/login', ['email' => 'pendente@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->get('/dashboard')->assertRedirect(route('verification.notice'));
});

it('conta pendente de verificação pede redefinição de senha normalmente', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create(['email' => 'esqueci@example.com']);

    $this->post('/forgot-password', ['email' => 'esqueci@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});
