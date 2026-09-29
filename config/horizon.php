<?php

// =============================================================================
// Horizon: supervisor de filas Redis + dashboard /horizon.
//
// Acesso ao dashboard: gate viewHorizon (is_admin + conta ativa — ver
// App\Providers\HorizonServiceProvider) + barreira de origem do admin
// (EnsureAdminIpAllowed no middleware abaixo). Em produção as filas rodam no
// serviço `horizon` do docker-compose.prod.yml (php artisan horizon).
// =============================================================================

use Illuminate\Support\Str;
use Laravel\Horizon\Http\Middleware\Authenticate;
use Twstec\Kit\Foundation\Security\Middleware\EnsureAdminIpAllowed;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    // Duas barreiras, na ordem em que custam menos:
    //
    //   EnsureAdminIpAllowed — barreira de ORIGEM, a mesma do painel /admin
    //   (allowlist de IP). Vem antes da autenticação de propósito: uma
    //   origem não permitida é recusada sem que a sessão seja nem consultada.
    //
    //   Authenticate (do pacote) — barreira de IDENTIDADE: roda o gate
    //   viewHorizon (App\Providers\HorizonServiceProvider), que exige is_admin
    //   + conta ativa fora do ambiente local.
    //
    // POR QUE O Authenticate ESTÁ ESCRITO AQUI: o Horizon já o aplica, mas de
    // dentro do CONSTRUTOR do controller base dele
    // (Laravel\Horizon\Http\Controllers\Controller). Ou seja, a autorização do
    // dashboard dependia de um detalhe interno do vendor — de todo controller
    // do pacote continuar herdando daquela classe e de middleware declarado em
    // construtor de controller continuar existindo no Laravel. Declarado no
    // grupo de rotas, o gate passa a valer por contrato nosso. Rodar o gate
    // duas vezes é inofensivo: Gate::check é consulta pura, sem efeito.
    //
    // (Cuidado ao ler a pilha efetiva: o pacote ainda prepõe o
    // SentinelMiddleware a esta lista, que NÃO é uma barreira de produção —
    // o driver dele devolve `true` de imediato fora do ambiente local.)
    'middleware' => ['web', EnsureAdminIpAllowed::class, Authenticate::class],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    // Por quanto tempo (minutos) o Horizon guarda cada job no Redis — COM o
    // payload, que é o que o dashboard /horizon exibe.
    //
    // O payload dos jobs de e-mail e notificação do kit sai CRIPTOGRAFADO com a
    // APP_KEY (ShouldBeEncrypted em Twstec\Kit\Foundation\Mail\KitMailable e na
    // ResetPasswordNotification): código de verificação, token de redefinição
    // de senha, destinatário e mensagem do formulário de contato ficam
    // ilegíveis aqui. Job NOVO que carregue dado pessoal ou segredo deve fazer o
    // mesmo — o teste de arquitetura em tests/Feature/Horizon cobra isso de todo
    // Mailable e Notification enfileirável.
    //
    // A retenção continua sendo a segunda linha: o que não precisa ficar, não
    // fica. Concluídos saem em 1 hora; falhos ficam 7 dias por padrão — o tempo
    // de alguém ver o alerta, investigar e dar retry. A tabela `failed_jobs`
    // (o registro do framework, fora do Redis) segue a mesma janela pela poda
    // agendada em routes/console.php (QUEUE_FAILED_RETENTION_HOURS).
    'trim' => [
        'recent' => (int) env('HORIZON_TRIM_RECENT_MINUTES', 60),
        'pending' => (int) env('HORIZON_TRIM_RECENT_MINUTES', 60),
        'completed' => (int) env('HORIZON_TRIM_RECENT_MINUTES', 60),
        'recent_failed' => (int) env('HORIZON_TRIM_FAILED_MINUTES', 10080),
        'failed' => (int) env('HORIZON_TRIM_FAILED_MINUTES', 10080),
        'monitored' => (int) env('HORIZON_TRIM_FAILED_MINUTES', 10080),
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESSES', 10),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => (int) env('HORIZON_MAX_PROCESSES_LOCAL', 3),
            ],
        ],

        // O ambiente de TESTES usa o mínimo possível (pest não sobe o
        // Horizon; o valor existe só para a config nunca quebrar).
        'testing' => [
            'supervisor-1' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
