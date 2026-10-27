<?php

namespace UBO\Common\ApiClient;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\Auth\Exceptions\ServiceTokenException;

/**
 * Synchronous call to a UBO API: each verb returns a successful Response or throws
 * UboApiException.
 *
 *     $comunas = UboApi::call('api-maestros', $url)->get('/api/maestros/v1/geografia/regiones/13/comunas')->json('data');
 *
 * @method Response get(string $path, array|string|null $query = null)
 * @method Response head(string $path, array|string|null $query = null)
 * @method Response post(string $path, array $data = [])
 * @method Response put(string $path, array $data = [])
 * @method Response patch(string $path, array $data = [])
 * @method Response delete(string $path, array $data = [])
 */
final class UboClient extends PendingUboRequest
{
    protected function run(Closure $send): Response
    {
        $request = $this->prepare();

        try {
            $response = $send($request);
        } catch (ConnectionException $e) {
            throw UboApiException::connection($this->api->name, $e);
        } catch (ServiceTokenException $e) {
            throw UboApiException::authentication($this->api->name, $e);
        }

        return ResultGuard::verify($this->api->name, $response);
    }
}
