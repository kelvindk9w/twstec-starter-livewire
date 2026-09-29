<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Twstec\Kit\Demo\Contact\Mail\ContactMessageMail;
use Twstec\Kit\Demo\Filament\Resources\FormSubmissions\Pages\ListFormSubmissions;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;
use Twstec\Kit\Foundation\Support\Platform;

// Formulário de contato da landing: validação server-side, honeypot,
// rate limit (throttle:sensitive) e e-mail enfileirado (Mailpit em dev).

function validContact(array $overrides = []): array
{
    return array_merge([
        'name' => 'Maria Silva',
        'email' => 'maria@example.com',
        'subject' => 'suggestion',
        'message' => 'Adorei o kit, queria sugerir um componente de tabela.',
    ], $overrides);
}

it('envia a mensagem por fila para o e-mail de contato configurado', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'contato@example.com');
    // Platform é singleton resolvido no boot (Filament provider) — re-resolve.
    app()->forgetInstance(Platform::class);

    $this->post(route('contact.store'), validContact())
        ->assertRedirect()
        ->assertSessionHas('contact_status', __('contact.sent'));

    // replyTo é montado no envelope() (aplicado só no envio real) — com
    // Mail::fake, asserção via propriedades do mailable.
    Mail::assertQueued(
        ContactMessageMail::class,
        fn (ContactMessageMail $mail): bool => $mail->hasTo('contato@example.com')
            && $mail->senderEmail === 'maria@example.com'
            && $mail->senderName === 'Maria Silva'
            && $mail->subjectKey === 'suggestion',
    );
});

it('honeypot preenchido = bot: sucesso falso e NENHUM e-mail', function () {
    Mail::fake();

    $this->post(route('contact.store'), validContact(['website' => 'https://spam.example']))
        ->assertRedirect()
        ->assertSessionHas('contact_status', __('contact.sent'));

    Mail::assertNothingQueued();
});

it('valida os campos obrigatórios e o assunto na whitelist', function () {
    Mail::fake();

    $this->post(route('contact.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

    $this->post(route('contact.store'), validContact(['email' => 'nao-e-email', 'subject' => 'hack', 'message' => 'curta']))
        ->assertSessionHasErrors(['email', 'subject', 'message']);

    Mail::assertNothingQueued();
});

it('respeita o rate limit de rotas sensíveis', function () {
    Mail::fake();

    $max = (int) config('security.rate_limit.sensitive', 5);

    for ($i = 0; $i < $max; $i++) {
        $this->post(route('contact.store'), validContact())->assertRedirect();
    }

    $this->post(route('contact.store'), validContact())->assertTooManyRequests();
});

it('sem e-mail de contato configurado, registra aviso e responde sucesso', function () {
    Mail::fake();
    config()->set('platform.contact_email', null);
    app()->forgetInstance(Platform::class);

    $this->post(route('contact.store'), validContact())
        ->assertRedirect()
        ->assertSessionHas('contact_status');

    Mail::assertNothingQueued();
});

it('feedback de sucesso aparece como toast do kit na landing', function () {
    $this->withSession(['contact_status' => __('contact.sent')])
        ->get('/')
        ->assertOk()
        ->assertSee('data-toast', false)
        ->assertSee(__('contact.sent'));
});

it('respeita o locale do visitante (cookie) na mensagem de retorno', function () {
    Mail::fake();

    $this->withCookie(SetLocale::COOKIE, 'en')
        ->post(route('contact.store'), validContact())
        ->assertSessionHas('contact_status', __('contact.sent', locale: 'en'));
});

// =============================================================================
// Bug de QA #5 — a mensagem de contato real também precisa virar registro
// auditável em form_submissions (origem `contact`), não só e-mail.
// =============================================================================

it('grava a mensagem em form_submissions com origem contact e remetente', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'contato@example.com');
    app()->forgetInstance(Platform::class);

    $this->post(route('contact.store'), validContact())->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->origin)->toBe(FormSubmission::ORIGIN_CONTACT)
        ->and($submission->nickname)->toBe('Maria Silva')
        ->and($submission->sender_email)->toBe('maria@example.com')
        ->and($submission->subject)->toBe('suggestion')
        ->and($submission->message)->toContain('componente de tabela')
        ->and($submission->isBlocked())->toBeFalse();

    Mail::assertQueued(ContactMessageMail::class);
});

it('registra a tentativa do honeypot como bloqueada e NÃO envia e-mail', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'contato@example.com');
    app()->forgetInstance(Platform::class);

    $this->post(route('contact.store'), validContact(['website' => 'http://spam.example']))
        // Sucesso FALSO: o bot não descobre que foi detectado.
        ->assertSessionHas('contact_status', __('contact.sent'));

    $submission = FormSubmission::query()->sole();

    expect($submission->origin)->toBe(FormSubmission::ORIGIN_CONTACT)
        ->and($submission->isBlocked())->toBeTrue()
        ->and($submission->attack_type)->toBe('honeypot');

    Mail::assertNothingQueued();
});

it('a submissão de contato aparece na listagem do super admin com a origem', function () {
    Mail::fake();
    config()->set('platform.contact_email', 'contato@example.com');
    app()->forgetInstance(Platform::class);

    $this->post(route('contact.store'), validContact())->assertRedirect();

    $submission = FormSubmission::query()->sole();
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(ListFormSubmissions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$submission])
        ->assertSee(__('admin.submissions.origin_contact'))
        ->assertSee('Maria Silva');
});
