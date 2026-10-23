<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InsufficientScope;
use UBO\Common\Problems\Domain\Problem;
use UBO\Common\Problems\Exceptions\DomainProblem;
use UBO\Common\Problems\ProblemRenderer;
use UBO\Common\Tests\TestCase;

final class TooManyRows extends DomainProblem
{
    public function code(): string
    {
        return 'DEMASIADOS_REGISTROS';
    }

    public function status(): int
    {
        return 422;
    }

    public function title(): string
    {
        return 'Demasiados registros';
    }

    public function extensions(): array
    {
        return ['maximo' => 5000];
    }
}

beforeEach(function () {
    // What the application does in bootstrap/app.php.
    app(ExceptionHandler::class)->renderable(new ProblemRenderer);
});

/**
 * Asserts the RFC 9457 body and content type.
 *
 * @param  array<string, mixed>  $extensions
 */
function assertProblem($response, int $status, string $title, string $detail, string $error, array $extensions = []): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'error' => $error,
            ...$extensions,
        ]);
}

test('a domain problem keeps its code, title, message and extensions', function () {
    Route::get('api/rows', fn () => throw new TooManyRows('Se pidieron más de 5000 registros.'));

    assertProblem($this->getJson('api/rows'), 422, 'Demasiados registros', 'Se pidieron más de 5000 registros.', 'DEMASIADOS_REGISTROS', ['maximo' => 5000]);
});

test('an unauthenticated request is a 401', function () {
    Route::get('api/private', fn () => throw new AuthenticationException);

    assertProblem($this->getJson('api/private'), 401, 'No autenticado', 'Se requiere un token de acceso válido, vigente y no revocado.', 'NO_AUTENTICADO');
});

test('a token without the scope is a 403 naming the scope', function () {
    Route::get('api/scoped', fn () => throw new InsufficientScope(['geografia:leer']));

    assertProblem($this->getJson('api/scoped'), 403, 'Scope insuficiente', 'El token no tiene el scope requerido: geografia:leer.', 'SCOPE_INSUFICIENTE');
});

test('any other authorization failure is a 403', function () {
    Route::get('api/denied', fn () => throw new AuthorizationException('Internal reason'));

    assertProblem($this->getJson('api/denied'), 403, 'Acceso denegado', 'No tiene permiso para realizar esta operación.', 'ACCESO_DENEGADO');
});

test('invalid data is a 422 with the errors per field', function () {
    Route::post('api/rows', fn (Request $request) => $request->validate(['nombre' => 'required']));

    $response = $this->postJson('api/rows', []);

    assertProblem($response, 422, 'Datos inválidos', 'Los datos enviados no son válidos.', 'VALIDACION', [
        'errores' => ['nombre' => [$response->json('errores.nombre.0')]],
    ]);
    expect($response->json('errores.nombre'))->toHaveCount(1);
});

test('too many requests is a 429 with the rate limit headers', function () {
    Route::middleware('throttle:1,1')->get('api/limited', fn () => ['ok' => true]);

    $this->getJson('api/limited')->assertOk();
    $response = $this->getJson('api/limited');

    assertProblem($response, 429, 'Límite de peticiones excedido', 'Se superó el límite de peticiones por minuto.', 'LIMITE_EXCEDIDO');
    $response->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit', '1');
});

test('an unknown route is a 404', function () {
    assertProblem($this->getJson('api/missing'), 404, 'Ruta no encontrada', 'La ruta solicitada no existe.', 'RUTA_NO_ENCONTRADA');
});

test('a wrong method is a 405 with the allowed methods', function () {
    Route::get('api/rows', fn () => []);

    $response = $this->deleteJson('api/rows');

    assertProblem($response, 405, 'Método no permitido', 'El método HTTP no está permitido para esta ruta.', 'METODO_NO_PERMITIDO');
    $response->assertHeader('Allow', 'GET, HEAD');
});

