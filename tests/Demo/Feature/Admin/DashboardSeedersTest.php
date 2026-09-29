<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Demo\Catalog\Models\Product;
use Twstec\Kit\Demo\Database\Seeders\ApiKeySeeder;
use Twstec\Kit\Demo\Database\Seeders\DashboardHistorySeeder;
use Twstec\Kit\Demo\Database\Seeders\ProductHistorySeeder;
use Twstec\Kit\Demo\Database\Seeders\ProductSeeder;
use Twstec\Kit\Demo\Database\Seeders\ProjectSeeder;
use Twstec\Kit\Demo\Database\Seeders\RequestLogHistorySeeder;
use Twstec\Kit\Demo\Database\Seeders\RequestLogSeeder;
use Twstec\Kit\Demo\Database\Seeders\SubmissionHistorySeeder;
use Twstec\Kit\Demo\Database\Seeders\UploadSeeder;
use Twstec\Kit\Demo\Database\Seeders\UserSeeder;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// O histórico que faz os dashboards nascerem CHEIOS em qualquer instalação.
//
// Sem estes seeders, `projects`, `api_keys` e `uploads` nascem vazios e as
// submissões cabem todas nas últimas 40 horas — metade dos cards das três
// variantes ficaria em zero, e o Δ% nunca teria período anterior.
//
// Todos são idempotentes por identificador determinístico (UUID v5).
// =============================================================================

beforeEach(function () {
    $this->seed(UserSeeder::class);
});

it('o histórico completo alimenta as tabelas que nasciam vazias', function () {
    $this->seed(ProductSeeder::class);
    $this->seed(DashboardHistorySeeder::class);

    expect(comoSistema(fn () => Project::query()->count()))->toBe(ProjectSeeder::QUANTIDADE)
        ->and(comoSistema(fn () => ApiKey::query()->count()))->toBe(ApiKeySeeder::QUANTIDADE)
        ->and(comoSistema(fn () => Upload::query()->count()))->toBeGreaterThan(200)
        ->and(FormSubmission::query()->count())->toBeGreaterThan(100)
        ->and(RequestLog::query()->count())->toBeGreaterThan(1000);
})->group('slow');

it('rodar o histórico duas vezes não duplica nada', function () {
    $this->seed(ProductSeeder::class);
    $this->seed(DashboardHistorySeeder::class);

    $antes = [
        comoSistema(fn () => Project::query()->count()),
        comoSistema(fn () => ApiKey::query()->count()),
        comoSistema(fn () => Upload::query()->count()),
        FormSubmission::query()->count(),
        RequestLog::query()->count(),
    ];

    $this->seed(DashboardHistorySeeder::class);

    expect([
        comoSistema(fn () => Project::query()->count()),
        comoSistema(fn () => ApiKey::query()->count()),
        comoSistema(fn () => Upload::query()->count()),
        FormSubmission::query()->count(),
        RequestLog::query()->count(),
    ])->toBe($antes);
})->group('slow');

it('os uploads cobrem as três janelas do seletor, com período anterior', function () {
    $this->seed(UploadSeeder::class);

    foreach ([7, 30, 90] as $dias) {
        $janela = comoSistema(fn () => Upload::query()->where('created_at', '>=', now()->subDays($dias))->count());
        $anterior = comoSistema(fn () => Upload::query()
            ->whereBetween('created_at', [now()->subDays($dias * 2), now()->subDays($dias)])
            ->count());

        // Janela E período anterior com dados: é o que faz o Δ% ter sentido
        // em vez de dizer "sem base de comparação" em toda instalação nova.
        expect($janela)->toBeGreaterThan(0)
            ->and($anterior)->toBeGreaterThan(0);
    }

    // Nenhum registro no futuro (data de demo não pode "vazar" para frente).
    expect(comoSistema(fn () => Upload::query()->where('created_at', '>', now())->count()))->toBe(0);
});

it('as submissões antigas não trazem payload de ataque — só volume', function () {
    $this->seed(SubmissionHistorySeeder::class);

    $antigas = FormSubmission::query()->where('created_at', '<', now()->subDays(2))->get();

    expect($antigas)->not->toBeEmpty();

    foreach ($antigas as $submissao) {
        expect($submissao->message)->not->toContain('<script')
            ->and($submissao->nickname)->not->toContain('<');
    }
});

it('as chaves de API semeadas são inertes: nenhuma secreta existe', function () {
    $this->seed(ProjectSeeder::class);
    $this->seed(ApiKeySeeder::class);

    $chaves = comoSistema(fn () => ApiKey::query()->get());

    expect($chaves)->toHaveCount(ApiKeySeeder::QUANTIDADE);

    foreach ($chaves as $chave) {
        expect($chave->public_key)->toStartWith('pk_test_')
            // Só o hash é gravado, e ele não corresponde a nenhuma sk_ emitida.
            ->and(strlen((string) $chave->secret_hash))->toBe(64)
            ->and($chave->codigo_publico)->toStartWith('KEY-');
    }

    // Variedade de status para os filtros do painel terem o que filtrar.
    expect($chaves->pluck('status')->unique())->toHaveCount(4);
});

it('os produtos ganham data de cadastro espalhada, sem criar nem apagar nada', function () {
    $this->seed(ProductSeeder::class);

    $total = Product::query()->count();

    $this->seed(ProductHistorySeeder::class);

    $datas = Product::query()->pluck('created_at')
        ->map(fn (Carbon $data): string => $data->toDateString())
        ->unique();

    expect(Product::query()->count())->toBe($total)
        ->and($datas->count())->toBeGreaterThan(10)
        ->and(Product::query()->where('created_at', '>', now())->count())->toBe(0);

    // Reexecutar no mesmo dia não move nada (a âncora é o início do dia).
    $antes = Product::query()->orderBy('id')->pluck('created_at')->map->toDateTimeString();

    $this->seed(ProductHistorySeeder::class);

    expect(Product::query()->orderBy('id')->pluck('created_at')->map->toDateTimeString()->all())
        ->toBe($antes->all());
});

it('o passado dos request logs respeita append-only e não colide com o seeder dos 30 dias', function () {
    $this->seed(RequestLogSeeder::class);
    $recentes = RequestLog::query()->count();

    $this->seed(RequestLogHistorySeeder::class);

    expect(RequestLog::query()->count())->toBeGreaterThan($recentes)
        // O passado começa onde os 30 dias recentes terminam.
        ->and(RequestLog::query()->where('created_at', '<', now()->subDays(RequestLogSeeder::DIAS))->count())
        ->toBeGreaterThan(0)
        // Correlation ids continuam únicos (namespaces diferentes).
        ->and(RequestLog::query()->distinct()->count('correlation_id'))
        ->toBe(RequestLog::query()->count());

    $log = RequestLog::query()->first();

    expect(fn () => $log->update(['endpoint' => 'hackeado']))
        ->toThrow(AppendOnlyViolationException::class);
})->group('slow');
