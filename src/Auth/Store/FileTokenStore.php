<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth\Store;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;

/**
 * Keeps the token in a JSON file and serialises refreshes with `flock()`.
 *
 * Suits a single server, or several processes (PHP-FPM workers, cron jobs, queue consumers) that
 * share a disk. It does not lock across machines: with several servers use a store on a shared
 * database or Redis instead, or give each server its own file and its own login (each login is
 * one of the 20 sessions Furgonetka allows per user and application).
 *
 * How it protects the token:
 *  - The file is written to a temporary file in the same directory, flushed to disk and renamed
 *    over the target, so a crash mid-write leaves the previous token, not a half-written one.
 *    That matters more than usual, because the refresh token in the old file is already spent
 *    once its replacement exists, and a lost replacement means logging in again.
 *  - The file is created with mode 0600 and a missing directory with 0700. An existing directory
 *    is left alone, and a token file that others can read is reported as a warning. Keep it out
 *    of the web root and out of world-writable directories such as /tmp.
 *  - The lock is a separate `<file>.lock`, not the token file itself, because the rename replaces
 *    the token file and a lock on the old inode would guard nothing.
 *  - Waiting for the lock is bounded, see the `lockTimeout` parameter.
 *
 * A file that is not valid token JSON is ignored with a warning and treated as "no token", so a
 * damaged file heals on the next login instead of blocking every request.
 */
final class FileTokenStore implements TokenStoreInterface
{
    /**
     * Longer than the slowest refresh: a request has a 30 second timeout by default, and a
     * reauthorizing manager can spend two of them (refresh, then password login) under the lock.
     */
    public const DEFAULT_LOCK_TIMEOUT = 120;

    /** Pause between attempts to take a contended lock, in microseconds. */
    private const LOCK_POLL_INTERVAL = 50_000;

    private readonly LoggerInterface $logger;
    private int $lockDepth = 0;
    private bool $warnedAboutPermissions = false;

    /**
     * @param string               $path        The token file, e.g. `/var/lib/shop/furgonetka/token.json`.
     * @param LoggerInterface|null $logger      Receives warnings about a bad file or its permissions.
     * @param int                  $lockTimeout Seconds to wait for the lock before giving up.
     */
    public function __construct(
        private readonly string $path,
        ?LoggerInterface $logger = null,
        private readonly int $lockTimeout = self::DEFAULT_LOCK_TIMEOUT,
    ) {
        if (trim($path) === '') {
            throw new \InvalidArgumentException('FileTokenStore: the path must not be empty.');
        }

        if ($lockTimeout < 1) {
            throw new \InvalidArgumentException('FileTokenStore: lockTimeout must be at least 1 second.');
        }

        $this->logger = $logger ?? new NullLogger();
    }

    public function load(): ?AccessToken
    {
        clearstatcache(true, $this->path);

        if (!is_file($this->path)) {
            return null;
        }

        $json = @file_get_contents($this->path);

        if ($json === false) {
            throw new FurgonetkaApiException('FileTokenStore: the token file exists but cannot be read, check its permissions.');
        }

        $this->warnIfReadableByOthers();

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                throw new \InvalidArgumentException('not a JSON object');
            }

            return AccessToken::fromArray($data);
        } catch (\JsonException | \InvalidArgumentException $e) {
            // The reason is logged, the content never: it may be a token in an unexpected shape.
            $this->logger->warning('Furgonetka: the token file is not a valid token and is ignored', [
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function save(AccessToken $token): void
    {
        $directory = dirname($this->path);
        $this->ensureDirectory($directory);

        // Not tempnam(): when the directory is not writable it silently falls back to the system
        // temporary directory, which would put the token in a shared place before the rename fails.
        $temporary = $directory . '/.furgonetka-token-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new FurgonetkaApiException('FileTokenStore: cannot create a temporary file next to the token file.');
        }

        $moved = false;

        try {
            // Before anything is written, so the token is never in a file others can read.
            @chmod($temporary, 0600);

            // No JSON_THROW_ON_ERROR: a JsonException would carry the token array in the trace of
            // json_encode(), an internal function whose arguments no attribute can mask.
            $json = json_encode($token->toArray(), JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                throw new FurgonetkaApiException('FileTokenStore: the token cannot be encoded as JSON.');
            }

            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new FurgonetkaApiException('FileTokenStore: writing the token file failed (disk full?).');
            }

            fclose($handle);

            if (!@rename($temporary, $this->path)) {
                throw new FurgonetkaApiException('FileTokenStore: cannot move the new token file into place.');
            }

            $moved = true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (!$moved) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Re-entrant within one process, because `flock()` on a second handle of the same file would
     * deadlock against the first.
     */
    public function synchronized(callable $callback): mixed
    {
        if ($this->lockDepth > 0) {
            return $callback();
        }

        $handle = $this->acquireLock();
        $this->lockDepth++;

        try {
            return $callback();
        } finally {
            $this->lockDepth--;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource
     * @throws FurgonetkaApiException when the lock file cannot be opened or the lock is not
     *         obtained within the timeout.
     */
    private function acquireLock(): mixed
    {
        $this->ensureDirectory(dirname($this->path));

        $lockPath = $this->path . '.lock';
        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            throw new FurgonetkaApiException('FileTokenStore: cannot open the lock file next to the token file.');
        }

        @chmod($lockPath, 0600);

        $deadline = microtime(true) + $this->lockTimeout;

        while (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if ($wouldBlock !== 1) {
                fclose($handle);

                throw new FurgonetkaApiException('FileTokenStore: the lock file cannot be locked.');
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new FurgonetkaApiException(sprintf(
                    'FileTokenStore: gave up after %d seconds waiting for the token lock; '
                    . 'another process is holding it (a stuck refresh?).',
                    $this->lockTimeout,
                ));
            }

            usleep(self::LOCK_POLL_INTERVAL);
        }

        return $handle;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        // The is_dir() re-check covers another process creating it between the two calls.
        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new FurgonetkaApiException('FileTokenStore: cannot create the directory of the token file.');
        }
    }

    private function warnIfReadableByOthers(): void
    {
        if ($this->warnedAboutPermissions || DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $permissions = @fileperms($this->path);

        if ($permissions !== false && ($permissions & 0077) !== 0) {
            $this->warnedAboutPermissions = true;
            $this->logger->warning('Furgonetka: the token file can be read by other users, restrict it to the owner (chmod 600)', [
                'mode' => sprintf('%04o', $permissions & 0777),
            ]);
        }
    }
}
