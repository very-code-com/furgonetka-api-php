<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Http;

use VeryCodeCom\FurgonetkaApi\Dto\ApiError;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiAuthException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiConflictException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiGoneException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiHttpException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiNotFoundException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiRateLimitException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiServerException;
use VeryCodeCom\FurgonetkaApi\Internal\Json\ResponseParser;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Turns an error response of the API host into the narrowest exception that describes it.
 *
 * The API host answers errors in two JSON shapes, and this reads both into
 * {@see FurgonetkaApiHttpException::$errors}:
 *
 *   {"errors": [{"path": "/receiver/postcode", "message": "...", "code": "..."}]}   400, 404, 410, 500
 *   {"code": "access_denied", "message": "...", "details": "..."}                    401
 *
 * The token endpoint has a third shape (RFC 6749) and is not handled here, see
 * {@see \VeryCodeCom\FurgonetkaApi\Auth\OAuthClient}.
 *
 * A body that is not JSON at all, such as the HTML page of a proxy in front of the API, still
 * gives the right exception type, chosen from the status alone; its message quotes a short,
 * cleaned-up excerpt of the body so that the cause is visible in a log.
 *
 * The raw body is never attached here. The client does that in debug mode, after redaction.
 *
 * @internal
 */
final class ErrorMapper
{
    /** Longest excerpt of a non-JSON body that goes into a message. */
    private const EXCERPT_LENGTH = 200;

    private readonly ResponseParser $parser;

    public function __construct()
    {
        $this->parser = new ResponseParser();
    }

    /**
     * @param TransportRequest  $request  The request that was answered, for the message.
     * @param TransportResponse $response An answer with an error status (not 2xx).
     */
    public function map(TransportRequest $request, TransportResponse $response): FurgonetkaApiHttpException
    {
        $status = $response->statusCode;
        $data = $this->decode($response);
        $errors = $data === null ? [] : $this->errors($data);
        $message = $this->message($request, $response, $data, $errors);

        return match (true) {
            $status === 400 => new FurgonetkaApiBadRequestException($message, $status, $errors),
            $status === 401, $status === 403 => new FurgonetkaApiAuthException($message, $status, $errors),
            $status === 404 => new FurgonetkaApiNotFoundException($message, $status, $errors),
            $status === 409 => new FurgonetkaApiConflictException($message, $status, $errors),
            $status === 410 => new FurgonetkaApiGoneException($message, $status, $errors),
            $status === 429 => (new FurgonetkaApiRateLimitException($message, $status, $errors))
                ->withRetryAfter($response->retryAfterSeconds()),
            $status >= 500 && $status <= 599 => new FurgonetkaApiServerException($message, $status, $errors),
            default => new FurgonetkaApiHttpException($message, $status, $errors),
        };
    }

    /**
     * The decoded body when it is a JSON object, otherwise null.
     *
     * @return array<string, mixed>|null
     */
    private function decode(TransportResponse $response): ?array
    {
        try {
            return $this->parser->decode($response->body, 'the error answer');
        } catch (FurgonetkaApiResponseParseException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ApiError>
     */
    private function errors(array $data): array
    {
        // A list only: the legacy `{"errors": {"packageParams": {...}}}` that validate answers an
        // empty body with is a map, and reading its values as entries would invent an empty error.
        $errors = $data['errors'] ?? null;

        if (is_array($errors) && array_is_list($errors)) {
            $list = Value::objectList($data, 'errors');

            if ($list !== []) {
                return array_map(ApiError::fromArray(...), $list);
            }
        }

        // The flat 401 shape: the object is itself the one error.
        if (Value::string($data, 'code') !== null || Value::string($data, 'message') !== null) {
            return [ApiError::fromArray($data)];
        }

        return [];
    }

    /**
     * @param array<string, mixed>|null $data
     * @param list<ApiError>            $errors
     */
    private function message(TransportRequest $request, TransportResponse $response, ?array $data, array $errors): string
    {
        $message = sprintf('Furgonetka API %s failed (HTTP %d)', $request->describe(), $response->statusCode);

        if ($errors !== []) {
            $message .= ': ' . $errors[0];

            if (count($errors) > 1) {
                $message .= sprintf(' (and %d more)', count($errors) - 1);
            }
        } elseif ($data === null && trim($response->body) !== '') {
            $message .= ': ' . self::excerpt($response->body);
        } else {
            $message .= '.';
        }

        if ($response->statusCode === 401) {
            $hint = self::authHint($errors);

            if ($hint !== null) {
                $message .= ' Hint: ' . $hint;
            }
        }

        if ($response->statusCode === 409 && self::hasCode($errors, 'terms_and_conditions_not_valid')) {
            $message .= ' Hint: the account has not accepted the carrier\'s current regulations. Read what is pending with '
                . 'pendingRegulations() and accept it with acceptRegulations(); the library never does that for you.';
        }

        if ($response->statusCode === 429) {
            $wait = $response->retryAfterSeconds();
            $message .= $wait !== null ? " Do not retry for {$wait} seconds." : ' The request limit is used up.';
        }

        return $message;
    }

    /** @param list<ApiError> $errors */
    private static function hasCode(array $errors, string $code): bool
    {
        foreach ($errors as $error) {
            if ($error->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a 401 means, from the `details` and `message` Furgonetka gives. All three texts were
     * seen on the sandbox on 2026-09-28; anything else gets no hint.
     *
     * @param list<ApiError> $errors
     */
    private static function authHint(array $errors): ?string
    {
        foreach ($errors as $error) {
            if ($error->details === 'Missing "Authorization" header') {
                return 'no access token was sent.';
            }

            if ($error->details === 'Error while decoding from JSON') {
                return 'the access token is not valid here. It may belong to the other environment '
                    . '(a sandbox token sent to production, or the other way round) or be damaged.';
            }

            if ($error->message === 'Error user authentication') {
                return 'the token belongs to no user. A client credentials token cannot call endpoints that act for a user.';
            }
        }

        return null;
    }

    /**
     * A one-line, tag-free, control-character-free excerpt of a body that is not JSON.
     *
     * Tags become spaces, not nothing, so that `<h1>502</h1><p>Bad Gateway</p>` does not read as
     * `502Bad Gateway`. A body that is not valid UTF-8 (a page in another encoding) is not quoted.
     */
    private static function excerpt(string $body): string
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            return '(no readable text)';
        }

        $text = (string) preg_replace(['/[\x00-\x1F\x7F]+/', '/<[^>]*+>/', '/\s+/u'], ' ', $body);
        $text = trim($text);

        if (mb_strlen($text) > self::EXCERPT_LENGTH) {
            $text = mb_substr($text, 0, self::EXCERPT_LENGTH) . '...';
        }

        return $text !== '' ? $text : '(no readable text)';
    }
}
