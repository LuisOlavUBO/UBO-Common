<?php

namespace UBO\Common\Tests\Login;

use UBO\Common\Auth\Testing\TokenFactory;
use UBO\Common\Tests\TestCase;

/**
 * An application with the login module on, a login client and IAM at a fake address. The
 * browser reaches IAM at iam.test; this server calls it at api-auth.test.
 */
abstract class LoginTestCase extends TestCase
{
    /** Issues the tokens IAM would, signed with the test key the login trusts. */
    public TokenFactory $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = TokenFactory::fake();
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('ubo-common.modules.login', true);
    }

    /**
     * The next request carries the session cookie the browser would hold, so the session of the
     * previous request continues (and an id that changed shows in a new cookie).
     */
    protected function withBrowserSession(): static
    {
        return $this->withCookie(config('session.cookie'), app('session.store')->getId());
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://portal.test');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('ubo-login.public_url', 'https://iam.test');
        $app['config']->set('ubo-login.client_id', 'login-client');
        $app['config']->set('ubo-login.client_secret', 'login-secret');
    }
}
