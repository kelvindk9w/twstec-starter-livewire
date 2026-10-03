<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Idempotency-Key no POST /api/v1/uploads (twstec/kit-uploads) — ver
// docs/uploads.md e docs/api.md, "Idempotência".
//
// O mesmo arquivo com a mesma chave vira replay: um registro e um arquivo no
// armazenamento. Arquivo diferente com a mesma chave: 422. A URL assinada é
// credencial enquanto vale: não é guardada nem volta na repetição.
// =============================================================================

beforeEach(function (): void {
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');
});

it('o mesmo arquivo com a mesma chave: um upload e um arquivo; o replay vem sem a URL assinada', function (): void {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $headers = [...headersApi($key, $secret), 'Idempotency-Key' => 'upload-2026-0001-abcdefgh'];

    $first = $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], [...$headers, 'Accept' => 'application/json']);
    $replay = $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], [...$headers, 'Accept' => 'application/json']);

    $first->assertCreated();
    // A URL que a resposta original assinou (no disco falso, só com a validade).
    $url = (string) $first->json('data.url');
    $nomeNoDisco = basename((string) parse_url($url, PHP_URL_PATH));
    expect($url)->toContain('expiration=');

    $replay->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.uuid', $first->json('data.uuid'))
        ->assertJsonPath('data.size', $first->json('data.size'))
        ->assertJsonPath('data.sha256', $first->json('data.sha256'))
        ->assertJsonPath('idempotency.body_withheld', true)
        ->assertJsonMissingPath('data.url')
        ->assertJsonMissingPath('data.path');

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(1)
        ->and(Storage::disk('uploads-test')->allFiles())->toHaveCount(1)
        ->and($replay->getContent())->not->toContain('expiration=')
        ->and($replay->getContent())->not->toContain($nomeNoDisco)
        ->and(json_encode(DB::table(IdempotencyStore::TABLE)->get()))->not->toContain($nomeNoDisco)
        ->and(Crypt::decryptString((string) DB::table(IdempotencyStore::TABLE)->value('response')))->not->toContain($nomeNoDisco);
});

it('arquivo diferente com a mesma chave: 422 idempotency_key_reused e nada gravado', function (): void {
    $user = User::factory()->create();
    ['api_key' => $key, 'secret_key' => $secret] = criarChave($user);
    $headers = [...headersApi($key, $secret), 'Idempotency-Key' => 'upload-2026-0002-abcdefgh', 'Accept' => 'application/json'];

    $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], $headers)->assertCreated();

    assertErroApi(
        $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPng(), 'nota.pdf')], $headers),
        422,
        'idempotency_key_reused',
    );

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(1)
        ->and(Storage::disk('uploads-test')->allFiles())->toHaveCount(1);
});
