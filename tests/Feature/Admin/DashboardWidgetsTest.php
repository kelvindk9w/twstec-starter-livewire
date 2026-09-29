<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Twstec\Kit\Admin\Widgets\Growth\GrowthStats;
use Twstec\Kit\Admin\Widgets\Overview\OverviewStats;
use Twstec\Kit\Admin\Widgets\Overview\RequestsTrendChart;
use Twstec\Kit\Admin\Widgets\Support\Metric;
use Twstec\Kit\Admin\Widgets\Support\MetricFormat;
use Twstec\Kit\Admin\Widgets\Support\MetricStat;
use Twstec\Kit\Admin\Widgets\Support\Period;
use Twstec\Kit\Demo\Filament\Widgets\Content\ContentStats;
use Twstec\Kit\Demo\Filament\Widgets\Content\UploadsPerDayChart;
use Twstec\Kit\Demo\Filament\Widgets\Overview\LatestSubmissions;
use Twstec\Kit\Demo\Showcase\Models\FormSubmission;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// A BASE reutilizável dos dashboards (app/Filament/Widgets/Support):
// Metric (Δ% vs. período anterior + sparkline), Period (janela e janela
// anterior), MetricStat (o card) e as bases de gráfico e de tabela.
//
// É o contrato que faz um widget novo nascer em poucas linhas — e o que
// impede um card de comparar 30 dias com 7.
// =============================================================================

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

/** Cria um request log numa data específica. */
function logEm(string $quando, int $http = 200, int $duracao = 100): RequestLog
{
    // forceFill: `created_at` não é fillable no RequestLog (append-only), e é
    // exatamente a data que estes testes precisam controlar.
    $log = new RequestLog;

    $log->forceFill([
        'correlation_id' => (string) Str::uuid7(),
        'method' => 'GET',
        'endpoint' => 'api/v1/projects',
        'status' => RequestLogStatus::Concluida,
        'http_status_response' => $http,
        'duration_ms' => $duracao,
        'created_at' => $quando,
    ])->save();

    return $log;
}

it('o período resolve a janela atual e a anterior, e cai no padrão quando o filtro é inválido', function () {
    $periodo = Period::days(30);

    expect($periodo->days)->toBe(30)
        ->and($periodo->dates())->toHaveCount(30)
        ->and($periodo->previousDates())->toHaveCount(30)
        // A janela anterior termina EXATAMENTE onde a atual começa.
        ->and($periodo->previousEnd()->toDateTimeString())->toBe($periodo->start()->toDateTimeString())
        ->and($periodo->dates())->toContain(now()->toDateString());

    // Filtro ausente, vazio ou fora das opções: padrão da config, nunca erro.
    expect(Period::fromFilters(null)->days)->toBe(Period::defaultDays())
        ->and(Period::fromFilters(['period' => '7'])->days)->toBe(7)
        ->and(Period::fromFilters(['period' => 'abacaxi'])->days)->toBe(Period::defaultDays())
        ->and(Period::days(365)->days)->toBe(Period::defaultDays());
});

it('a métrica calcula o Δ% comparando a janela com a janela anterior', function () {
    $periodo = Period::days(7);

    // 6 registros nos últimos 7 dias; 4 nos 7 dias anteriores → +50%.
    foreach ([0, 1, 2, 3, 4, 5] as $dia) {
        logEm(now()->subDays($dia)->toDateTimeString());
    }

    foreach ([8, 9, 10, 11] as $dia) {
        logEm(now()->subDays($dia)->toDateTimeString());
    }

    $metrica = Metric::count(fn () => RequestLog::query(), $periodo);

    expect($metrica->current())->toBe(6.0)
        ->and($metrica->previous())->toBe(4.0)
        ->and(round((float) $metrica->delta(), 2))->toBe(50.0)
        ->and($metrica->deltaAbsolute())->toBe(2.0)
        // Sparkline: um ponto por dia da janela, o mais recente por último.
        ->and($metrica->series())->toHaveCount(7)
        ->and(array_sum($metrica->series()))->toBe(6.0);
});

