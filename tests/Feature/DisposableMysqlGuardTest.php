<?php

require_once __DIR__.'/../Mysql/DisposableMysqlGuard.php';

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->originalOptIn = [$_ENV['MYSQL_CONCURRENCY_TESTS'] ?? null, $_SERVER['MYSQL_CONCURRENCY_TESTS'] ?? null];
    $_ENV['MYSQL_CONCURRENCY_TESTS'] = $_SERVER['MYSQL_CONCURRENCY_TESTS'] = '1';
    app()->instance('env', 'testing');
    app()->instance('config_loaded_from_cache', false);
    config(['app.env' => 'testing', 'database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'mysql', 'database' => 'pami_phase1b_abc123_test', 'username' => 'pami_test', 'url' => null,
    ]]);
});

afterEach(function () {
    foreach (['_ENV', '_SERVER'] as $index => $variable) {
        if ($this->originalOptIn[$index] === null) {
            unset($GLOBALS[$variable]['MYSQL_CONCURRENCY_TESTS']);
        } else {
            $GLOBALS[$variable]['MYSQL_CONCURRENCY_TESTS'] = $this->originalOptIn[$index];
        }
    }
});

/** Mock only server metadata; the guard itself and Laravel configuration remain real. */
function disposableMysqlMetadata(string $database = 'pami_phase1b_abc123_test', ?array $grants = null, ?array $schemas = null, string $account = 'pami_test@localhost'): void
{
    DB::shouldReceive('selectOne')->with('SELECT DATABASE() AS db, CURRENT_USER() AS account')
        ->andReturn((object) ['db' => $database, 'account' => $account]);
    DB::shouldReceive('select')->with('SHOW GRANTS FOR CURRENT_USER()')->andReturn(array_map(
        fn (string $grant): object => (object) ['Grants' => $grant],
        $grants ?? ['GRANT USAGE ON *.* TO `pami_test`@`localhost`', 'GRANT ALL PRIVILEGES ON `pami\\_phase1b\\_%\\_test`.* TO `pami_test`@`localhost`'],
    ));
    DB::shouldReceive('select')->with('SELECT schema_name AS name FROM information_schema.schemata')->andReturn(array_map(
        fn (string $name): object => (object) ['name' => $name],
        $schemas ?? ['information_schema', 'performance_schema', $database],
    ));
}

it('accepts isolated payment and legacy namespaces', function (string $database, string $scope) {
    config(['database.connections.mysql.database' => $database]);
    disposableMysqlMetadata($database, ['GRANT ALL PRIVILEGES ON `'.$scope.'`.* TO `pami_test`@`localhost`']);

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->not->toThrow(RuntimeException::class);
})->with([
    ['pami_payment_a1b2c3_test', 'pami\\_payment\\_%\\_test'],
    ['pami_phase1b_a1b2c3_test', 'pami\\_phase1b\\_%\\_test'],
    ['pami_doku_a1b2c3_test', 'pami\\_doku\\_%\\_test'],
]);

it('rejects unsafe runtime configuration before querying MySQL', function (string $key, mixed $value) {
    if ($key === 'environment') {
        app()->instance('env', $value);
    } elseif ($key === 'cached') {
        app()->instance('config_loaded_from_cache', $value);
    } elseif ($key === 'opt_in') {
        $_ENV['MYSQL_CONCURRENCY_TESTS'] = $_SERVER['MYSQL_CONCURRENCY_TESTS'] = $value;
    } else {
        config([$key => $value]);
    }
    DB::shouldReceive('select')->never()->andReturn([]);
    DB::shouldReceive('selectOne')->never();

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->toThrow(RuntimeException::class);
})->with([
    ['environment', 'production'],
    ['app.env', 'production'],
    ['opt_in', '0'],
    ['opt_in', 'false'],
    ['opt_in', 'yes'],
    ['cached', true],
    ['database.default', 'sqlite'],
    ['database.connections.mysql.driver', 'sqlite'],
    ['database.connections.mysql.url', 'mysql://other.invalid/application'],
    ['database.connections.mysql.read', []],
    ['database.connections.mysql.write', []],
    ['database.connections.mysql.username', ''],
    ['database.connections.mysql.username', 'root'],
    ['database.connections.mysql.username', 'ROOT'],
    ['database.connections.mysql.username', 'payment_admin'],
    ['database.connections.mysql.database', 'wisata_daerahku_test'],
    ['database.connections.mysql.database', 'pami_phase1b_demo_abc_test'],
    ['database.connections.mysql.database', 'pami_payment__test'],
    ['database.connections.mysql.database', 'pami_payment_abcdefghijklmnop_test'],
]);

it('rejects a connection that differs from the active configuration before querying', function () {
    $connection = config('database.connections.mysql');
    $connection['database'] = 'pami_phase1b_different_test';
    DB::shouldReceive('select')->never()->andReturn([]);
    DB::shouldReceive('selectOne')->never();

    expect(fn () => assertDisposableMysqlAccount($connection))->toThrow(RuntimeException::class);
});

it('rejects a worker whose expected database differs before querying', function () {
    DB::shouldReceive('select')->never()->andReturn([]);
    DB::shouldReceive('selectOne')->never();

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql'), 'pami_payment_other_test'))->toThrow(RuntimeException::class);
});

it('rejects a different selected database', function () {
    disposableMysqlMetadata('pami_phase1b_other_test', schemas: ['information_schema', 'pami_phase1b_abc123_test']);

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->toThrow(RuntimeException::class);
});

it('rejects unexpected authenticated accounts', function (string $account) {
    disposableMysqlMetadata(account: $account);

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->toThrow(RuntimeException::class);
})->with(['root@localhost', 'pami_admin@localhost', 'other_test@localhost', '']);

it('rejects grants outside the disposable scope', function (array $grants) {
    disposableMysqlMetadata(grants: $grants);

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->toThrow(RuntimeException::class);
})->with([
    [[]],
    [['GRANT USAGE ON *.* TO `pami_test`@`localhost`']],
    [['GRANT ALL PRIVILEGES ON *.* TO `pami_test`@`localhost`']],
    [['GRANT ALL PRIVILEGES ON `pami_phase1b_%_test`.* TO `pami_test`@`localhost`']],
    [['GRANT ALL PRIVILEGES ON `pami\\_phase1b\\_%\\_test`.* TO `pami_test`@`localhost` WITH GRANT OPTION']],
    [['GRANT ALL PRIVILEGES ON `pami\\_payment\\_%\\_test`.* TO `pami_test`@`localhost`']],
]);

it('rejects visible non-disposable schemas', function (string $schema) {
    disposableMysqlMetadata(schemas: ['information_schema', 'pami_phase1b_abc123_test', $schema]);

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->toThrow(RuntimeException::class);
})->with(['application', 'mysql', 'wisata_daerahku_test', 'pami_phase1b_demo_abc_test']);

it('rejects a bootstrap database absent from visible schemas', function () {
    disposableMysqlMetadata(schemas: ['information_schema']);

    expect(fn () => assertDisposableMysqlAccount(config('database.connections.mysql')))->toThrow(RuntimeException::class);
});
