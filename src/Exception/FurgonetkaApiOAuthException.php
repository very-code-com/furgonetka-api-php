<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown when the OAuth token endpoint refuses a request.
 *
 * The token endpoint does not use the error shape of the rest of the API. It answers in the
 * RFC 6749 form `{"error": "invalid_grant", "error_description": "..."}`, so these errors get
 * their own branch of the hierarchy instead of being a {@see FurgonetkaApiHttpException}.
 *
 * Common values of {@see self::$error}:
 *  - `invalid_client`: the Client ID or Secret is wrong, or belongs to the other environment.
 *  - `invalid_grant`: wrong user credentials, an expired or already used authorization code, or
 *    a refresh token that was used, revoked or pushed out by the 20-session limit.
 *  - `2fa_required`: see {@see FurgonetkaApiTwoFactorRequiredException}.
 */
class FurgonetkaApiOAuthException extends FurgonetkaApiException
{
    /**
     * @param string $error            The RFC 6749 `error` code, empty when the body had none.
     * @param string $errorDescription The `error_description` text, empty when absent.
     * @param int    $statusCode       HTTP status of the answer, 0 when the exception is raised
     *                                 without one (for example when no refresh token is stored).
     */
    public function __construct(
        string $message,
        public readonly string $error = '',
        public readonly string $errorDescription = '',
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