it('sem base de comparação a métrica devolve null, e não um +100% inventado', function () {
    logEm(now()->toDateTimeString());

    $metrica = Metric::count(fn () => RequestLog::query(), Period::days(7));

    expect($metrica->previous())->toBe(0.0)
        ->and($metrica->delta())->toBeNull();

    $stat = MetricStat::make('Requisições', $metrica)->toStat();

    expect($stat->getDescription())->toContain(__('admin.dashboards.common.no_baseline'));
});

it('a métrica soma, faz média e razão — e a razão compara pontos, não porcentagem de porcentagem', function () {
    $periodo = Period::days(7);

    logEm(now()->toDateTimeString(), 200, 100);
    logEm(now()->toDateTimeString(), 500, 300);
    logEm(now()->subDays(1)->toDateTimeString(), 200, 200);
    logEm(now()->subDays(1)->toDateTimeString(), 200, 200);

    $media = Metric::average(fn () => RequestLog::query(), 'duration_ms', $periodo);

    expect($media->current())->toBe(200.0);

    $total = Metric::count(fn () => RequestLog::query(), $periodo);
    $erros = Metric::count(fn () => RequestLog::query()->where('http_status_response', '>=', 400), $periodo);

    // 1 erro em 4 requisições = 25% no período; sem período anterior = null.
    expect(Metric::ratio($erros, $total)->current())->toBe(25.0)
        ->and(Metric::ratio($erros, $total)->series())->toHaveCount(7);
});

it('a série acumulada parte de uma base e nunca desce', function () {
    logEm(now()->subDays(1)->toDateTimeString());
    logEm(now()->toDateTimeString());

    $serie = Metric::count(fn () => RequestLog::query(), Period::days(7))->cumulativeSeries(10.0);
    $ultimo = $serie[count($serie) - 1];

    expect($serie)->toHaveCount(7)
        ->and($ultimo)->toBe(12.0)
        ->and($serie)->toBe(array_values(collect($serie)->sort()->values()->all()));
});

it('o card mostra a seta certa e a cor certa — inclusive quando subir é ruim', function () {
    $subindo = Metric::fromValues(120, 100, [1, 2, 3]);
    $caindo = Metric::fromValues(80, 100, [3, 2, 1]);

    $bom = MetricStat::make('Usuários', $subindo)->toStat();
    $ruim = MetricStat::make('Taxa de erro', $subindo)->inverted()->toStat();

    expect($bom->getColor())->toBe('success')
        ->and($ruim->getColor())->toBe('danger')
        ->and(MetricStat::make('Usuários', $caindo)->toStat()->getColor())->toBe('danger')
        // Métrica invertida caindo é boa notícia.
        ->and(MetricStat::make('Latência', $caindo)->inverted()->toStat()->getColor())->toBe('success');

    // Δ em PONTOS para métricas que já são porcentagem.
    $emPontos = MetricStat::make('Taxa de erro', Metric::fromValues(3.5, 2.0, [1, 2, 3]))
        ->format(MetricFormat::Percent)
        ->deltaInPoints()
        ->toStat();

    expect($emPontos->getDescription())->toContain('1,5');
});

it('os formatos escrevem o número na borda, nunca no cálculo', function () {
    expect(MetricFormat::Integer->display(1234))->toContain('1')
        ->and(MetricFormat::Percent->display(12.34))->toEndWith('%')
        ->and(MetricFormat::Milliseconds->display(320))->toEndWith('ms')
        ->and(MetricFormat::Milliseconds->display(2500))->toEndWith('s')
        ->and(MetricFormat::Bytes->display(2_097_152))->toContain('MB');
});

it('o gráfico de série temporal devolve um ponto por dia da janela da página', function () {
    logEm(now()->toDateTimeString());

    $componente = Livewire::test(RequestsTrendChart::class, ['pageFilters' => ['period' => 7]])->assertOk();

    $dados = (fn (): array => $this->getCachedData())->call($componente->instance());

    expect($dados['labels'])->toHaveCount(7)
        ->and($dados['datasets'])->toHaveCount(2)
        ->and($dados['datasets'][0]['data'])->toHaveCount(7)
        // A requisição de hoje é o último ponto da série.
        ->and(end($dados['datasets'][0]['data']))->toBeGreaterThanOrEqual(1.0);

    // Trocar o período da PÁGINA reflete no widget — o filtro é um só.
    $noventa = Livewire::test(RequestsTrendChart::class, ['pageFilters' => ['period' => 90]])->assertOk();

    expect((fn (): array => $this->getCachedData())->call($noventa->instance())['labels'])->toHaveCount(90);
});

