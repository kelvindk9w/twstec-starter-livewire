<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Twstec\Kit\Demo\Livewire\ContactForm;

// =============================================================================
// Padrões de formulário e erros: config/ui.php → error_display (inline |
// summary | toast | both), <x-form-errors>, field_error(), flash → toast,
// repopulação com old() (senhas nunca) e os exemplos funcionais do /ui.
// =============================================================================

/**
 * Compartilha uma bag de erros como o ShareErrorsFromSession faria.
 */
function shareFormErrors(array $errors): void
{
    $bag = new ViewErrorBag;
    $bag->put('default', new MessageBag($errors));
    view()->share('errors', $bag);
}

// --- Config + helpers --------------------------------------------------------

it('error_display padrão é inline e a whitelist cai para inline', function () {
    expect(config('ui.error_display'))->toBe('inline')
        ->and(form_error_display())->toBe('inline')
        ->and(form_error_display('summary'))->toBe('summary')
        ->and(form_error_display('hack'))->toBe('inline');
});

it('field_error retorna a mensagem em inline/both e suprime em summary/toast', function () {
    shareFormErrors(['email' => ['Erro de e-mail']]);

    expect(field_error('email'))->toBe('Erro de e-mail')
        ->and(field_error('email', 'both'))->toBe('Erro de e-mail')
        ->and(field_error('email', 'summary'))->toBe('')
        ->and(field_error('email', 'toast'))->toBe('')
        ->and(field_error('name'))->toBe('');
});

it('field_error segue a estratégia do config quando não há override', function () {
    shareFormErrors(['email' => ['Erro de e-mail']]);
    config()->set('ui.error_display', 'summary');

    expect(field_error('email'))->toBe('');

    config()->set('ui.error_display', 'toast');

    expect(field_error('email'))->toBe('');
});

// --- <x-form-errors> ---------------------------------------------------------

it('x-form-errors não renderiza nada na estratégia inline', function () {
    shareFormErrors(['email' => ['Erro de e-mail']]);

    expect(trim(Blade::render('<x-form-errors />')))->toBe('');
});

it('x-form-errors renderiza resumo com âncoras para os campos (summary/both)', function (string $display) {
    shareFormErrors(['email' => ['Erro de e-mail'], 'message' => ['Erro de mensagem']]);

    $html = Blade::render('<x-form-errors display="'.$display.'" />');

    expect($html)->toContain('role="alert"')
        ->toContain(__('ui.form_errors.title'))
        ->toContain('href="#email"')
        ->toContain('href="#message"')
        ->toContain('Erro de e-mail');
})->with(['summary', 'both']);

it('x-form-errors dispara o toast do kit na estratégia toast', function () {
    shareFormErrors(['email' => ['Erro de e-mail']]);

    $html = Blade::render('<x-form-errors display="toast" />');

    expect($html)->toContain('data-toast')
        ->toContain('Erro de e-mail')
        ->not->toContain('href="#email"');
});

it('override por formulário (prop display) vence o config', function () {
    shareFormErrors(['email' => ['Erro de e-mail']]);
    config()->set('ui.error_display', 'summary');

    // Config diz summary, mas o formulário força inline → sem resumo.
    expect(trim(Blade::render('<x-form-errors display="inline" />')))->toBe('');

    config()->set('ui.error_display', 'inline');

    // Config diz inline, mas o formulário força summary → resumo presente.
    expect(Blade::render('<x-form-errors display="summary" />'))->toContain('href="#email"');
});

it('x-form-errors aceita mensagens de demonstração (prop messages)', function () {
    $html = Blade::render('<x-form-errors display="summary" :messages="[\'campo\' => \'Erro demo\']" />');

    expect($html)->toContain('href="#campo"')->toContain('Erro demo');
});

// --- Exemplo Blade clássico do showcase (POST + redirect + old()) ------------

it('form clássico do /ui valida e retorna os erros', function () {
    config()->set('ui.showcase_enabled', true);

    $this->from('/ui')->post(route('ui.form-demo'), [])
        ->assertRedirect('/ui')
        ->assertSessionHasErrors(['classic_nickname', 'classic_subject', 'classic_message']);
});

it('form clássico do /ui responde 404 quando o showcase está desabilitado', function () {
    config()->set('ui.showcase_enabled', false);

    $this->post(route('ui.form-demo'), [])->assertNotFound();
});

it('form clássico repopula os campos com old() após erro de validação', function () {
    config()->set('ui.showcase_enabled', true);

    $this->followingRedirects()
        ->from('/ui')
        ->post(route('ui.form-demo'), [
            'classic_nickname' => 'joaoteste',
            'classic_subject' => 'complaint',
            'classic_message' => 'curta', // inválida (min:10) → volta com erros
        ])
        ->assertOk()
        ->assertSee('value="joaoteste"', false)
        ->assertSee('curta')
        ->assertSee('value="complaint" selected', false);
});

it('form clássico válido confirma via flash de sessão → toast', function () {
    config()->set('ui.showcase_enabled', true);

    $this->followingRedirects()
        ->from('/ui')
        ->post(route('ui.form-demo'), [
            'classic_nickname' => 'joaoteste',
            'classic_subject' => 'suggestion',
            'classic_message' => 'Mensagem válida da demonstração.',
        ])
        ->assertOk()
        ->assertSee('data-toast', false)
        ->assertSee(__('showcase.form_patterns.demo_sent'));
});

