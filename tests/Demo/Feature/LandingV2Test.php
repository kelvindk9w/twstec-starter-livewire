<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Redactor;
use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// Landing alternativa "O Rastro" (/v2) — valida CONTEÚDO, não só status.
// A página inteira argumenta que nada nela é inventado: os testes
// são o que impede essa afirmação de virar mentira com o tempo.
// =============================================================================

it('v2 responde 200 com as oito seções da narrativa', function () {
    $response = $this->get('/v2');

    $response->assertOk()
        ->assertSee(platform()->name)
        // 1 herói · 2 confiança · 3 mecanismo · 4 profundidade
        ->assertSee(__('landing_v2.hero.title'))
        ->assertSee(__('landing_v2.hero.subtitle'))
        ->assertSee(__('landing_v2.trust.tests'))
        ->assertSee(__('landing_v2.mechanism.heading'))
        ->assertSee(__('landing_v2.depth.heading'))
        // 5 tese/redação · 6 prova · 7 comunidade · 8 CTA
        ->assertSee(__('landing_v2.thesis.heading'))
        ->assertSee(__('landing_v2.thesis.chain_heading'))
        ->assertSee(__('landing_v2.proof.heading'))
        ->assertSee(__('landing_v2.community.heading'))
        ->assertSee(__('landing_v2.cta.heading'));
});

it('v2 tem rota nomeada e não substitui a landing atual', function () {
    expect(route('landing.v2'))->toEndWith('/v2');

    // A home continua sendo a landing atual — a /v2 é comparação, não troca.
    $this->get('/')->assertOk()->assertSee(__('landing.hero.title_line_1'));
});

it('v2 carrega o bundle próprio e nenhum script de CDN', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    expect($html)->toContain('landing-v2')
        // A CSP do kit não permite script/font externos: nenhuma tag pode
        // apontar para fora do próprio host.
        ->and($html)->not->toContain('cdn.jsdelivr.net')
        ->and($html)->not->toContain('unpkg.com')
        ->and($html)->not->toContain('cdnjs.cloudflare.com')
        ->and($html)->not->toContain('fonts.googleapis.com');
});

it('v2 acrescenta api.github.com ao connect-src e NADA além disso', function () {
    $base = (string) config('security.headers.content_security_policy');
    $csp = $this->get('/v2')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("connect-src https://api.github.com 'self'")
        // O resto da política é idêntico ao da CSP base: nenhuma exceção de
        // script-src, style-src ou font-src foi aberta para a landing.
        ->and($csp)->not->toContain('unsafe-eval')
        ->and(str_replace("connect-src https://api.github.com 'self'", "connect-src 'self'", (string) $csp))
        ->toBe($base);
});

it('a landing atual e o painel seguem com a CSP base', function () {
    $base = (string) config('security.headers.content_security_policy');

    expect($this->get('/')->headers->get('Content-Security-Policy'))->toBe($base)
        ->and($this->get('/login')->headers->get('Content-Security-Policy'))->toBe($base);
});

it('os números da faixa de confiança são reais, não escritos na view', function () {
    cache()->forget('landing-v2.stats');

    $html = $this->get('/v2')->assertOk()->getContent();

    // Contagem de testes: a MESMA varredura que o controller faz, refeita
    // aqui de forma independente.
    $cases = 0;

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*Test.php') as $file) {
        $cases += preg_match_all('/^\s*(?:it|test)\s*\(/m', (string) file_get_contents($file->getRealPath()));
    }

    expect($cases)->toBeGreaterThan(100)
        ->and($html)->toContain(number_format($cases, 0, ',', '.'));

    // Licença: do composer.json. Versão: do config/platform.php (.env).
    $license = json_decode((string) file_get_contents(base_path('composer.json')), true)['license'];

    expect($html)->toContain($license)
        ->and($html)->toContain(platform()->version);
});

it('o payload da redação é a saída REAL do Redactor do kit', function () {
    $html = $this->get('/v2')->assertOk()->getContent();
    $redactor = app(Redactor::class);

    // Os três casos que a seção mostra, redigidos aqui pela mesma classe.
    expect($html)
        ->toContain(e($redactor->redactString('marina.duarte@exemplo.com')))
        ->and($html)->toContain(e($redactor->redactString('472.918.330-15')))
        ->and($html)->toContain(Redactor::MASK)
        // E o dado CRU também está lá: é ele que o visitante vê sumir.
        ->and($html)->toContain('472.918.330-15');
});

it('a cadeia de ciclo de vida usa os estados reais do enum', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    foreach (RequestLogStatus::cases() as $status) {
        expect($html)->toContain($status->value)
            ->and($html)->toContain(__('landing_v2.thesis.chain.'.$status->value));
    }
});

it('os comandos do mecanismo apontam para o repositório do .env', function () {
    config()->set('platform.repo_url', 'https://github.com/tws/tws-laravel-starter-kit');
    app()->forgetInstance(Platform::class); // o singleton tipado lê a config na 1ª resolução

    $this->get('/v2')
        ->assertOk()
        ->assertSee('git clone https://github.com/tws/tws-laravel-starter-kit meu-projeto')
        ->assertSee('docker compose up -d --build')
        ->assertSee('docker compose exec app ./vendor/bin/pest');
});

