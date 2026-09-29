<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Tests\Unit\Internal\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RequestBuilder;
use VeryCodeCom\FurgonetkaApi\Tests\Support\TokenFactory;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;

/**
 * The multipart request of a file upload. What matters is that the body is well formed whatever
 * the file is called and holds, that the boundary cannot be confused with the content, and that a
 * hostile file name cannot add a header to the part.
 */
final class RequestBuilderUploadTest extends TestCase
{
    /** 15 characters, so that a leak into a stack trace (which cuts arguments to 15) would show whole. */
    private const TOKEN = 'bearer-secret-1';
    private const UUID = '8b881c52-6473-467a-a7c9-d0aa4c4f3bec';

    private function builder(?FurgonetkaApiConfig $config = null): RequestBuilder
    {
        return new RequestBuilder($config ?? TokenFactory::config());
    }

    private function upload(string $contents = "%PDF-1.4\nbody", string $filename = 'invoice.pdf', string $contentType = 'application/pdf', ?string $type = 'invoice', ?RequestBuilder $builder = null): TransportRequest
    {
        return ($builder ?? $this->builder())->buildUpload(Endpoint::UploadFile, ['uuid' => self::UUID], $contents, $filename, $contentType, $type, self::TOKEN);
    }

    private static function boundaryOf(TransportRequest $request): string
    {
        $type = (string) $request->header('content-type');
        preg_match('/^multipart\/form-data; boundary=(furgonetka-[0-9a-f]{24})$/D', $type, $match);

        return $match[1] ?? throw new \LogicException("Not a multipart content type of this library: {$type}");
    }

    public function testTheRequestIsAPostToTheUploadUrlWithTheTokenAndTheLanguage(): void
    {
        $request = $this->upload();

        self::assertSame('POST', $request->method);
        self::assertSame('https://api.sandbox.furgonetka.pl/file-command/' . self::UUID, $request->url);
        self::assertSame('Bearer ' . self::TOKEN, $request->header('authorization'));
        self::assertSame('application/json', $request->header('accept'));
        self::assertSame('en_GB', $request->header('x-language'));
        self::assertNull($request->header(FurgonetkaApiConfig::HEADER_COMPANY_ID));
    }

    public function testTheCompanyIsSentWhenTheConfigHasOne(): void
    {
        $request = $this->upload(builder: $this->builder(TokenFactory::config()->withCompanyId(508899)));

        self::assertSame('508899', $request->header(FurgonetkaApiConfig::HEADER_COMPANY_ID));
    }

