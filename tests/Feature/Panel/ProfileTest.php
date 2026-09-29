<?php

declare(strict_types=1);

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
