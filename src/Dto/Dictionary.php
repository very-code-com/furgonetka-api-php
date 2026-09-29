<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * Furgonetka's own dictionary (`GET /configuration/dictionary`, no login needed): the names of
 * carriers, additional services, currencies and form fields, in the language of the request.
 *
 * It is the most complete list of carriers there is: {@see \VeryCodeCom\FurgonetkaApi\Enum\Carrier}
 * is built from it. Everything is in {@see self::raw()}; the two lookups below are the ones a
 * client needs.
 */
final class Dictionary
{
    /** @param array<string, mixed> $raw The whole answer. */
    public function __construct(private readonly array $raw = [])
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Carrier identifier => display name.
     *
     * @return array<string, string>
     */
    public function carriers(): array
    {
        return $this->labels('services');
    }

    /**
     * Additional service name => label. It has about a hundred entries, twice what the
     * specification documents.
     *
     * @return array<string, string>
     */
    public function additionalServices(): array
    {
        return $this->labels('additional_services');
    }

    /**
     * The whole answer as Furgonetka sent it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $section): array
    {
        $labels = [];

        foreach (Value::object($this->raw, $section) ?? [] as $key => $label) {
            if (is_string($label)) {
                $labels[(string) $key] = $label;
            }
        }

        return $labels;
    }
}
