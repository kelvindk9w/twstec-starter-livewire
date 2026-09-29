<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\FormSubmissionResource;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\Pages\ListFormSubmissions;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Demo\Showcase\Support\SubmissionExcerpt;

// =============================================================================
// Submissões bloqueadas no super admin: listagem NEUTRALIZADA, detalhe como
// evidência forense.
//
// A regra que estes testes seguram: uma tentativa de ataque não pode ser lida
// por acidente. Na fila, o operador vê o SELO (XSS, SQLi, honeypot) e um
// trecho sem dentes; o payload por extenso só existe na tela que se declara
// evidência — e mesmo lá, escapado.
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

// -----------------------------------------------------------------------------
// A neutralização em si
// -----------------------------------------------------------------------------

it('neutraliza payloads: sem tags, sem entidades que virem tags, colapsado', function (string $payload) {
    $excerpt = SubmissionExcerpt::neutralize($payload);

    expect($excerpt)
        ->not->toContain('<')
        ->not->toContain('>')
        ->not->toContain("\0")
        ->and(strlen($excerpt))->toBeLessThanOrEqual(SubmissionExcerpt::MAX_LENGTH + 3);
})->with([
    'script simples' => "<script>alert('x')</script>",
    'script duplamente codificado' => '&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;',
    'entidade html' => '&lt;img src=x onerror=alert(1)&gt;',
    'iframe' => '<iframe src="javascript:alert(1)"></iframe>',
    'sqli' => "' OR 1=1; DROP TABLE users; --",
    'null byte' => "arquivo.php\0.jpg",
    'texto longo' => 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt',
]);

it('trecho vazio quando o payload não tem nada legível fora das tags', function () {
    expect(SubmissionExcerpt::neutralize('<script></script>'))->toBe('')
        ->and(SubmissionExcerpt::neutralize(null))->toBe('')
        ->and(SubmissionExcerpt::neutralize('   '))->toBe('');
});

// -----------------------------------------------------------------------------
// Listagem
// -----------------------------------------------------------------------------

it('a listagem marca o trecho como neutralizado e nomeia o tipo de ataque', function () {
    FormSubmission::factory()->blocked('sqli')->create([
        'message' => "' OR 1=1; DROP TABLE users; --",
    ]);

    $this->get('/admin/form-submissions')
        ->assertOk()
        ->assertSee(__('admin.submissions.neutralized'))
        ->assertSee(__('admin.submissions.blocked_attack', [
            'type' => FormSubmissionResource::attackLabel('sqli'),
        ]));
});

it('submissão aceita não recebe a legenda de conteúdo neutralizado', function () {
    FormSubmission::factory()->create(['message' => 'Mensagem legítima de um visitante.']);

    $this->get('/admin/form-submissions')
        ->assertOk()
        ->assertSee('Mensagem legítima de um visitante.')
        ->assertDontSee(__('admin.submissions.neutralized'));
});

it('o apelido também é neutralizado na listagem (o payload pode vir por ali)', function () {
    FormSubmission::factory()->blocked('xss')->create([
        'nickname' => '<b onclick=alert(1)>zé</b>',
        'message' => 'texto qualquer',
    ]);

    $html = $this->get('/admin/form-submissions')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<b onclick')
        ->not->toContain('&lt;b onclick');
});

it('o tipo de ataque desconhecido cai num rótulo genérico, nunca na chave de tradução', function () {
    expect(FormSubmissionResource::attackLabel('tipo_que_nao_existe'))
        ->toBe(__('admin.submissions.attack_unknown'))
        ->and(FormSubmissionResource::attackLabel(null))
        ->toBe(__('admin.submissions.attack_unknown'))
        ->and(FormSubmissionResource::attackLabel('xss'))
        ->not->toContain('admin.submissions');
});

it('a listagem continua com bloqueadas no topo e o filtro por origem', function () {
    $antigaBloqueada = FormSubmission::factory()->blocked('xss')->create([
        'created_at' => now()->subDays(10),
        'origin' => FormSubmission::ORIGIN_CLASSIC,
    ]);
    $recenteAceita = FormSubmission::factory()->create([
        'created_at' => now(),
        'origin' => FormSubmission::ORIGIN_LIVEWIRE,
    ]);

    Livewire::test(ListFormSubmissions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$antigaBloqueada, $recenteAceita], inOrder: true)
        ->filterTable('blocked', true)
        ->assertCanSeeTableRecords([$antigaBloqueada])
        ->assertCanNotSeeTableRecords([$recenteAceita]);
});

// -----------------------------------------------------------------------------
// Detalhe
// -----------------------------------------------------------------------------

it('o detalhe exibe o payload íntegro escapado, com aviso e metadados', function () {
    $submission = FormSubmission::factory()->blocked('xss')->create([
        'nickname' => 'atacante',
        'message' => "<script>alert('evidencia')</script>",
        'sender_email' => 'quem@exemplo.test',
        'ip' => '203.0.113.7',
    ]);

    $this->get("/admin/form-submissions/{$submission->uuid}")
        ->assertOk()
        ->assertSee(__('admin.submissions.forensic_section'))
        ->assertSee(__('admin.submissions.forensic_heading'))
        ->assertSee(__('admin.submissions.forensic_warning'))
        // Payload por extenso, escapado (texto na página, nunca marcação).
        ->assertSee('&lt;script&gt;alert(&#039;evidencia&#039;)&lt;/script&gt;', false)
        // Metadados da evidência.
        ->assertSee('203.0.113.7')
        ->assertSee('quem@exemplo.test')
        ->assertSee(__('admin.submissions.blocked_attack', [
            'type' => FormSubmissionResource::attackLabel('xss'),
        ]));
});

it('o detalhe de uma submissão aceita mostra a mensagem, sem bloco de evidência', function () {
    $submission = FormSubmission::factory()->create([
        'message' => 'Gostaria de saber mais sobre os planos.',
    ]);

    $this->get("/admin/form-submissions/{$submission->uuid}")
        ->assertOk()
        ->assertSee('Gostaria de saber mais sobre os planos.')
        ->assertDontSee(__('admin.submissions.forensic_section'));
});

it('a rota do detalhe usa o uuid — o id interno nunca aparece na URL', function () {
    $submission = FormSubmission::factory()->create();

    $this->get("/admin/form-submissions/{$submission->id}")->assertNotFound();
    $this->get("/admin/form-submissions/{$submission->uuid}")->assertOk();
});

it('o payload continua gravado CRU no banco (auditoria)', function () {
    $payload = "<script>alert('cru')</script>";

    $submission = FormSubmission::factory()->blocked('xss')->create(['message' => $payload]);

    expect($submission->fresh()->message)->toBe($payload);
});
