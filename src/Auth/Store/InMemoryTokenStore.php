<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth\Store;

use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;

/**
 * Keeps the token in the memory of the current PHP process.
 *
 * Right for tests, for a script that logs in and works within one run, and for a long-running
 * worker that is the only process using the account. It is **not** right for a web application:
 * every request is a fresh process that would start without a token, and each login would open
 * another of the 20 sessions Furgonetka allows. There is also no cross-process locking, because
 * there is no other process to lock against.
 */
final class InMemoryTokenStore implements TokenStoreInterface
{
    public function __construct(private ?AccessToken $token = null)
    {
    }

    public function load(): ?AccessToken
    {
        return $this->token;
    }

    public function save(AccessToken $token): void
    {
        $this->token = $token;
    }

    /** Nothing can interleave within one process, so the callback simply runs. */
    public function synchronized(callable $callback): mixed
    {
        return $callback();
    }
}
