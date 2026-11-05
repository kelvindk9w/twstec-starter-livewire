<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Database\AppendOnlyTables;
use Twstec\Kit\Foundation\Database\ApplicationRolePrivileges;
use Twstec\Kit\Foundation\Database\MaintenanceRolePrivileges;

// =============================================================================
// SEPARAÇÃO DE PAPÉIS NO POSTGRESQL (twstec/kit-foundation, Database\*).
//
// A aplicação conecta com um papel que não é dono de nada e que, nas tabelas
// só-acréscimo, só lê e insere; as migrations rodam com o papel de migração.
// Aqui o papel da aplicação é um papel de verdade, criado no banco de teste
// (e apagado no fim): `tws_kit_roles_probe`. As conexões `roles_owner` (o
// papel da suíte, dono do banco de teste) e `roles_app` (o papel de prova)
// são próprias — o que fazem é confirmado na hora, fora da transação do
// teste, para a outra conexão enxergar.
//
// O papel de MANUTENÇÃO (o das podas, sem a senha do dono no scheduler) é
// outro papel de prova, `tws_kit_maint_probe`, com a conexão `roles_maint`.
//
// Só no PostgreSQL (phpunit.pgsql.xml), e só se o papel da suíte puder criar
// papéis (superusuário ou CREATEROLE: o do Docker de desenvolvimento e o do
// CI podem).
// =============================================================================

const ROLES_PROBE = 'tws_kit_roles_probe';
const ROLES_PROBE_PASSWORD = 'tws-kit-roles-probe';
const ROLES_PROBE_MIGRATION = '2026_10_30_000000_create_roles_probe_table';
const MAINT_PROBE = 'tws_kit_maint_probe';
const MAINT_PROBE_PASSWORD = 'tws-kit-maint-probe';

/**
 * O erro do PostgreSQL de uma sentença rodada pelo papel da aplicação.
 */
function rolesProbeError(string $sql, string $connection = 'roles_app'): ?string
{
    try {
        DB::connection($connection)->statement($sql);
    } catch (QueryException $exception) {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode());
    }

    return null;
}

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Separação de papéis: só no PostgreSQL (phpunit.pgsql.xml).');
    }

    if (DB::scalar('SELECT rolsuper OR rolcreaterole FROM pg_roles WHERE rolname = current_user') !== true) {
        $this->markTestSkipped('O papel da suíte não pode criar papéis (superusuário ou CREATEROLE).');
    }

    app()->setLocale('pt_BR');

    $this->defaultConnection = (string) config('database.default');
    $suite = (array) config("database.connections.{$this->defaultConnection}");

    config([
        'database.connections.roles_owner' => $suite,
        'database.connections.roles_app' => array_merge($suite, ['username' => ROLES_PROBE, 'password' => ROLES_PROBE_PASSWORD]),
        'database.connections.roles_maint' => array_merge($suite, ['username' => MAINT_PROBE, 'password' => MAINT_PROBE_PASSWORD]),
    ]);
});

afterEach(function (): void {
    if (! isset($this->defaultConnection)) {
        return;
    }

    DB::setDefaultConnection($this->defaultConnection);
    DB::purge('roles_app');
    DB::purge('roles_maint');

    $owner = DB::connection('roles_owner');
    $owner->statement('DROP TABLE IF EXISTS tws_roles_probe');
    $owner->table('migrations')->where('migration', ROLES_PROBE_MIGRATION)->delete();

    // O rastro que as podas de prova gravaram (com o commit da conexão de
    // manutenção): sai pela mesma porta da poda, com a flag da transação.
    $owner->transaction(function () use ($owner): void {
        $owner->statement("SELECT set_config('tws.audit_prune', 'on', true)");
        $owner->table('audit_events')->whereIn('action', ['audit_event.pruned', 'outbound_http_log.pruned', 'tws_maint_probe.old'])->delete();
    });

    foreach ([ROLES_PROBE, MAINT_PROBE] as $probe) {
        if ($owner->scalar('SELECT count(*) FROM pg_roles WHERE rolname = ?', [$probe]) > 0) {
            $owner->statement('DROP OWNED BY '.$probe);
            $owner->statement('DROP ROLE '.$probe);
        }
    }

    DB::purge('roles_owner');
});

