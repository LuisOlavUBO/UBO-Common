<?php

use UBO\Common\Auth\Domain\VerifiedToken;

test('the subject is not a user when it is the client id, in any case', function (mixed $subject, ?string $expected) {
    expect(VerifiedToken::userIdFromSubject($subject, '01a15f76-9829-7298-85b5-fa625530d051'))->toBe($expected);
})->with([
    'same value' => ['01a15f76-9829-7298-85b5-fa625530d051', null],
    'upper case (SQL Server)' => ['01A15F76-9829-7298-85B5-FA625530D051', null],
    'empty' => ['', null],
    'missing' => [null, null],
    'numeric user id' => [42, '42'],
    'string user id' => ['42', '42'],
    'another client' => ['99999999-0000-0000-0000-000000000000', '99999999-0000-0000-0000-000000000000'],
]);

test('roles are checked as written, with the same capitalization', function () {
    $token = new VerifiedToken('jti', 'SIS', '42', [], new DateTimeImmutable('+1 hour'), roles: ['coordinador', 'Lector']);

    expect($token->hasRole('coordinador'))->toBeTrue()
        ->and($token->hasRole('Lector'))->toBeTrue()
        ->and($token->hasRole('lector'))->toBeFalse()
        ->and($token->hasRole('admin'))->toBeFalse();
});

test('a token built without roles, as before they existed, has none', function () {
    $token = new VerifiedToken('jti', 'SIS', null, ['geografia:leer'], new DateTimeImmutable('+1 hour'), ['SIS']);

    expect($token->roles)->toBe([])
        ->and($token->hasRole('lector'))->toBeFalse();
});
