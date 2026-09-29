<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * The countries shipments can go from and to (`GET /configuration/allowed-countries`, which needs
 * no login).
 *
 * The `duty` flag is the authority on which destinations need customs data: on the sandbox it
 * lists sixteen countries, two (China and Serbia) more than the specification's text. Pass
 * {@see self::dutyCountryCodes()} to the local validation and the list is always current.
 *
 * The regions (counties, states, provinces), the list of all countries and the freight exchange
 * countries are left in {@see self::raw()}.
 */
final class AllowedCountries
{
    /**
     * @param list<Country>        $shipment Countries a shipment can be sent from.
     * @param list<Country>        $delivery Countries a shipment can be delivered to.
     * @param array<string, mixed> $raw      The whole answer.
     */
    public function __construct(
        public readonly array $shipment = [],
        public readonly array $delivery = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(Country::fromArray(...), Value::objectList($data, 'shipment')),
            array_map(Country::fromArray(...), Value::objectList($data, 'delivery')),
            $data,
        );
    }

    /**
     * The country codes a shipment needs `duty` for when it is delivered there.
     *
     * @return list<string>
     */
    public function dutyCountryCodes(): array
    {
        $codes = [];

        foreach ($this->delivery as $country) {
            if ($country->duty === true && $country->code !== null) {
                $codes[] = strtoupper($country->code);
            }
        }

        return $codes;
    }

    /** True when a shipment delivered to this country needs `duty`. */
    public function requiresDuty(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->dutyCountryCodes(), true);
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
}
