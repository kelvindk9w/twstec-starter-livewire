<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
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
// esquece a trait reprova aqui, com o banco de teste migrado de verdade (as
// colunas são lidas do banco, não do código).
//
// Os models são descobertos no projeto (app/) e nos pacotes do kit
// (vendor/twstec/kit-*/src). As exceções são EXPLÍCITAS, com o motivo, em
// ACCOUNT_MODELS_WITHOUT_ACCOUNT_SCOPE — um model que precisar de exceção
// entra na lista, revisado, nunca por esquecimento.
// =============================================================================

/**
 * Models com `account_id` que NÃO são dado de uma conta: model => motivo.
 *
 * @var array<class-string<Model>, string>
 */
const ACCOUNT_MODELS_WITHOUT_ACCOUNT_SCOPE = [
    AccountMembership::class => 'o vínculo pessoa × conta é a estrutura do tenant, não dado dele',
];

/**
 * @return list<class-string<Model>>
 */
function accountModelsDiscovered(): array
{
    $base = base_path();
    $pastas = [$base.'/app', ...(glob($base.'/vendor/twstec/kit-*/src', GLOB_ONLYDIR) ?: [])];
    $classes = [];

    foreach ((new Finder)->files()->in($pastas)->name('*.php') as $file) {
        $codigo = $file->getContents();

        if (preg_match('/^namespace\s+([^;]+);/m', $codigo, $ns) !== 1 || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $codigo, $cl) !== 1) {
            continue;
        }

        $classe = $ns[1].'\\'.$cl[1];

        // Classe que só carrega com um módulo opcional ausente (estende uma
        // classe do Filament sem o /admin, por exemplo) fica de fora — mas um
        // arquivo que declara um MODEL e não carrega derruba a trava, em vez
        // de escapar dela.
        try {
            $carrega = class_exists($classe);
        } catch (Throwable $erro) {
            if (preg_match('/\bextends\s+\\\\?[\w\\\\]*(?:Model|Authenticatable|Pivot)\b/', $codigo) === 1) {
                throw $erro;
            }

            continue;
        }

        if ($carrega && is_subclass_of($classe, Model::class) && ! (new ReflectionClass($classe))->isAbstract()) {
            $classes[] = $classe;
        }
    }

    sort($classes);

    return $classes;
}

it('todo model com account_id carrega o escopo da conta atual', function (): void {
    $comConta = [];
    $semEscopo = [];

    foreach (accountModelsDiscovered() as $classe) {
        /** @var Model $model */
        $model = new $classe;

        if (! Schema::hasTable($model->getTable()) || ! Schema::hasColumn($model->getTable(), 'account_id') || array_key_exists($classe, ACCOUNT_MODELS_WITHOUT_ACCOUNT_SCOPE)) {
            continue;
        }

        $comConta[] = $classe;

        if (! in_array(BelongsToAccount::class, class_uses_recursive($classe), true) || ! $model->hasGlobalScope(AccountScope::class)) {
            $semEscopo[] = $classe;
        }
    }

    // A descoberta não é cega: os models da conta de hoje estão lá.
    expect($comConta)->toContain(Project::class, ApiKey::class, ...(Kit::has('uploads') ? [Upload::class] : []))
        // Os quatro models do pacote de webhooks (opcional), quando instalado.
        ->and($comConta)->toContain(...(Kit::has('webhooks') ? [
            'Twstec\\Kit\\Webhooks\\Models\\WebhookEndpoint',
            'Twstec\\Kit\\Webhooks\\Models\\WebhookEvent',
            'Twstec\\Kit\\Webhooks\\Models\\WebhookDelivery',
            'Twstec\\Kit\\Webhooks\\Models\\WebhookDeliveryAttempt',
        ] : [Project::class]))
        ->and($semEscopo)->toBe([]);
})->group('accounts');

it('as exceções da lista existem, têm account_id e motivo', function (): void {
    foreach (ACCOUNT_MODELS_WITHOUT_ACCOUNT_SCOPE as $classe => $motivo) {
        expect(class_exists($classe))->toBeTrue("{$classe} não existe mais: tire da lista")
            ->and(Schema::hasColumn((new $classe)->getTable(), 'account_id'))->toBeTrue("{$classe} não tem account_id: tire da lista")
            ->and(trim($motivo))->not->toBe('');
    }
})->group('accounts');

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

    foreach (accountModelsDiscovered() as $classe) {
        if (! in_array(BelongsToAccount::class, class_uses_recursive($classe), true)) {
            continue;
        }

        // Método que veio do trait tem o arquivo do trait; sobrescrito, o do model.
        if ((new ReflectionMethod($classe, 'allowsRecordWithoutAccount'))->getFileName() !== $doTrait) {
            $abrem[] = $classe;
        }
    }

    expect($abrem)->toBe($revisados);
})->group('accounts');
