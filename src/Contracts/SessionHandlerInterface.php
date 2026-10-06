<?php

declare(strict_types=1);

namespace Marko\Session\Contracts;

use SessionHandlerInterface as PhpSessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface as PhpSessionUpdateTimestampHandlerInterface;

/**
 * Storage backend for sessions.
 *
 * Extends PHP's SessionUpdateTimestampHandlerInterface so that
 * session.use_strict_mode actually applies: PHP calls validateId() before
 * resuming an inbound id and starts a fresh session when it returns false.
 * Without it, PHP adopts any well-formed id a client sends.
 */
interface SessionHandlerInterface extends PhpSessionHandlerInterface, PhpSessionUpdateTimestampHandlerInterface
{
    /**
     * Perform garbage collection.
     *
     * @param int $max_lifetime Sessions older than this (in seconds) will be deleted
     * @return int|false Number of sessions deleted, or false on failure
     */
    public function gc(int $max_lifetime): int|false;

    /**
     * Whether the store holds a session with this id that has not expired.
     *
     * Return false for an unknown or expired id: PHP then discards the id and
     * generates a fresh one, so a client can never choose its own session id.
     */
    public function validateId(string $id): bool;

    /**
     * Refresh the expiry of an existing session without rewriting its payload.
     *
     * PHP calls this instead of write() when the session data is unchanged.
     * It must never create a session that does not exist.
     */
    public function updateTimestamp(
        string $id,
        string $data,
    ): bool;
}
