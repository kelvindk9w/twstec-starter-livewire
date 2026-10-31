<?php

declare(strict_types=1);

use App\Models\User;
use Twstec\Kit\Foundation\Mail\MailPreview;
use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// O PROJETO CRIADO TEM A IDENTIDADE DELE: com o nome do projeto na
// configuração, nenhuma superfície (página inicial, telas de entrada, painel,
// perfil, página de erro, galeria de e-mails, /admin e cada e-mail nos três
// idiomas) mostra a marca do kit — e trocar o PLATFORM_NAME troca o nome em
// todas elas, inclusive no /admin, que monta o painel no boot.
// =============================================================================

const PROJECT_NAME = 'Acme Pagamentos';

beforeEach(function (): void {
    config([
        'app.name' => PROJECT_NAME,
        'platform.name' => PROJECT_NAME,
        'platform.company_name' => PROJECT_NAME,
        'platform.repo_url' => null,
        // A galeria aberta: pela flag (o portão padrão) e, com a demonstração
        // do kit instalada, pelo login de demonstração (o portão dela).
        'mail.preview.enabled' => true,
        'ui.demo_login.enabled' => true,
    ]);
    app()->forgetInstance(Platform::class);
});

/**
 * A superfície mostra o nome do projeto (quando mostra um nome) e nada da
 * marca do kit.
 */
function expectProjectIdentity(string $html, bool $showsName = true): void
{
    if ($showsName) {
        expect($html)->toContain(e(PROJECT_NAME));
    }

    expect(mb_strtolower($html))->not->toContain('starter kit')
        ->not->toContain('tws starter');
}

it('a página inicial do produto é do projeto', function (): void {
    expectProjectIdentity((string) $this->view('home'));
});

it('as telas de entrada são do projeto', function (string $uri): void {
    expectProjectIdentity($this->get($uri)->assertOk()->getContent());
})->with(['/login', '/register', '/forgot-password']);

it('o painel e o perfil são do projeto', function (string $uri): void {
    $this->actingAs(User::factory()->create());

    expectProjectIdentity($this->get($uri)->assertOk()->getContent());
})->with(['/dashboard', '/profile']);

it('a página de erro é do projeto', function (): void {
    expectProjectIdentity($this->get('/endereco-que-nao-existe')->assertNotFound()->getContent(), showsName: false);
});

it('a galeria de e-mails é do projeto e não aponta o Mailpit de outro lugar', function (): void {
    config(['mail.preview.mailpit_url' => null]);
    $html = $this->get('/mail-preview')->assertOk()->getContent();

    expectProjectIdentity($html);
    expect($html)->not->toContain('18025')
        ->toContain(e(__('mail.preview.mailpit_hint_generic')));

    config(['mail.preview.mailpit_url' => 'http://acme.localhost:8021']);
    expect($this->get('/mail-preview')->getContent())->toContain('http://acme.localhost:8021');
});

it('cada e-mail, nos três idiomas, é do projeto', function (string $slug, string $locale): void {
    $email = MailPreview::render($slug, $locale);

    expectProjectIdentity($email['html']);
    expect(mb_strtolower($email['text']))->not->toContain('starter kit');
})->with(MailPreview::slugs())->with(['pt_BR', 'en', 'es']);

it('o /admin mostra o nome configurado agora, não o do boot', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    expectProjectIdentity($this->get('/admin')->assertOk()->getContent());
})->group('admin');
