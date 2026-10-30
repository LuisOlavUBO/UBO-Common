<?php

namespace UBO\Common\Login;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use LogicException;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\UserTokenClient;
use UBO\Common\Login\Domain\UboUser;
use UBO\Common\Login\Http\Controllers\LoginController;
use UBO\Common\Login\Http\Middleware\EnsureLoggedIn;
use UBO\Common\Login\Http\Middleware\RequireRole;
use UBO\Common\Login\Infrastructure\UboGuard;
use UBO\Common\Support\ModuleServiceProvider;

/**
 * Login module: lets people of a Laravel application log in with IAM (API-Auth) through
 * Authorization Code with PKCE. Registers its routes, the "ubo" guard, the "ubo.login" and
 * "ubo.role" middleware and the Gate rule that turns the scopes of the person into abilities.
 * Off by default (ubo-common.modules.login).
 */
final class LoginServiceProvider extends ModuleServiceProvider
{
    protected function configName(): string
    {
        return 'ubo-login';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-login.php';
    }

    protected function registerModule(): void
    {
        // Resolved on every use (not a singleton) so config changes, e.g. in tests, apply at once.
        $this->app->bind(LoginSettings::class, function (Application $app) {
            if (! $app->bound(UserTokenClient::class)) {
                throw new LogicException('The login module needs the auth module enabled (ubo-common.modules.auth).');
            }

            return LoginSettings::fromConfig(
                (array) $app['config']->get('ubo-login'),
                $app->make(AuthSettings::class),
                $app['config']->get('app.url'),
            );
        });
    }

    protected function bootModule(): void
    {
        $this->registerGuard();
        $this->registerRoutes();
        $this->registerMiddleware();
        $this->registerAbilities();
    }

    private function registerGuard(): void
    {
        $this->app['auth']->extend('ubo', fn (Application $app) => new UboGuard(
            fn () => $app['request'],
            $app->make(AuthSettings::class),
        ));

        // Available as auth:ubo without touching config/auth.php; the default guard stays as it is.
        if (! $this->app['config']->has('auth.guards.ubo')) {
            $this->app['config']->set('auth.guards.ubo', ['driver' => 'ubo']);
        }
    }

    private function registerRoutes(): void
    {
        Route::middleware('web')
            ->prefix(LoginSettings::routePrefixFrom((array) $this->app['config']->get('ubo-login')))
            ->group(function () {
                Route::get('login', [LoginController::class, 'start'])->name('ubo.login');
                Route::get('callback', [LoginController::class, 'callback'])->name('ubo.login.callback');
                Route::post('logout', [LoginController::class, 'logout'])->name('ubo.logout');
            });
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('ubo.login', EnsureLoggedIn::class);
        $router->aliasMiddleware('ubo.role', RequireRole::class);
    }

    /**
     * The scopes of the person are abilities. Never denies: an ability that is not one of their
     * scopes is left to the policies and Gate::define rules of the application.
     */
    private function registerAbilities(): void
    {
        $this->app->make(Gate::class)->before(
            fn ($user, string $ability) => $user instanceof UboUser && $user->hasScope($ability) ? true : null,
        );
    }
}
