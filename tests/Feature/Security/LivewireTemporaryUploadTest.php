<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileNotPreviewableException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Livewire\WithFileUploads;

// =============================================================================
// UPLOAD TEMPORÁRIO DO LIVEWIRE (config/livewire.php → temporary_file_upload).
//
// Antes de chegar ao SecureUploadService, o arquivo passa pelo upload
// temporário do Livewire, que tem regras e pré-visualização próprias. O
// padrão do pacote aceita arquivo de até 12 MB e pré-visualiza SVG
// (documento que carrega script), áudio e vídeo. O kit usa o maior limite dos
// uploads seguros e restringe a pré-visualização a imagem raster. O TIPO é
// decidido depois, pela regra por conteúdo (SafeFile), com a mensagem dela.
// =============================================================================

/**
 * Componente mínimo com upload — o mesmo caminho do campo de avatar do perfil.
 */
function componenteComUpload(): Component
{
    return new class extends Component
    {
        use WithFileUploads;

        public ?TemporaryUploadedFile $arquivo = null;

        public function render(): string
        {
            return '<div></div>';
        }
    };
}

it('a pré-visualização é só de imagem raster: nada de SVG, áudio ou vídeo', function (): void {
    expect(config('livewire.temporary_file_upload.preview_mimes'))->toBe(['png', 'jpg', 'jpeg', 'webp']);
});

it('as regras do upload temporário usam o maior limite por tipo dos uploads seguros (não os 12 MB do Livewire)', function (): void {
    $maior = max((int) config('uploads.types.image.max_kb', 5120), (int) config('uploads.types.pdf.max_kb', 10240));

    expect(FileUploadConfiguration::rules())->toBe(['required', 'file', 'max:'.$maior]);
});

it('SVG com script no upload temporário nunca vira pré-visualização (nem URL para abrir no domínio da aplicação)', function (): void {
    Storage::fake('tmp-for-tests');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

    $temporario = Livewire::test(componenteComUpload())
        ->set('arquivo', UploadedFile::fake()->createWithContent('logo.svg', $svg)->mimeType('image/svg+xml'))
        ->get('arquivo');

    expect($temporario)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($temporario->isPreviewable())->toBeFalse()
        ->and(fn () => $temporario->temporaryUrl())->toThrow(FileNotPreviewableException::class);
});

it('imagem raster passa e ganha pré-visualização assinada', function (): void {
    Storage::fake('tmp-for-tests');

    $componente = Livewire::test(componenteComUpload())
        ->set('arquivo', UploadedFile::fake()->image('foto.png', 10, 10))
        ->assertHasNoErrors();

    $arquivo = $componente->get('arquivo');

    expect($arquivo)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($arquivo->temporaryUrl())->toContain('signature=');
});
