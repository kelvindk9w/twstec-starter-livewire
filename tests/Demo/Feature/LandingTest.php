<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Vite;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;
use Twstec\Kit\Foundation\Support\Platform;

// Landing OFICIAL "Céu" — rota /. Valida CONTEÚDO, não só status.

it('responde 200 e renderiza as seções da página', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.title_line_1'))
        ->assertSee(__('landing.hero.title_line_2'))
        ->assertSee(__('landing.hero.subtitle'))
        ->assertSee(__('landing.components.title'))
        ->assertSee(__('landing.how.title'))
        ->assertSee(__('landing.security.title'))
        ->assertSee(__('landing.ready.title'))
        ->assertSee(__('landing.footer.title'));
});

it('é a home do kit, pela rota nomeada `landing`', function () {
    expect(route('landing', absolute: false))->toBe('/');

    $this->get(route('landing'))->assertOk();
});

it('o endereço antigo /v3 continua valendo: 301 para a home', function () {
    // A página morou em /v3 enquanto era uma direção em avaliação. Links já
    // compartilhados não podem virar 404 — e nem uma segunda URL servindo o
    // mesmo conteúdo (isso é conteúdo duplicado para o buscador).
    $this->get('/v3')->assertRedirect('/')->assertStatus(301);
});

it('a landing anterior saiu do kit sem deixar rota nem view para trás', function () {
    // Ela vive no histórico do git. O que não pode existir é o meio-termo:
    // uma view órfã que ninguém renderiza e ninguém mantém.
    expect(base_path('vendor/twstec/kit-demo/resources/views/landing-v3.blade.php'))->not->toBeFile()
        ->and(is_dir(base_path('vendor/twstec/kit-demo/resources/views/landing-v3')))->toBeFalse()
        ->and(base_path('vendor/twstec/kit-demo/lang/pt_BR/landing_v3.php'))->not->toBeFile()
        ->and(config('landing_v3'))->toBeNull();

    // A antiga seção de horas foi um inventário item a item; hoje é UMA linha
    // com o número do config. A chave que sobrou tem de ser a nova.
    expect(__('landing.hours.label'))->not->toBe('landing.hours.label');
});

it('mostra as quatro telas reais do kit, em versão clara e escura', function () {
    $response = $this->get('/');

    foreach (['dashboard', 'admin', 'ui', 'login'] as $screen) {
        foreach (['light', 'dark'] as $theme) {
            expect(base_path("vendor/twstec/kit-demo/resources/img/landing/{$screen}-{$theme}-1440.webp"))->toBeFile()
                ->and(base_path("vendor/twstec/kit-demo/resources/img/landing/{$screen}-{$theme}-720.webp"))->toBeFile();
        }

        $response->assertSee(Vite::asset("vendor/twstec/kit-demo/resources/img/landing/{$screen}-light-1440.webp"), false)
            ->assertSee(Vite::asset("vendor/twstec/kit-demo/resources/img/landing/{$screen}-dark-1440.webp"), false)
            ->assertSee(__("landing.hero.screens.{$screen}.alt"));
    }
});

it('mostra o split-screen com o código do x-table e a captura da tela renderizada', function () {
    expect(base_path('vendor/twstec/kit-demo/resources/img/landing/table-light-900.webp'))->toBeFile()
        ->and(base_path('vendor/twstec/kit-demo/resources/img/landing/table-dark-900.webp'))->toBeFile();

    $this->get('/')
        ->assertSee('data-sky-split-range', false)
        ->assertSee(Vite::asset('vendor/twstec/kit-demo/resources/img/landing/table-light-900.webp'), false)
        ->assertSee(__('landing.components.code_label'))
        ->assertSee(__('landing.components.screen_label'))
        // O trecho de código é o USO REAL do componente do kit.
        ->assertSee('&lt;x-table', false)
        ->assertSee('&lt;x-table-cell', false);
});

it('lista as três etapas com etiqueta de cursor e comando de terminal', function () {
    $response = $this->get('/');

    foreach (__('landing.how.steps') as $step) {
        $response->assertSee($step['title'])
            ->assertSee($step['text'])
            ->assertSee($step['cursor'])
            ->assertSee($step['command']);
    }
});

