<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth\Store;

use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;

/**
 * Durable home of the current token pair, shared by every process that talks to Furgonetka for
 * the same account.
 *
 * Furgonetka's refresh tokens are single use. When two processes notice at the same moment that
 * the access token is about to expire and both refresh it, one wins and the other holds a spent
 * token and loses the session. The store therefore has one job beyond keeping the data: it lets
 * {@see \VeryCodeCom\FurgonetkaApi\Auth\TokenManager} run "read, refresh, write" as one step that
 * no other process can interleave with.
 *
 * Implement it for your own infrastructure, for example a database row read with
 * `SELECT ... FOR UPDATE` inside a transaction, or a Redis key guarded by `SET NX` with a TTL.
 * Keep one store (one row, one key, one file) per Furgonetka account: a store never holds more
 * than one token.
 */
interface TokenStoreInterface
{
    /**
     * The stored token, or null when there is none or it cannot be read as a token. Must not
     * block on the lock: the manager reads without it first, and a write is atomic, so a reader
     * sees either the old token or the new one, never half of it.
     */
    public function load(): ?AccessToken;

    /**
     * Replace the stored token. The replacement must be atomic for readers, and a failure must
     * throw: a silently lost token is a lost refresh token, which means logging in again.
     */
    public function save(AccessToken $token): void;

    /**
     * Run the callback while holding an exclusive lock that is shared with every other process
     * using this store, and return its result. {@see self::load()} and {@see self::save()} must
     * work inside the callback. The lock is released however the callback ends.
     *
     * A lock that cannot be obtained within a reasonable time must throw instead of waiting
     * forever. Callers never nest calls, so an implementation is not required to be re-entrant.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function synchronized(callable $callback): mixed;
}