it('estratégia inline (padrão): erro junto ao campo, sem resumo', function () {
    config()->set('ui.showcase_enabled', true);

    $response = $this->followingRedirects()
        ->from('/ui')
        ->post(route('ui.form-demo'), ['classic_subject' => 'suggestion', 'classic_message' => 'Mensagem válida com dez caracteres.']);

    $response->assertOk()->assertDontSee('href="#classic_nickname"', false);

    // Erro inline no <p> do próprio campo (markup do <x-input>), ligado ao
    // input por aria-describedby — o leitor de tela chega ao motivo da recusa.
    $expected = __('validation.required', ['attribute' => __('showcase.form_patterns.demo_nickname')]);
    $response->assertSee('id="classic_nickname-error"', false)
        ->assertSee('aria-describedby="classic_nickname-error"', false)
        ->assertSee('aria-invalid="true"', false)
        ->assertSee($expected);
});

it('override por formulário (classic_display) troca a estratégia de exibição', function () {
    config()->set('ui.showcase_enabled', true);

    // summary: resumo com âncoras, inline suprimido
    $response = $this->followingRedirects()
        ->from('/ui')
        ->post(route('ui.form-demo'), ['classic_display' => 'summary']);

    $response->assertOk()->assertSee('href="#classic_nickname"', false);

    $expected = __('validation.required', ['attribute' => __('showcase.form_patterns.demo_nickname')]);
    $response->assertDontSee('<p class="mt-1.5 text-sm text-red-600 dark:text-red-400">'.$expected.'</p>', false);

    // toast: erros disparam o toast do kit (sem resumo, sem inline)
    $this->flushSession();

    $response = $this->followingRedirects()
        ->from('/ui')
        ->post(route('ui.form-demo'), ['classic_display' => 'toast']);

    $response->assertOk()
        ->assertSee('data-toast', false)
        ->assertSee($expected)
        ->assertDontSee('href="#classic_nickname"', false);
});

// --- Formulários reais migrados (auth + contato) ------------------------------

it('login: e-mail é repopulado com old(), senha NUNCA', function () {
    $this->followingRedirects()
        ->from('/login')
        ->post('/login', [
            'email' => 'fulano@example.com',
            'password' => 'SenhaErrada123',
        ])
        ->assertOk()
        ->assertSee('value="fulano@example.com"', false)
        ->assertDontSee('SenhaErrada123')
        // Erro inline (estratégia padrão) com a mensagem anti-enumeração.
        ->assertSee(__('auth.failed'));
});

it('registro: nome e e-mail voltam com old(); senhas nunca', function () {
    $this->followingRedirects()
        ->from('/register')
        ->post('/register', [
            'name' => 'Maria Teste',
            'email' => 'maria@example.com',
            'password' => 'fraca',
            'password_confirmation' => 'fraca',
        ])
        ->assertOk()
        ->assertSee('value="Maria Teste"', false)
        ->assertSee('value="maria@example.com"', false)
        ->assertDontSee('value="fraca"', false);
});

it('contato da landing segue a estratégia do config (summary = só resumo)', function () {
    config()->set('ui.error_display', 'summary');

    $this->followingRedirects()
        ->from('/')
        ->post(route('contact.store'), [])
        ->assertOk()
        ->assertSee('href="#email"', false)
        ->assertSee('href="#message"', false);
});

it('flash genérico de sessão vira toast do kit (success e error)', function () {
    $this->withSession(['success' => 'Tudo certo por aqui'])
        ->get('/')
        ->assertOk()
        ->assertSee('data-toast', false)
        ->assertSee('Tudo certo por aqui');

    $this->withSession(['error' => 'Algo falhou'])
        ->get('/')
        ->assertOk()
        ->assertSee('Algo falhou');
});

it('flash status aparece como toast nas telas de auth', function () {
    $this->withSession(['status' => __('passwords.sent')])
        ->get('/login')
        ->assertOk()
        ->assertSee('data-toast', false)
        ->assertSee(__('passwords.sent'));
});

// --- Exemplo Livewire (AJAX) do showcase -------------------------------------

it('form Livewire do /ui valida server-side sem reload', function () {
    Livewire::test(ContactForm::class)
        ->set('subject', '') // tem default válido — esvaziar para falhar
        ->call('send')
        ->assertHasErrors(['nickname', 'subject', 'message'])
        ->assertSet('sent', false);
});

it('form Livewire do /ui confirma na própria tela e limpa os campos', function () {
    Livewire::test(ContactForm::class)
        ->set('nickname', 'maria_silva')
        ->set('subject', 'suggestion')
        ->set('message', 'Mensagem via Livewire no showcase.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSet('nickname', '');
});

it('form Livewire com honeypot preenchido finge sucesso (registrada como bloqueada)', function () {
    Livewire::test(ContactForm::class)
        ->set('nickname', 'Bot')
        ->set('subject', 'other')
        ->set('message', 'Mensagem de bot com honeypot.')
        ->set('website', 'https://spam.example')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);
});
