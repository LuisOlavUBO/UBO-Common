<?php

namespace UBO\Common\Auth\Contracts;

/**
 * A validator that only checks the token itself (signature and expiry), whatever
 * UBO_AUTH_STRATEGY says: no calls to API-Auth besides the public key. For tokens API-Auth has
 * just handed to this application, where asking whether they were revoked adds nothing.
 */
interface LocalTokenValidator extends TokenValidator {}
