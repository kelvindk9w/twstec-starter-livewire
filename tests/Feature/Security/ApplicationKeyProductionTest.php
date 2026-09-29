<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Twstec\Kit\Foundation\Support\CriticalSecrets;
use Twstec\Kit\Foundation\Support\Exceptions\MissingApplicationKeyException;

// =============================================================================
// CHAVE DA APLICAÇÃO E SEGREDOS DE PLACEHOLDER — FAIL-CLOSED EM PRODUÇÃO
//
// O achado que originou estes testes: o entrypoint de produção GERAVA uma
// APP_KEY quando ela vinha vazia e a escrevia no `.env` de dentro do container.
// Como a imagem é a mesma para app, migrate, horizon e scheduler (e para cada
// réplica), cada container terminava com uma chave DIFERENTE — e, por morar na
// camada gravável, a chave também não sobrevivia ao restart.
//
// Nada disso dava erro na subida: o cast `encrypted` do nome do usuário
// simplesmente deixava de descriptografar, o cookie de sessão assinado por um
// container era rejeitado pelo outro, e o pepper do hash das chaves de API
// (que tem fallback para a APP_KEY) mudava junto, invalidando toda chave de
// API já emitida. Perda silenciosa de dado é pior que serviço que não sobe.
//
// A PRIMEIRA VERSÃO DESTA CORREÇÃO QUEBROU O CI, e por isso o contrato virou o
// centro destes testes. Sem `.env`, `config/app.php` resolve
// `env('APP_ENV', 'production')` — a aplicação se considera em PRODUÇÃO. Como
// `composer install` dispara `artisan package:discover`, um guard de recusa
// larga derrubava a instalação de dependências: no CI (que instala antes de
// criar o `.env`), no build da imagem de produção (que nunca tem `.env`) e no
// primeiro clone do kit. Hoje a recusa vale onde há dano real — processo que
// SERVE tráfego ou PROCESSA trabalho — e os comandos de instalação e
// manutenção recebem aviso alto em vez de exceção.
//
// Aqui se testa a camada da APLICAÇÃO, incluindo o caminho de instalação de
// verdade (subprocesso). A camada do shell (o entrypoint que aborta com código
// 78) e a do Compose (senhas sem fallback funcional) são provadas pelos
// comandos registrados em docs/producao.md, porque dependem de Docker e
// a suíte não pode depender dele.
// =============================================================================

/**
 * Chave legítima: 32 bytes aleatórios, o formato que o `key:generate` produz.
 */
