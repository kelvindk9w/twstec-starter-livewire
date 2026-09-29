<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Verification\Drivers\EmailVerificationDriver;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;

// i18n: middleware SetLocale, rota de troca e preferência no perfil.
// Prioridade: conta logada → cookie do visitante → padrão da plataforma.

it('visitante sem cookie vê o padrão da plataforma (pt-BR)', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.title_line_2', locale: 'pt_BR'));
})->group('demo');

it('visitante com cookie vê o idioma escolhido', function (string $locale, string $expected) {
    // withCookie: o harness criptografa (como o EncryptCookies faria na borda).
    $this->withCookie(SetLocale::COOKIE, $locale)
        ->get('/')
        ->assertOk()
        ->assertSee($expected);
})->group('demo')->with([
    'en' => ['en', 'The base your AI'],
    'es' => ['es', 'La base que tu IA'],
]);

it('cookie com locale fora da whitelist cai no padrão da plataforma', function () {
    $this->withCookie(SetLocale::COOKIE, 'fr')
        ->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.title_line_2', locale: 'pt_BR'));
})->group('demo');

it('usuário logado com preferência salva vê o painel no idioma dela', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Hola,');
});

it('preferência da conta vence o cookie do visitante', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->withCookie(SetLocale::COOKIE, 'es')
        ->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.title_line_2', locale: 'en'));
})->group('demo');

it('rota de troca grava o cookie e redireciona de volta', function () {
    $response = $this->get('/locale/en');

    $response->assertRedirect('/')
        ->assertCookie(SetLocale::COOKIE, 'en');
});

// Open redirect (auditoria de segurança): a rota de troca devolve o usuário à
// página anterior lendo o `Referer`, que é dado do CLIENTE. Sem validação de
// origem o domínio do kit viraria redirecionador aberto — o link começa no
// domínio legítimo e termina no do atacante. Contrato: só destino de mesma
// origem é obedecido; todo o resto cai no fallback, e a troca de idioma em si
// continua acontecendo nos dois casos.
it('rota de troca devolve o usuário à página interna de onde ele veio', function (string $path) {
    $origem = rtrim((string) config('app.url'), '/').$path;

    $this->withHeader('Referer', $origem)
        ->get('/locale/en')
        ->assertRedirect($origem)
        ->assertCookie(SetLocale::COOKIE, 'en');
})->with([
    'painel' => ['/dashboard'],
    'com query string' => ['/ui?tab=forms'],
    'raiz' => ['/'],
]);

it('rota de troca ignora Referer que aponta para fora da aplicação', function (string $referer) {
    $this->withHeader('Referer', $referer)
        ->get('/locale/en')
        ->assertRedirect('/')
        ->assertCookie(SetLocale::COOKIE, 'en');
})->with([
    'domínio externo' => ['https://evil.example.com/phish'],
    'alvo legítimo no caminho' => ['https://evil.example.com/http://localhost:8180/dashboard'],
    'relativa ao protocolo' => ['//evil.example.com/phish'],
    'sufixo do domínio legítimo' => ['http://localhost.evil.example.com/phish'],
    'barra invertida depois do esquema' => ['https:/\\evil.example.com/phish'],
    'credenciais embutidas' => ['https://localhost:8180@evil.example.com/phish'],
    'credenciais com host legítimo' => ['https://user:senha@localhost:8180/dashboard'],
    'host percent-encoded' => ['http://%6c%6fcalhost:8180/dashboard'],
    'porta diferente' => ['http://localhost:9999/dashboard'],
    'esquema não http' => ['javascript:alert(1)'],
    'esquema de dados' => ['data:text/html,<script>alert(1)</script>'],
    'vazio' => [''],
    'só espaços' => ['   '],
    'lixo sem esquema' => ['evil.example.com'],
]);

it('rota de troca sem Referer nenhum cai no fallback', function () {
    $this->get('/locale/es')
        ->assertRedirect('/')
        ->assertCookie(SetLocale::COOKIE, 'es');
});

it('fallback de redirecionamento é configurável', function () {
    config(['security.redirects.fallback' => '/v2']);

    $this->withHeader('Referer', 'https://evil.example.com/phish')
        ->get('/locale/en')
        ->assertRedirect('/v2');
});

it('origem extra declarada em configuração passa a ser aceita', function () {
    config(['security.redirects.allowed_origins' => ['https://app.exemplo.test']]);

    $this->withHeader('Referer', 'https://app.exemplo.test/dashboard')
        ->get('/locale/en')
        ->assertRedirect('https://app.exemplo.test/dashboard');
});

it('host forjado no header Host não libera redirecionamento externo', function () {
    // A origem permitida vem de config('app.url'), nunca do host declarado
    // pelo cliente — por isso a validação não afrouxa quando o TrustProxies
    // entrar (X-Forwarded-Host é dado do cliente do mesmo jeito).
    $this->withHeader('Host', 'evil.example.com')
        ->withHeader('Referer', 'http://evil.example.com/phish')
        ->get('/locale/en')
        ->assertRedirect('/');
});

it('rota de troca rejeita locale fora da whitelist', function () {
    $this->get('/locale/fr')->assertNotFound();
});

it('rota de troca persiste a preferência na conta do usuário logado', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)->get('/locale/es')->assertRedirect('/');

    expect($user->fresh()->locale)->toBe('es');
});

it('perfil salva a preferência de idioma junto dos dados básicos', function () {
    $user = User::factory()->create(['locale' => null]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', 'Maria Silva')
        ->set('locale', 'en')
        ->call('updateProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->locale)->toBe('en');
});

it('perfil rejeita idioma fora da whitelist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('locale', 'fr')
        ->call('updateProfile')
        ->assertHasErrors(['locale']);
});

// O seletor é um dropdown de LINKS reais para locale.switch (era um <select>
// nativo com bandeira em emoji): o contrato é a URL de cada idioma + o nome.
it('seletor de idioma aparece na landing e no painel com um link por idioma', function () {
    $landing = $this->get('/')->assertOk();
    $painel = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();

    foreach (platform()->availableLocales as $locale) {
        $landing->assertSee(route('locale.switch', $locale), false)
            ->assertSee(__("ui.locale.names.{$locale}"));

        $painel->assertSee(route('locale.switch', $locale), false);
    }
});

it('e-mail transacional sai no locale do destinatário', function () {
    Mail::fake();

    $user = User::factory()->create(['locale' => 'es']);

    app(EmailVerificationDriver::class)->send($user, '123456', VerificationPurpose::SensitiveAction);

    Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail): bool => $mail->locale === 'es');
});

it('preferredLocale cai no padrão da plataforma sem preferência válida', function () {
    expect(User::factory()->create(['locale' => null])->preferredLocale())->toBe(platform()->locale)
        ->and(User::factory()->create(['locale' => 'fr'])->preferredLocale())->toBe(platform()->locale)
        ->and(User::factory()->create(['locale' => 'en'])->preferredLocale())->toBe('en');
});

it('todos os idiomas disponíveis têm os mesmos arquivos e chaves do pt-BR', function () {
    $reference = [];

    foreach (glob(lang_path('pt_BR/*.php')) as $file) {
        $reference[basename($file)] = collect(require $file)->dot()->keys()->sort()->values();
    }

    foreach (['en', 'es'] as $locale) {
        foreach ($reference as $file => $keys) {
            $path = lang_path("{$locale}/{$file}");

            expect($path)->toBeFile();

            $actual = collect(require $path)->dot()->keys()->sort()->values();

            expect($actual->all())->toBe($keys->all(), "lang/{$locale}/{$file} diverge do pt-BR");
        }
    }
});
