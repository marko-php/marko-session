<?php

declare(strict_types=1);

namespace Marko\Session\Exceptions;

class InvalidSessionIdException extends SessionException
{
    /**
     * The id usually comes from a client cookie, so only its length is
     * reported: echoing an attacker-supplied value into error output or logs
     * would hand them a reflection and log-injection vector.
     */
    public static function forId(
        string $id,
    ): self {
        return new self(
            message: 'Invalid session ID format',
            context: 'Provided session ID length: ' . strlen($id) . ' characters',
            suggestion: 'Session IDs must be alphanumeric (with hyphens) and between 32-128 characters',
        );
    }
}
