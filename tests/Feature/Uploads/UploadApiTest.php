<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Uploads\Enums\UploadStatus;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Uploads Seguros — endpoint da API v1.
//
// Todos os testes usam arquivos com CONTEÚDO REAL gerado programaticamente
// (tests/Fixtures/uploads.php): a validação é por magic bytes, não extensão.
// =============================================================================

beforeEach(function () {
    // Disco fake isolado por teste; o serviço é apontado para ele via config.
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');
});

/**
 * POST autenticado como tenant (par de chaves — ResolveTenant).
 *
 * @return TestResponse
 */
function postUpload(User $user, UploadedFile $file, array $scopes = ['*:*'])
{
    ['api_key' => $apiKey, 'secret_key' => $secret] = criarChave($user, ['scopes' => $scopes]);

    return test()->postJson('/api/v1/uploads', ['file' => $file], headersApi($apiKey, $secret));
}

it('aceita PDF legítimo: retorno padronizado + registro com tenant correto', function () {
    $user = User::factory()->create();
    $bytes = fixtureBytesPdf();

    $response = postUpload($user, fixtureArquivoEnviado($bytes, 'contrato-social.pdf'));

    $response->assertCreated()
        ->assertJsonPath('message', __('uploads.stored'))
        ->assertJsonPath('data.mime', 'application/pdf')
        ->assertJsonPath('data.size', strlen($bytes))
        ->assertJsonPath('data.sha256', hash('sha256', $bytes))
        ->assertJsonPath('data.status', UploadStatus::Stored->value)
        ->assertJsonPath('data.original_name', 'contrato-social.pdf')
        ->assertJsonStructure(['data' => ['uuid', 'codigo_publico', 'path', 'url', 'created_at']]);

    // Código público legível UPL-xxxxxx; id interno NUNCA exposto.
    expect($response->json('data.codigo_publico'))->toStartWith('UPL-')
        ->and($response->json('data.id'))->toBeNull();

    // Nome seguro: uuid + extensão do MIME REAL — nunca o nome original.
    $path = (string) $response->json('data.path');
    expect($path)->not->toContain('contrato-social')
        ->and(basename($path))->toMatch('/^[0-9a-f-]{36}\.pdf$/');

    // Registro em banco vinculado à CONTA da chave (a pessoal do dono) e a
    // quem enviou (a pessoa por trás da chave).
    $upload = comoSistema(fn () => Upload::query()->sole());
    expect($upload->account_id)->toBe(contaPessoal($user)->id)
        ->and($upload->created_by)->toBe($user->id)
        ->and($upload->disk)->toBe('uploads-test');

    Storage::disk('uploads-test')->assertExists($path);
});

it('aceita imagem legítima e a RE-ENCODA (payload trailing não sobrevive)', function () {
    $user = User::factory()->create();
    // PNG real + payload de trailing data (sem marcador de script — o que
    // elimina este payload é o RE-ENCODE GD, não a varredura).
    $bytes = fixtureBytesPng().'EVILPAYLOAD';

    $response = postUpload($user, fixtureArquivoEnviado($bytes, 'logo.png'));

    $response->assertCreated()
        ->assertJsonPath('data.mime', 'image/png');

    $path = (string) $response->json('data.path');
    expect(basename($path))->toMatch('/^[0-9a-f-]{36}\.png$/');

    // O conteúdo persistido é a imagem RE-GERADA: sem o payload embutido,
    // e o sha256 do retorno confere com o que está no disco.
    $stored = (string) Storage::disk('uploads-test')->get($path);
    expect($stored)->not->toContain('EVILPAYLOAD')
        ->and(hash('sha256', $stored))->toBe($response->json('data.sha256'));
});

it('rejeita PDF com JavaScript embutido (política: suspeita = não aceita)', function () {
    $user = User::factory()->create();

    $response = postUpload($user, fixtureArquivoEnviado(fixtureBytesPdfComJavaScript(), 'documento.pdf'));

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.errors.file.0', __('uploads.rejected.pdf_auto_action'));

    // Rejeitado NUNCA toca o banco nem o disco (só o log).
    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0)
        ->and(Storage::disk('uploads-test')->allFiles())->toBeEmpty();
});

it('rejeita executável renomeado para .pdf (magic bytes, não extensão)', function () {
    $user = User::factory()->create();

    // A camada de FORMULÁRIO (mimes: do Form Request) já barra: a regra do
    // Laravel sniffa o MIME real (finfo) e o ELF não casa com pdf. A camada
    // de segurança do serviço tem sua própria trava explícita de executável
    // (defesa em profundidade) — exercitada em SecureUploadServiceTest.
    $response = postUpload($user, fixtureArquivoEnviado(fixtureBytesElf(), 'boleto.pdf'));

    $response->assertUnprocessable()
        ->assertJsonStructure(['error' => ['errors' => ['file']]]);

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('rejeita imagem polyglot com PHP embutido', function () {
    $user = User::factory()->create();
    $bytes = fixtureBytesPng()."\n<?php system(\$_GET['c']);";

    $response = postUpload($user, fixtureArquivoEnviado($bytes, 'foto.png'));

    $response->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', __('uploads.rejected.embedded_script'));

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('rejeita extensão divergente do conteúdo real', function () {
    $user = User::factory()->create();
    // Conteúdo PNG real, extensão declarada .pdf → divergência = disfarce.
    $response = postUpload($user, fixtureArquivoEnviado(fixtureBytesPng(), 'documento.pdf'));

    $response->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', __('uploads.rejected.extension_mismatch'));

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('rejeita arquivo acima do tamanho máximo do tipo', function () {
    config()->set('uploads.types.pdf.max_kb', 1);
    $user = User::factory()->create();
    // PDF válido inflado com comentários (% é comentário em PDF) além de 1 KB.
    $bytes = fixtureBytesPdf()."\n%".str_repeat('A', 2048);

    $response = postUpload($user, fixtureArquivoEnviado($bytes, 'grande.pdf'));

    $response->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', __('uploads.rejected.too_large', ['max' => 1]));

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('rejeita conteúdo de texto disfarçado de imagem', function () {
    $user = User::factory()->create();

    $response = postUpload($user, fixtureArquivoEnviado('<?php echo 1;', 'avatar.png'));

    $response->assertUnprocessable()
        ->assertJsonStructure(['error' => ['errors' => ['file']]]);

    expect(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('exige o scope uploads:create', function () {
    $user = User::factory()->create();

    postUpload($user, fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf'), scopes: ['projects:read'])
        ->assertForbidden()
        ->assertJsonPath('error.message', __('api_keys.scopes.denied', ['scope' => 'uploads:create']));
});

it('exige credenciais de API válidas (deny-by-default)', function () {
    $this->postJson('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf')])
        ->assertUnauthorized();
});