function chaveDeVerdade(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/**
 * Finge que esta instalação é de produção — o mesmo sinal que o Laravel usa
 * para HTTPS forçado e que arma todas as proteções de ambiente do kit.
 */
function simulaProducaoDeSegredos(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

beforeEach(function (): void {
    // Estado de partida saudável: chave própria e nenhum segredo de fachada.
    // Cada teste estraga exatamente uma coisa.
    config()->set('app.key', chaveDeVerdade());
    config()->set('database.connections.pgsql.password', 'senha-longa-e-propria-9f2c');
    config()->set('database.redis.default.password', 'outra-senha-propria-4b71');
    config()->set('backup.backup.password', null);
    config()->set('api_keys.hash_pepper', 'pepper-dedicado-a1b2c3');
    config()->set('filesystems.disks.s3.secret', null);
});

// -----------------------------------------------------------------------------
// O CONTRATO: onde a recusa vale
//
// Função pura dos dois sinais do processo, justamente para que os dois ramos
// sejam verificáveis sem que o teste tenha de fingir ser php-fpm.
// -----------------------------------------------------------------------------

it('recusa sempre quando o processo está servindo tráfego', function (): void {
    // `runningInConsole` falso = php-fpm, servidor embutido, Octane. Atender
    // requisição com o segredo errado não tem meio-termo.
    expect(CriticalSecrets::refusalRequired(runningInConsole: false, command: null))->toBeTrue();
});

it('recusa nos comandos que processam trabalho de verdade', function (string $comando): void {
    expect(CriticalSecrets::refusalRequired(runningInConsole: true, command: $comando))->toBeTrue();
})->with([
    'queue:work',
    'queue:listen',
    'horizon',
    'horizon:work',
    'horizon:supervisor',
    'schedule:run',
    'schedule:work',
]);

it('NÃO recusa nos comandos de instalação e manutenção', function (string $comando): void {
    expect(CriticalSecrets::refusalRequired(runningInConsole: true, command: $comando))->toBeFalse();
})->with([
    // O que quebrou o CI: `composer install` dispara este comando no
    // post-autoload-dump, sem `.env` e portanto "em produção".
    'package:discover',
    'config:cache',
    'config:clear',
    'vendor:publish',
    'optimize',
    'about',
    // A saída: o comando que existe para consertar a ausência de chave.
    'key:generate',
    // Escreve ESQUEMA, não dado criptografado — e é o primeiro container do
    // compose de produção. Recusá-lo derrubaria o deploy num ponto em que nada
    // está em risco; o aviso dele é o alerta mais precoce da operação.
    'migrate',
    'migrate:status',
    // Sem comando nenhum (`php artisan` puro) também não processa nada.
    '',
]);

it('não recusa quando não há comando nenhum no argv', function (): void {
    expect(CriticalSecrets::refusalRequired(runningInConsole: true, command: null))->toBeFalse();
});

it('deixa a instalação declarar o próprio worker na lista, com curinga', function (): void {
    config()->set('security.secrets.processing_commands', ['meu-consumidor:*']);

    expect(CriticalSecrets::isProcessingCommand('meu-consumidor:pedidos'))->toBeTrue();
    expect(CriticalSecrets::isProcessingCommand('meu-relatorio:gerar'))->toBeFalse();
    // Com a lista trocada, o worker padrão deixa de ser reconhecido — prova de
    // que a lista é de verdade a fonte da decisão, e não um reforço dela.
    expect(CriticalSecrets::isProcessingCommand('queue:work'))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Onde a recusa vale: chave inutilizável estoura
// -----------------------------------------------------------------------------

it('recusa o boot quando a APP_KEY está ausente', function (): void {
    simulaProducaoDeSegredos();
    config()->set('app.key', null);

    expect(fn () => CriticalSecrets::apply(refuse: true))
        ->toThrow(MissingApplicationKeyException::class);

    expect(CriticalSecrets::applicationKeyUsable())->toBeFalse();
});

it('trata o prefixo base64: solto como ausência de chave', function (): void {
    simulaProducaoDeSegredos();
    config()->set('app.key', 'base64:');

    expect(fn () => CriticalSecrets::apply(refuse: true))
        ->toThrow(MissingApplicationKeyException::class);
});

it('recusa o boot quando a APP_KEY é um valor de placeholder', function (string $chave): void {
    simulaProducaoDeSegredos();
    config()->set('app.key', $chave);

    expect(fn () => CriticalSecrets::apply(refuse: true))
        ->toThrow(MissingApplicationKeyException::class);

    expect(CriticalSecrets::applicationKeyUsable())->toBeFalse();
})->with([
    'vocabulário' => 'troque-esta-chave',
    'vocabulário com prefixo' => 'base64:troque-esta-senha',
    'caixa diferente' => 'CHANGE-ME',
    // "Preenchi com qualquer coisa": 32 bytes nulos. É o placeholder que não
    // tem nome para entrar em lista nenhuma, e por isso é reconhecido pela
    // forma (bytes todos iguais), não pelo valor.
    'degenerado' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
]);

it('aceita placeholder declarado pela própria instalação no vocabulário', function (): void {
    simulaProducaoDeSegredos();
    config()->set('security.secrets.placeholders', ['minha-chave-temporaria']);
    config()->set('app.key', 'minha-chave-temporaria');

    expect(fn () => CriticalSecrets::apply(refuse: true))
        ->toThrow(MissingApplicationKeyException::class);
});

it('deixa o boot seguir quando a APP_KEY é própria', function (): void {
    simulaProducaoDeSegredos();

    CriticalSecrets::apply(refuse: true);

    expect(CriticalSecrets::applicationKeyUsable())->toBeTrue();
});

// -----------------------------------------------------------------------------
// Onde a recusa NÃO vale: aviso alto, sem exceção
// -----------------------------------------------------------------------------

it('avisa em voz alta, sem estourar, quando a recusa não se aplica', function (): void {
    simulaProducaoDeSegredos();
    config()->set('app.key', null);

    Log::spy();

    CriticalSecrets::apply(refuse: false);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'tolerada')
            && str_contains($mensagem, 'APP_KEY'))
        ->once();
});

it('não derruba o boot do provider num processo de console que não processa trabalho', function (): void {
    // A suíte roda em console, com argv apontando para o Pest — ou seja, o
    // mesmo perfil de processo de um `package:discover`. Se o guard estourasse
    // aqui, a regressão do CI estaria de volta.
    simulaProducaoDeSegredos();
    config()->set('app.key', null);

    CriticalSecrets::guard();

    expect(CriticalSecrets::applicationKeyUsable())->toBeFalse();
});

// -----------------------------------------------------------------------------
// O CAMINHO DE INSTALAÇÃO DE VERDADE (subprocesso)
//
// Estes dois são os testes que faltavam quando o CI reprovou: em vez de
// afirmar o contrato, executam o comando real no mesmo estado que o CI tinha —
// APP_ENV resolvida como `production` e nenhuma APP_KEY. As variáveis são
// passadas ao processo filho porque o Dotenv do Laravel é IMUTÁVEL: ele não
// sobrepõe variável que já existe no ambiente, então isto reproduz com
// fidelidade o "sem .env" do CI e do build da imagem.
// -----------------------------------------------------------------------------

it('não derruba `artisan package:discover` em produção sem chave — o caminho do composer install', function (): void {
    $resultado = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'APP_KEY' => ''])
        ->run('php artisan package:discover --no-ansi');

    expect($resultado->successful())->toBeTrue();
    expect($resultado->exitCode())->toBe(0);

    // O aviso vai para o stderr de propósito: um alerta que só existe em
    // storage/logs é invisível para quem está olhando a saída do build, e é
    // justamente essa pessoa que pode corrigir.
    expect($resultado->errorOutput())->toContain('[segredos]');
    expect($resultado->errorOutput())->toContain('APP_KEY');
});