it('com o papel da aplicação, UPDATE, DELETE e TRUNCATE nas tabelas só-acréscimo falham por privilégio, não pelo gatilho', function (): void {
    app(ApplicationRolePrivileges::class)->sync(DB::connection('roles_owner'), ROLES_PROBE, ROLES_PROBE_PASSWORD);

    expect(array_keys(AppendOnlyTables::all()))->toContain('audit_events', 'request_logs', 'outbound_http_logs');

    foreach (array_keys(AppendOnlyTables::all()) as $table) {
        // 42501 = insufficient_privilege. O gatilho das trilhas responderia
        // P0001 (raise_exception): aqui ele nem chega a rodar.
        expect(rolesProbeError("UPDATE {$table} SET id = id WHERE false"))->toBe('42501', "UPDATE em {$table}")
            ->and(rolesProbeError("DELETE FROM {$table} WHERE false"))->toBe('42501', "DELETE em {$table}")
            ->and(rolesProbeError("TRUNCATE {$table}"))->toBe('42501', "TRUNCATE em {$table}")
            ->and(rolesProbeError("ALTER TABLE {$table} DISABLE TRIGGER ALL"))->toBe('42501', "desligar gatilho de {$table}")
            ->and(rolesProbeError("DROP TABLE {$table}"))->toBe('42501', "apagar {$table}")
            ->and(DB::connection('roles_app')->scalar("SELECT count(*) FROM {$table}"))->toBeInt();
    }

    // O ciclo de vida de request_logs continua: só as colunas liberadas.
    expect(rolesProbeError('UPDATE request_logs SET status = status, http_status_response = http_status_response, error_message = error_message, duration_ms = duration_ms, tenant_uuid = tenant_uuid WHERE false'))->toBeNull()
        ->and(rolesProbeError('UPDATE request_logs SET payload = payload WHERE false'))->toBe('42501')
        ->and(rolesProbeError('UPDATE request_logs SET endpoint = endpoint WHERE false'))->toBe('42501');

    // Fora das trilhas, a aplicação lê e escreve normalmente — mas não cria
    // estrutura nem mexe no registro das migrations.
    expect(rolesProbeError('UPDATE users SET name = name WHERE false'))->toBeNull()
        ->and(rolesProbeError('DELETE FROM users WHERE false'))->toBeNull()
        ->and(rolesProbeError('CREATE TABLE tws_roles_probe (id int)'))->toBe('42501')
        ->and(rolesProbeError('DELETE FROM migrations WHERE false'))->toBe('42501');

    $role = DB::connection('roles_owner')->selectOne('SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls FROM pg_roles WHERE rolname = ?', [ROLES_PROBE]);
    expect([$role->rolsuper, $role->rolcreaterole, $role->rolcreatedb, $role->rolbypassrls])->toBe([false, false, false, false]);
});

it('db:check-roles sai com erro conectando como dono e com sucesso com o papel da aplicação', function (): void {
    app(ApplicationRolePrivileges::class)->sync(DB::connection('roles_owner'), ROLES_PROBE, ROLES_PROBE_PASSWORD);

    // O papel da suíte é o dono do banco de teste (e, no Docker e no CI,
    // superusuário).
    expect(Artisan::call('db:check-roles', ['--connection' => 'roles_owner']))->toBe(1)
        ->and(Artisan::output())->toMatch('/superusuário|dono do banco|dono do esquema/');

    expect(Artisan::call('db:check-roles', ['--connection' => 'roles_app']))->toBe(0)
        ->and(Artisan::output())->toContain(ROLES_PROBE)->toContain('audit_events');
});

