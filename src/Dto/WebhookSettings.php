<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The webhook the account has set up (`GET /account/settings/notification/webhook`): where
 * Furgonetka posts tracking changes, in which format, and the token it signs them with.
 *
 * An account that never set a webhook answers with all three null. **The token is a secret**: it
 * is the salt of the checksum of each notification, so `var_dump()`, `print_r()` and the debug
 * report show it as `[redacted]`; read it from {@see self::$token} on purpose.
 */
final class WebhookSettings
{
    /**
     * @param string|null          $url         Address Furgonetka posts to.
     * @param WebhookFormat|null   $format      Format; null when there is none or it is not a member.
     * @param string|null          $formatValue Format as sent.
     * @param string|null          $token       Checksum salt.
     * @param array<string, mixed> $raw         The whole answer, token included.
     */
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?WebhookFormat $format = null,
        public readonly ?string $formatValue = null,
        #[\SensitiveParameter]
        public readonly ?string $token = null,
        #[\SensitiveParameter]
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        return new self(
            Value::string($data, 'url'),
            Value::enum($data, 'format', WebhookFormat::class),
            Value::string($data, 'format'),
            Value::string($data, 'token'),
            $data,
        );
    }

    /** True when the account has a webhook address. */
    public function isConfigured(): bool
    {
        return $this->url !== null;
    }

    /**
     * The whole answer as Furgonetka sent it, token included.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'url' => $this->url,
            'format' => $this->format,
            'formatValue' => $this->formatValue,
            'token' => $this->token === null ? null : '[redacted]',
            'raw' => '[redacted]',
        ];
    }
}