it('gráfico sem nenhum movimento mostra estado vazio ilustrado, não uma reta no zero', function () {
    $vazio = Livewire::test(UploadsPerDayChart::class)->assertOk()->instance();

    expect($vazio->isEmpty())->toBeTrue()
        ->and($vazio->getEmptyStateHeading())->toBe(__('admin.dashboards.common.empty_chart_heading'))
        ->and($vazio->getEmptyStateDescription())->toBe(__('admin.dashboards.common.empty_chart_description'));

    naConta(User::factory()->create(), fn () => Upload::query()->create([
        'disk' => 'public',
        'path' => 'uploads/exemplo.jpg',
        'original_name' => 'exemplo.jpg',
        'mime' => 'image/jpeg',
        'size' => 1024,
        'sha256' => str_repeat('a', 64),
    ]));

    expect(Livewire::test(UploadsPerDayChart::class)->assertOk()->instance()->isEmpty())->toBeFalse();
})->group('demo');

it('a tabela de últimos registros mostra o mais recente, sem paginação e com ver tudo', function () {
    FormSubmission::factory()
        ->count(12)
        ->sequence(fn ($sequencia) => ['created_at' => now()->subDays($sequencia->index + 1)])
        ->create();

    $recente = FormSubmission::factory()->create([
        'nickname' => 'ultima-mensagem',
        'created_at' => now(),
    ]);

    $componente = Livewire::test(LatestSubmissions::class)
        ->assertOk()
        // O mais recente aparece, e o link para a listagem completa também.
        ->assertSee('ultima-mensagem')
        ->assertSee(__('admin.dashboards.common.see_all'));

    // É um RESUMO: mostra só as N últimas linhas, sem paginação, mesmo com o
    // dobro de registros no banco.
    expect($componente->instance()->getTableRecords())
        ->toHaveCount((int) config('dashboards.latest_records'))
        ->and($recente->exists)->toBeTrue();
})->group('demo');

it('a tabela do dashboard nunca imprime payload de tentativa de ataque', function () {
    FormSubmission::factory()->blocked('xss')->create([
        'nickname' => "<script>alert('x')</script>",
        'created_at' => now(),
    ]);

    Livewire::test(LatestSubmissions::class)
        ->assertOk()
        ->assertDontSee('<script>', escape: false)
        ->assertSee(__('admin.submissions.attack_xss'));
})->group('demo');

it('as faixas de KPI das três variantes montam os quatro cards com dados reais', function () {
    logEm(now()->toDateTimeString());

    naConta(User::factory()->create(), fn () => Upload::query()->create([
        'disk' => 'public',
        'path' => 'uploads/exemplo.pdf',
        'original_name' => 'exemplo.pdf',
        'mime' => 'application/pdf',
        'size' => 2048,
        'sha256' => str_repeat('b', 64),
    ]));

    foreach ([OverviewStats::class, GrowthStats::class, ContentStats::class] as $widget) {
        $componente = Livewire::test($widget)->assertOk();

        $stats = (fn (): array => $this->getCachedStats())->call($componente->instance());

        expect($stats)->toHaveCount(4);

        foreach ($stats as $stat) {
            // Todo card diz a que período ele se compara — nunca um número solto.
            expect((string) $stat->getDescription())->not->toBeEmpty();
        }
    }

    Livewire::test(OverviewStats::class)->assertSee(__('admin.dashboards.overview.users'));
    Livewire::test(GrowthStats::class)->assertSee(__('admin.dashboards.growth.error_rate'));
    Livewire::test(ContentStats::class)->assertSee(__('admin.dashboards.content.storage'));
})->group('demo');
