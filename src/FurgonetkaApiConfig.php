<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi;

use VeryCodeCom\FurgonetkaApi\Auth\OAuthCredentials;
use VeryCodeCom\FurgonetkaApi\Enum\Language;
use VeryCodeCom\FurgonetkaApi\Internal\Env;

/**
 * Furgonetka client configuration.
 *
 * Furgonetka authenticates with OAuth2. What lives here is the part that is configuration: the
 * keys of your OAuth application (Client ID and Client Secret, created in the panel under
 * "Aplikacje OAuth"), the environment, the response language and a few knobs. The tokens obtained
 * with those keys are state and belong in a token store, never in this object. User passwords
 * are not part of the configuration at all.
 *
 * Keys are issued **per environment**: sandbox (https://sandbox.furgonetka.pl) and production
 * (https://furgonetka.pl) are separate installations with separate accounts, and an application
 * created in one does not exist in the other.
 *
 * Four ways to build a config:
 *
 * 1. Named constructors:
 *      $config = FurgonetkaApiConfig::sandbox($clientId, $clientSecret);
 *      $config = FurgonetkaApiConfig::production($clientId, $clientSecret, redirectUri: $uri);
 *      $config = FurgonetkaApiConfig::production();   // no keys: public endpoints only
 *
 * 2. From environment variables (recommended for 12-factor apps):
 *      $config = FurgonetkaApiConfig::fromEnv();
 *
 *    FURGONETKA_CLIENT_ID        - required
 *    FURGONETKA_CLIENT_SECRET    - required
 *    FURGONETKA_ENV              - "sandbox" | "production" (default: "production")
 *    FURGONETKA_REDIRECT_URI     - only for the authorization_code grant
 *    FURGONETKA_LANGUAGE         - "en_GB" | "pl_PL" (default: "en_GB")
 *    FURGONETKA_COMPANY_ID       - sent as X-Company-Id, for multi-company accounts
 *    FURGONETKA_TIMEOUT          - int seconds (default: 30)
 *    FURGONETKA_CONNECT_TIMEOUT  - int seconds (default: 10)
 *    FURGONETKA_DEBUG            - "1"/"true"/"yes"/"on" to enable debug reporting
 *
 *    The library does not read `.env` files and does not depend on a dotenv package: loading
 *    variables is the job of your application, container or CI.
 *
 * 3. From an array (framework config files); the keys are the lower-case names above without the
 *    prefix: client_id, client_secret, env, redirect_uri, language, company_id, timeout,
 *    connect_timeout, debug.
 *
 * 4. The constructor, with an {@see OAuthCredentials} object.
 *
 * Values that are present but invalid are rejected instead of falling back to a default. That is
 * deliberate for `env`: a typo such as "sandbx" must not quietly point a shop at production,
 * where every shipment ordered is real and billed.
 */
final class FurgonetkaApiConfig
{
    public const BASE_URL_SANDBOX    = 'https://api.sandbox.furgonetka.pl';
    public const BASE_URL_PRODUCTION = 'https://api.furgonetka.pl';

    /** Header that selects the language of the messages in responses. */
    public const HEADER_LANGUAGE = 'X-Language';

    /** Header that selects the company on accounts that have more than one. */
    public const HEADER_COMPANY_ID = 'X-Company-Id';

    public const DEFAULT_TIMEOUT         = 30;
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    /** Environment variable name for each config key, used by {@see self::fromEnv()}. */
    private const ENV_NAMES = [
        'client_id'       => 'FURGONETKA_CLIENT_ID',
        'client_secret'   => 'FURGONETKA_CLIENT_SECRET',
        'env'             => 'FURGONETKA_ENV',
        'redirect_uri'    => 'FURGONETKA_REDIRECT_URI',
        'language'        => 'FURGONETKA_LANGUAGE',
        'company_id'      => 'FURGONETKA_COMPANY_ID',
        'timeout'         => 'FURGONETKA_TIMEOUT',
        'connect_timeout' => 'FURGONETKA_CONNECT_TIMEOUT',
        'debug'           => 'FURGONETKA_DEBUG',
    ];

