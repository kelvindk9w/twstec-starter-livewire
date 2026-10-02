<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// TESTES DE MÓDULO OPCIONAL NÃO DERRUBAM A SUÍTE SEM O MÓDULO.
//
// O teste de um módulo opcional (twstec/kit-accounts, twstec/kit-uploads,
// twstec/kit-admin) fica no grupo do módulo e PULA sozinho quando ele não está
// instalado (tests/TestCase.php). Mas o Pest carrega TODOS os arquivos antes
// de decidir o que pula: uma classe declarada no TOPO de um arquivo de teste
// que estende ou implementa um tipo de módulo opcional (ou do Filament, que
// só vem com o /admin) derruba o carregamento da suíte inteira numa
// instalação sem ele ("Interface ... not found"). Foi o que aconteceu na
// simulação "Livewire só a base" do CI da 2.0.0-beta.10.
//
// A regra: classe de apoio que depende de módulo opcional nasce DENTRO do
// teste (classe anônima, `new class implements ...`), nunca no topo.
// =============================================================================

const OPTIONAL_MODULE_PREFIXES = ['Twstec\\Kit\\Accounts\\', 'Twstec\\Kit\\Uploads\\', 'Twstec\\Kit\\Admin\\', 'Filament\\'];

/**
 * Classes declaradas no topo do arquivo que estendem/implementam um tipo de
 * módulo opcional.
 *
 * @return list<string>
 */
function topLevelOptionalModuleClasses(string $codigo): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($codigo), fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
    $imports = [];
    $profundidade = 0;
    $violacoes = [];

    foreach ($tokens as $i => $token) {
        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $profundidade++;
        } elseif ($token->text === '}') {
            $profundidade--;
        }

        if ($profundidade === 0 && $token->is(T_USE) && $tokens[$i + 1]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
            $nome = ltrim($tokens[$i + 1]->text, '\\');
            $apelido = ($tokens[$i + 2]->is(T_AS) ? $tokens[$i + 3]->text : substr((string) strrchr('\\'.$nome, '\\'), 1));
            $imports[$apelido] = $nome;
        }

        if ($profundidade !== 0 || ! $token->is([T_CLASS, T_ENUM, T_TRAIT, T_INTERFACE]) || (($tokens[$i - 1] ?? null)?->is(T_NEW) ?? false)) {
            continue;
        }

        // Até a chave da classe: o que ela estende/implementa.
        for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->text !== '{'; $j++) {
            if (! $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || $j === $i + 1) {
                continue;
            }

            $nome = ltrim($tokens[$j]->text, '\\');
            $primeiro = explode('\\', $nome)[0];
            $completo = isset($imports[$primeiro]) ? $imports[$primeiro].substr($nome, strlen($primeiro)) : $nome;

            foreach (OPTIONAL_MODULE_PREFIXES as $prefixo) {
                if (str_starts_with($completo, $prefixo)) {
                    $violacoes[] = $tokens[$i + 1]->text.' → '.$completo;
                }
            }
        }
    }

    return $violacoes;
}

it('nenhum arquivo de teste declara no topo classe que depende de módulo opcional', function (): void {
    $violacoes = [];

    foreach ((new Finder)->files()->in(base_path('tests'))->name('*.php') as $arquivo) {
        foreach (topLevelOptionalModuleClasses($arquivo->getContents()) as $violacao) {
            $violacoes[] = str_replace(base_path().'/', '', $arquivo->getRealPath()).': '.$violacao;
        }
    }

    expect($violacoes)->toBe([]);
});

it('a trava enxerga a classe de topo e deixa passar a anônima', function (): void {
    $topo = "<?php\nuse Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\nfinal class X implements DeletionCheck {}\n";
    $anonima = "<?php\nuse Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\nfunction f() { return new class implements DeletionCheck {}; }\n";
    $comum = "<?php\nuse Illuminate\\Support\\Collection;\nfinal class Y extends Collection {}\n";

    expect(topLevelOptionalModuleClasses($topo))->toBe(['X → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck'])
        ->and(topLevelOptionalModuleClasses($anonima))->toBe([])
        ->and(topLevelOptionalModuleClasses($comum))->toBe([]);
});
