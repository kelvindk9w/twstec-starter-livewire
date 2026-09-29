<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Security\AttackDetector;
use Twstec\Kit\Foundation\Security\RequestInputs;

// Cobertura da inspeção (R4): cada fonte que a aplicação pode LER precisa ser
// inspecionada. Os testes rodam no modo `block` porque nele um buraco de
// cobertura aparece como requisição que passa (200 em vez de 422).

beforeEach(function () {
    config()->set('security.validation.mode', 'block');

    Route::post('/api/_test/echo', fn (Request $request) => response()->json([
        'query' => $request->query('q'),
        'body' => $request->post('q'),
    ]));
});

it('inspeciona a query mesmo quando o corpo tem um campo com o mesmo nome', function () {
    // Antes: a visão mesclada (`input()`) deixava o corpo esconder a query, e
    // a rota que lê `$request->query('q')` recebia um valor não inspecionado.
    $this->post('/api/_test/echo?q='.rawurlencode('<script>alert(1)</script>'), ['q' => 'limpo'])
        ->assertUnprocessable();

    // A evidência guarda o valor escondido, neutralizado.
    $payload = RequestLog::query()->sole()->payload;

    expect($payload['q'])->toBe('limpo')
        ->and($payload['_query']['q'])->toContain('&lt;script&gt;');
});

it('inspeciona o campo de texto irmão de um arquivo no mesmo array', function () {
    // Antes: a chave inteira do array com arquivo era trocada pelos metadados
    // e o texto ao lado dele sumia da inspeção e da trilha.
    $this->post('/api/_test/echo', [
        'docs' => [[
            'file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
            'caption' => '<script>alert(1)</script>',
        ]],
    ])->assertUnprocessable();

    expect(RequestLog::query()->sole()->payload['docs'][0])
        ->toHaveKeys(['file', 'caption']);
});

it('a trilha mantém texto e metadados do arquivo lado a lado', function () {
    $request = Request::create('/x', 'POST', ['docs' => [['caption' => 'legenda']]], [], [
        'docs' => [['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')]],
    ]);

    $data = RequestInputs::extract($request);

    expect($data['docs'][0]['caption'])->toBe('legenda')
        ->and($data['docs'][0]['file']['nome_arquivo'])->toBe('a.pdf');
});

it('inspeciona o nome do arquivo enviado (metadado)', function () {
    $this->post('/api/_test/echo', [
        'arquivo' => UploadedFile::fake()->create('"><img src=x onerror=alert(1)>.png', 1),
    ])->assertUnprocessable();
});

it('inspeciona corpo não estruturado (texto puro e JSON com Content-Type errado)', function (string $conteudo) {
    $this->call('POST', '/api/_test/echo', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $conteudo)
        ->assertUnprocessable();
})->with([
    'texto' => ['<script>alert(1)</script>'],
    'json como texto' => ['{"nome":"<script>alert(1)</script>"}'],
]);

it('inspeciona JSON aninhado, inclusive nomes de campo', function (array $corpo) {
    $this->postJson('/api/_test/echo', $corpo)->assertUnprocessable();
})->with([
    'valor fundo' => [['a' => ['b' => ['c' => ['d' => "1' OR '1'='1"]]]]],
    'nome de campo' => [['filtro' => ['<script>alert(1)</script>' => 'x']]],
]);

it('inspeciona os cabeçalhos configurados e guarda a evidência neutralizada', function () {
    $this->postJson('/api/_test/echo', ['ok' => 1], ['Referer' => 'https://x.example/"><script>alert(1)</script>'])
        ->assertUnprocessable();

    $log = RequestLog::query()->sole();

    expect($log->attack_type)->toBe('xss')
        ->and($log->payload['_cabecalhos']['referer'])->toContain('&lt;script&gt;')
        ->and(json_encode($log->payload))->not->toContain('<script>');
});

it('o User-Agent da tentativa é gravado escapado', function () {
    $this->postJson('/api/_test/echo', ['ok' => 1], ['User-Agent' => '<script>alert(1)</script>'])
        ->assertUnprocessable();

    expect(RequestLog::query()->sole()->user_agent)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
});

it('cabeçalho fora da lista não é inspecionado; lista vazia desliga os cabeçalhos', function () {
    $this->postJson('/api/_test/echo', ['ok' => 1], ['X-Qualquer' => '<script>alert(1)</script>'])->assertOk();

    config()->set('security.validation.inspected_headers', []);

    $this->postJson('/api/_test/echo', ['ok' => 1], ['Referer' => '<script>alert(1)</script>'])->assertOk();
});

it('inspeciona o caminho decodificado (parâmetros de rota) sem gravar o caminho', function () {
    Route::get('/api/_test/item/{slug}', fn (string $slug) => response()->json(['slug' => $slug]));

    $this->get('/api/_test/item/'.rawurlencode("1' OR '1'='1"))->assertUnprocessable();

    $log = RequestLog::query()->sole();

    expect($log->attack_type)->toBe('sqli')
        ->and($log->payload['_detectado_em'])->toBe('caminho')
        ->and($log->endpoint)->not->toContain('OR');
});

it('falha do motor de regex não é "limpo": vira inspection_error', function () {
    $limite = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $tipo = (new AttackDetector)->detectInString('<a href="x" title="texto qualquer bem comprido">');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limite);
    }

    expect($tipo)->toBe(AttackDetector::INSPECTION_ERROR);
});
