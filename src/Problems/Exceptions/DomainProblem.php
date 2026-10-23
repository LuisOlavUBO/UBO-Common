<?php

namespace UBO\Common\Problems\Exceptions;

use RuntimeException;

/**
 * Business error with a stable code. ProblemRenderer turns it into an RFC 9457 problem
 * whose detail is the exception message: {type, title, status, detail, error, ...extensions}.
 */
abstract class DomainProblem extends RuntimeException
{
    /** Stable error code (e.g. NO_ENCONTRADO). */
    abstract public function code(): string;

    /** HTTP status. */
    abstract public function status(): int;

    /** Short title of the kind of error. */
    abstract public function title(): string;

    /**
     * Extra members of the problem (e.g. ['maximo' => 5000]).
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return [];
    }
}
