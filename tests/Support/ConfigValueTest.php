<?php

use UBO\Common\Support\ConfigValue;

test('a positive int falls back to the default unless it is a number above zero', function (mixed $value, int $expected) {
    expect(ConfigValue::positiveInt($value, 7))->toBe($expected);
})->with([
    'valid' => ['30', 30],
    'int' => [12, 12],
    'empty' => ['', 7],
    'null' => [null, 7],
    'not a number' => ['abc', 7],
    'zero' => ['0', 7],
    'negative' => ['-5', 7],
]);

test('a non-negative int accepts zero', function (mixed $value, int $expected) {
    expect(ConfigValue::nonNegativeInt($value, 7))->toBe($expected);
})->with([
    'zero' => ['0', 0],
    'valid' => ['30', 30],
    'empty' => ['', 7],
    'not a number' => ['abc', 7],
    'negative' => ['-1', 7],
]);

test('a filled string falls back to the default when blank', function (mixed $value, string $expected) {
    expect(ConfigValue::filledString($value, 'default'))->toBe($expected);
})->with([
    'valid' => ['jwt', 'jwt'],
    'empty' => ['', 'default'],
    'spaces' => ['  ', 'default'],
    'null' => [null, 'default'],
    'array' => [['x'], 'default'],
]);
