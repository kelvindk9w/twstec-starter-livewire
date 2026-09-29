<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// TRAVA DO "ESQUECIMENTO" — contas com membros, no starter E nos cinco
// pacotes do kit (lidos de vendor/twstec/kit-*, como o aplicativo os usa).
//
// O isolamento é um escopo global nos models da conta: sem conta atual, a
// consulta lança exceção. O que poderia contornar isso reprova aqui:
//
// 1. `withoutGlobalScope(s)`, `newQueryWithoutScopes()`, `newModelQuery()` e
//    `->getQuery()` — consultas que pulam os escopos globais;
// 2. query builder cru nas tabelas das contas (`DB::table('projects')`,
//    `->from('api_keys')`, `->join('api_key_project', …)`, SQL literal) —
//    fora da classe dos gatilhos do banco do pacote de contas e das
//    migrations (que não são varridas);
// 3. modo sistema (`Accounts::asSystem` e `Accounts::systemModeForRequest`) ou conta explícita
//    (`Accounts::actingAs`) em lugar NÃO REVISADO — cada chamada aparece na
//    lista abaixo, com o porquê; chamada nova reprova até ser revisada e
//    entrar na lista;
// 4. a porta de baixo nível do contexto (o quadro de sistema) fora do pacote
//    de contas.
// =============================================================================

const ISOLATION_ACCOUNT_TABLES = ['accounts', 'account_memberships', 'account_invitations', 'projects', 'api_keys', 'api_key_project', 'uploads'];

/**
 * Chamadas de modo sistema REVISADAS (arquivo => quantas), com o motivo.
 */
const ISOLATION_SYSTEM_MODE_REVIEWED = [
    // API: a chave ainda não sabe de que conta é (busca pela chave pública).
    'vendor/twstec/kit-accounts/src/Tenancy/Middleware/ResolveTenant.php' => 1,
    // Comando diário: varre as chaves de todas as contas.
    'vendor/twstec/kit-accounts/src/ApiKeys/Console/ProcessApiKeyInactivity.php' => 1,
    // Excluir conta; arrumar o que a pessoa excluída deixou nas contas.
    // + ler, antes da exclusão da pessoa, as chaves que ela deixa órfãs em
    // contas alheias (para o aviso aos donos).
    'vendor/twstec/kit-accounts/src/Account/Services/AccountService.php' => 3,
    // O link de convite não diz de que conta é o convite: achar pelo hash do
    // token e marcá-lo como aceito/recusado (um ponto só).
    'vendor/twstec/kit-accounts/src/Account/Invitations/InvitationTokens.php' => 1,
    // Uploads (F8c). A foto de perfil é da PESSOA, não de uma conta: gravar
    // a foto pessoal (sem conta) e lê-la — restrita ao upload apontado pela
    // própria pessoa, foto pessoal ou da conta pessoal dela.
    'vendor/twstec/kit-uploads/src/Services/SecureUploadService.php' => 1,
    'vendor/twstec/kit-uploads/src/Concerns/HasAvatar.php' => 1,
    // Exclusão da pessoa ou da conta (LGPD): ler e apagar os uploads de
    // contas que somem — não são a conta atual de ninguém.
    'vendor/twstec/kit-uploads/src/Erasure/UploadEraser.php' => 1,
    // Limpeza agendada: varre os uploads sem dono de todas as contas.
    'vendor/twstec/kit-uploads/src/Console/PruneOrphanUploads.php' => 1,
    // O /admin inteiro (todas as requisições do painel, depois do acesso de
    // admin) — modo sistema da requisição (systemModeForRequest).
    'vendor/twstec/kit-admin/src/Http/Middleware/OperateAdminPanelAsSystem.php' => 1,
    // db:seed grava em várias contas.
    'database/seeders/DatabaseSeeder.php' => 1,
    // Seeders da demonstração, também quando rodados sozinhos (--class).
    'vendor/twstec/kit-demo/src/Database/Seeders/ProjectSeeder.php' => 1,
    'vendor/twstec/kit-demo/src/Database/Seeders/ApiKeySeeder.php' => 1,
    'vendor/twstec/kit-demo/src/Database/Seeders/UploadSeeder.php' => 1,
];

/**
 * Chamadas de conta explícita (Accounts::actingAs) REVISADAS no código de
 * produção. Hoje nenhuma: a conta vem da sessão, da chave ou do job.
 */
const ISOLATION_ACTING_AS_REVIEWED = [];

/**
 * @return array<string, string> caminho relativo ao starter => conteúdo
 */
function isolationSources(): array
{
    $base = base_path();
    $pastas = [$base.'/app', $base.'/database/seeders'];

    foreach (glob($base.'/vendor/twstec/kit-*/src', GLOB_ONLYDIR) ?: [] as $pacote) {
        $pastas[] = $pacote;
    }

    $arquivos = [];

    foreach ((new Finder)->files()->in($pastas)->name('*.php') as $file) {
        // Caminho pelo vendor (não pelo link do monorepo): é assim que o
        // aplicativo instalado vê o pacote.
        $caminho = str_replace($base.'/', '', $file->getPath().'/'.$file->getFilename());
        $arquivos[$caminho] = $file->getContents();
    }

    ksort($arquivos);

    return $arquivos;
}

function isolationWithoutComments(string $codigo): string
{
    $texto = '';

    foreach (PhpToken::tokenize($codigo) as $token) {
        if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
            $texto .= $token->text;
        }
    }

    return $texto;
}

/**
 * @return list<string>
 */