it('db:check-roles aponta privilégio proibido, coluna fora da lista e tabela do projeto sem a revogação', function (): void {
    $owner = DB::connection('roles_owner');
    app(ApplicationRolePrivileges::class)->sync($owner, ROLES_PROBE, ROLES_PROBE_PASSWORD);

    $owner->statement('GRANT DELETE ON audit_events TO '.ROLES_PROBE);
    $owner->statement('GRANT UPDATE (payload) ON request_logs TO '.ROLES_PROBE);

    // O projeto declara uma tabela dele (aqui, `users`): o papel ainda tem
    // UPDATE e DELETE nela até o próximo migrate.
    config(['database_roles.append_only_tables' => ['users']]);

    expect(Artisan::call('db:check-roles', ['--connection' => 'roles_app']))->toBe(1);
    $output = Artisan::output();

    expect($output)->toContain('DELETE na tabela só-acréscimo audit_events')
        ->toContain('coluna payload da tabela só-acréscimo request_logs')
        ->toContain('UPDATE na tabela só-acréscimo users')
        ->toContain('DELETE na tabela só-acréscimo users')
        ->not->toContain('superusuário');

    // O acerto (o que todo migrate faz) resolve tudo — inclusive a tabela
    // do projeto, que passa a recusar UPDATE e DELETE por privilégio.
    app(ApplicationRolePrivileges::class)->sync($owner, ROLES_PROBE, ROLES_PROBE_PASSWORD);

    expect(Artisan::call('db:check-roles', ['--connection' => 'roles_app']))->toBe(0)
        ->and(rolesProbeError('DELETE FROM users WHERE false'))->toBe('42501')
        ->and(rolesProbeError('SELECT 1 FROM users LIMIT 1'))->toBeNull();
});

