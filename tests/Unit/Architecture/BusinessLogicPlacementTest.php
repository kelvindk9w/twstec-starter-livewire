<?php

declare(strict_types=1);

// =============================================================================
// ONDE MORA A REGRA DE NEGÓCIO — a trava contra a regra voltar para a tela.
//
// O kit vai servir a mais de um front (Livewire hoje, React depois). Regra que
// mora num controller ou numa tela é regra que o próximo front duplica. Este
// arquivo reprova o build quando:
//
// 1. um controller de autenticação volta a fazer o trabalho das Actions:
//    autenticar ou validar senha (Auth), mexer no broker de senha (Password),
//    no limiter (RateLimiter), no hash, no banco, disparar evento de auth,
//    abrir/ler o estado intermediário do segundo fator, gravar model ou mexer
//    na sessão (regenerar, invalidar, renovar token);
// 2. uma tela ou controller que já passou para um serviço volta a consultar
//    ou gravar model direto (chamada estática em App\Core\*\Models\*,
//    App\Models\* ou Twstec\Kit\*\Models\*).
//
// A leitura é por tokens do PHP: comentários e strings não contam.
// =============================================================================

/**
 * Controllers que só fazem HTTP: a regra está nas Actions de Twstec\Kit\Auth\Actions.
 * Os que recebem os formulários são do pacote twstec/kit-auth (lidos pelo
 * vendor/, que no desenvolvimento é um link para packages/auth); as telas são
 * do starter (AuthPageController).
 */
const THIN_AUTH_CONTROLLERS = [
    'vendor/twstec/kit-auth/src/Http/Controllers/AuthenticatedSessionController.php',
    'vendor/twstec/kit-auth/src/Http/Controllers/EmailVerificationController.php',
    'vendor/twstec/kit-auth/src/Http/Controllers/NewPasswordController.php',
    'vendor/twstec/kit-auth/src/Http/Controllers/PasswordResetLinkController.php',
    'vendor/twstec/kit-auth/src/Http/Controllers/RegisteredUserController.php',
    'vendor/twstec/kit-auth/src/Http/Controllers/TwoFactorChallengeController.php',
    'app/Http/Controllers/Auth/AuthPageController.php',
    // Contas com membros: os envios do link de convite e a troca de conta
    // (regra nas Actions de Twstec\Kit\Accounts\Account\Actions) e as telas
    // do starter que os chamam.
    'vendor/twstec/kit-accounts/src/Account/Http/Controllers/InvitationController.php',
    'vendor/twstec/kit-accounts/src/Account/Http/Controllers/AccountSwitchController.php',
    'app/Http/Controllers/Accounts/InvitationPageController.php',
    'app/Http/Controllers/Accounts/OpenAccountController.php',
];

/**
 * Classes que esses controllers não podem nomear (prefixo ou nome exato).
 */
const THIN_AUTH_FORBIDDEN_CLASSES = [
    'Illuminate\Support\Facades\Auth',
    'Illuminate\Support\Facades\Password',
    'Illuminate\Support\Facades\RateLimiter',
    'Illuminate\Support\Facades\Hash',
    'Illuminate\Support\Facades\DB',
    'Illuminate\Auth\Events\\',
    'Twstec\Kit\Auth\Support\PendingTwoFactorLogin',
];

/**
 * Métodos que esses controllers não podem chamar: gravação de model e
 * segurança da sessão são da Action.
 */
const THIN_AUTH_FORBIDDEN_METHODS = [
    'save', 'forceFill', 'markEmailAsVerified', 'createWithPublicCodeRetry',
    'regenerate', 'invalidate', 'regenerateToken', 'login', 'logout',
];

/**
 * Telas e controllers que falam com um serviço/consulta de backend e não
 * podem chamar model direto.
 */
const SERVICE_BACKED_ENTRY_POINTS = [
    'app/Livewire/Dashboard.php',
    'app/Livewire/Projects/Index.php',
    'vendor/twstec/kit-accounts/src/Tenancy/Http/Controllers/ProjectController.php',
    // Contas com membros: telas sobre as Actions e o AccountDirectory.
    'app/Livewire/Account/Show.php',
    'app/Livewire/Account/Create.php',
    'app/Livewire/Support/AccountMenu.php',
    'app/Http/Controllers/Accounts/InvitationPageController.php',
    'app/Http/Controllers/Accounts/OpenAccountController.php',
    'vendor/twstec/kit-accounts/src/Account/Http/Controllers/InvitationController.php',
    'vendor/twstec/kit-accounts/src/Account/Http/Controllers/AccountSwitchController.php',
];

/**
 * Mapa apelido => nome completo dos `use` de classe de um arquivo PHP.
 *
 * @return array<string, string>
 */
function placementImports(string $contents): array
{
    $imports = [];

    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $contents, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $name = $match[1];
        $alias = $match[2] ?? '';
        $imports[$alias !== '' ? $alias : substr((string) strrchr('\\'.$name, '\\'), 1)] = $name;
    }

    return $imports;
}

