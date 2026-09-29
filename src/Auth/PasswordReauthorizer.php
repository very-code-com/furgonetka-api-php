<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Auth;

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiReauthorizationRequiredException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTwoFactorRequiredException;
use VeryCodeCom\FurgonetkaApi\Internal\Env;

/**
 * Logs in again with the account's username and password when the refresh token is gone.
 *
 * **This is opt-in, and it is a trade.** It makes a shop that connected its own account survive
 * the three month life of a refresh token without anyone noticing, but only because the password
 * sits in the server's environment. Anyone who can read that environment can log in as the
 * account. Use it only for your own shop's account, never for accounts of your customers (use the
 * authorization code flow for those, so their passwords never reach you), and prefer a dedicated
 * Furgonetka user with only the rights the shop needs.
 *
 * It cannot help an account with two-factor authentication: the code has to come from a person,
 * so the login fails with {@see FurgonetkaApiReauthorizationRequiredException}.
 *
 * Each login is a new session out of the 20 Furgonetka allows per user and application. The
 * token manager calls this only when the refresh token failed, which is rare, and never per
 * request.
 */
final class PasswordReauthorizer implements ReauthorizerInterface
{
    public const ENV_USERNAME = 'FURGONETKA_USERNAME';
    public const ENV_PASSWORD = 'FURGONETKA_PASSWORD';

    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
    ) {
        if (trim($username) === '') {
            throw new \InvalidArgumentException('PasswordReauthorizer: the username must not be empty.');
        }

        if ($password === '') {
            throw new \InvalidArgumentException('PasswordReauthorizer: the password must not be empty.');
        }
    }

    /**
     * Read `FURGONETKA_USERNAME` and `FURGONETKA_PASSWORD`. These variables are read here and
     * nowhere else: {@see \VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig} does not know them, so
     * a client without a reauthorizer never has the password in reach.
     *
     * @throws \InvalidArgumentException when either variable is missing.
     */
    public static function fromEnv(): self
    {
        $username = Env::read(self::ENV_USERNAME);
        $password = Env::read(self::ENV_PASSWORD);

        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException(sprintf(
                'PasswordReauthorizer::fromEnv(): %s and %s are required.',
                self::ENV_USERNAME,
                self::ENV_PASSWORD,
            ));
        }

        return new self($username, $password);
    }

    public function reauthorize(OAuthClient $oauth): AccessToken
    {
        try {
            return $oauth->passwordGrant($this->username, $this->password);
        } catch (FurgonetkaApiTwoFactorRequiredException $e) {
            throw new FurgonetkaApiReauthorizationRequiredException(
                'Furgonetka needs a new login, but the account has two-factor authentication, '
                . 'so it cannot be done without a person. Connect the account again by hand.',
                $e->error,
                $e->errorDescription,
                $e->statusCode,
                $e,
            );
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => '[redacted]'];
    }
}
