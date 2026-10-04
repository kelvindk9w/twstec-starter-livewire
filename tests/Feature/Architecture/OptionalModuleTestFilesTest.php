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
// só vem com o /admin) — ou que usa um trait dele — derruba o carregamento da
// suíte inteira numa instalação sem ele ("Interface ... not found"). Foi o que
// aconteceu na simulação "Livewire só a base" do CI da 2.0.0-beta.10.
//
// A regra: classe de apoio que depende de módulo opcional nasce DENTRO do
// teste (classe anônima, `new class implements ...`), nunca no topo.
//
// A trava lê o arquivo por TOKENS (PhpToken) e só acusa DECLARAÇÃO de verdade
// (class/interface/trait/enum com nome, no topo do arquivo ou de um bloco
// `namespace`). Não acusa o que não carrega nada na leitura do arquivo:
// `X::class` (a constante é o nome, em texto), `new X` e `instanceof X`
// dentro de função ou closure, classe anônima, comentário, texto.
// =============================================================================

const OPTIONAL_MODULE_PREFIXES = ['Twstec\\Kit\\Accounts\\', 'Twstec\\Kit\\Uploads\\', 'Twstec\\Kit\\Admin\\', 'Twstec\\Kit\\Webhooks\\', 'Filament\\'];

/**
 * Declarações no topo do arquivo que estendem, implementam ou usam (trait) um
 * tipo de módulo opcional — cada uma como "Nome → Tipo\Completo".
 *
 * @return list<string>
 */
