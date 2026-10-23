<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use UBO\Common\SqlServer\SqlServerServiceProvider;
use UBO\Common\Tests\TestCase;

/**
 * A connection that never reaches a server and records its unprepared statements.
 */
final class RecordingConnection extends Connection
{
    /** @var list<string> */
    public array $statements = [];

    public function unprepared($query): bool
    {
        $this->statements[] = $query;

        return true;
    }
}

/**
 * Registers a connection named $name with the given driver, without opening it.
 */
function fakeConnection(string $name, string $driver): void
{
    config(["database.connections.{$name}" => ['driver' => $driver, 'name' => $name]]);

    DB::extend($name, fn (array $config) => new RecordingConnection(fn () => null, '', '', $config));
}

function enableSqlServerModule(array $config = []): void
{
    TestCase::$bootConfig = ['ubo-common.modules.sqlserver' => true, ...$config];
    test()->refreshApplication();
}

test('the module is off by default', function () {
    fakeConnection('sqlsrv-app', 'sqlsrv');

    expect(DB::connection('sqlsrv-app')->statements)->toBe([])
        ->and(config('ubo-sqlserver'))->toBeNull();
});

test('each sqlsrv connection uses the ymd date format when it opens', function () {
    enableSqlServerModule();
    fakeConnection('sqlsrv-app', 'sqlsrv');

    expect(DB::connection('sqlsrv-app')->statements)->toBe(['SET DATEFORMAT ymd']);
});

test('connections opened before the module booted are also fixed', function () {
    enableSqlServerModule();
    fakeConnection('sqlsrv-app', 'sqlsrv');
    $connection = DB::connection('sqlsrv-app');
    $connection->statements = [];

    (new SqlServerServiceProvider(app()))->boot();

    expect($connection->statements)->toBe(['SET DATEFORMAT ymd']);
});

test('other drivers are left alone', function () {
    enableSqlServerModule();
    fakeConnection('sqlite-app', 'sqlite');

    expect(DB::connection('sqlite-app')->statements)->toBe([]);
});

test('the date format is configurable', function () {
    enableSqlServerModule(['ubo-sqlserver.date_format' => 'DMY']);
    fakeConnection('sqlsrv-app', 'sqlsrv');

    expect(DB::connection('sqlsrv-app')->statements)->toBe(['SET DATEFORMAT dmy']);
});

test('an unknown date format fails loudly instead of reaching SQL Server', function () {
    enableSqlServerModule(['ubo-sqlserver.date_format' => 'ymd; DROP TABLE users']);
    fakeConnection('sqlsrv-app', 'sqlsrv');

    DB::connection('sqlsrv-app');
})->throws(InvalidArgumentException::class, 'Unknown SQL Server date format');
