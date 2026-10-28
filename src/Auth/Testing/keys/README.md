TEST-ONLY RSA keys used by UBO\Common\Auth\Testing\TokenFactory.
They are public in this repository: never configure them outside automated tests.

With APP_ENV=production the package refuses them: if the API-Auth public key in use is
test-public.key, every token is rejected (503) and the reason is logged; TokenFactory::fake()
throws; and "php artisan ubo:auth:check" reports the key as FAIL.
