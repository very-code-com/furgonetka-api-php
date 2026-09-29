<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Support;

/**
 * A throwaway HTTP server on the loopback interface, started with PHP's built-in web server.
 *
 * It runs as a single process on purpose. The built-in server's multi-worker mode leaves its
 * workers running after the parent is terminated, which would keep the test run's output pipe
 * open and leak processes; one process dies with one signal.
 *
 * It lets the tests exercise the real {@see \VeryCodeCom\FurgonetkaApi\Transport\CurlTransport}:
 * the verbs, the bodies, the header handling and the failure modes are properties of cURL and of
 * the code that configures it, which a scripted fake cannot check. Only 127.0.0.1 is involved, so
 * the suite still needs no network.
 */
final class LocalServer
{
    /** @param resource $process */
    private function __construct(
        private $process,
        public readonly string $baseUrl,
    ) {
    }

    /**
     * @param array<string, string> $env Extra environment variables for the server process, for
     *                                   routes that need to know where to keep their state.
     * @throws \RuntimeException when the server cannot be started; callers turn that into a skip.
     */
    public static function start(array $env = []): self
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($probe === false) {
            throw new \RuntimeException("Cannot reserve a loopback port: {$errstr}");
        }

        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/Server/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env === [] ? null : array_merge(getenv(), $env),
        );

        if ($process === false) {
            throw new \RuntimeException('Cannot start the PHP built-in server.');
        }

        $server = new self($process, "http://127.0.0.1:{$port}");

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return $server;
            }

            usleep(100_000);
        }

        $server->stop();

        throw new \RuntimeException('The PHP built-in server did not come up in time.');
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /**
     * A URL on the loopback interface that nothing listens on, for connection-refused tests.
     *
     * @throws \RuntimeException when no loopback port can be reserved.
     */
    public static function closedUrl(): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            throw new \RuntimeException('Cannot reserve a loopback port.');
        }

        $name = stream_socket_get_name($probe, false);
        fclose($probe);

        if ($name === false) {
            throw new \RuntimeException('Cannot read the address of the reserved loopback port.');
        }

        return 'http://' . $name . '/';
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}