/**
 * Tokens significativos (sem espaço e comentário).
 *
 * @return list<PhpToken>
 */
function placementTokens(string $contents): array
{
    return array_values(array_filter(
        PhpToken::tokenize($contents),
        fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
}

/**
 * Nomes completos de classe que o arquivo usa (imports e nomes qualificados).
 *
 * @return list<string>
 */
function placementClassNames(string $contents): array
{
    $names = array_values(placementImports($contents));

    foreach (placementTokens($contents) as $token) {
        if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $names[] = ltrim($token->text, '\\');
        }
    }

    return array_values(array_unique($names));
}

/**
 * Chamadas estáticas (`Classe::metodo`, `Classe::query`…) a models do app,
 * com o nome resolvido pelos imports. `Classe::class` não conta.
 *
 * @return list<string>
 */
function placementModelStaticCalls(string $contents): array
{
    $imports = placementImports($contents);
    $tokens = placementTokens($contents);
    $calls = [];

    foreach ($tokens as $i => $token) {
        if (! $token->is(T_DOUBLE_COLON) || ! isset($tokens[$i - 1], $tokens[$i + 1])) {
            continue;
        }

        $class = ltrim($tokens[$i - 1]->text, '\\');
        $member = $tokens[$i + 1]->text;

        if ($member === 'class') {
            continue;
        }

        $resolved = $imports[$class] ?? $class;

        // Models do app (App\Core\<Módulo>\Models, App\Models) e dos pacotes
        // do kit (Twstec\Kit\<Pacote>\Models).
        if (preg_match('/^(App\\\\Core\\\\[^\\\\]+\\\\|App\\\\|Twstec\\\\Kit\\\\[^\\\\]+\\\\)Models\\\\/', $resolved) === 1) {
            $calls[] = "{$resolved}::{$member}";
        }
    }

    return $calls;
}

/**
 * Métodos chamados em objeto (`->metodo(` e `?->metodo(`).
 *
 * @return list<string>
 */
function placementMethodCalls(string $contents): array
{
    $tokens = placementTokens($contents);
    $calls = [];

    foreach ($tokens as $i => $token) {
        if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && isset($tokens[$i + 1], $tokens[$i + 2])
            && $tokens[$i + 1]->is(T_STRING)
            && $tokens[$i + 2]->text === '(') {
            $calls[] = $tokens[$i + 1]->text;
        }
    }

    return $calls;
}

/**
 * O arquivo está ao alcance desta instalação? Só um arquivo de PACOTE OPCIONAL
 * não instalado (vendor/twstec/<pacote>/ ausente — ver `php artisan
 * tws:install`) fica de fora; qualquer outro arquivo da lista que suma
 * continua reprovando (file_get_contents falha alto).
 */
function placementPathInstalled(string $path): bool
{
    if (preg_match('#^vendor/twstec/([^/]+)/#', $path, $match) === 1) {
        return is_dir(base_path("vendor/twstec/{$match[1]}"));
    }

    return true;
}

it('mantém os controllers de autenticação só com HTTP (a regra está nas Actions)', function (): void {
    $violations = [];

    foreach (array_filter(THIN_AUTH_CONTROLLERS, placementPathInstalled(...)) as $path) {
        $contents = (string) file_get_contents(base_path($path));

        foreach (placementClassNames($contents) as $name) {
            foreach (THIN_AUTH_FORBIDDEN_CLASSES as $forbidden) {
                if ($name === $forbidden || (str_ends_with($forbidden, '\\') && str_starts_with($name, $forbidden))) {
                    $violations[] = "{$path} usa {$name}";
                }
            }
        }

        foreach (array_intersect(placementMethodCalls($contents), THIN_AUTH_FORBIDDEN_METHODS) as $method) {
            $violations[] = "{$path} chama ->{$method}()";
        }

        foreach (placementModelStaticCalls($contents) as $call) {
            $violations[] = "{$path} chama {$call}";
        }
    }

    expect($violations)->toBe([]);
});

it('mantém telas e controllers com serviço de backend sem chamar model direto', function (): void {
    $violations = [];

    foreach (array_filter(SERVICE_BACKED_ENTRY_POINTS, placementPathInstalled(...)) as $path) {
        foreach (placementModelStaticCalls((string) file_get_contents(base_path($path))) as $call) {
            $violations[] = "{$path} chama {$call}";
        }
    }

    expect($violations)->toBe([]);
});

it('a leitura por tokens pega o que deve pegar (a trava não é cega)', function (): void {
    $sample = <<<'PHP'
        <?php
        use App\Core\Tenancy\Models\Project as P;
        use Illuminate\Support\Facades\Auth;
        // Project::query() em comentário não conta
        $a = P::query()->where('x', 1);
        $b = P::class;
        $request->session()->regenerate();
        PHP;

    expect(placementModelStaticCalls($sample))->toBe(['App\Core\Tenancy\Models\Project::query'])
        ->and(placementMethodCalls($sample))->toContain('regenerate', 'session', 'where')
        ->and(placementClassNames($sample))->toContain('Illuminate\Support\Facades\Auth');
});