    /**
     * @param OAuthCredentials|null $credentials Keys of the OAuth application. Null gives a client
     *                                           that can only call the public endpoints (the
     *                                           dictionary and the pickup point map).
     * @param bool                  $sandbox     Target api.sandbox.furgonetka.pl instead of production.
     * @param Language              $language    Language of API messages; English unless you ask for Polish.
     * @param int|null              $companyId   Company to act for, on accounts with several companies.
     * @param int                   $timeout     Total request timeout, in seconds.
     * @param int                   $connectTimeout TCP connection timeout, in seconds.
     * @param bool                  $debug       When true, the client attaches the raw Furgonetka response
     *                                           to thrown exceptions and logs a full debug report (raw
     *                                           body + stack trace) for every error. Tokens are masked
     *                                           in both. Leave false in production to keep exceptions
     *                                           and logs short.
     */
    public function __construct(
        public readonly ?OAuthCredentials $credentials = null,
        public readonly bool $sandbox = false,
        public readonly Language $language = Language::English,
        public readonly ?int $companyId = null,
        public readonly int $timeout = self::DEFAULT_TIMEOUT,
        public readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        public readonly bool $debug = false,
    ) {
        if ($companyId !== null && $companyId < 1) {
            throw new \InvalidArgumentException('FurgonetkaApiConfig: companyId must be a positive integer.');
        }

        if ($timeout < 1) {
            throw new \InvalidArgumentException('FurgonetkaApiConfig: timeout must be at least 1 second.');
        }

        if ($connectTimeout < 1) {
            throw new \InvalidArgumentException('FurgonetkaApiConfig: connectTimeout must be at least 1 second.');
        }
    }

    // -- Named constructors ----------------------------------------------------

    /**
     * Target the test installation (api.sandbox.furgonetka.pl): nothing ordered there is real.
     * Without keys the config only serves the public endpoints.
     */
    public static function sandbox(
        ?string $clientId = null,
        #[\SensitiveParameter]
        ?string $clientSecret = null,
        ?string $redirectUri = null,
    ): self {
        return new self(self::credentials($clientId, $clientSecret, $redirectUri), sandbox: true);
    }

    /**
     * Target the live installation (api.furgonetka.pl): a shipment ordered here is real and
     * billed. Without keys the config only serves the public endpoints.
     */
    public static function production(
        ?string $clientId = null,
        #[\SensitiveParameter]
        ?string $clientSecret = null,
        ?string $redirectUri = null,
    ): self {
        return new self(self::credentials($clientId, $clientSecret, $redirectUri));
    }

    // -- Factories -------------------------------------------------------------