function isolationViolations(string $codigo): array
{
    $texto = isolationWithoutComments($codigo);
    $violacoes = [];

    foreach (['withoutGlobalScope', 'newQueryWithoutScopes', 'newModelQuery', '->getQuery()'] as $atalho) {
        if (str_contains($texto, $atalho)) {
            $violacoes[] = "usa {$atalho}";
        }
    }

    $tabelas = implode('|', ISOLATION_ACCOUNT_TABLES);

    if (preg_match("/(?:table|from|join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]({$tabelas})['\"]/", $texto, $m) === 1) {
        $violacoes[] = "consulta crua em {$m[1]}";
    }

    if (preg_match("/(?:select|statement|update|delete|insert|unprepared)\\(\\s*['\"][^'\"]*\\b(?:from|into|update|join)\\s+({$tabelas})\\b/i", $texto, $m) === 1) {
        $violacoes[] = "SQL literal em {$m[1]}";
    }

    return $violacoes;
}

/**
 * Quantas chamadas `Accounts::<método>(` há no código (tokens: comentário e
 * texto não contam) e quantas delas sem motivo literal.
 *
 * @return array{total: int, sem_motivo: int}
 */
function isolationStaticCalls(string $codigo, string $metodo): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($codigo),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));

    $total = 0;
    $semMotivo = 0;

    foreach ($tokens as $i => $token) {
        $nome = ltrim($token->text, '\\');

        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || ! ($nome === 'Accounts' || str_ends_with($nome, '\\Accounts'))) {
            continue;
        }

        if (($tokens[$i + 1]->text ?? '') !== '::' || ($tokens[$i + 2]->text ?? '') !== $metodo || ($tokens[$i + 3]->text ?? '') !== '(') {
            continue;
        }

        $total++;
        $primeiro = $tokens[$i + 4] ?? null;

        if ($primeiro === null || ! $primeiro->is(T_CONSTANT_ENCAPSED_STRING) || trim($primeiro->text, '\'" ') === '') {
            $semMotivo++;
        }
    }

    return ['total' => $total, 'sem_motivo' => $semMotivo];
}

it('nada no starter nem nos pacotes pula o escopo da conta ou consulta as tabelas das contas por fora', function (): void {
    $violacoes = [];

    foreach (isolationSources() as $caminho => $codigo) {
        if ($caminho === 'vendor/twstec/kit-accounts/src/Account/Support/AccountDatabaseGuards.php') {
            continue; // os gatilhos do banco são SQL de propósito
        }

        foreach (isolationViolations($codigo) as $problema) {
            $violacoes[] = "{$caminho}: {$problema}";
        }
    }

    expect($violacoes)->toBe([]);
});

it('modo sistema só onde foi revisado — e sempre com motivo', function (): void {
    $encontrado = [];

    foreach (isolationSources() as $caminho => $codigo) {
        $callback = isolationStaticCalls($codigo, 'asSystem');
        $requisicao = isolationStaticCalls($codigo, 'systemModeForRequest');
        $total = $callback['total'] + $requisicao['total'];
        $semMotivo = $callback['sem_motivo'] + $requisicao['sem_motivo'];

        expect($semMotivo)->toBe(0, "{$caminho}: modo sistema sem motivo literal");

        if ($total > 0) {
            $encontrado[$caminho] = $total;
        }
    }

    // Sem a demonstração instalada, os seeders dela não existem (e não contam).
    $revisado = array_filter(
        ISOLATION_SYSTEM_MODE_REVIEWED,
        fn (string $caminho): bool => is_file(base_path($caminho)),
        ARRAY_FILTER_USE_KEY,
    );
    ksort($encontrado);
    ksort($revisado);

    expect($encontrado)->toBe($revisado);
});

it('conta explícita (actingAs) no código de produção só onde foi revisado', function (): void {
    $encontrado = [];

    foreach (isolationSources() as $caminho => $codigo) {
        // A própria porta de entrada do pacote define o método.
        if ($caminho === 'vendor/twstec/kit-accounts/src/Accounts.php') {
            continue;
        }

        $total = isolationStaticCalls($codigo, 'actingAs')['total'];

        if ($total > 0) {
            $encontrado[$caminho] = $total;
        }
    }

    expect($encontrado)->toBe(ISOLATION_ACTING_AS_REVIEWED);
});

it('a porta de baixo nível do contexto (quadro de sistema, pilha) só é usada dentro do pacote de contas', function (): void {
    $fora = [];

    foreach (isolationSources() as $caminho => $codigo) {
        if (str_starts_with($caminho, 'vendor/twstec/kit-accounts/src/')) {
            continue;
        }

        if (preg_match('/runAsSystem\(|systemFrame\(|FRAME_SYSTEM|CurrentAccount::class\)->(?:push|popTo|runWith|runAs)\(/', isolationWithoutComments($codigo)) === 1) {
            $fora[] = $caminho;
        }
    }

    expect($fora)->toBe([]);
});

it('a trava não é cega: pega cada atalho e cada chamada, e ignora comentário', function (): void {
    expect(isolationViolations('<?php ApiKey::query()->withoutGlobalScopes()->get();'))->toBe(['usa withoutGlobalScope'])
        ->and(isolationViolations("<?php DB::table('api_keys')->where('id', 1)->update([]);"))->toBe(['consulta crua em api_keys'])
        ->and(isolationViolations("<?php DB::select('SELECT * FROM projects WHERE id = 1');"))->toBe(['SQL literal em projects'])
        ->and(isolationViolations("<?php // withoutGlobalScopes e DB::table('projects')\n\$a = 1;"))->toBe([])
        ->and(isolationStaticCalls("<?php Accounts::asSystem('x', fn () => 1); Accounts::asSystem(\$y, fn () => 1);", 'asSystem'))
        ->toBe(['total' => 2, 'sem_motivo' => 1])
        ->and(isolationStaticCalls("<?php \$t = 'Accounts::actingAs(\$c)'; Accounts::actingAs(\$c, fn () => 1);", 'actingAs')['total'])->toBe(1);
});
