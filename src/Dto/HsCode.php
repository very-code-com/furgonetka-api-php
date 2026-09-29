<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/** One code of the customs tariff (HS code) with its description, from a search. */
final class HsCode
{
    /**
     * @param string|null $hsCode      The code, for `ExportItem::$tariff`.
     * @param string|null $description What the code covers.
     * @param string|null $lang        Language of the description, for instance "EN".
     */
    public function __construct(
        public readonly ?string $hsCode = null,
        public readonly ?string $description = null,
        public readonly ?string $lang = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Value::string($data, 'hsCode'), Value::string($data, 'description'), Value::string($data, 'lang'));
    }
}
