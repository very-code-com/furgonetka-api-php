<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** One document of a carrier's regulations, to be shown to the person who accepts them. */
final class RegulationDocument
{
    /**
     * @param string|null $name              Title.
     * @param string|null $url               Where to read it.
     * @param string|null $type              `regulations` or `statement`.
     * @param string|null $key               Identifier of the document.
     * @param string|null $additionalContent Extra text for the acceptance.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $url = null,
        public readonly ?string $type = null,
        public readonly ?string $key = null,
        public readonly ?string $additionalContent = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Value::string($data, 'name'),
            Value::string($data, 'url'),
            Value::string($data, 'type'),
            Value::string($data, 'key'),
            Value::string($data, 'additional_content'),
        );
    }
}
