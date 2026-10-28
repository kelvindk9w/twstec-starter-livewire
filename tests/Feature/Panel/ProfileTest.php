<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Support\SessionFingerprint;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Perfil (Livewire): dados, senha de login, senha de transação
// (TransactionPasswordService) e avatar (função global de upload
// seguro). Tudo na mesma tela.
// =============================================================================

beforeEach(function () {
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');
});

it('exige autenticação (deny-by-default)', function () {
    $this->get('/profile')->assertRedirect(route('login'));
});

it('renderiza com os dados do usuário', function () {
    $user = User::factory()->create(['name' => 'João Teste', 'email' => 'joao@example.com']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertOk()
        ->assertSee('joao@example.com')
        ->assertSet('name', 'João Teste');
});

it('atualiza o nome', function () {
    $user = User::factory()->create(['name' => 'Antigo']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', 'Nome Novo')
        ->call('updateProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Nome Novo');
});

it('troca a senha de login exigindo a senha atual', function () {
    $user = User::factory()->create(['password' => Hash::make('SenhaAtual123')]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('currentPassword', 'SenhaAtual123')
        ->set('password', 'NovaSenhaForte456')
        ->set('passwordConfirmation', 'NovaSenhaForte456')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('NovaSenhaForte456', (string) $user->fresh()->password))->toBeTrue();
});

it('trocar a senha mantém esta sessão e derruba a de outro aparelho e o "lembrar de mim"', function () {
    $user = User::factory()->create(['password' => 'SenhaAtual123']);

    $this->post('/login', ['email' => $user->email, 'password' => 'SenhaAtual123'])->assertRedirect();
    $this->get('/dashboard')->assertOk();
    // O que uma sessão aberta em outro aparelho guarda (mesma senha de antes).
    $outroAparelho = session(SessionFingerprint::SESSION_KEY);
    $lembrarDeMim = $user->fresh()->getRememberToken();

    Livewire::test(Profile::class)
        ->set('currentPassword', 'SenhaAtual123')
        ->set('password', 'NovaSenhaForte456')
        ->set('passwordConfirmation', 'NovaSenhaForte456')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $this->get('/dashboard')->assertOk();
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRememberToken())->not->toBe($lembrarDeMim)->not->toBeEmpty();

    session()->put(SessionFingerprint::SESSION_KEY, $outroAparelho);

    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.session_ended')]);
    $this->assertGuest();
});

it('rejeita troca de senha com a senha atual errada', function () {
    $user = User::factory()->create(['password' => Hash::make('SenhaAtual123')]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('currentPassword', 'Errada')
        ->set('password', 'NovaSenhaForte456')
        ->set('passwordConfirmation', 'NovaSenhaForte456')
        ->call('updatePassword')
        ->assertHasErrors(['currentPassword']);

    expect(Hash::check('SenhaAtual123', (string) $user->fresh()->password))->toBeTrue();
});

it('define a senha de transação (hash separado)', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('transactionPassword', 'TransacaoNova1')
        ->set('transactionPasswordConfirmation', 'TransacaoNova1')
        ->call('updateTransactionPassword')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->hasTransactionPassword())->toBeTrue()
        ->and(Hash::check('TransacaoNova1', (string) $user->transaction_password))->toBeTrue();
});

it('exige a senha de transação ATUAL para alterá-la', function () {
    $user = User::factory()->withTransactionPassword()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('currentTransactionPassword', 'errada')
        ->set('transactionPassword', 'OutraTransacao2')
        ->set('transactionPasswordConfirmation', 'OutraTransacao2')
        ->call('updateTransactionPassword')
        ->assertHasErrors(['currentTransactionPassword']);
});

it('rejeita senha de transação igual à senha de login', function () {
    $user = User::factory()->create(['password' => Hash::make('MesmaSenha123')]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('transactionPassword', 'MesmaSenha123')
        ->set('transactionPasswordConfirmation', 'MesmaSenha123')
        ->call('updateTransactionPassword')
        ->assertHasErrors(['transactionPassword']);

    expect($user->fresh()->hasTransactionPassword())->toBeFalse();
});

it('faz upload do avatar pela função global de upload seguro', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('avatar', fixtureArquivoLivewire(fixtureBytesPng(), 'foto.png'))
        ->call('updateAvatar')
        ->assertHasNoErrors();

    // A foto é PESSOAL (sem conta): lida no modo sistema do teste.
    $upload = comoSistema(fn () => Upload::query()->sole());

    expect($upload->mime)->toBe('image/png')
        ->and($upload->path)->toStartWith('avatars/')
        ->and($user->fresh()->avatar_upload_id)->toBe($upload->id);

    Storage::disk('uploads-test')->assertExists((string) $upload->path);
})->group('uploads');

it('rejeita avatar que não é imagem de verdade (validação por conteúdo)', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('avatar', fixtureArquivoLivewire(fixtureBytesElf(), 'foto.png'))
        ->call('updateAvatar')
        ->assertHasErrors(['avatar']);

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0)
        ->and($user->fresh()->avatar_upload_id)->toBeNull();
})->group('uploads');

