<?php

namespace UBO\Common\Auth\Console;

use Illuminate\Console\Command;
use Throwable;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\PublicKeyProvider;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Infrastructure\Validators\IntrospectionValidator;
use UBO\Common\Auth\Testing\TestKey;

/**
 * Diagnoses this application's connection with API-Auth: configuration, public key
 * and (when credentials are set) its own client credentials token.
 */
final class CheckCommand extends Command
{
    protected $signature = 'ubo:auth:check';

    protected $description = 'Check the configuration and connection with API-Auth';

    public function handle(AuthSettings $settings, PublicKeyProvider $keys, ServiceTokenProvider $tokens): int
    {
        $ok = true;
        $needsCredentials = $settings->strategy !== 'jwt';

        $this->components->twoColumnDetail('Strategy', $settings->strategy);
        $this->components->twoColumnDetail('API-Auth URL', $settings->url ?? '<fg=red>not configured</>');
        $this->components->twoColumnDetail('This API (aud)', $settings->api ?? 'not set: tokens issued for any API are accepted');

        $ok = $this->check('Public key', function () use ($keys) {
            $key = $keys->refresh() ?? $keys->get();

            if (openssl_pkey_get_public($key) === false) {
                throw new \RuntimeException('the key is not a valid PEM public key');
            }

            if ($this->laravel->isProduction() && TestKey::isTestKey($key)) {
                throw new \RuntimeException('it is the public test key of TokenFactory: every token is refused in production');
            }
        }) && $ok;

        if ($settings->clientId === null || $settings->clientSecret === null) {
            if ($needsCredentials) {
                $this->components->error("The {$settings->strategy} strategy needs UBO_AUTH_CLIENT_ID and UBO_AUTH_CLIENT_SECRET.");
                $ok = false;
            } else {
                $this->components->twoColumnDetail('Client credentials', 'not configured (not needed for jwt)');
            }
        } else {
            $ok = $this->check('Service token (scope introspect)', function () use ($tokens) {
                $tokens->forget([IntrospectionValidator::SCOPE]);
                $tokens->token([IntrospectionValidator::SCOPE]);
            }) && $ok;
        }

        $ok
            ? $this->components->info('API-Auth connection is ready.')
            : $this->components->error('API-Auth connection has problems.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $label, callable $probe): bool
    {
        try {
            $probe();
            $this->components->twoColumnDetail($label, '<fg=green>OK</>');

            return true;
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($label, '<fg=red>FAIL</> '.$e->getMessage());

            return false;
        }
    }
}
