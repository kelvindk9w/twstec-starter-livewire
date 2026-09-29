<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Demo\Database\Seeders\FormSubmissionSeeder;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\FormSubmissionResource;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\Pages\ListFormSubmissions;
use Twstec\Kit\Demo\Livewire\ContactForm;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Demo\Showcase\Support\SubmissionExcerpt;

// Formulários demo do /ui gravando de verdade em form_submissions + vitrine
// de segurança: ataques reais (XSS, SQLi, honeypot) contra AMBOS os forms —
// nada executa, nada quebra, tentativas registradas (blocked_at + tipo) e
// exibidas no TOPO da listagem do super admin com o payload INERTE/escapado.

beforeEach(function () {
    config()->set('ui.showcase_enabled', true);
});

// ---------------------------------------------------------------------------
// Salvamento real (os dois padrões)
// ---------------------------------------------------------------------------

it('form clássico grava a submissão com origem classic', function () {
    $this->post(route('ui.form-demo'), [
        'classic_nickname' => 'maria_classic',
        'classic_subject' => 'suggestion',
        'classic_message' => 'Mensagem de teste do form clássico.',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->origin)->toBe(FormSubmission::ORIGIN_CLASSIC)
        ->and($submission->nickname)->toBe('maria_classic')
        ->and($submission->subject)->toBe('suggestion')
        ->and($submission->blocked_at)->toBeNull()
        ->and($submission->attack_type)->toBeNull();
});

it('form Livewire grava a submissão com origem livewire', function () {
    Livewire::test(ContactForm::class)
        ->set('nickname', 'ana_livewire')
        ->set('subject', 'complaint')
        ->set('message', 'Mensagem de teste do form Livewire.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $submission = FormSubmission::query()->sole();

    expect($submission->origin)->toBe(FormSubmission::ORIGIN_LIVEWIRE)
        ->and($submission->nickname)->toBe('ana_livewire')
        ->and($submission->blocked_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Ataques reais contra os DOIS formulários
// ---------------------------------------------------------------------------

it('XSS no form clássico: gravado INERTE, bloqueado, resposta de sucesso falso', function () {
    $payload = "<script>alert('ola')</script>";

    $this->post(route('ui.form-demo'), [
        'classic_nickname' => 'atacante',
        'classic_subject' => 'other',
        'classic_message' => $payload.' tentando XSS no clássico',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $submission = FormSubmission::query()->sole();

    expect($submission->blocked_at)->not->toBeNull()
        ->and($submission->attack_type)->toBe('xss')
        ->and($submission->message)->toContain($payload); // texto inerte, cru
});

it('XSS no form Livewire: gravado INERTE, bloqueado, sucesso falso', function () {
    $payload = "<script>alert('ola')</script>";

    Livewire::test(ContactForm::class)
        ->set('nickname', 'atacante_ajax')
        ->set('subject', 'other')
        ->set('message', $payload.' tentando XSS no Livewire')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $submission = FormSubmission::query()->sole();

    expect($submission->blocked_at)->not->toBeNull()
        ->and($submission->attack_type)->toBe('xss')
        ->and($submission->message)->toContain($payload);
});

it('SQLi no form clássico: bloqueado e registrado (nada executa)', function () {
    $this->post(route('ui.form-demo'), [
        'classic_nickname' => "' OR 1=1 --",
        'classic_subject' => 'other',
        'classic_message' => 'Tentativa de SQL injection no apelido.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $submission = FormSubmission::query()->sole();

    expect($submission->blocked_at)->not->toBeNull()
        ->and($submission->attack_type)->toBe('sqli')
        ->and(FormSubmission::query()->count())->toBe(1); // nada quebrou
});

it('SQLi no form Livewire: bloqueado e registrado (nada executa)', function () {
    Livewire::test(ContactForm::class)
        ->set('nickname', "' OR 1=1 --")
        ->set('subject', 'other')
        ->set('message', 'Tentativa de SQL injection no apelido.')
        ->call('send')
        ->assertSet('sent', true);

    $submission = FormSubmission::query()->sole();

    expect($submission->blocked_at)->not->toBeNull()
        ->and($submission->attack_type)->toBe('sqli');
});

it('honeypot preenchido no form clássico: bloqueado com sucesso falso', function () {
    $this->post(route('ui.form-demo'), [
        'classic_nickname' => 'bot_classic',
        'classic_subject' => 'other',
        'classic_message' => 'Spam do bot no form clássico.',
        'website' => 'https://spam.example.com',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $submission = FormSubmission::query()->sole();

    expect($submission->blocked_at)->not->toBeNull()
        ->and($submission->attack_type)->toBe('honeypot');
});

it('honeypot preenchido no form Livewire: bloqueado com sucesso falso', function () {
    Livewire::test(ContactForm::class)
        ->set('nickname', 'bot_ajax')
        ->set('subject', 'other')
        ->set('message', 'Spam do bot no form Livewire.')
        ->set('website', 'https://spam.example.com')
        ->call('send')
        ->assertSet('sent', true);

    $submission = FormSubmission::query()->sole();

    expect($submission->blocked_at)->not->toBeNull()
        ->and($submission->attack_type)->toBe('honeypot');
});

it('middleware global NÃO bloqueia os endpoints delegados (a camada do form defende)', function () {
    // Prova que a delegação (config security.validation) está ativa: o POST
    // com XSS NÃO recebe 422 do middleware — o form grava inerte.
    $this->post(route('ui.form-demo'), [
        'classic_nickname' => 'x',
        'classic_subject' => 'other',
        'classic_message' => '<script>alert(1)</script> payload delegado',
    ])->assertRedirect();

    expect(FormSubmission::query()->sole()->attack_type)->toBe('xss');
});

it('no modo block, o middleware global SEGUE bloqueando ataques em rotas não delegadas', function () {
    // Modo `block` (o padrão é `observe` — ver ValidationMode).
    config()->set('security.validation.mode', 'block');
    $this->postJson('/api/keys', ['name' => '<script>alert(1)</script>'])
        ->assertStatus(422);

    expect(FormSubmission::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Listagem no super admin
// ---------------------------------------------------------------------------

it('admin lista submissões: bloqueadas no TOPO, depois as mais recentes', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $antiga = FormSubmission::factory()->create(['created_at' => now()->subDays(2)]);
    $recente = FormSubmission::factory()->create(['created_at' => now()]);
    $bloqueada = FormSubmission::factory()->blocked('xss')->create(['created_at' => now()->subDays(5)]);

    Livewire::actingAs($admin)
        ->test(ListFormSubmissions::class)
        ->assertCanSeeTableRecords([$bloqueada, $recente, $antiga])
        ->assertSeeInOrder([
            // a mais antiga, mas bloqueada → primeira; depois as recentes
            e($bloqueada->nickname),
            e($recente->nickname),
            e($antiga->nickname),
        ]);
});

it('admin vê o selo de ataque bloqueado com o tipo traduzido', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    FormSubmission::factory()->blocked('sqli')->create();
    FormSubmission::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListFormSubmissions::class)
        ->call('loadTable')
        // O tipo aparece pelo NOME (SQL injection), não pela sigla interna.
        ->assertSee(__('admin.submissions.blocked_attack', [
            'type' => FormSubmissionResource::attackLabel('sqli'),
        ]))
        ->assertSee(__('admin.submissions.accepted'));
});

it('admin filtra por origem e o filtro vem da query string', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $classic = FormSubmission::factory()->create(['origin' => FormSubmission::ORIGIN_CLASSIC]);
    $livewire = FormSubmission::factory()->create(['origin' => FormSubmission::ORIGIN_LIVEWIRE]);

    Livewire::actingAs($admin)
        ->withQueryParams(['filters' => ['origin' => ['value' => 'classic']]])
        ->test(ListFormSubmissions::class)
        ->assertCanSeeTableRecords([$classic])
        ->assertCanNotSeeTableRecords([$livewire]);
});

// ---------------------------------------------------------------------------
// Payload: NEUTRALIZADO na listagem, íntegro (e escapado) só no detalhe
// ---------------------------------------------------------------------------

it('a listagem do admin NÃO mostra o payload — nem cru, nem escapado', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    FormSubmission::factory()->blocked('xss')->create([
        'nickname' => '<script>alert(1)</script>',
        'message' => "<script>alert('ola')</script>",
    ]);

    $html = $this->actingAs($admin)->get('/admin/form-submissions')
        ->assertOk()
        ->assertSee(__('admin.submissions.neutralized'))
        ->getContent();

    // A página do painel carrega os próprios <script>, então o que se prova
    // aqui é que a MARCAÇÃO do payload sumiu da listagem — nas duas formas,
    // crua e escapada — e que o trecho neutralizado tomou o lugar dela.
    expect($html)
        ->not->toContain('<script>alert(')
        ->not->toContain('&lt;script&gt;alert(')
        ->not->toContain('&lt;/script&gt;')
        ->toContain(__('admin.submissions.neutralized'));

    // E o trecho exibido é, ele mesmo, incapaz de virar marcação.
    expect(SubmissionExcerpt::neutralize("<script>alert('ola')</script>"))
        ->not->toContain('<')
        ->not->toContain('>');
});

it('o detalhe mostra o payload íntegro ESCAPADO como evidência forense', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $submission = FormSubmission::factory()->blocked('xss')->create([
        'message' => "<script>alert('ola')</script>",
    ]);

    $response = $this->actingAs($admin)->get("/admin/form-submissions/{$submission->uuid}");

    $response->assertOk()
        // O bloco se identifica como evidência e avisa o operador.
        ->assertSee(__('admin.submissions.forensic_section'))
        ->assertSee(__('admin.submissions.forensic_warning'))
        // O payload aparece por extenso, ESCAPADO (texto, jamais marcação).
        ->assertSee('&lt;script&gt;alert(&#039;ola&#039;)&lt;/script&gt;', false);

    // …e nunca em forma executável.
    $html = $response->getContent();
    $cru = substr_count($html, "<script>alert('ola')</script>");
    $emSnapshot = substr_count($html, '<script>alert(\u0027ola\u0027)<\/script>');

    expect($cru - $emSnapshot)->toBe(0);
});

it('nenhum template exibe submissões com echo cru ({!! !!})', function () {
    $views = array_merge(
        glob(resource_path('views/filament/**/*.blade.php')) ?: [],
        // As views do painel são do pacote twstec/kit-admin desde a 2.0.
        glob(base_path('vendor/twstec/kit-admin/resources/views/**/*.blade.php')) ?: [],
        glob(resource_path('views/livewire/*.blade.php')) ?: [],
    );

    foreach ($views as $view) {
        $content = file_get_contents($view);

        expect(preg_match('/\{!!.*(message|nickname|submission)/i', (string) $content))
            ->toBe(0, "Echo cru de campo de submissão em {$view}");
    }
});

it('o seeder da demo cadastra 40 submissões variadas com bloqueadas', function () {
    $this->seed(FormSubmissionSeeder::class);

    expect(FormSubmission::query()->count())->toBe(40)
        ->and(FormSubmission::query()->whereNotNull('blocked_at')->count())->toBe(5)
        ->and(FormSubmission::query()->where('origin', 'classic')->count())->toBeGreaterThan(0)
        ->and(FormSubmission::query()->where('origin', 'livewire')->count())->toBeGreaterThan(0);
});

it('delegação Livewire vale no endpoint real ofuscado (livewire-<hash>/update)', function () {
    // Modo `block` (o padrão é `observe` — ver ValidationMode).
    config()->set('security.validation.mode', 'block');
    // Descobre o path real do update do Livewire 4 (ofuscado) via HTML do /ui.
    config()->set('ui.showcase_enabled', true);
    $html = $this->get('/ui')->assertOk()->getContent();
    preg_match('#livewire-[a-z0-9]+/update#', (string) $html, $m);
    $updatePath = $m[0] ?? 'livewire/update';

    $snapshot = json_encode(['memo' => ['name' => 'contact-form'], 'data' => []]);
    $payload = [
        'components' => [
            ['snapshot' => $snapshot, 'updates' => ['message' => "<script>alert('ola')</script>"], 'calls' => []],
        ],
    ];

    // Componente delegado: o middleware NÃO bloqueia (a camada do form defende).
    $delegated = $this->postJson('/'.$updatePath, $payload);
    expect($delegated->status())->not->toBe(422);

    // Componente FORA da allowlist: o middleware bloqueia normalmente (422).
    $outro = $this->postJson('/'.$updatePath, [
        'components' => [
            ['snapshot' => json_encode(['memo' => ['name' => 'dashboard'], 'data' => []]), 'updates' => ['message' => '<script>alert(1)</script>'], 'calls' => []],
        ],
    ]);
    expect($outro->status())->toBe(422);
});
