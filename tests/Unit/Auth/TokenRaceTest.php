<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Tests\Support\LocalServer;

/**
 * The property the whole token design exists for: several real processes that need a refresh at
 * the same moment must cause exactly one refresh.
 *
 * Furgonetka's refresh tokens are single use, so a second refresh with the same token loses the
 * session. Each worker is its own PHP process running the real stack (cURL, {@see FileTokenStore},
 * TokenManager) against a loopback token server that enforces single use, and all of them are
 * released at the same instant. Without the lock, or without the second read after the lock, the
 * losers get `invalid_grant` and the test fails.
 */
final class TokenRaceTest extends TestCase
{
    private const WORKERS = 5;

    private string $directory;
    private string $tokenFile;
    private string $stateFile;
    private LocalServer $server;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available.');
        }

        $this->directory = sys_get_temp_dir() . '/furgonetka-race-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->tokenFile = $this->directory . '/token.json';
        $this->stateFile = $this->directory . '/server-state.json';

        try {
            $this->server = LocalServer::start(['FURGONETKA_TEST_OAUTH_STATE' => $this->stateFile]);
        } catch (\RuntimeException $e) {
            $this->remove($this->directory);
            self::markTestSkipped('No loopback server available: ' . $e->getMessage());
        }

        file_put_contents($this->stateFile, json_encode(['refresh' => 'refresh-0', 'issued' => 0, 'requests' => 0], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }

        if (isset($this->directory)) {
            $this->remove($this->directory);
        }
    }

    private function remove(string $directory): void
    {
        foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($directory);
    }

    /** Store a token for the worker's config that expires `$expiresIn` seconds from now, on the real clock. */
    private function storeToken(int $expiresIn): void
    {
        (new FileTokenStore($this->tokenFile))->save(new AccessToken(
            accessToken: 'access-0',
            expiresAt: new \DateTimeImmutable('@' . (time() + $expiresIn)),
            refreshToken: 'refresh-0',
            environment: 'sandbox',
            clientIdHash: hash('sha256', 'client-1'),
        ));
    }

    /**
     * Start the workers, wait for them, and return what each printed and how it exited.
     *
     * @return list<array{output: string, error: string, exit: int}>
     */
    private function runWorkers(string $mode): array
    {
        // Late enough for every process to boot and (in "refused" mode) to fetch its first token.
        $startAt = microtime(true) + 2.0;
        $processes = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../../Support/refresh_worker.php', $this->tokenFile, $this->server->baseUrl, sprintf('%.3f', $startAt), $mode],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            self::assertIsResource($process);
            $processes[] = ['process' => $process, 'pipes' => $pipes];
        }

        $results = [];
        $deadline = microtime(true) + 60;

        foreach ($processes as $entry) {
            $output = $error = '';

            foreach ([1, 2] as $index) {
                stream_set_blocking($entry['pipes'][$index], false);
            }

            while (true) {
                $output .= (string) stream_get_contents($entry['pipes'][1]);
                $error .= (string) stream_get_contents($entry['pipes'][2]);
                $status = proc_get_status($entry['process']);

                if (!$status['running']) {
                    $output .= (string) stream_get_contents($entry['pipes'][1]);
                    $error .= (string) stream_get_contents($entry['pipes'][2]);
                    break;
                }

                if (microtime(true) > $deadline) {
                    proc_terminate($entry['process']);
                    self::fail('A refresh worker did not finish in time: the lock may be stuck.');
                }

                usleep(20_000);
            }

            // proc_get_status() reports the real exit code only once, on the call that saw it stop.
            $exit = $status['exitcode'];
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            proc_close($entry['process']);

            $results[] = ['output' => $output, 'error' => $error, 'exit' => $exit];
        }

        return $results;
    }

    /** @return array{refresh: string, issued: int, requests: int} */
    private function serverState(): array
    {
        /** @var array{refresh: string, issued: int, requests: int} $state */
        $state = json_decode((string) file_get_contents($this->stateFile), true, flags: JSON_THROW_ON_ERROR);

        return $state;
    }

    public function testProcessesThatFindTheTokenExpiredRefreshItExactlyOnce(): void
    {
        $this->storeToken(expiresIn: 30);

        $results = $this->runWorkers('expired');

        foreach ($results as $result) {
            self::assertSame('', $result['error']);
            self::assertSame(0, $result['exit']);
            self::assertSame('access-1', $result['output'], 'Everyone must end up with the one token that was issued.');
        }

        $state = $this->serverState();
        self::assertSame(1, $state['requests'], 'More than one refresh request means the lock or the second read failed.');
        self::assertSame(1, $state['issued']);
        self::assertSame('refresh-1', (new FileTokenStore($this->tokenFile))->load()?->refreshToken);
    }

    public function testProcessesThatAllGetA401RefreshExactlyOnce(): void
    {
        $this->storeToken(expiresIn: 3600);

        $results = $this->runWorkers('refused');

        foreach ($results as $result) {
            self::assertSame('', $result['error']);
            self::assertSame(0, $result['exit']);
            self::assertSame('access-1', $result['output']);
        }

        $state = $this->serverState();
        self::assertSame(1, $state['requests'], 'Refreshing a token that another process already replaced spends the new refresh token.');
        self::assertSame(1, $state['issued']);
    }
}