it('texto renomeado para .png é recusado com a mensagem da validação por conteúdo', function () {
    $user = User::factory()->create();

    // O Livewire (>= 4.4.2) detecta o MIME do upload temporário pelo conteúdo,
    // então as regras genéricas `image`/`mimes` também recusariam — com um
    // "deve ser uma imagem" que não explica nada. Quem responde é a SafeFile.
    // Em teste o Livewire não lê o conteúdo: usa o MIME do arquivo falso. Por
    // isso ele é declarado como o navegador real o faria chegar (text/plain).
    $arquivo = fixtureArquivoLivewire('isto aqui e texto puro, apenas renomeado para .png', 'nao-e-imagem.png')
        ->mimeType('text/plain');

    $component = Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('avatar', $arquivo)
        ->call('updateAvatar')
        ->assertHasErrors(['avatar']);

    $erros = $component->errors()->get('avatar');

    expect($erros)->toHaveCount(1)
        ->and(array_values(__('uploads.rejected')))->toContain($erros[0])
        ->and(comoSistema(fn (): int => Upload::query()->count()))->toBe(0)
        ->and($user->fresh()->avatar_upload_id)->toBeNull();
})->group('uploads');

// Ações Livewire não passam pelo `throttle:sensitive` da rota: o limite de
// senha errada vem das regras do pacote (PasswordConfirmation), por conta.

it('troca de senha: senha atual errada demais bloqueia, até com a certa', function () {
    config()->set('auth.password_confirmation.max_attempts', 2);
    $user = User::factory()->create(['password' => 'SenhaAtual123']);
    $perfil = Livewire::actingAs($user)->test(Profile::class);

    foreach (['Errada1', 'Errada2'] as $errada) {
        $perfil->set('currentPassword', $errada)
            ->set('password', 'NovaSenhaForte456')
            ->set('passwordConfirmation', 'NovaSenhaForte456')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword' => __('panel.profile.current_password_invalid')]);
    }

    $perfil->set('currentPassword', 'SenhaAtual123')
        ->set('password', 'NovaSenhaForte456')
        ->set('passwordConfirmation', 'NovaSenhaForte456')
        ->call('updatePassword')
        ->assertHasErrors('currentPassword');

    expect($perfil->errors()->first('currentPassword'))->toStartWith(rtrim((string) strstr(__('auth.password_confirmation.locked').':', ':', true)))
        ->and(Hash::check('SenhaAtual123', (string) $user->fresh()->password))->toBeTrue();
});

it('troca da senha de transação: a atual errada demais bloqueia, até com a certa', function () {
    config()->set('auth.password_confirmation.max_attempts', 2);
    $user = User::factory()->create(['transaction_password' => 'TransacaoAtual1', 'transaction_password_set_at' => now()]);
    $perfil = Livewire::actingAs($user)->test(Profile::class);

    foreach (['Errada111', 'Errada222'] as $errada) {
        $perfil->set('currentTransactionPassword', $errada)
            ->set('transactionPassword', 'TransacaoNova1')
            ->set('transactionPasswordConfirmation', 'TransacaoNova1')
            ->call('updateTransactionPassword')
            ->assertHasErrors(['currentTransactionPassword' => __('auth.transaction_password.current_invalid')]);
    }

    $perfil->set('currentTransactionPassword', 'TransacaoAtual1')
        ->call('updateTransactionPassword')
        ->assertHasErrors('currentTransactionPassword');

    expect($perfil->errors()->first('currentTransactionPassword'))->toStartWith(rtrim((string) strstr(__('auth.password_confirmation.locked').':', ':', true)))
        ->and(Hash::check('TransacaoAtual1', (string) $user->fresh()->transaction_password))->toBeTrue();
});

it('confirmação de ação sensível: senha de transação errada demais bloqueia o envio do código', function () {
    Mail::fake();
    config()->set('auth.password_confirmation.max_attempts', 2);
    $user = User::factory()->create(['transaction_password' => 'TransacaoAtual1', 'transaction_password_set_at' => now()]);
    $perfil = Livewire::actingAs($user)->test(Profile::class);

    foreach (['Errada111', 'Errada222'] as $errada) {
        $perfil->set('sensitivePassword', $errada)
            ->call('sendSensitiveCode')
            ->assertHasErrors(['sensitivePassword' => __('auth.transaction_password.invalid')]);
    }

    $perfil->set('sensitivePassword', 'TransacaoAtual1')
        ->call('sendSensitiveCode')
        ->assertHasErrors('sensitivePassword')
        ->assertSet('codeSent', false);

    expect($perfil->errors()->first('sensitivePassword'))->toStartWith(rtrim((string) strstr(__('auth.password_confirmation.locked').':', ':', true)));
    Mail::assertNothingQueued();
});

it('a senha de transação digitada no modal não fica no estado do componente', function () {
    Mail::fake();
    $user = User::factory()->create(['transaction_password' => 'TransacaoAtual1', 'transaction_password_set_at' => now()]);
    $perfil = Livewire::actingAs($user)->test(Profile::class);

    // Errou: sai do estado mesmo assim.
    $perfil->set('sensitivePassword', 'Errada111')
        ->call('sendSensitiveCode')
        ->assertHasErrors('sensitivePassword')
        ->assertSet('sensitivePassword', '');
    expect(json_encode($perfil->snapshot))->not->toContain('Errada111');

    // Acertou: o código foi enviado e a senha já não está no snapshot.
    $perfil->set('sensitivePassword', 'TransacaoAtual1')
        ->call('sendSensitiveCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true)
        ->assertSet('sensitivePassword', '');
    expect(json_encode($perfil->snapshot))->not->toContain('TransacaoAtual1');
    Mail::assertQueued(VerificationCodeMail::class);

    // Pedir outro código volta ao passo da senha.
    $perfil->call('resendSensitiveCode')
        ->assertSet('codeSent', false)
        ->assertSet('sensitiveCode', '');
});
