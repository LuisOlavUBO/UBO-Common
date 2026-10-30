<?php

namespace UBO\Common\Auth;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use UBO\Common\Auth\Console\CheckCommand;
use UBO\Common\Auth\Contracts\LocalTokenValidator;
use UBO\Common\Auth\Contracts\PublicKeyProvider;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Contracts\UserTokenClient;
use UBO\Common\Auth\Http\Middleware\Authenticate;
use UBO\Common\Auth\Infrastructure\HttpPublicKeyProvider;
use UBO\Common\Auth\Infrastructure\HttpServiceTokenProvider;
use UBO\Common\Auth\Infrastructure\HttpUserTokenClient;
use UBO\Common\Auth\Infrastructure\LaravelClock;
use UBO\Common\Auth\Infrastructure\StaticPublicKeyProvider;
use UBO\Common\Auth\Infrastructure\Validators\HybridValidator;
use UBO\Common\Auth\Infrastructure\Validators\IntrospectionValidator;
use UBO\Common\Auth\Infrastructure\Validators\JwtValidator;
use UBO\Common\Support\ModuleServiceProvider;

/**
 * Auth module: validates API-Auth tokens ("ubo.auth" middleware), gets this
 * application's own tokens from API-Auth (ServiceTokenProvider) and those of a person
 * (UserTokenClient).
 */
final class AuthServiceProvider extends ModuleServiceProvider
{
    /** Container key of the introspection validator (TokenFactory::fake() replaces it). */
    public const INTROSPECTION = 'ubo.auth.introspection';

    protected function configName(): string
    {
        return 'ubo-auth';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-auth.php';
    }

    protected function registerModule(): void
    {
        // Resolved on every use (not singletons) so config changes, e.g. in tests, apply at once.
        $this->app->bind(AuthSettings::class, fn (Application $app) => AuthSettings::fromConfig($app['config']->get('ubo-auth')));

        $this->app->when([JwtValidator::class, IntrospectionValidator::class, HttpServiceTokenProvider::class])
            ->needs(ClockInterface::class)
            ->give(LaravelClock::class);

        $this->app->bind(PublicKeyProvider::class, function (Application $app) {
            $settings = $app->make(AuthSettings::class);

            return $settings->publicKey !== null
                ? new StaticPublicKeyProvider($settings->publicKey)
                : $app->make(HttpPublicKeyProvider::class);
        });

        $this->app->bind(ServiceTokenProvider::class, HttpServiceTokenProvider::class);

        $this->app->bind(UserTokenClient::class, HttpUserTokenClient::class);

        $this->app->bind(LocalTokenValidator::class, JwtValidator::class);

        $this->app->bind(self::INTROSPECTION, IntrospectionValidator::class);

        $this->app->bind(TokenValidator::class, fn (Application $app) => match ($app->make(AuthSettings::class)->strategy) {
            'jwt' => $app->make(JwtValidator::class),
            'introspection' => $app->make(self::INTROSPECTION),
            'hybrid' => $this->hybridValidator($app),
            default => throw new InvalidArgumentException('UBO_AUTH_STRATEGY must be jwt, introspection or hybrid.'),
        });
    }

    private function hybridValidator(Application $app): HybridValidator
    {
        $settings = $app->make(AuthSettings::class);

        return new HybridValidator(
            $app->make(JwtValidator::class),
            $app->make(self::INTROSPECTION),
            $settings->degradedMode,
            $app->make('log'),
            $app['cache']->store($settings->cacheStore),
            $settings->cachePrefix,
        );
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('ubo.auth', Authenticate::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
    }
}