test('API-Auth unavailable is a 503 and the reason only goes to the log', function () {
    Log::spy();
    Route::get('api/private', fn () => throw new AuthServerUnavailable('cURL error 28: timeout at 10.0.0.5'));

    $response = $this->getJson('api/private');

    assertProblem($response, 503, 'Autenticación no disponible', 'No fue posible verificar el token de acceso. Intente nuevamente.', 'AUTENTICACION_NO_DISPONIBLE');
    expect($response->getContent())->not->toContain('10.0.0.5');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'cURL error 28'))->once();
});

test('any other HTTP exception keeps its status and headers', function () {
    Route::get('api/busy', fn () => throw new HttpException(409, 'Internal detail', headers: ['X-Reason' => 'busy']));

    $response = $this->getJson('api/busy');

    assertProblem($response, 409, 'Error en la petición', 'La petición no pudo procesarse.', 'ERROR_HTTP');
    $response->assertHeader('X-Reason', 'busy');
});

test('an unexpected error is a 500 that does not expose the message', function () {
    config(['app.debug' => true]);
    Route::get('api/boom', fn () => throw new RuntimeException('SQLSTATE[42S02] secret table'));

    $response = $this->getJson('api/boom');

    assertProblem($response, 500, 'Error interno', 'Ocurrió un error inesperado al procesar la petición.', 'ERROR_INTERNO');
    expect($response->getContent())->not->toContain('secret')->not->toContain('trace')->not->toContain('.php');
});

test('the texts are sent without escaping accents', function () {
    expect($this->getJson('api/missing')->getContent())->toContain('"La ruta solicitada no existe."')
        ->and($this->deleteJson('api/missing')->getContent())->not->toContain('\u00');

    Route::get('api/rows', fn () => []);

    expect($this->deleteJson('api/rows')->getContent())->toContain('Método no permitido');
});

test('a response built on purpose is sent as is', function () {
    Route::get('api/teapot', fn () => throw new HttpResponseException(response()->json(['custom' => true], 418)));

    $this->getJson('api/teapot')->assertStatus(418)->assertExactJson(['custom' => true]);
});

test('routes outside the pattern keep the default rendering', function () {
    Route::get('web/boom', fn () => throw new AuthenticationException);
    Route::get('web/error', fn () => throw new RuntimeException('boom'));

    $this->getJson('web/boom')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    expect($this->getJson('web/error')->assertServerError()->json('error'))->toBeNull();
    $this->getJson('web/missing')->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

test('the route patterns are configurable', function () {
    config(['ubo-problems.routes' => 'v2/*, internal/*']);
    Route::get('v2/private', fn () => throw new AuthenticationException);
    Route::get('api/private', fn () => throw new AuthenticationException);

    $this->getJson('v2/private')->assertStatus(401)->assertJsonPath('error', 'NO_AUTENTICADO');
    $this->getJson('internal/missing')->assertNotFound()->assertJsonPath('error', 'RUTA_NO_ENCONTRADA');
    $this->getJson('api/private')->assertStatus(401)->assertJsonMissingPath('error');
});

test('an application can build a problem for its own errors', function () {
    $response = (new Problem(400, 'Solicitud OAuth inválida', 'Falta el parámetro.', 'invalid_request', headers: ['Cache-Control' => 'no-store']))->toResponse();

    expect($response->getStatusCode())->toBe(400)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getData(true))->toBe([
            'type' => 'about:blank',
            'title' => 'Solicitud OAuth inválida',
            'status' => 400,
            'detail' => 'Falta el parámetro.',
            'error' => 'invalid_request',
        ]);
});

test('the renderer works with the module disabled, using the defaults', function () {
    TestCase::$bootConfig = ['ubo-common.modules.problems' => false];
    $this->refreshApplication();
    app(ExceptionHandler::class)->renderable(new ProblemRenderer);

    expect(config('ubo-problems'))->toBeNull();
    $this->getJson('api/missing')->assertNotFound()->assertJsonPath('error', 'RUTA_NO_ENCONTRADA');
});