it('mostra os dois recursos herdados da landing anterior', function () {
    // Mailpit/e-mails prontos e "feito em componentes" eram cartões da landing
    // que saiu. Eles não são segurança — por isso vivem numa linha própria,
    // "Pronto para produzir" —, mas não podiam sumir junto com a página.
    $response = $this->get('/');

    $items = __('landing.ready.items');

    expect($items)->toBeArray()->toHaveCount(2);

    foreach ($items as $item) {
        $response->assertSee($item['title'])->assertSee($item['text']);
    }
});

it('mostra a conta das horas com o número do config, e some quando é zero', function () {
    config()->set('landing.hours_saved', 280);

    $this->get('/')
        ->assertOk()
        ->assertSee('id="horas"', false)
        ->assertSee('data-sky-count="280"', false)
        ->assertSee(__('landing.hours.label'));

    // Zero esconde a faixa inteira: um "0+ horas economizadas" seria pior do
    // que não dizer nada.
    config()->set('landing.hours_saved', 0);

    $this->get('/')
        ->assertOk()
        ->assertDontSee(__('landing.hours.label'))
        ->assertDontSee('id="horas"', false);
});

it('mantém o formulário de contato funcional que veio da landing anterior', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('id="contato"', false)
        ->assertSee(route('contact.store'), false)
        ->assertSee('name="website"', false) // honeypot anti-spam
        ->assertSee(__('contact.form.submit'));
});

it('atende as âncoras do cabeçalho do site (#recursos, #horas, #stack)', function () {
    // O menu do produto (App\Livewire\Support\Navigation) aponta para essas
    // três âncoras em QUALQUER tela. Se elas não existirem aqui, o menu leva
    // ao topo da home e mente sobre onde a informação está.
    $html = $this->get('/')->assertOk()->getContent();

    foreach (['id="recursos"', 'id="horas"', 'id="stack"'] as $anchor) {
        expect($html)->toContain($anchor);
    }
});

it('lista as seis peças de segurança no bento', function () {
    $response = $this->get('/');

    $items = __('landing.security.items');

    expect($items)->toBeArray()->toHaveCount(6);

    foreach ($items as $item) {
        $response->assertSee($item['title'])->assertSee($item['text']);
    }

    $response->assertSee('data-sky-bento', false);
});

it('usa o LOGOTIPO OFICIAL de cada tecnologia, inline e sem CDN', function () {
    // Decisão do dono: os cubos carregam a marca real, não um ícone genérico.
    // O desenho é forma PREENCHIDA (logo), nunca traço — engrossar contorno
    // deformaria a marca de outra empresa. E ele vem do repositório: nenhuma
    // requisição, nada que a CSP tenha de abrir.
    $html = $this->get('/')->assertOk()->getContent();

    foreach (['laravel', 'php', 'postgres', 'redis', 'docker', 'livewire', 'filament', 'tailwind'] as $tech) {
        expect($html)->toContain('data-sky-mark="'.$tech.'"');
    }

    expect($html)->toContain('fill="currentColor"')
        // A cor da marca acompanha o desenho: é ela que o 3D lê para pintar o
        // esmalte do cubo (data-sky-color).
        ->and($html)->toContain('data-sky-color="#ff2d20"')  // Laravel
        ->and($html)->toContain('data-sky-color="#336791"')  // PostgreSQL
        ->and($html)->not->toContain('cdn.simpleicons.org')
        ->and($html)->not->toContain('<img src="https://');
});

it('desenha o arco das oito tecnologias com nome visível (fallback do 3D)', function () {
    $response = $this->get('/');

    $tech = __('landing.footer.tech');

    expect($tech)->toBeArray()->toHaveCount(8);

    foreach ($tech as $label) {
        $response->assertSee($label);
    }

    // O 3D levanta as MARCAS deste mesmo bloco: sem ele, não há textura.
    $response->assertSee('id="sky-arc-marks"', false)
        ->assertSee('data-sky-layout="arc"', false);
});

it('usa os números do config, nunca hardcoded na view', function () {
    config()->set('landing.tests', 1234);
    config()->set('landing.clones', 4321);

    // O número da prova social conta 0 → N: ele mora num <span data-sky-count>
    // dentro da pílula âmbar, ao lado (nunca dentro) da frase traduzida.
    $this->get('/')
        ->assertOk()
        ->assertSee('data-sky-count="4321"', false)
        ->assertSee('>4.321</span>+', false)
        // Com clones no .env, é esse o número — e a frase muda junto.
        ->assertSee(__('landing.hero.proof_clones'))
        ->assertDontSee(__('landing.hero.proof_tests'))
        // O chip do leque NÃO repete o número: traz outro fato.
        ->assertSee(__('landing.hero.chip_tenancy'));
});

