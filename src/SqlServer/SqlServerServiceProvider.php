<?php

namespace UBO\Common\SqlServer;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use UBO\Common\Support\ModuleServiceProvider;

/**
 * SqlServer module: fixes the date format of every sqlsrv connection. The shared SQL
 * Server logins default to Spanish (dmy), which misreads Laravel's "Y-m-d H:i:s" dates.
 * Off by default: it changes how the application's connections behave.
 */
final class SqlServerServiceProvider extends ModuleServiceProvider
{
    protected function configName(): string
    {
        return 'ubo-sqlserver';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-sqlserver.php';
    }

    protected function bootModule(): void
    {
        $this->app['events']->listen(
            ConnectionEstablished::class,
            fn (ConnectionEstablished $event) => $this->prepare($event->connection),
        );

        // Connections opened before this provider booted.
        foreach ($this->app['db']->getConnections() as $connection) {
            $this->prepare($connection);
        }
    }

    private function prepare(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlsrv') {
            return;
        }

        $format = SqlServerSettings::fromConfig((array) $this->app['config']->get('ubo-sqlserver'))->dateFormat;

        // unprepared(): a SET inside a prepared statement only lasts for that batch.
        $connection->unprepared("SET DATEFORMAT {$format}");
    }
}
