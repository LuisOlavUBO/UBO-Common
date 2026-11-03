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

test('the uuid of a person is lowercase, and a machine token has none', function (mixed $uuid, ?string $userId, ?string $expected) {
    expect(VerifiedToken::uuidFrom($uuid, $userId))->toBe($expected);
})->with([
    'user token' => ['9f1c2b3a-0000-4000-8000-00000000abcd', '42', '9f1c2b3a-0000-4000-8000-00000000abcd'],
    'upper case (SQL Server)' => ['9F1C2B3A-0000-4000-8000-00000000ABCD', '42', '9f1c2b3a-0000-4000-8000-00000000abcd'],
    'user token without the claim' => [null, '42', null],
    'empty' => ['', '42', null],
    'not a string' => [123, '42', null],
    'machine token' => ['9f1c2b3a-0000-4000-8000-00000000abcd', null, null],
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
