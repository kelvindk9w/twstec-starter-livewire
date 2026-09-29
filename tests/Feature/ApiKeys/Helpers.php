<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;

// =============================================================================
// Helpers compartilhados da suíte de API Keys/Tenancy.
// Arquivo sem testes — apenas funções usadas pelos demais arquivos da suíte.
// =============================================================================

/**
 * Cria uma chave de API real via service (o caminho de produção) e retorna
 * a chave + a secreta em claro (que só existe neste momento). A chave é da
 * CONTA PESSOAL da pessoa (ou da conta dada) e a pessoa é quem a criou.
 *
 * @param  array{name?: string, scopes?: list<string>|null, expires_at?: string|null, project_uuids?: list<string>|null}  $data
 * @return array{api_key: ApiKey, secret_key: string}
 */
function criarChave(User $user, array $data = [], ?Account $conta = null): array
{
    return Accounts::actingAs(
        $conta ?? contaPessoal($user),
        fn (): array => app(ApiKeyService::class)->create($user, ['name' => 'Chave de teste', ...$data]),
        $user,
    );
}

/**
 * A conta pessoal da pessoa (criada junto com ela pelo pacote de contas).
 */
function contaPessoal(User $user): Account
{
    return app(AccountService::class)->personalAccountOf($user)
        ?? throw new LogicException('Pessoa sem conta pessoal.');
}

/**
 * Roda o arranjo do teste dentro da conta pessoal da pessoa (como o painel
 * dela faria).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function naConta(User $user, Closure $callback): mixed
{
    return Accounts::actingAs(contaPessoal($user), $callback, $user);
}

/**
 * Leitura/gravação direta do teste SEM filtro de conta — o que toda consulta
 * de teste fazia antes das contas (o escopo das contas lança exceção sem
 * conta atual). Só para o arranjo e as conferências do próprio teste.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function comoSistema(Closure $callback): mixed
{
    return Accounts::asSystem('teste', $callback);
}

/**
 * Projeto na conta pessoal da pessoa, criado por ela.
 */
function projetoDe(User $user, string $nome): Project
{
    return naConta($user, fn (): Project => Project::createWithPublicCodeRetry(['name' => $nome]));
}

/**
 * Headers de autenticação da API: pk_ no X-Api-Key + sk_ no Bearer.
 *
 * @return array<string, string>
 */
function headersApi(ApiKey $apiKey, string $secretKey): array
{
    return [
        'X-Api-Key' => $apiKey->public_key,
        'Authorization' => 'Bearer '.$secretKey,
    ];
}

/**
 * Emite um token de ação sensível pelo FLUXO REAL da ação sensível (senha de
 * transação + código de 6 dígitos capturado do e-mail com Mail::fake).
 * Exige usuário criado com withTransactionPassword() e Mail::fake() ativo.
 */
function tokenAcaoSensivel(User $user): string
{
    test()->actingAs($user)->postJson('/sensitive-actions/code', [
        'transaction_password' => 'Trans4cao!Segura',
    ])->assertOk();

    $codigo = null;

    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    $response = test()->actingAs($user)->postJson('/sensitive-actions/confirm', ['code' => $codigo]);

    $response->assertOk();

    return (string) $response->json('token');
}

/**
 * Envelope de ERRO da API (ver ApiErrorRenderer e docs/api.md):
 * {"error": {"code", "message", "correlation_id"}}, com "errors" em 422.
 *
 * Estes helpers existem para que a suíte inteira afirme o MESMO contrato:
 * se o envelope mudar, muda em um lugar só.
 */
function assertErroApi(TestResponse $response, int $status, string $code): TestResponse
{
    return $response->assertStatus($status)
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']])
        ->assertJsonPath('error.code', $code);
}

/**
 * 422 com erro no campo informado (a chave pode ter ponto — "scopes.0" —,
 * por isso a checagem é por chave literal, não por caminho aninhado).
 */
function assertErroDeValidacaoApi(TestResponse $response, string $campo): TestResponse
{
    assertErroApi($response, 422, 'validation_failed');

    /** @var array<string, mixed> $errors */
    $errors = $response->json('error.errors') ?? [];

    expect($errors)->toHaveKey($campo);

    return $response;
}
