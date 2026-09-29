<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Auth\Store;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\Auth\AccessToken;
use VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Tests\Support\RecordingLogger;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;

final class FileTokenStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/furgonetka-store-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0700);

            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }

    private function path(string $name = 'token.json'): string
    {
        return $this->directory . '/' . $name;
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);

        return (int) fileperms($path) & 0777;
    }

    private function skipWhenPermissionsDoNotApply(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            self::markTestSkipped('File permissions are not enforced here (Windows, or running as root).');
        }
    }

    // -- load and save ---------------------------------------------------------

    public function testAMissingFileIsNoToken(): void
    {
        self::assertNull((new FileTokenStore($this->path()))->load());
    }

    public function testASavedTokenComesBackIntact(): void
    {
        $store = new FileTokenStore($this->path());
        $token = TokenFactory::token('access-1', 'refresh-1', expiresIn: 5400);

        $store->save($token);
        $loaded = (new FileTokenStore($this->path()))->load();

        self::assertNotNull($loaded);
        self::assertSame('access-1', $loaded->accessToken);
        self::assertSame('refresh-1', $loaded->refreshToken);
        self::assertSame($token->expiresAt->getTimestamp(), $loaded->expiresAt->getTimestamp());
        self::assertSame($token->environment, $loaded->environment);
        self::assertSame($token->clientIdHash, $loaded->clientIdHash);
    }

    public function testTheFileHoldsTheDocumentedJson(): void
    {
        (new FileTokenStore($this->path()))->save(TokenFactory::token('access-1', 'refresh-1'));

        $data = json_decode((string) file_get_contents($this->path()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(TokenFactory::token('access-1', 'refresh-1')->toArray(), $data);
    }

    public function testSavingReplacesTheTokenAndLeavesNoTemporaryFilesBehind(): void
    {
        $store = new FileTokenStore($this->path());

        $store->save(TokenFactory::token('access-1', 'refresh-1'));
        $store->save(TokenFactory::token('access-2', 'refresh-2'));

        self::assertSame('access-2', $store->load()?->accessToken);
        self::assertSame(['.', '..', 'token.json'], scandir($this->directory));
    }

    public function testTheFileIsPrivateToTheOwner(): void
    {
        $this->skipWhenPermissionsDoNotApply();
        $store = new FileTokenStore($this->path());

        $store->save(TokenFactory::token());
        self::assertSame(0600, $this->mode($this->path()));

        $store->save(TokenFactory::token('access-2'));
        self::assertSame(0600, $this->mode($this->path()), 'A replacement must be private as well.');
    }

    public function testAMissingDirectoryIsCreatedPrivateAndAnExistingOneIsLeftAlone(): void
    {
        $this->skipWhenPermissionsDoNotApply();

        (new FileTokenStore($this->directory . '/nested/deeper/token.json'))->save(TokenFactory::token());
        self::assertSame(0700, $this->mode($this->directory . '/nested/deeper'));

        mkdir($this->directory . '/shared', 0755);
        (new FileTokenStore($this->directory . '/shared/token.json'))->save(TokenFactory::token());
        self::assertSame(0755, $this->mode($this->directory . '/shared'), 'The store must not change permissions it did not set.');
    }

    public function testASaveThatCannotWriteThrowsAndLeavesTheOldTokenAndNoDebris(): void
    {
        $this->skipWhenPermissionsDoNotApply();
        $store = new FileTokenStore($this->path());
        $store->save(TokenFactory::token('access-1'));
        chmod($this->directory, 0500);

        try {
            $store->save(TokenFactory::token('access-2'));
            self::fail('Expected a FurgonetkaApiException.');
        } catch (FurgonetkaApiException $e) {
            self::assertStringContainsString('temporary file', $e->getMessage());
        } finally {
            chmod($this->directory, 0700);
        }

        self::assertSame('access-1', $store->load()?->accessToken);
        self::assertSame(['.', '..', 'token.json'], scandir($this->directory));
    }

    public function testATokenThatCannotBeEncodedAsJsonIsAnErrorAndLeavesNoDebris(): void
    {
        $store = new FileTokenStore($this->path());
        // Invalid UTF-8: json_encode() refuses it.
        $token = new AccessToken("bad\xB1token", TokenFactory::now(), null, 'sandbox', hash('sha256', 'x'));

        try {
            $store->save($token);
            self::fail('Expected a FurgonetkaApiException.');
        } catch (FurgonetkaApiException $e) {
            self::assertStringContainsString('cannot be encoded as JSON', $e->getMessage());
            // No JsonException as the cause: its trace would hold the token array.
            self::assertNull($e->getPrevious());
        }

        self::assertSame(['.', '..'], scandir($this->directory));
    }

    public function testASaveThatCannotReplaceTheTargetThrowsAndCleansUpItsTemporaryFile(): void
    {
        // A directory where the token file should be: rename() cannot replace it.
        mkdir($this->path(), 0700, true);

        try {
            (new FileTokenStore($this->path()))->save(TokenFactory::token());
            self::fail('Expected a FurgonetkaApiException.');
        } catch (FurgonetkaApiException $e) {
            self::assertStringContainsString('cannot move the new token file into place', $e->getMessage());
        }

        self::assertSame(['.', '..', 'token.json'], scandir($this->directory));
    }

    public function testADirectoryThatCannotBeCreatedThrows(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/a-file', 'x');

        $this->expectException(FurgonetkaApiException::class);
        $this->expectExceptionMessage('cannot create the directory');

        (new FileTokenStore($this->directory . '/a-file/token.json'))->save(TokenFactory::token());
    }

    // -- damaged and exposed files ---------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedFiles(): iterable
    {
        yield 'empty' => [''];
        yield 'truncated' => ['{"version":1,"access_token":"abc'];
        yield 'not JSON' => ['<html lang="en">nope</html>'];
        yield 'a JSON string' => ['"just a string"'];
        yield 'a JSON number' => ['42'];
        yield 'an object of another shape' => ['{"token":"abc"}'];
        yield 'a future format' => ['{"version":2,"access_token":"a","refresh_token":null,"expires_at":"2026-09-28T13:00:00+00:00","environment":"sandbox","client_id_hash":"' . '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef' . '"}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedFiles')]
    public function testADamagedFileIsNoTokenAndIsReportedWithoutItsContent(string $content): void
    {
        mkdir($this->directory);
        file_put_contents($this->path(), $content);
        $logger = new RecordingLogger();

        self::assertNull((new FileTokenStore($this->path(), $logger))->load());

        self::assertTrue($logger->has('warning', 'not a valid token'));
        self::assertStringNotContainsString('nope', $logger->dump());
    }

    public function testADamagedFileHealsOnTheNextSave(): void
    {
        mkdir($this->directory);
        file_put_contents($this->path(), 'garbage');
        $store = new FileTokenStore($this->path());

        $store->save(TokenFactory::token('access-1'));

        self::assertSame('access-1', $store->load()?->accessToken);
    }

    public function testAFileThatCannotBeReadIsAnErrorNotSilentlyNoToken(): void
    {
        $this->skipWhenPermissionsDoNotApply();
        $store = new FileTokenStore($this->path());
        $store->save(TokenFactory::token());
        chmod($this->path(), 0000);

        try {
            $store->load();
            self::fail('Expected a FurgonetkaApiException.');
        } catch (FurgonetkaApiException $e) {
            self::assertStringContainsString('cannot be read', $e->getMessage());
        } finally {
            chmod($this->path(), 0600);
        }
    }

    public function testATokenFileThatOthersCanReadIsReportedOnce(): void
    {
        $this->skipWhenPermissionsDoNotApply();
        $logger = new RecordingLogger();
        $store = new FileTokenStore($this->path(), $logger);
        $store->save(TokenFactory::token());
        chmod($this->path(), 0644);

        $store->load();
        $store->load();

        self::assertCount(1, $logger->records);
        self::assertTrue($logger->has('warning', 'can be read by other users'));
        self::assertSame('0644', $logger->records[0]['context']['mode']);
    }

    public function testAPrivateFileIsNotReported(): void
    {
        $logger = new RecordingLogger();
        $store = new FileTokenStore($this->path(), $logger);
        $store->save(TokenFactory::token());

        $store->load();

        self::assertSame([], $logger->records);
    }

    // -- locking ---------------------------------------------------------------

    public function testSynchronizedReturnsTheResultAndLoadAndSaveWorkInside(): void
    {
        $store = new FileTokenStore($this->path());

        $result = $store->synchronized(function () use ($store): string {
            $store->save(TokenFactory::token('inside'));

            return $store->load()->accessToken ?? 'none';
        });

        self::assertSame('inside', $result);
    }

    public function testTheLockFileIsPrivateAndSeparateFromTheTokenFile(): void
    {
        $this->skipWhenPermissionsDoNotApply();
        $store = new FileTokenStore($this->path());

        $store->synchronized(static fn (): null => null);

        self::assertFileExists($this->path('token.json.lock'));
        self::assertSame(0600, $this->mode($this->path('token.json.lock')));
        self::assertFileDoesNotExist($this->path());
    }

    public function testALockFileThatCannotBeOpenedIsAnError(): void
    {
        // A directory where the lock file should be.
        mkdir($this->path('token.json.lock'), 0700, true);

        $this->expectException(FurgonetkaApiException::class);
        $this->expectExceptionMessage('cannot open the lock file');

        (new FileTokenStore($this->path()))->synchronized(static fn (): null => null);
    }

    public function testTheLockIsReleasedWhenTheCallbackThrows(): void
    {
        $store = new FileTokenStore($this->path(), lockTimeout: 1);

        try {
            $store->synchronized(static function (): never {
                throw new \DomainException('boom');
            });
        } catch (\DomainException) {
            // Expected.
        }

        // A second store on the same file would wait out its timeout and throw if the lock leaked.
        self::assertSame('free', (new FileTokenStore($this->path(), lockTimeout: 1))->synchronized(static fn (): string => 'free'));
    }

    public function testTheLockIsReentrantWithinOneStore(): void
    {
        $store = new FileTokenStore($this->path(), lockTimeout: 1);

        $result = $store->synchronized(fn (): string => $store->synchronized(static fn (): string => 'nested'));

        self::assertSame('nested', $result);
    }

    public function testASecondStoreOnTheSameFileWaitsAndThenGivesUp(): void
    {
        $holder = new FileTokenStore($this->path());
        $waiter = new FileTokenStore($this->path(), lockTimeout: 1);

        $started = microtime(true);

        try {
            $holder->synchronized(static function () use ($waiter): void {
                $waiter->synchronized(static fn (): null => null);
            });
            self::fail('Expected a FurgonetkaApiException.');
        } catch (FurgonetkaApiException $e) {
            self::assertStringContainsString('gave up after 1 seconds', $e->getMessage());
            self::assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        }
    }

    public function testTheLockOfOneFileDoesNotBlockAnother(): void
    {
        $one = new FileTokenStore($this->path('one.json'));
        $two = new FileTokenStore($this->path('two.json'), lockTimeout: 1);

        $result = $one->synchronized(static fn (): string => $two->synchronized(static fn (): string => 'not blocked'));

        self::assertSame('not blocked', $result);
    }

    // -- construction ----------------------------------------------------------

    public function testTheConstructorValidatesItsArguments(): void
    {
        foreach ([['', 60], ['  ', 60], ['/tmp/token.json', 0]] as [$path, $timeout]) {
            try {
                new FileTokenStore($path, lockTimeout: $timeout);
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