it('o botão de som existe na /v2 e em nenhuma outra tela', function () {
    $this->get('/v2')
        ->assertOk()
        ->assertSee('data-lv2-sound', false)
        ->assertSee(__('landing_v2.sound.label'));

    $this->get('/')->assertOk()->assertDontSee('data-lv2-sound', false);
    $this->get('/login')->assertOk()->assertDontSee('data-lv2-sound', false);
});

it('a /v2 reusa o cabeçalho e o rodapé do site', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    // Marca, seletor de idioma e rodapé institucional vêm dos componentes do
    // kit — a v2 muda a direção de arte, não o produto.
    expect($html)
        ->toContain(route('locale.switch', 'en'))
        ->and($html)->toContain(__('landing.footer.tagline'))
        ->and($html)->toContain(__('ui.footer.operated_by', ['platform' => platform()->name]));
});

it('v2 traduz a página inteira nos idiomas disponíveis', function () {
    foreach (platform()->availableLocales as $locale) {
        app()->setLocale($locale);

        $this->withCookie('locale', $locale)
            ->get('/v2')
            ->assertOk()
            ->assertSee(__('landing_v2.hero.title'))
            ->assertSee(__('landing_v2.thesis.heading'))
            ->assertSee(__('landing_v2.cta.button'));
    }
})->with([[null]]);

it('nenhuma string da v2 ficou fora de lang/', function () {
    $files = ['vendor/twstec/kit-demo/resources/views/landing-v2.blade.php'];

    foreach (glob(base_path('vendor/twstec/kit-demo/resources/views/landing-v2/*.blade.php')) as $partial) {
        $files[] = 'vendor/twstec/kit-demo/resources/views/landing-v2/'.basename($partial);
    }

    $offenders = [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents(base_path($file));

        // Texto solto entre tags: qualquer coisa que não seja {{ … }},
        // diretiva Blade, comentário ou pontuação isolada.
        preg_match_all('/>\s*([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s,\.\-]{4,})\s*</u', $contents, $matches);

        foreach ($matches[1] as $text) {
            $offenders[] = $file.': "'.trim($text).'"';
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));
});

it('cada seção abre por uma frase de dor ou promessa, não por feature', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    // Narrativa 3Ps: Dor (o que dá errado quando o sistema não se lembra),
    // Pessoa (quem responde por segurança) e Promessa (o título da seção).
    foreach (['mechanism', 'depth', 'thesis', 'proof', 'community', 'cta'] as $section) {
        expect($html)->toContain(e(__("landing_v2.{$section}.pain")));
    }

    // A Pessoa aparece no herói: quem responde pelo vazamento é o leitor.
    expect($html)->toContain(e(__('landing_v2.hero.subtitle')));
});

it('o campo interativo do herói existe e não envia nada a lugar nenhum', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    expect($html)
        ->toContain('data-lv2-live-input')
        ->and($html)->toContain('data-lv2-live-output')
        ->and($html)->toContain(e(__('landing_v2.hero.live_label')));

    // Sem `name` e sem <form> em volta: a redação roda inteira no navegador.
    expect($html)->not->toContain('name="lv2-live"');
});

it('os contadores da faixa de confiança levam "+" só no que é contagem', function () {
    cache()->forget('landing-v2.stats');

    $html = $this->get('/v2')->assertOk()->getContent();

    // Testes e arquivos são contagem: ganham contador e "+".
    expect($html)
        ->toContain('data-lv2-count-suffix="+"')
        // Licença e data não: uma data que sobe de zero é mentira animada.
        ->and(substr_count($html, 'data-lv2-count='))->toBe(2);
});

it('o herói tem os quatro planos da cena', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    foreach (['ambient', 'trace', 'object', 'front'] as $layer) {
        expect($html)->toContain('data-lv2-layer="'.$layer.'"');
    }

    expect($html)->toContain('data-lv2-hero')->and($html)->toContain('data-lv2-object-tilt');
});

it('a /v2 declara a TINTA como mundo padrão, sem mexer nas outras telas', function () {
    // O padrão da TELA vai no data-theme-default — a mesma cadeia de
    // resolução do kit (localStorage → data-theme-default → 'system').
    $this->get('/v2')->assertOk()->assertSee('data-theme-default="dark"', false);

    // A landing atual e o login seguem com o padrão do kit. (O /ui fica
    // fora: o showcase responde 404 quando UI_SHOWCASE_ENABLED está
    // desligado, que é o caso no ambiente de teste.)
    foreach (['/', '/login'] as $path) {
        $this->get($path)->assertOk()->assertSee('data-theme-default="system"', false);
    }
});

it('a espinha marca as seções que existem de verdade na página', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    // Cada marcador aponta para uma âncora REAL: um marcador que aponta para
    // uma seção inexistente é um fio que não mede nada.
    preg_match_all('/data-lv2-mark="#([a-z-]+)"/', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $anchor) {
        expect($html)->toContain('id="'.$anchor.'"');
    }

    // E os rótulos são os estados reais do ciclo de vida do log.
    expect($html)->toContain('INICIADA')->and($html)->toContain('CONCLUIDA');
});

it('o campo ambiente existe no herói e no CTA final (o ciclo fecha)', function () {
    $html = $this->get('/v2')->assertOk()->getContent();

    expect(substr_count($html, 'data-lv2-ambient'))->toBe(2)
        ->and($html)->toContain('data-lv2-ambient-final');
});
