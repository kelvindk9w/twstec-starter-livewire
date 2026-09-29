<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;

// =============================================================================
// SecureUploadService diretamente: exercita a camada de SEGURANÇA do
// arquivo sem passar pela camada de formulário (que tem seus próprios cortes
// e esconderia estes caminhos — ex.: o Form Request já barra ELF pelo MIME
// sniffado antes do serviço). Defesa em profundidade testada de verdade.
// =============================================================================

beforeEach(function () {
    Storage::fake('uploads-test');
    config()->set('uploads.disk', 'uploads-test');
});

/**
 * Executa o serviço e retorna a exceção de rejeição esperada.
 */
function esperarRejeicao(UploadedFile $file): UploadRejectedException
{
    try {
        // Na conta pessoal de alguém (o serviço exige conta atual).
        naConta(User::factory()->create(), fn () => app(SecureUploadService::class)->handle($file));
    } catch (UploadRejectedException $exception) {
        return $exception;
    }

    test()->fail('O upload deveria ter sido rejeitado.');
}

it('rejeita executável ELF mesmo com extensão .pdf (trava explícita)', function () {
    $exception = esperarRejeicao(fixtureArquivoEnviado(fixtureBytesElf(), 'boleto.pdf'));

    expect($exception->reason)->toBe('executable')
        ->and(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('rejeita conteúdo de texto puro disfarçado de PDF (mime_not_allowed)', function () {
    $exception = esperarRejeicao(fixtureArquivoEnviado('Relatorio de vendas do mes.', 'relatorio.pdf'));

    expect($exception->reason)->toBe('mime_not_allowed')
        ->and(comoSistema(fn (): int => Upload::query()->count()))->toBe(0);
});

it('rejeita arquivo vazio', function () {
    $exception = esperarRejeicao(fixtureArquivoEnviado('', 'vazio.pdf'));

    expect($exception->reason)->toBe('empty');
});

it('rejeita imagem acima do teto de pixels (decompression bomb)', function () {
    config()->set('uploads.types.image.max_pixels', 1000);

    // PNG 100×100 gerado em runtime (10.000 pixels > teto de 1.000).
    $image = imagecreatetruecolor(100, 100);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    $exception = esperarRejeicao(fixtureArquivoEnviado($bytes, 'foto.png'));

    expect($exception->reason)->toBe('image_too_many_pixels');
});

it('deriva a extensão do MIME real, nunca do nome original', function () {
    // JPEG declarado como .jpeg: extensão final canônica é .jpg (config).
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    $upload = naConta(User::factory()->create(), fn () => app(SecureUploadService::class)->handle(
        fixtureArquivoEnviado($bytes, 'foto.jpeg'),
    ));

    expect($upload->mime)->toBe('image/jpeg')
        ->and(basename((string) $upload->path))->toMatch('/^[0-9a-f-]{36}\.jpg$/');
});
