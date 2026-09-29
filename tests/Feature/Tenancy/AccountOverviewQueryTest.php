<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Tenancy\Queries\AccountOverview;
use Twstec\Kit\Accounts\Tenancy\Queries\AccountOverviewQuery;
use Twstec\Kit\Accounts\Tenancy\Services\ProjectService;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;

// =============================================================================
// AccountOverviewQuery — os números da visão geral da conta, fora da tela.
//
// O dashboard do painel (DashboardTest) segue provando o que aparece na tela;
// aqui a prova é do backend reutilizável: isolamento por conta, janelas
// configuráveis e o formato do resultado que qualquer front recebe. A consulta
// é da CONTA ATUAL (naConta = a conta pessoal da pessoa, como no painel dela);
// o tráfego é o de `request_logs.tenant_uuid` = uuid da conta, que na conta
// pessoal é o uuid da pessoa.
// =============================================================================

function overviewLog(string $tenantUuid, Carbon $quando, string $endpoint = '/api/v1/projects'): void
{
    DB::table('request_logs')->insert([
        'uuid' => (string) Str::uuid7(),
        'correlation_id' => (string) Str::uuid7(),
        'tenant_uuid' => $tenantUuid,
        'ip' => '127.0.0.1',
        'method' => 'GET',
        'endpoint' => $endpoint,
        'status' => RequestLogStatus::Concluida->value,
        'http_status_response' => 200,
        'created_at' => $quando,
    ]);
}

it('conta só o que é da conta: chaves ativas, projetos e tráfego', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    criarChave($user, ['name' => 'Minha']);
    criarChave($outro, ['name' => 'Alheia']);
    naConta($user, fn () => app(ProjectService::class)->create($user, 'Loja'));
    naConta($outro, fn () => app(ProjectService::class)->create($outro, 'Alheia'));

    overviewLog($user->uuid, now()->subDay());
    overviewLog($outro->uuid, now()->subDay(), '/api/v1/alheio');

    $overview = naConta($user, fn () => app(AccountOverviewQuery::class)->forCurrentAccount());

    expect($overview)->toBeInstanceOf(AccountOverview::class)
        ->and($overview->activeKeysCount)->toBe(1)
        ->and($overview->projectsCount)->toBe(1)
        ->and($overview->recentRequestsCount)->toBe(1)
        ->and($overview->recentCalls)->toHaveCount(1)
        ->and($overview->recentCalls->first()->endpoint)->toBe('/api/v1/projects')
        ->and($overview->lastKeyUsedAt)->toBeNull();
});

it('usa as janelas padrão e aceita outras', function () {
    $user = User::factory()->create();

    overviewLog($user->uuid, now());
    overviewLog($user->uuid, now()->subDays(2));
    overviewLog($user->uuid, now()->subDays(5));

    $padrao = naConta($user, fn () => app(AccountOverviewQuery::class)->forCurrentAccount());

    expect($padrao->chartDays)->toBe(AccountOverviewQuery::CHART_DAYS)
        ->and($padrao->chart['labels'])->toHaveCount(AccountOverviewQuery::CHART_DAYS)
        ->and($padrao->recentRequestsDays)->toBe(AccountOverviewQuery::RECENT_DAYS)
        ->and($padrao->recentRequestsCount)->toBe(3);

    $curta = naConta($user, fn () => app(AccountOverviewQuery::class)->forCurrentAccount(chartDays: 4, recentDays: 2, recentCalls: 1));

    expect($curta->chart['labels'])->toHaveCount(4)
        ->and(array_sum($curta->chart['values']))->toBe(2)
        ->and($curta->recentRequestsCount)->toBe(1)
        ->and($curta->recentCalls)->toHaveCount(1);
});

it('entrega à tela as mesmas chaves que o dashboard sempre recebeu', function () {
    $user = User::factory()->create();

    expect(array_keys(naConta($user, fn () => app(AccountOverviewQuery::class)->forCurrentAccount())->toArray()))->toBe([
        'activeKeysCount',
        'projectsCount',
        'recentRequestsCount',
        'recentRequestsDays',
        'lastKeyUsedAt',
        'chartDays',
        'chart',
        'recentCalls',
    ]);
});