it('derruba `artisan queue:work` em produção sem chave — o processo que trata dado real', function (): void {
    $resultado = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'APP_KEY' => ''])
        ->run('php artisan queue:work --once --no-ansi');

    expect($resultado->successful())->toBeFalse();
    expect($resultado->output().$resultado->errorOutput())
        ->toContain('MissingApplicationKeyException');
});

// -----------------------------------------------------------------------------
// Segredos de infraestrutura: aviso alto, sem recusar, em qualquer processo
// -----------------------------------------------------------------------------

it('avisa no log, sem recusar, quando um segredo de infraestrutura é placeholder', function (): void {
    simulaProducaoDeSegredos();
    config()->set('database.connections.pgsql.password', 'troque-esta-senha');
    config()->set('database.redis.default.password', 'troque-esta-senha');

    Log::spy();

    // Não recusa nem no modo de recusa: derrubar a aplicação não troca a senha
    // do Postgres. O que ela pode fazer é não deixar ninguém esquecer.
    CriticalSecrets::apply(refuse: true);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'DB_PASSWORD')
            && str_contains($mensagem, 'REDIS_PASSWORD'))
        ->once();
});

it('aponta cada segredo crítico pelo nome da variável de ambiente', function (string $caminho, string $variavel): void {
    simulaProducaoDeSegredos();
    config()->set($caminho, 'troque-esta-senha');

    expect(CriticalSecrets::infrastructureSecretsWithPlaceholder())->toContain($variavel);
})->with([
    ['database.connections.pgsql.password', 'DB_PASSWORD'],
    ['database.redis.default.password', 'REDIS_PASSWORD'],
    ['backup.backup.password', 'BACKUP_ARCHIVE_PASSWORD'],
    ['api_keys.hash_pepper', 'API_KEYS_HASH_PEPPER'],
    ['filesystems.disks.s3.secret', 'AWS_SECRET_ACCESS_KEY'],
]);

it('não aponta segredo vazio: ausência é uma decisão possível, valor público não', function (): void {
    simulaProducaoDeSegredos();
    config()->set('backup.backup.password', null);
    config()->set('filesystems.disks.s3.secret', '');

    expect(CriticalSecrets::infrastructureSecretsWithPlaceholder())->toBe([]);
});

it('não avisa nada quando todos os segredos são próprios', function (): void {
    simulaProducaoDeSegredos();

    Log::spy();

    CriticalSecrets::apply(refuse: true);

    Log::shouldNotHaveReceived('warning');
});
