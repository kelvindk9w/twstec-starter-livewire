<?php

namespace Tests;

use App\Providers\Filament\AdminPanelProvider;
use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use RuntimeException;
use Twstec\Kit\Foundation\Kit;

abstract class TestCase extends BaseTestCase
{
    /**
     * Grupo dos testes que exercitam a DEMONSTRAÇÃO do kit (twstec/kit-demo):
     * tudo em tests/Demo e os casos do produto marcados com `->group('demo')`.
     */
    public const DEMO_GROUP = 'demo';

    /*
     * MÓDULOS OPCIONAIS (twstec/kit-accounts, twstec/kit-uploads,
     * twstec/kit-admin — escolhidos no `php artisan tws:install`): o teste
     * que exercita um deles fica no grupo com o NOME DO MÓDULO (`accounts`,
     * `uploads`, `admin`) — por pasta, em tests/Pest.php, ou caso a caso com
     * `->group(...)`. Sem o módulo instalado (Kit::has), o teste PULA, com o
     * motivo: a suíte de qualquer combinação é o `pest` de sempre.
     */

    /**
     * A demonstração é um pacote de desenvolvimento (require-dev) que pode
     * ser removido (`composer remove --dev twstec/kit-demo`). Sem ela, os
     * testes do grupo `demo` PULAM, com o motivo — a suíte do produto passa
     * com o `pest` de sempre, sem filtro de grupo.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(self::DEMO_GROUP, $this->groups(), true) && ! self::demoInstalled()) {
            $this->markTestSkipped('Demonstração do kit (twstec/kit-demo) não instalada.');
        }

        foreach (Kit::OPTIONAL as $module) {
            if (in_array($module, $this->groups(), true) && ! Kit::has($module)) {
                $this->markTestSkipped(sprintf('Módulo opcional %s (%s) não instalado.', $module, Kit::package($module)));
            }
        }
    }

    /**
     * Módulo fingido ausente (Kit::pretendAbsent) não vaza para o próximo
     * teste.
     */
    protected function tearDown(): void
    {
        Kit::flushFakes();

        parent::tearDown();
    }

    /**
     * Classes do APLICATIVO que só carregam com um módulo opcional instalado
     * (estendem ou implementam uma classe dele) => o módulo. Sem o módulo o
     * aplicativo não as registra; os testes que varrem app/ com class_exists
     * as pulam em vez de derrubar a suíte.
     *
     * @var array<class-string, string>
     */
    public const MODULE_ONLY_APP_CLASSES = [
        AdminPanelProvider::class => 'admin',
    ];

    /**
     * A classe do aplicativo carrega nesta instalação?
     */
    public static function appClassLoadable(string $class): bool
    {
        $module = self::MODULE_ONLY_APP_CLASSES[$class] ?? null;

        return $module === null || Kit::has($module);
    }

    /**
     * O pacote da demonstração está instalado neste aplicativo?
     */
    public static function demoInstalled(): bool
    {
        return InstalledVersions::isInstalled('twstec/kit-demo');
    }

    /**
     * Trava de segurança: a suíte APAGA o banco (RefreshDatabase roda
     * `migrate:fresh`). Em SQLite ela usa memória; em qualquer outro banco,
     * só aceita um banco cujo nome termine em `_test` — nunca o banco de
     * desenvolvimento do .env, nem o de produção.
     *
     * Roda antes das traits (é nelas que o banco é recriado).
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Suíte recusada: o banco "%s" (%s) não é de teste. Use um banco cujo nome termine em _test (ver phpunit.pgsql.xml e docs/testes.md).',
                $database,
                $driver,
            ));
        }

        if ($driver === 'pgsql') {
            self::lockSuiteDatabase((array) config("database.connections.{$connection}"));
        }

        return parent::setUpTraits();
    }

    /**
     * Conexão que segura a trava da suíte no banco de teste, aberta uma vez
     * por processo e mantida até ele acabar (estática: sobrevive à aplicação
     * que cada teste recria).
     */
    private static ?PDO $suiteLock = null;

    /**
     * UMA SUÍTE POR VEZ NO BANCO DE TESTE (PostgreSQL). Duas execuções
     * simultâneas contra o mesmo `<banco>_test` se atropelam: o
     * `migrate:fresh` de uma apaga as tabelas no meio da outra, e o que um
     * teste grava com commit (as conexões próprias dos testes de concorrência
     * e do gatilho das contas demo) aparece na contagem de outro — falhas que
     * vão e vêm conforme o horário. A primeira execução pega uma trava
     * consultiva (`pg_try_advisory_lock`) numa conexão própria e a segura até
     * acabar; a segunda para logo, com o motivo, em vez de falhar ao acaso.
     *
     * @param  array<string, mixed>  $config
     */
    private static function lockSuiteDatabase(array $config): void
    {
        if (self::$suiteLock !== null) {
            return;
        }

        $database = (string) ($config['database'] ?? '');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'] ?? '127.0.0.1', $config['port'] ?? 5432, $database),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $statement = $pdo->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $statement->execute(['tws-kit-test-suite:'.$database]);

        if ($statement->fetchColumn() !== true) {
            throw new RuntimeException(sprintf(
                'Suíte recusada: outra execução está usando o banco de teste "%s" agora. Duas suítes ao mesmo tempo no mesmo banco se atropelam (migrate:fresh, linhas com commit) e dão falhas ao acaso — espere a outra terminar.',
                $database,
            ));
        }

        self::$suiteLock = $pdo;
    }
}