it('sem clones, a prova social é a suíte verde — nunca um número inventado', function () {
    // Uma pílula com "0 desenvolvedores já clonaram" é uma confissão. Sem esse
    // dado, a prova que o kit TEM é a suíte de testes: um fato do projeto.
    config()->set('landing.clones', 0);
    config()->set('landing.tests', 589);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.proof_tests'))
        ->assertDontSee(__('landing.hero.proof_clones'))
        ->assertSee('data-sky-count="589"', false);
});

it('aponta o CTA para o repositório quando configurado', function () {
    config()->set('platform.repo_url', 'https://github.com/tws/tws-laravel-starter-kit');
    app()->forgetInstance(Platform::class);

    $this->get('/')
        ->assertOk()
        ->assertSee('https://github.com/tws/tws-laravel-starter-kit', false)
        ->assertSee(__('landing.hero.cta_primary'))
        ->assertSee(__('landing.footer.cta'));
});

it('sem repositório configurado o CTA cai em criar conta (nunca URL quebrada)', function () {
    config()->set('platform.repo_url', null);
    app()->forgetInstance(Platform::class);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.cta_primary'))
        ->assertSee(route('register'), false);
});

it('reaproveita o cabeçalho e o rodapé do kit (variantes, não cópias)', function () {
    $this->get('/')
        ->assertOk()
        // Cabeçalho: a pílula flutuante é o MESMO <x-site-header>.
        ->assertSee('sky-nav-shell', false)
        ->assertSee(platform()->name)
        ->assertSee(route('login'), false)
        // Rodapé institucional do kit, dentro do céu.
        ->assertSee(__('landing.footer.tagline'))
        ->assertSee(__('landing.footer.rights', ['year' => date('Y'), 'company' => platform()->companyName]));
});

it('carrega os bundles próprios da landing e nenhum script de CDN', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('landing')
        ->and($html)->not->toContain('https://cdn')
        ->and($html)->not->toContain('unpkg.com')
        ->and($html)->not->toContain('fonts.googleapis.com');
});

it('respeita a chave que desliga o WebGL', function () {
    config()->set('landing.webgl_enabled', false);

    $this->get('/')->assertOk()->assertSee('data-sky-webgl="off"', false);
});

it('toda string da landing passa pelo arquivo de idioma (sem chave crua na tela)', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('landing.')
        ->and(__('landing.hero.title_line_1'))->not->toBe('landing.hero.title_line_1')
        ->and(__('landing.components.items'))->toBeArray()->toHaveCount(3)
        ->and(__('landing.how.steps'))->toBeArray()->toHaveCount(3);
});

it('fala os três idiomas do kit', function (string $locale) {
    $this->withCookie(SetLocale::COOKIE, $locale)
        ->get('/')
        ->assertOk()
        ->assertSee(__('landing.hero.title_line_1', locale: $locale))
        ->assertSee(__('landing.security.title', locale: $locale));
})->with(['pt_BR', 'en', 'es']);

it('os três idiomas têm exatamente as mesmas chaves', function () {
    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $keys = array_merge($keys, is_array($value) ? $flatten($value, $path) : [$path]);
        }

        return $keys;
    };

    $reference = $flatten(require base_path('vendor/twstec/kit-demo/lang/pt_BR/landing.php'));

    foreach (['en', 'es'] as $locale) {
        expect($flatten(require base_path("vendor/twstec/kit-demo/lang/{$locale}/landing.php")))
            ->toEqualCanonicalizing($reference);
    }
});

it('não deixa a landing vazar para o resto do produto', function () {
    // As variantes do cabeçalho/rodapé são ADITIVAS: fora da landing o padrão
    // continua a barra colada no topo com a linha embaixo, e nenhuma tela do
    // produto baixa um byte do bundle da landing.
    $panel = $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('sticky top-0 z-40 border-b border-border', false)
        ->assertDontSee('sky-nav-shell', false)
        ->getContent();

    expect($panel)->not->toContain('resources/css/landing.css')
        ->and($panel)->not->toContain('resources/js/landing.js');
});
