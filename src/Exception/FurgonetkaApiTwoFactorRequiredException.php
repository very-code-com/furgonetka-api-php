<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Exception;

/**
 * Thrown by the password grant when the account has two-factor authentication turned on.
 *
 * This is not a failure but the first half of the login. Ask the user for the code from their
 * authenticator app and finish with `completeTwoFactor($hint, $login, $code)` within five
 * minutes; after that the whole login has to start again.
 */
class FurgonetkaApiTwoFactorRequiredException extends FurgonetkaApiOAuthException
{
    /**
     * @param string $hint UUID from the response's `hint` field, the handle of the pending login.
     */
    public function __construct(
        string $message,
        private readonly string $hint,
        string $errorDescription = '',
        int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, '2fa_required', $errorDescription, $statusCode, $previous);
    }

    /** Identifier to hand back to `completeTwoFactor()`. */
    public function hint(): string
    {
        return $this->hint;
    }
}
