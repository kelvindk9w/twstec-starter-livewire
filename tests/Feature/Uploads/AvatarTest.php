<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Avatar do perfil (web autenticada): prova o reuso da MESMA função
// global de upload fora da API. A foto é da PESSOA (upload pessoal, sem
// conta, enviado por quem está logado) e o destino é restrito a imagens
// (re-encode GD obrigatório).
// =============================================================================

beforeEach(function () {
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');
});

it('atualiza o avatar com imagem legítima, vinculada ao usuário da sessão', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/settings/avatar', [
        'avatar' => fixtureArquivoEnviado(fixtureBytesPng(), 'minha-foto.png'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', __('uploads.avatar_updated'))
        ->assertJsonPath('data.mime', 'image/png')
        ->assertJsonStructure(['data' => ['uuid', 'codigo_publico', 'path', 'url', 'sha256']]);

    $upload = comoSistema(fn () => Upload::query()->sole());
    expect($upload->created_by)->toBe($user->id)
        ->and($upload->personal)->toBeTrue()
        ->and($upload->account_id)->toBeNull()
        ->and($user->fresh()->avatar_upload_id)->toBe($upload->id)
        ->and($upload->path)->toStartWith('avatars/')
        ->and(basename((string) $upload->path))->toMatch('/^[0-9a-f-]{36}\.png$/');

    Storage::disk('uploads-test')->assertExists((string) $upload->path);
});

it('rejeita PDF no avatar (endpoint restrito a imagens)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/settings/avatar', [
        'avatar' => fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.png'),
    ])->assertUnprocessable();

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('exige autenticação (deny-by-default)', function () {
    $this->postJson('/settings/avatar', [
        'avatar' => fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png'),
    ])->assertUnauthorized();
});