    public function testTheBodyHoldsTheFileAndTheTypeBetweenTheBoundaries(): void
    {
        $request = $this->upload("%PDF-1.4\nbinary \x00\xFF body");
        $boundary = self::boundaryOf($request);

        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"invoice.pdf\"\r\n"
            . "Content-Type: application/pdf\r\n\r\n"
            . "%PDF-1.4\nbinary \x00\xFF body\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"type\"\r\n\r\ninvoice\r\n"
            . "--{$boundary}--\r\n",
            $request->body,
        );
    }

    public function testWithoutATypeThereIsNoTypePart(): void
    {
        foreach ([null, ''] as $type) {
            $request = $this->upload(type: $type);

            self::assertStringNotContainsString('name="type"', (string) $request->body);
            self::assertStringEndsWith('--' . self::boundaryOf($request) . "--\r\n", (string) $request->body);
        }
    }

    public function testEveryRequestHasItsOwnBoundary(): void
    {
        self::assertNotSame(self::boundaryOf($this->upload()), self::boundaryOf($this->upload()));
    }

    public function testAFileThatContainsAMultipartBodyDoesNotEndThePartEarly(): void
    {
        $inner = "--abc\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\nx\r\n--abc--\r\n";
        $request = $this->upload($inner);
        $boundary = self::boundaryOf($request);

        self::assertStringNotContainsString($boundary, $inner);
        self::assertSame(1, substr_count((string) $request->body, "--{$boundary}--"), 'The closing line appears once, at the end.');
        self::assertStringContainsString($inner, (string) $request->body);
    }

    /** @return iterable<string, array{string, string}> the file name given, what the part carries */
    public static function fileNames(): iterable
    {
        yield 'a plain name' => ['invoice.pdf', 'invoice.pdf'];
        yield 'quotes are replaced' => ['a"b.pdf', 'a_b.pdf'];
        yield 'a backslash and a slash are replaced' => ['a\\b/c.pdf', 'a_b_c.pdf'];
        yield 'a line break cannot start a new header' => ["a.pdf\r\nX-Injected: yes", 'a.pdf__X-Injected: yes'];
        yield 'a null byte' => ["a\0b.pdf", 'a_b.pdf'];
        yield 'a name that is empty' => ['', 'file'];
        yield 'a name that is blank' => ["  ", 'file'];
    }

    #[DataProvider('fileNames')]
    public function testTheFileNameCannotBreakOutOfItsHeader(string $given, string $expected): void
    {
        $body = (string) $this->upload(filename: $given)->body;

        self::assertStringContainsString('name="file"; filename="' . $expected . "\"\r\n", $body);
        self::assertStringNotContainsString("\r\nX-Injected", $body);
        self::assertSame(1, preg_match_all('/^Content-Disposition:/m', $body) - 1, 'Exactly one Content-Disposition line for the file plus one for the type.');
    }

    public function testANonAsciiFileNameIsReducedToWhatAHeaderCanHold(): void
    {
        self::assertStringContainsString('filename="faktura-__.pdf"', (string) $this->upload(filename: "faktura-\u{0142}\u{00F3}.pdf")->body);
    }

    /** @return iterable<string, array{string}> */
    public static function badContentTypes(): iterable
    {
        yield 'no slash' => ['pdf'];
        yield 'spaces' => ['application / pdf'];
        yield 'a parameter' => ['text/plain; charset=utf-8'];
        yield 'a line break' => ["application/pdf\r\nX-Injected: yes"];
        yield 'a trailing newline' => ["application/pdf\n"];
        yield 'empty' => [''];
    }

    public function testAFileNameThatIsNotUtf8IsReducedByteByByteInsteadOfLost(): void
    {
        self::assertStringContainsString('filename="a__.pdf"', (string) $this->upload(filename: "a\xFF\xFE.pdf")->body);
    }

    #[DataProvider('badContentTypes')]
    public function testAContentTypeThatIsNotAMediaTypeIsRefused(string $contentType): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('content type');

        $this->upload(contentType: $contentType);
    }

    public function testAnEmptyTokenIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs an access token');

        $this->builder()->buildUpload(Endpoint::UploadFile, ['uuid' => self::UUID], 'x', 'a.pdf', 'application/pdf', null, '');
    }

    public function testOnlyTheUploadOperationCanBeBuiltThisWay(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not a file upload');

        $this->builder()->buildUpload(Endpoint::CreatePackage, [], 'x', 'a.pdf', 'application/pdf', null, self::TOKEN);
    }

    public function testTheUuidIsPercentEncodedInThePathLikeAnyOtherParameter(): void
    {
        $request = $this->builder()->buildUpload(Endpoint::UploadFile, ['uuid' => 'a b/c'], 'x', 'a.pdf', 'application/pdf', null, self::TOKEN);

        self::assertStringEndsWith('/file-command/a%20b%2Fc', $request->url);
    }

    public function testTheTokenAndTheFileNeverShowInADumpOrATrace(): void
    {
        $secret = 'invoice-secret-';
        self::assertSame(15, strlen($secret));

        $request = $this->upload($secret);

        ob_start();
        var_dump($request);
        print_r($request);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('[multipart body withheld:', $dump);

        try {
            $this->builder()->buildUpload(Endpoint::UploadFile, ['uuid' => self::UUID], $secret, 'a.pdf', 'not a type', null, self::TOKEN);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            $trace = print_r($e->getTrace(), true);

            self::assertStringNotContainsString($secret, $trace);
            self::assertStringNotContainsString(self::TOKEN, $trace);
            self::assertSame('0', (string) ini_get('zend.exception_ignore_args'), 'phpunit.xml keeps the trace arguments, so this check can fail.');
        }
    }
}
