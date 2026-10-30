<?php

namespace UBO\Common\Login\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The person authenticated in IAM but has no role in this application (403).
 */
final class NoAccess extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'The person has no role in this application.');
    }
}
