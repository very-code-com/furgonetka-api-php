<?php

declare(strict_types=1);

/**
 * Connects your own Furgonetka account once, from a terminal, with the password grant.
 *
 *   FURGONETKA_CLIENT_ID=... FURGONETKA_CLIENT_SECRET=... FURGONETKA_ENV=sandbox \
 *   php examples/02_password_grant_and_2fa.php [login]
 *
 * The login comes from the argument, FURGONETKA_USERNAME or a prompt; the password is asked for and
 * never read from a file or the environment of a service. With two-factor authentication the account
 * answers with a hint, and the script asks for the code.
 *
 * What it shows:
 *   - connectWithPassword() logs in and keeps the tokens in the store (a file here);
 *   - a two-factor account throws FurgonetkaApiTwoFactorRequiredException; connectWithTwoFactor() finishes;
 *   - after that the client refreshes the token itself and no password is needed.
 *
 * Every password login is a new OAuth session and Furgonetka keeps at most 20 per application and user,
 * so run this once per installation and never per request. For a shop that must survive a lost token
 * without a person, PasswordReauthorizer can log in again on its own; it needs the password in the
 * environment and cannot pass a two-factor code, so it is off unless you pass it to create().
 * Where you connect somebody else's account, use 01_authorize_user.php and never keep their password.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTwoFactorRequiredException;

/** Reads a line from the terminal, hiding what is typed when the terminal allows it. */
function ask(string $question, bool $hidden = false): string
{
    fwrite(STDERR, $question);
    $hide = $hidden && function_exists('posix_isatty') && posix_isatty(STDIN);

    if ($hide) {
        shell_exec('stty -echo');
    }

    $answer = fgets(STDIN);

    if ($hide) {
        shell_exec('stty echo');
        fwrite(STDERR, PHP_EOL);
    }

    return trim($answer === false ? '' : $answer);
}

exampleRun(static function (): void {
    $client = exampleClient();

    $login = exampleArgument(1) ?? (getenv('FURGONETKA_USERNAME') ?: '');
    $login = $login !== '' ? $login : ask('Furgonetka login: ');
    $password = ask('Password: ', hidden: true);

    try {
        $client->connectWithPassword($login, $password);
    } catch (FurgonetkaApiTwoFactorRequiredException $e) {
        $code = ask('Two-factor code: ', hidden: true);
        $client->connectWithTwoFactor($e->hint(), $login, $code);
    }

    $services = $client->listServices();

    echo 'Connected. The token is in ' . exampleTokenFile(exampleConfig()) . PHP_EOL;
    echo 'The account has ' . count($services) . ' carrier contracts.' . PHP_EOL;
});
