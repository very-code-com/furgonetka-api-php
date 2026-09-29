<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\PointService;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * The data of a "send to me" link ("Nadanie do mnie"): a receiver who is asked to fill in the
 * shipment form themselves, with their own data already in it.
 *
 * The rules below are the ones the documentation states and the sandbox enforced when this was
 * checked: e-mail, phone and country always; a name or a company; the street, postcode and city
 * unless a pickup point is given; and the carrier of the point when there is one. What the
 * documentation adds for Ireland, the United States and Canada (a county) and for Ukraine (building
 * and flat number, a postcode format) is not checked here: the sandbox accepted an Irish address
 * without a county, so it is a hint, not a rule. Furgonetka validates the values themselves (a
 * badly formed e-mail or phone is a 400).
 */
final class SendToMeRequest
{
    /**
     * @param string                $email          E-mail of the receiver.
     * @param string                $phone          Phone of the receiver.
     * @param string                $countryCode    ISO 3166-1 alpha-2.
     * @param string|null           $name           First and last name; required when there is no company.
     * @param string|null           $company        Company name; required when there is no name.
     * @param string|null           $street         Street with the building number; required without a point.
     * @param string|null           $postcode       Postcode; required without a point.
     * @param string|null           $city           City; required without a point.
     * @param string|null           $county         County, state or province (Ireland, the United States, Canada).
     * @param string|null           $buildingNumber Building number (Ukraine).
     * @param string|null           $flatNumber     Flat number (Ukraine).
     * @param string|null           $point          Identifier of a pickup point, see `Point::identifier()`; makes the address optional.
     * @param PointService|string|null $service     Carrier of the point; required with a point.
     *
     * @throws \InvalidArgumentException when a rule stated above is broken.
     */
    public function __construct(
        public readonly string $email,
        public readonly string $phone,
        public readonly string $countryCode,
        public readonly ?string $name = null,
        public readonly ?string $company = null,
        public readonly ?string $street = null,
        public readonly ?string $postcode = null,
        public readonly ?string $city = null,
        public readonly ?string $county = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $flatNumber = null,
        public readonly ?string $point = null,
        public readonly PointService|string|null $service = null,
    ) {
        foreach (['email' => $email, 'phone' => $phone, 'countryCode' => $countryCode] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("SendToMeRequest: {$field} must not be empty.");
            }
        }

        if (trim($name ?? '') === '' && trim($company ?? '') === '') {
            throw new \InvalidArgumentException('SendToMeRequest: give a name or a company.');
        }

        $hasPoint = trim($point ?? '') !== '';

        if ($hasPoint && self::serviceName($service) === '') {
            throw new \InvalidArgumentException('SendToMeRequest: a point needs the carrier that runs it in service.');
        }

        if (!$hasPoint) {
            foreach (['street' => $street, 'postcode' => $postcode, 'city' => $city] as $field => $value) {
                if (trim($value ?? '') === '') {
                    throw new \InvalidArgumentException("SendToMeRequest: {$field} is required unless a point is given.");
                }
            }
        }
    }

    /**
     * The body of the request.
     *
     * @return array{receiver: array<string, mixed>, service?: string}
     */
    public function toArray(): array
    {
        $body = ['receiver' => Payload::compact([
            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->countryCode,
            'street' => $this->street,
            'postcode' => $this->postcode,
            'city' => $this->city,
            'name' => $this->name,
            'company' => $this->company,
            'county' => $this->county,
            'building_number' => $this->buildingNumber,
            'flat_number' => $this->flatNumber,
            'point' => $this->point,
        ])];

        $service = self::serviceName($this->service);

        if ($service !== '') {
            $body['service'] = $service;
        }

        return $body;
    }

    private static function serviceName(PointService|string|null $service): string
    {
        return $service instanceof PointService ? $service->value : trim((string) $service);
    }
}