it('as migrations rodam com o papel de migração e terminam acertando os privilégios do papel da aplicação', function (): void {
    $owner = DB::connection('roles_owner');
    $ownerName = (string) $owner->scalar('SELECT current_user');

    // A aplicação conecta como o papel de prova; o papel de migração é o da
    // suíte. O papel de prova ainda NÃO existe: o migrate o cria.
    config([
        'database.default' => 'roles_app',
        'database_roles.migration.username' => $ownerName,
        'database_roles.migration.password' => (string) config('database.connections.roles_owner.password'),
        'database_roles.migration_connection' => 'roles_owner',
    ]);
    DB::setDefaultConnection('roles_app');

    // Fora dos testes, o Laravel transforma os eventos do Symfony em
    // CommandStarting/CommandFinished; na suíte, é preciso pedir.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);

    $exit = Artisan::call('migrate', [
        '--path' => base_path('tests/Fixtures/DatabaseRoles'),
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(DB::getDefaultConnection())->toBe('roles_app')
        ->and($owner->scalar('SELECT migrated_by FROM tws_roles_probe'))->toBe($ownerName)
        ->and($owner->scalar("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'tws_roles_probe'::regclass"))->toBe($ownerName);

    // O papel da aplicação foi criado sem atributo especial e já usa a tabela
    // nova — sem ser dono dela nem poder criar outra.
    expect(DB::connection('roles_app')->scalar('SELECT count(*) FROM tws_roles_probe'))->toBe(1)
        ->and(rolesProbeError("INSERT INTO tws_roles_probe (migrated_by) VALUES ('app')"))->toBeNull()
        ->and(rolesProbeError('ALTER TABLE tws_roles_probe ADD COLUMN x int'))->toBe('42501')
        ->and(rolesProbeError('DELETE FROM audit_events WHERE false'))->toBe('42501');

    expect(Artisan::call('db:check-roles'))->toBe(0);
});

// -----------------------------------------------------------------------------
// PAPEL DE MANUTENÇÃO: só lê e apaga nas trilhas com poda (e grava o rastro
// da poda na auditoria). O scheduler o usa no lugar do dono.
// -----------------------------------------------------------------------------

/**
 * Os dois papéis de prova com os privilégios do `migrate`.
 */
function syncProbeRoles(): void
{
    $owner = DB::connection('roles_owner');

    app(ApplicationRolePrivileges::class)->sync($owner, ROLES_PROBE, ROLES_PROBE_PASSWORD);
    app(MaintenanceRolePrivileges::class)->sync($owner, MAINT_PROBE, MAINT_PROBE_PASSWORD);
}

/**
 * O processo como o scheduler de produção: a aplicação com o papel de prova,
 * o papel de manutenção definido e SEM o papel de migração.
 */
function actAsScheduler(): void
{
    config([
        'database.default' => 'roles_app',
        'database_roles.migration.username' => null,
        'database_roles.migration.password' => null,
        'database_roles.maintenance.username' => MAINT_PROBE,
        'database_roles.maintenance.password' => MAINT_PROBE_PASSWORD,
        'database_roles.maintenance_connection' => 'roles_maint',
    ]);
    DB::setDefaultConnection('roles_app');

    // Fora dos testes, o Laravel transforma os eventos do Symfony em
    // CommandStarting/CommandFinished; na suíte, é preciso pedir.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
}

it('o papel de manutenção só lê e apaga nas trilhas com poda: o resto falha por privilégio', function (): void {
    syncProbeRoles();

    expect(rolesProbeError('DELETE FROM outbound_http_logs WHERE false', 'roles_maint'))->toBeNull()
        ->and(rolesProbeError('DELETE FROM audit_events WHERE false', 'roles_maint'))->toBeNull()
        ->and(DB::connection('roles_maint')->scalar('SELECT count(*) FROM audit_events'))->toBeInt();

    foreach (['audit_events', 'outbound_http_logs'] as $table) {
        expect(rolesProbeError("UPDATE {$table} SET id = id WHERE false", 'roles_maint'))->toBe('42501', "UPDATE em {$table}")
            ->and(rolesProbeError("TRUNCATE {$table}", 'roles_maint'))->toBe('42501', "TRUNCATE em {$table}")
            ->and(rolesProbeError("ALTER TABLE {$table} DISABLE TRIGGER ALL", 'roles_maint'))->toBe('42501', "desligar gatilho de {$table}")
            ->and(rolesProbeError("DROP TABLE {$table}", 'roles_maint'))->toBe('42501', "apagar {$table}");
    }

    // Trilha só-acréscimo sem poda, tabela do domínio e o registro das
    // migrations: nem leitura.
    expect(rolesProbeError('DELETE FROM request_logs WHERE false', 'roles_maint'))->toBe('42501')
        ->and(rolesProbeError('SELECT 1 FROM request_logs LIMIT 1', 'roles_maint'))->toBe('42501')
        ->and(rolesProbeError('DELETE FROM users WHERE false', 'roles_maint'))->toBe('42501')
        ->and(rolesProbeError('SELECT 1 FROM users LIMIT 1', 'roles_maint'))->toBe('42501')
        ->and(rolesProbeError("INSERT INTO outbound_http_logs (uuid, method, host, path, duration_ms) VALUES (gen_random_uuid(), 'GET', 'x', '/', 1)", 'roles_maint'))->toBe('42501')
        ->and(rolesProbeError('DELETE FROM migrations WHERE false', 'roles_maint'))->toBe('42501')
        ->and(rolesProbeError('CREATE TABLE tws_roles_probe (id int)', 'roles_maint'))->toBe('42501');

    $role = DB::connection('roles_owner')->selectOne('SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls FROM pg_roles WHERE rolname = ?', [MAINT_PROBE]);
    expect([$role->rolsuper, $role->rolcreaterole, $role->rolcreatedb, $role->rolbypassrls])->toBe([false, false, false, false]);
});

it('as podas rodam com o papel de manutenção num processo sem a senha do dono, apagam o que venceu e deixam o rastro', function (): void {
    syncProbeRoles();
    $owner = DB::connection('roles_owner');

    $owner->table('audit_events')->insert([
        'uuid' => (string) Str::uuid(), 'occurred_at' => '2000-01-01 00:00:00+00', 'context' => 'console',
        'action' => 'tws_maint_probe.old', 'outcome' => 'success',
    ]);
    $owner->table('outbound_http_logs')->insert([
        'uuid' => (string) Str::uuid(), 'method' => 'GET', 'host' => 'maint-probe.invalid', 'path' => '/',
        'duration_ms' => 1, 'created_at' => '2000-01-01 00:00:00+00',
    ]);

    actAsScheduler();

    expect(Artisan::call('audit:prune', ['--days' => 30]))->toBe(0)
        ->and(Artisan::call('outbound-http:prune', ['--days' => 30]))->toBe(0)
        ->and(DB::getDefaultConnection())->toBe('roles_app');

    expect($owner->table('audit_events')->where('action', 'tws_maint_probe.old')->count())->toBe(0)
        ->and($owner->table('outbound_http_logs')->where('host', 'maint-probe.invalid')->count())->toBe(0)
        ->and($owner->table('audit_events')->where('action', 'audit_event.pruned')->count())->toBeGreaterThanOrEqual(1)
        ->and($owner->table('audit_events')->where('action', 'outbound_http_log.pruned')->count())->toBeGreaterThanOrEqual(1);
});

it('db:check-roles confere o papel de manutenção: aprova o acertado e aponta privilégio a mais, a menos e papel que falta', function (): void {
    syncProbeRoles();
    actAsScheduler();

    expect(Artisan::call('db:check-roles'))->toBe(0)
        ->and(Artisan::output())->toContain('O papel de manutenção '.MAINT_PROBE)->toContain('outbound_http_logs');

    $owner = DB::connection('roles_owner');
    $owner->statement('GRANT DELETE ON request_logs TO '.MAINT_PROBE);
    $owner->statement('GRANT UPDATE ON audit_events TO '.MAINT_PROBE);
    $owner->statement('GRANT SELECT ON users TO '.MAINT_PROBE);
    $owner->statement('GRANT UPDATE (name) ON users TO '.MAINT_PROBE);
    $owner->statement('REVOKE DELETE ON outbound_http_logs FROM '.MAINT_PROBE);

    expect(Artisan::call('db:check-roles'))->toBe(1);
    $output = Artisan::output();

    expect($output)->toContain(MAINT_PROBE.' tem DELETE na tabela request_logs')
        ->toContain(MAINT_PROBE.' tem UPDATE na tabela audit_events')
        ->toContain(MAINT_PROBE.' tem SELECT na tabela users')
        ->toContain(MAINT_PROBE.' pode alterar colunas da tabela users')
        ->toContain(MAINT_PROBE.' não tem DELETE na tabela outbound_http_logs');

    // O acerto (o que todo migrate faz) tira o que sobrava e devolve o que
    // faltava.
    app(MaintenanceRolePrivileges::class)->sync($owner, MAINT_PROBE, MAINT_PROBE_PASSWORD);
    expect(Artisan::call('db:check-roles'))->toBe(0);

    config(['database_roles.maintenance.username' => 'tws_kit_maint_ausente']);
    expect(Artisan::call('db:check-roles'))->toBe(1)
        ->and(Artisan::output())->toContain('tws_kit_maint_ausente não existe');
});

it('o migrate com o papel de manutenção definido cria o papel e acerta os privilégios dele', function (): void {
    $owner = DB::connection('roles_owner');
    $ownerName = (string) $owner->scalar('SELECT current_user');

    config([
        'database.default' => 'roles_app',
        'database_roles.migration.username' => $ownerName,
        'database_roles.migration.password' => (string) config('database.connections.roles_owner.password'),
        'database_roles.migration_connection' => 'roles_owner',
        'database_roles.maintenance.username' => MAINT_PROBE,
        'database_roles.maintenance.password' => MAINT_PROBE_PASSWORD,
        'database_roles.maintenance_connection' => 'roles_maint',
    ]);
    DB::setDefaultConnection('roles_app');

    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);

    expect($owner->scalar('SELECT count(*) FROM pg_roles WHERE rolname = ?', [MAINT_PROBE]))->toBe(0);

    expect(Artisan::call('migrate', [
        '--path' => base_path('tests/Fixtures/DatabaseRoles'),
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    // A tabela nova da migration não dá nada ao papel de manutenção.
    expect(rolesProbeError('DELETE FROM outbound_http_logs WHERE false', 'roles_maint'))->toBeNull()
        ->and(rolesProbeError('SELECT 1 FROM tws_roles_probe', 'roles_maint'))->toBe('42501')
        ->and(Artisan::call('db:check-roles'))->toBe(0);
});
