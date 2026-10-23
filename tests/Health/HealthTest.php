<?php

use Illuminate\Support\Facades\Route;
use UBO\Common\Health\DatabaseHealth;
use UBO\Common\Health\Http\Controllers\HealthController;

beforeEach(function () {
    config([
        'database.default' => 'testing',
        'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'database.connections.down' => ['driver' => 'sqlite', 'database' => __DIR__.'/missing/database.sqlite'],
    ]);

    // What the application does in routes/api.php.
    Route::get('api/v1/salud', HealthController::class);
});

test('a database that answers is healthy', function () {
    $this->getJson('api/v1/salud')
        ->assertOk()
        ->assertExactJson(['estado' => 'OK', 'base_datos' => 'OK']);
});

test('a database that does not answer is a 503', function () {
    config(['database.default' => 'down']);

    $this->getJson('api/v1/salud')
        ->assertStatus(503)
        ->assertExactJson(['estado' => 'ERROR', 'base_datos' => 'NO_DISPONIBLE']);
});

test('the checked connection is configurable', function () {
    config(['ubo-health.connection' => 'down']);

    expect(app(DatabaseHealth::class)->isAvailable())->toBeFalse();

    config(['ubo-health.connection' => 'testing']);

    expect(app(DatabaseHealth::class)->isAvailable())->toBeTrue();
});

test('application tests can simulate a database that is down', function () {
    $this->mock(DatabaseHealth::class)->shouldReceive('isAvailable')->andReturnFalse();

    $this->getJson('api/v1/salud')->assertStatus(503);
});