function topLevelOptionalModuleClasses(string $codigo): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($codigo), fn (PhpToken $t): bool => ! $t->isIgnorable()));
    $nomes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    $namespace = '';
    $imports = [];
    // Uma entrada por chave aberta: 'namespace' (bloco de namespace — ainda
    // é o topo), 'class:<nome>' (corpo de uma classe declarada no topo) ou
    // 'other' (função, closure, controle, classe anônima, interpolação).
    $pilha = [];
    $violacoes = [];

    $noTopo = static function () use (&$pilha): bool {
        return array_diff($pilha, ['namespace']) === [];
    };
    $texto = static fn (int $i): string => isset($tokens[$i]) ? $tokens[$i]->text : '';

    $resolve = static function (PhpToken $nome) use (&$namespace, &$imports): string {
        if ($nome->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($nome->text, '\\');
        }

        $prefixo = $namespace === '' ? '' : $namespace.'\\';

        if ($nome->is(T_NAME_RELATIVE)) {
            return $prefixo.substr($nome->text, strlen('namespace\\'));
        }

        $primeiro = explode('\\', $nome->text)[0];

        return isset($imports[strtolower($primeiro)])
            ? $imports[strtolower($primeiro)].substr($nome->text, strlen($primeiro))
            : $prefixo.$nome->text;
    };

    $confere = static function (string $declarada, PhpToken $nome) use ($resolve, &$violacoes): void {
        $completo = $resolve($nome);

        foreach (OPTIONAL_MODULE_PREFIXES as $prefixo) {
            if (stripos($completo, $prefixo) === 0) {
                $violacoes[] = $declarada.' → '.$completo;
            }
        }
    };

    for ($i = 0, $total = count($tokens); $i < $total; $i++) {
        $token = $tokens[$i];

        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $pilha[] = 'other';

            continue;
        }

        if ($token->text === '}') {
            array_pop($pilha);

            continue;
        }

        // `namespace Nome;`, `namespace Nome { ... }` ou `namespace { ... }`:
        // os `use` valem só dali em diante.
        if ($token->is(T_NAMESPACE) && $noTopo()) {
            if (($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[++$i]->text;
            } elseif ($texto($i + 1) === '{') {
                $namespace = '';
            } else {
                continue;
            }

            $imports = [];

            if ($texto($i + 1) === '{') {
                $pilha[] = 'namespace';
                $i++;
            }

            continue;
        }

        // `use` de importação no topo: `use A\B;`, `use A\B as C, D\E;`,
        // `use A\{B, C as D};`. Fora daqui: `use function`/`use const` (não
        // são classes) e o `use (...)` de closure.
        if ($token->is(T_USE) && $noTopo() && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $base = '';
            $ultimo = null;

            for ($i++; $i < $total && $texto($i) !== ';'; $i++) {
                $atual = $tokens[$i];

                if ($atual->is($nomes)) {
                    if ($texto($i + 1) === '\\' && $texto($i + 2) === '{') {
                        $base = ltrim($atual->text, '\\').'\\';
                        $i += 2;

                        continue;
                    }

                    $ultimo = $base.ltrim($atual->text, '\\');
                    $apelido = substr((string) strrchr('\\'.$ultimo, '\\'), 1);

                    if ($tokens[$i + 1]->is(T_AS)) {
                        $apelido = $tokens[$i + 2]->text;
                        $i += 2;
                    }

                    $imports[strtolower($apelido)] = $ultimo;
                } elseif ($atual->text === '}') {
                    $base = '';
                }
            }

            continue;
        }

        // `use Trait;` direto no corpo de uma classe declarada no topo: o
        // trait é carregado junto com a declaração.
        if ($token->is(T_USE) && str_starts_with((string) end($pilha), 'class:')) {
            $declarada = substr((string) end($pilha), strlen('class:'));

            for ($i++; $i < $total && ! in_array($texto($i), [';', '{'], true); $i++) {
                if ($tokens[$i]->is($nomes)) {
                    $confere($declarada, $tokens[$i]);
                }
            }

            if ($texto($i) === '{') {
                $pilha[] = 'other';
            }

            continue;
        }

        // Declaração de verdade: class/interface/trait/enum seguida do NOME,
        // no topo. Não é declaração: `X::class` (o `class` vem depois de
        // `::`), classe anônima (`new class`, sem nome).
        if (! $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) || ! $noTopo()
            || ($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            || ! ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
            continue;
        }

        $declarada = $tokens[$i + 1]->text;
        $modo = null;

        // O cabeçalho, até a chave do corpo: só os nomes depois de `extends`
        // e `implements` (o tipo de um enum com valor, `: string`, não conta).
        for ($i += 2; $i < $total && $texto($i) !== '{'; $i++) {
            if ($tokens[$i]->is([T_EXTENDS, T_IMPLEMENTS])) {
                $modo = $tokens[$i]->id;
            } elseif ($modo !== null && $tokens[$i]->is($nomes)) {
                $confere($declarada, $tokens[$i]);
            }
        }

        $pilha[] = 'class:'.$declarada;
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

// A TRAVA NÃO PODE ACUSAR o que não carrega nada na leitura do arquivo.
it('a trava NÃO acusa o que não é declaração de classe', function (string $codigo): void {
    expect(topLevelOptionalModuleClasses("<?php\nuse Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\nuse Twstec\\Kit\\Accounts\\Deletion\\DeletionImpediment;\nuse Twstec\\Kit\\Accounts\\Account\\Models\\Account;\nuse Filament\\Pages\\Page;\n".$codigo))->toBe([]);
})->with([
    'X::class no topo' => ["\$verificadores = [DeletionCheck::class, Account::class];\nconfig(['accounts.deletion.checks' => [DeletionCheck::class]]);\nit('x', function () { expect(Account::class)->toBeString(); });\n"],
    'X::class seguido de closure e de outros nomes' => ["uses()->group(Account::class);\nit('y', function () use (\$a) { return new DeletionImpediment('a', 'b'); });\n"],
    'new X dentro de função e de closure' => ["function apoio(): DeletionImpediment { return new DeletionImpediment('a', 'b'); }\nit('z', fn () => new Account);\n"],
    'instanceof X' => ["function eVerificador(mixed \$a): bool { return \$a instanceof DeletionCheck; }\n\$e = fn (mixed \$a): bool => \$a instanceof Page;\n"],
    'classe anônima (o jeito certo)' => ["function verificador(): DeletionCheck { return new class implements DeletionCheck { public function impediments(\$r): iterable { return []; } }; }\n\$p = new class () extends Page {};\n\$q = new #[Attr] class implements DeletionCheck {};\n"],
    'comentário, texto e heredoc' => ["// final class X implements DeletionCheck {}\n/** class Y extends Page {} */\n\$t = 'class Z implements DeletionCheck {}';\n\$h = <<<TXT\nclass W extends Page {}\nTXT;\n"],
    'classe de topo que estende tipo comum' => ["final class Colecao extends Illuminate\\Support\\Collection implements JsonSerializable { public function jsonSerialize(): mixed { return static::class; } }\n"],
    'enum com valor que implementa tipo comum' => ["enum Situacao: string implements JsonSerializable { case A = 'a'; public function jsonSerialize(): mixed { return self::class; } }\n"],
    'mesmo nome curto, importado de outro lugar' => ["namespace Tests\\Apoio;\nuse App\\Contracts\\DeletionCheck;\nfinal class Local implements DeletionCheck {}\n"],
    'propriedade e método chamados class/enum' => ["\$x = \$objeto->class;\n\$y = \$objeto?->enum;\n"],
    'interpolação com chaves' => ["\$s = \"{\$a->b} \${c}\";\nfinal class Depois extends Illuminate\\Support\\Collection {}\n"],
]);

// A TRAVA TEM DE ACUSAR a declaração de verdade — inclusive a da beta.10.
it('a trava ACUSA a declaração de verdade', function (string $codigo, array $esperado): void {
    expect(topLevelOptionalModuleClasses("<?php\n".$codigo))->toBe($esperado);
})->with([
    'a classe da beta.10' => [
        "use Illuminate\\Support\\Facades\\DB;\nuse Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\nuse Twstec\\Kit\\Accounts\\Deletion\\DeletionRequest;\n\nfinal class RegistrosGuardadosDaConta implements DeletionCheck\n{\n    public function impediments(DeletionRequest \$request): iterable\n    {\n        return [];\n    }\n}\n",
        ['RegistrosGuardadosDaConta → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck'],
    ],
    'com apelido' => ["use Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck as Verificador;\nclass X implements Verificador {}\n", ['X → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck']],
    'nome completo, sem use' => ["abstract class X implements \\Twstec\\Kit\\Uploads\\Contracts\\Algo, \\Countable {}\n", ['X → Twstec\\Kit\\Uploads\\Contracts\\Algo']],
    'use em grupo' => ["use Twstec\\Kit\\Accounts\\Deletion\\{Contracts\\DeletionCheck, DeletionRequest as Pedido};\nfinal readonly class X implements DeletionCheck {}\n", ['X → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck']],
    'nome qualificado a partir do use' => ["use Twstec\\Kit\\Accounts\\Deletion\\Contracts;\nclass X implements Contracts\\DeletionCheck {}\n", ['X → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck']],
    'estende página do Filament' => ["use Filament\\Pages\\Page;\nclass Tela extends Page {}\n", ['Tela → Filament\\Pages\\Page']],
    'interface que estende' => ["use Twstec\\Kit\\Admin\\Approvals\\Contracts\\Algo;\ninterface Minha extends Algo {}\n", ['Minha → Twstec\\Kit\\Admin\\Approvals\\Contracts\\Algo']],
    'enum com valor que implementa' => ["use Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\nenum E: string implements DeletionCheck { case A = 'a'; }\n", ['E → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck']],
    'trait usado no corpo' => ["use Twstec\\Kit\\Accounts\\Account\\Concerns\\BelongsToAccount;\nclass Modelo extends Illuminate\\Database\\Eloquent\\Model { use BelongsToAccount; public function f() { return fn () => static::class; } }\n", ['Modelo → Twstec\\Kit\\Accounts\\Account\\Concerns\\BelongsToAccount']],
    'dentro de bloco namespace' => ["namespace Tests\\Apoio {\n    use Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\n    final class X implements DeletionCheck {}\n}\n", ['X → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck']],
    'X::class antes da declaração não esconde nem duplica' => ["use Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck;\n\$a = DeletionCheck::class;\nfinal class X implements DeletionCheck {}\n\$b = X::class;\n", ['X → Twstec\\Kit\\Accounts\\Deletion\\Contracts\\DeletionCheck']],
]);
