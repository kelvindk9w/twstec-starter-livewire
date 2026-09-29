<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\Account\Scopes\AccountScope;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Todo MODEL cuja tabela tem `account_id` é dado de conta — e carrega o
// escopo da conta atual (Concerns\BelongsToAccount). Um model novo que
// esquece a trait reprova aqui, com o banco migrado de verdade.
//
// Os models são descobertos no starter (app/) e nos cinco pacotes
// (vendor/twstec/kit-*/src). A exceção é o vínculo pessoa × conta
// (AccountMembership), que é a estrutura do tenant, não dado dele.
// =============================================================================

/**
 * @return list<class-string<Model>>
 */
function modelsDoKit(): array
{
    $base = base_path();
    $pastas = [$base.'/app', ...(glob($base.'/vendor/twstec/kit-*/src', GLOB_ONLYDIR) ?: [])];
    $classes = [];

    foreach ((new Finder)->files()->in($pastas)->name('*.php') as $file) {
        $codigo = $file->getContents();

        if (preg_match('/^namespace\s+([^;]+);/m', $codigo, $ns) !== 1 || preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $codigo, $cl) !== 1) {
            continue;
        }

        $classe = $ns[1].'\\'.$cl[1];

        if (TestCase::appClassLoadable($classe) && class_exists($classe) && is_subclass_of($classe, Model::class) && ! (new ReflectionClass($classe))->isAbstract()) {
            $classes[] = $classe;
        }
    }

    sort($classes);

    return $classes;
}

it('todo model com account_id carrega o escopo da conta atual', function (): void {
    $comConta = [];
    $semEscopo = [];

    foreach (modelsDoKit() as $classe) {
        /** @var Model $model */
        $model = new $classe;

        if (! Schema::hasTable($model->getTable()) || ! Schema::hasColumn($model->getTable(), 'account_id') || $classe === AccountMembership::class) {
            continue;
        }

        $comConta[] = $classe;

        if (! in_array(BelongsToAccount::class, class_uses_recursive($classe), true) || ! $model->hasGlobalScope(AccountScope::class)) {
            $semEscopo[] = $classe;
        }
    }

    // A descoberta não é cega: os models da conta de hoje estão lá.
    expect($comConta)->toContain(Project::class, ApiKey::class, ...(Kit::has('uploads') ? [Upload::class] : []))
        ->and($semEscopo)->toBe([]);
});

it('registro SEM conta só nos models revisados (hoje: a foto pessoal dos uploads)', function (): void {
    // BelongsToAccount deixa um model gravar registro sem conta — só em modo
    // sistema declarado — quando ele sobrescreve allowsRecordWithoutAccount().
    // Um model novo que abrir essa porta reprova aqui até ser revisado.
    $revisados = [
        // A foto de perfil é da PESSOA, não de uma conta (ver HasAvatar) —
        // com o pacote de uploads (opcional) instalado.
        ...(Kit::has('uploads') ? [Upload::class] : []),
    ];

    $abrem = [];
    $doTrait = (string) (new ReflectionClass(BelongsToAccount::class))->getFileName();

    foreach (modelsDoKit() as $classe) {
        if (! in_array(BelongsToAccount::class, class_uses_recursive($classe), true)) {
            continue;
        }

        // Método que veio do trait tem o arquivo do trait; sobrescrito, o do model.
        if ((new ReflectionMethod($classe, 'allowsRecordWithoutAccount'))->getFileName() !== $doTrait) {
            $abrem[] = $classe;
        }
    }

    expect($abrem)->toBe($revisados);
});
