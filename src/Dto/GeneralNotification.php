<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One general notification option of the account, such as the shipping documents by e-mail.
 *
 * The `name` and `description` are texts in the language of the request and are not stable, so key
 * off `key`. `enabled` is null when the option does not exist for the account.
 */
final class GeneralNotification
{
    /**
     * @param string|null $key         Identifier, for instance `shipmentDox` or `packageReport`.
     * @param string|null $name        Name for a person.
     * @param string|null $description What the option does.
     * @param bool|null   $enabled     Whether it is on; null when the option is not available.
     */
    public function __construct(
        public readonly ?string $key = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?bool $enabled = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'key'),
            Value::string($data, 'name'),
            Value::string($data, 'description'),
            Value::bool($data, 'enabled'),
        );
    }
}