    /**
     * Build the config from environment variables (see the class docblock for the list).
     *
     * Sources are read in the order `getenv()`, `$_ENV`, `$_SERVER`, and the first non-empty one
     * wins, so it works whether or not `variables_order` exposes `$_ENV`.
     *
     * @throws \InvalidArgumentException when a required variable is missing or a value is invalid.
     */
    public static function fromEnv(): self
    {
        $values = [];

        foreach (self::ENV_NAMES as $key => $name) {
            $value = Env::read($name);
            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return self::fromValues($values, self::ENV_NAMES, 'fromEnv');
    }

    /**
     * Build the config from an associative array, for framework config files.
     *
     * Keys: client_id*, client_secret*, env, redirect_uri, language, company_id, timeout,
     * connect_timeout, debug. Unknown keys are ignored.
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException when a required key is missing or a value is invalid.
     */
    public static function fromArray(array $config): self
    {
        $labels = [];
        foreach (array_keys(self::ENV_NAMES) as $key) {
            $labels[$key] = $key;
        }

        return self::fromValues($config, $labels, 'fromArray');
    }

    // -- Helpers ---------------------------------------------------------------

    /** Host root of the selected environment, without a trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    /**
     * Absolute URL of an API path. Furgonetka does not put the API version in the path (it is
     * chosen per endpoint through the media type), so this is only host + path.
     *
     * @param string $path e.g. `packages/123/tracking` or `oauth/token`.
     */
    public function url(string $path): string
    {
        return $this->getBaseUrl() . '/' . ltrim($path, '/');
    }

    public function getEnvironment(): string
    {
        return $this->sandbox ? 'sandbox' : 'production';
    }

    /** True when the OAuth application keys were given, i.e. authenticated endpoints can be used. */
    public function hasCredentials(): bool
    {
        return $this->credentials !== null;
    }

    /**
     * TLS verification is always on and cannot be turned off. Both environments use valid public
     * certificates, so there is no legitimate reason for a switch.
     */
    public function verifySsl(): bool
    {
        return true;
    }

    /** The same config acting for another company (or for none, with null). */
    public function withCompanyId(?int $companyId): self
    {
        if ($companyId === $this->companyId) {
            return $this;
        }

        return new self(
            credentials:    $this->credentials,
            sandbox:        $this->sandbox,
            language:       $this->language,
            companyId:      $companyId,
            timeout:        $this->timeout,
            connectTimeout: $this->connectTimeout,
            debug:          $this->debug,
        );
    }

    // -- Internals -------------------------------------------------------------

    private static function credentials(
        ?string $clientId,
        #[\SensitiveParameter]
        ?string $clientSecret,
        ?string $redirectUri,
    ): ?OAuthCredentials {
        if ($clientId === null && $clientSecret === null) {
            if ($redirectUri !== null) {
                throw new \InvalidArgumentException('FurgonetkaApiConfig: a redirectUri needs a clientId and a clientSecret.');
            }

            return null;
        }

        if ($clientId === null || $clientSecret === null) {
            throw new \InvalidArgumentException('FurgonetkaApiConfig: clientId and clientSecret must be given together.');
        }

        return new OAuthCredentials($clientId, $clientSecret, $redirectUri);
    }

    /**
     * Shared body of {@see self::fromEnv()} and {@see self::fromArray()}.
     *
     * @param array<string, mixed>  $values Raw values by config key.
     * @param array<string, string> $labels What to call each key in error messages (the variable
     *                                      name for env input, the key itself for arrays).
     */
    private static function fromValues(array $values, array $labels, string $origin): self
    {
        $prefix = "FurgonetkaApiConfig::{$origin}(): ";

        $clientId = self::text($values['client_id'] ?? null, $labels['client_id']);
        $clientSecret = self::text($values['client_secret'] ?? null, $labels['client_secret']);

        if ($clientId === null) {
            throw new \InvalidArgumentException($prefix . $labels['client_id'] . ' is required.');
        }

        if ($clientSecret === null) {
            throw new \InvalidArgumentException($prefix . $labels['client_secret'] . ' is required.');
        }

        return new self(
            credentials:    new OAuthCredentials($clientId, $clientSecret, self::text($values['redirect_uri'] ?? null, $labels['redirect_uri'])),
            sandbox:        self::environment($values['env'] ?? null, $labels['env']),
            language:       self::language($values['language'] ?? null, $labels['language']),
            companyId:      self::positiveInt($values['company_id'] ?? null, $labels['company_id']),
            timeout:        self::positiveInt($values['timeout'] ?? null, $labels['timeout']) ?? self::DEFAULT_TIMEOUT,
            connectTimeout: self::positiveInt($values['connect_timeout'] ?? null, $labels['connect_timeout']) ?? self::DEFAULT_CONNECT_TIMEOUT,
            debug:          self::flag($values['debug'] ?? null, $labels['debug']),
        );
    }

    /**
     * A trimmed string, or null for absent and blank. Also reads the client secret, hence the
     * sensitive marker: a wrong type must not put the value into a stack trace.
     */
    private static function text(
        #[\SensitiveParameter]
        mixed $value,
        string $label,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException("FurgonetkaApiConfig: {$label} must be a string.");
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** True for sandbox. Anything but the two known names is an error, see the class docblock. */
    private static function environment(mixed $value, string $label): bool
    {
        $name = self::text($value, $label);

        return match ($name === null ? 'production' : strtolower($name)) {
            'sandbox'    => true,
            'production' => false,
            default      => throw new \InvalidArgumentException(
                "FurgonetkaApiConfig: {$label} must be \"sandbox\" or \"production\", got \"{$name}\"."
            ),
        };
    }

    private static function language(mixed $value, string $label): Language
    {
        if ($value instanceof Language) {
            return $value;
        }

        $name = self::text($value, $label);

        if ($name === null) {
            return Language::English;
        }

        return Language::tryFrom($name)
            ?? throw new \InvalidArgumentException("FurgonetkaApiConfig: {$label} must be \"en_GB\" or \"pl_PL\", got \"{$name}\".");
    }

    private static function positiveInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException("FurgonetkaApiConfig: {$label} must be a positive integer.");
        }

        return $value;
    }

    private static function flag(mixed $value, string $label): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            if (in_array($normalised, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalised, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException("FurgonetkaApiConfig: {$label} must be a boolean (1/0, true/false, yes/no, on/off).");
    }
}
