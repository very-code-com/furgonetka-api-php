<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Validator;

use VeryCodeCom\FurgonetkaApi\Dto\AdditionalServices;
use VeryCodeCom\FurgonetkaApi\Dto\LabelSender;
use VeryCodeCom\FurgonetkaApi\Dto\Parcel;
use VeryCodeCom\FurgonetkaApi\Dto\Pickup;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\DutyType;
use VeryCodeCom\FurgonetkaApi\Enum\ParcelType;

/**
 * Local pre-flight validation of a {@see ShipmentRequest}, run before any network call.
 *
 * The authority is `POST /packages/validate` (`validateRemotely()`). This validator exists to
 * catch the mistakes that are certain, without a request and without using up the request limit,
 * for instance while a form is still being filled in or before a batch.
 *
 * **What is an error and what is a warning.** Furgonetka's documentation states more rules than
 * its API enforces: on 2026-09-29 the sandbox accepted a DHL parcel without a description, a DPD
 * reference number of 36 characters (documented limit 27), two Poczta parcels (documented 1) and,
 * for DPD, a COD above the declared value. A local error for a payload the API accepts would stop
 * a valid shipment, so:
 *
 *  - a rule is an **error** only when the sandbox enforces it (or the field is plainly required, as
 *    a street is);
 *  - a rule the documentation states but the sandbox does not enforce, or that could not be
 *    verified for that carrier, is a **warning**, which never stops anything.
 *
 * The sandbox result of each rule is written next to it. Carrier-specific rules apply only when
 * the carrier is known; without it the general limits still do.
 *
 * Messages start with the path of the field the way Furgonetka's own errors do
 * (`/receiver/name`, `/parcels/0/description`), and never quote the value: they are logged, and
 * a value is a name or an address.
 *
 * @internal
 */
final class ShipmentValidator
{
    /**
     * Destination countries that require `duty`: Australia, Belarus, Hong Kong, India, Israel,
     * Canada, Norway, New Zealand, Singapore, the United States, Switzerland, Turkey, Ukraine and
     * Great Britain. Verified on the sandbox for Great Britain (`/duty` is empty otherwise, for UPS
     * and GLS). `GET /configuration/allowed-countries` flags more, see `$dutyCountries`.
     */
    public const DUTY_COUNTRIES = ['AU', 'BY', 'HK', 'IN', 'IL', 'CA', 'NO', 'NZ', 'SG', 'US', 'CH', 'TR', 'UA', 'GB'];

    /** Countries whose addresses need a `county`: Ireland (county), the United States (state), Canada (province). Verified for the United States. */
    public const COUNTY_COUNTRIES = ['IE', 'US', 'CA'];

    /** Carriers whose sender data ("other data on the label") the documentation lists. */
    private const LABEL_SENDER_CARRIERS = [
        Carrier::Dpd, Carrier::Dhl, Carrier::Fedex, Carrier::Ups, Carrier::InPost, Carrier::InPostKurier, Carrier::AmbroExpress,
    ];

    /** Carriers that reject a COD above the sum of the parcel values (verified). DPD accepts it. */
    private const COD_VALUE_ENFORCED = [Carrier::Dhl, Carrier::Fedex, Carrier::Poczta];

    /** Longest full name, and longest company, when the carrier has no limit of its own. */
    private const NAME_MAX = 90;
    private const COMPANY_MAX = 80;

    /**
     * Documented length limits by carrier: role => carrier => limit. A role is `pickup`, `receiver` or `sender`.
     * FedEx's company limit of 150 is above the general 80, so it relaxes it.
     */
    private const NAME_LIMITS = [
        'pickup' => ['dhl' => 60, 'poczta' => 60, 'ups' => 30, 'ambroexpress' => 60],
        'receiver' => ['dhl' => 60, 'poczta' => 60, 'ups' => 30, 'ambroexpress' => 60],
        'sender' => ['dhl' => 60, 'ups' => 30],
    ];

    private const COMPANY_LIMITS = [
        'pickup' => ['dhl' => 60, 'fedex' => 150, 'poczta' => 30, 'orlen' => 30, 'ups' => 30, 'ambroexpress' => 60],
        'receiver' => ['dhl' => 60, 'fedex' => 150, 'poczta' => 30, 'orlen' => 30, 'ups' => 30, 'ambroexpress' => 60],
        'sender' => ['dhl' => 60, 'fedex' => 150, 'ups' => 30],
    ];

    /**
     * The documented limits the sandbox does not enforce, or that could not be checked: the
     * UPS receiver name and company and the Poczta receiver company accepted 31 characters, and
     * no ORLEN Paczka limit could be tried. Those are warnings above the documented limit.
     * Format: `role.field.carrier`.
     */
    private const LIMIT_NOT_ENFORCED = [
        'receiver.name.ups', 'receiver.company.poczta', 'receiver.company.ups',
        'pickup.company.orlen', 'receiver.company.orlen',
    ];

    /** Documented longest `user_reference_number` by carrier. The sandbox accepted longer ones. */
    private const REFERENCE_LIMITS = ['dpd' => 27, 'fedex' => 30, 'ups' => 35, 'inpost' => 28, 'gls' => 25];

    /** The longest reference DPD accepts (verified: 40 characters answer "The maximum number of characters is 36"). */
    private const DPD_REFERENCE_MAX = 36;

    /**
     * Run every rule.
     *
     * @param Carrier|null         $carrier       The carrier of `$request->serviceId`, when known.
     * @param AgreementOwner|null  $owner         Whose contract that service is, when known; decides UPS's parcel limit.
     * @param list<string>         $dutyCountries Countries to require `duty` for, beyond {@see self::DUTY_COUNTRIES}
     *                                            (the `duty` flag of `fetchAllowedCountries()`).
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(
        ShipmentRequest $request,
        ?Carrier $carrier = null,
        ?AgreementOwner $owner = null,
        array $dutyCountries = [],
    ): array {
        $errors = [];
        $warnings = [];

        $this->checkRequired($request, $carrier, $errors);
        $this->checkParty('pickup', $request->pickup->name, $request->pickup->company, $carrier, $errors, $warnings);
        $this->checkParty('receiver', $request->receiver->name, $request->receiver->company, $carrier, $errors, $warnings);
        $this->checkCounty($request, $errors);
        $this->checkParcelCount($request, $carrier, $owner, $errors, $warnings);
        $this->checkParcels($request, $carrier, $errors, $warnings);
        $this->checkServices($request, $carrier, $errors, $warnings);
        $this->checkDuty($request, $dutyCountries, $errors);
        $this->checkReference($request, $carrier, $errors, $warnings);
        $this->checkLabelSender($request, $carrier, $errors, $warnings);

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param list<string> $dutyCountries
     * @return list<string> Error messages; an empty list means nothing is certainly wrong.
     */
    public function validate(ShipmentRequest $request, ?Carrier $carrier = null, ?AgreementOwner $owner = null, array $dutyCountries = []): array
    {
        return $this->check($request, $carrier, $owner, $dutyCountries)['errors'];
    }

    /**
     * Rules the documentation states that the API accepts a violation of, or that could not be
     * verified. Never thrown; the client logs them.
     *
     * @param list<string> $dutyCountries
     * @return list<string>
     */
    public function warnings(ShipmentRequest $request, ?Carrier $carrier = null, ?AgreementOwner $owner = null, array $dutyCountries = []): array
    {
        return $this->check($request, $carrier, $owner, $dutyCountries)['warnings'];
    }

    // -- Required fields ---------------------------------------------------------

    /** @param list<string> $errors */
    private function checkRequired(ShipmentRequest $request, ?Carrier $carrier, array &$errors): void
    {
        if ($request->serviceId < 1) {
            $errors[] = '/service_id: must be the id of a service on the account (GET /account/services).';
        }

        if ($request->parcels === []) {
            $errors[] = '/parcels: at least one parcel is required.';
        }

        foreach (['street' => $request->pickup->street, 'postcode' => $request->pickup->postcode, 'city' => $request->pickup->city] as $field => $value) {
            if (trim($value) === '') {
                $errors[] = "/pickup/{$field}: is required.";
            }
        }

        // A parcel locker or an ORLEN point stands for the address. With an unknown carrier a point
        // gets the benefit of the doubt: the API decides.
        $point = trim($request->receiver->point ?? '') !== '';
        $pointStandsForAddress = $point && ($carrier === null || $carrier === Carrier::InPost || $carrier === Carrier::Orlen);

        if (!$pointStandsForAddress) {
            foreach (['street' => $request->receiver->street, 'postcode' => $request->receiver->postcode, 'city' => $request->receiver->city] as $field => $value) {
                if (trim($value ?? '') === '') {
                    $errors[] = "/receiver/{$field}: is required" . ($point ? ' for this carrier (only InPost parcel lockers and ORLEN Paczka points replace the address).' : '.');
                }
            }
        }
    }

    // -- Name and company --------------------------------------------------------

    /**
     * Presence, format and length of the name and the company of one party.
     *
     * A full name is at least two letters for the first name and two for the last, six characters
     * in all, ninety at most; a company two to eighty. Verified on the sandbox for DPD: `A B` is
     * too short, one word is not a full name, 100 characters are too long, an empty pair is
     * refused. InPost receivers need neither, by the documentation.
     *
     * @param 'pickup'|'receiver'|'sender' $role
     * @param list<string>                 $errors
     * @param list<string>                 $warnings
     */
    private function checkParty(string $role, ?string $name, ?string $company, ?Carrier $carrier, array &$errors, array &$warnings): void
    {
        $name = $name !== null && trim($name) !== '' ? trim($name) : null;
        $company = $company !== null && trim($company) !== '' ? trim($company) : null;

        if ($name === null && $company === null && !($role === 'receiver' && $carrier === Carrier::InPost)) {
            $errors[] = "/{$role}/name: a name or a company is required.";
        }

        if ($name !== null) {
            $parts = preg_split('/\s+/u', $name) ?: [];

            if (count($parts) < 2 || mb_strlen($parts[0]) < 2 || mb_strlen((string) end($parts)) < 2 || mb_strlen($name) < 6) {
                $errors[] = "/{$role}/name: must be a first and a last name of at least 2 characters each, 6 in all.";
            }

            $this->checkLength($role, 'name', mb_strlen($name), self::NAME_MAX, self::NAME_LIMITS[$role], $carrier, $errors, $warnings);
        }

        if ($company !== null) {
            if (mb_strlen($company) < 2) {
                $errors[] = "/{$role}/company: must have at least 2 characters.";
            }

            $this->checkLength($role, 'company', mb_strlen($company), self::COMPANY_MAX, self::COMPANY_LIMITS[$role], $carrier, $errors, $warnings);
        }
    }

    /**
     * @param 'pickup'|'receiver'|'sender' $role
     * @param 'name'|'company'             $field
     * @param array<string, int>           $carrierLimits
     * @param list<string>                 $errors
     * @param list<string>                 $warnings
     */
    private function checkLength(string $role, string $field, int $length, int $generalMax, array $carrierLimits, ?Carrier $carrier, array &$errors, array &$warnings): void
    {
        // Spelled out once, so that no message depends on how far a static analyser can narrow a nullable enum.
        $name = $carrier->value ?? '';
        $limit = $carrier !== null ? ($carrierLimits[$name] ?? null) : null;

        // The general maximum is an error for everyone but a carrier that sets a higher one (FedEx
        // company, 150), which replaces it.
        $errorMax = $limit !== null && $limit > $generalMax ? $limit : $generalMax;
        $enforced = $limit !== null && !in_array("{$role}.{$field}.{$name}", self::LIMIT_NOT_ENFORCED, true);

        if ($enforced) {
            $errorMax = (int) $limit;
        }

        if ($length > $errorMax) {
            $errors[] = "/{$role}/{$field}: is too long, at most {$errorMax} characters" . ($enforced ? " for {$name}." : '.');

            return;
        }

        if ($limit !== null && !$enforced && $length > $limit) {
            $warnings[] = "/{$role}/{$field}: the documentation limits it to {$limit} characters for {$name}, the API accepted more when checked; "
                . 'the carrier may cut or refuse it.';
        }
    }

    // -- County ------------------------------------------------------------------

    /** @param list<string> $errors */
    private function checkCounty(ShipmentRequest $request, array &$errors): void
    {
        $parties = ['pickup' => $request->pickup, 'receiver' => $request->receiver, 'sender' => $request->labelSender];

        foreach ($parties as $role => $party) {
            $country = $party === null ? null : strtoupper(trim($party->countryCode ?? ''));

            if ($party !== null && in_array($country, self::COUNTY_COUNTRIES, true) && trim($party->county ?? '') === '') {
                $errors[] = "/{$role}/county: is required for {$country} (county for IE, state for US, province for CA).";
            }
        }
    }

    // -- Parcels -----------------------------------------------------------------

    /**
     * How many parcels one shipment may hold.
     *
     * Verified on the sandbox: DPD, GLS, FedEx and DHL take 15 (a sixteenth is refused), UPS on
     * Furgonetka's contract takes 1, Poczta takes 10, DHL pallets 5 and a second DPD envelope is
     * refused. Poczta's documented limit of 1 and the documented limits of InPost (1), InPost Kurier
     * (15), ORLEN (1), DHL Express (1) and UPS on an own contract (15) are warnings.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkParcelCount(ShipmentRequest $request, ?Carrier $carrier, ?AgreementOwner $owner, array &$errors, array &$warnings): void
    {
        $count = count($request->parcels);

        if ($carrier === null || $count === 0) {
            return;
        }

        $types = array_map(static fn (Parcel $parcel): ParcelType => $parcel->type, $request->parcels);
        $anyDox = in_array(ParcelType::Dox, $types, true);
        $allPallets = count(array_filter($types, static fn (ParcelType $t): bool => $t === ParcelType::Pallet)) === $count;

        [$errorMax, $warnMax] = match ($carrier) {
            Carrier::Dpd => [$anyDox ? 1 : 15, null],
            Carrier::Gls, Carrier::Fedex => [15, null],
            Carrier::Dhl => [$allPallets ? 5 : 15, null],
            Carrier::Poczta => [10, 1],
            Carrier::Ups => match ($owner) {
                AgreementOwner::Furgonetka => [1, null],
                AgreementOwner::Client => [null, 15],
                null => [null, 1],
            },
            Carrier::InPost, Carrier::Orlen, Carrier::DhlInternational => [null, 1],
            Carrier::InPostKurier => [null, 15],
            default => [null, null],
        };

        if ($errorMax !== null && $count > $errorMax) {
            $errors[] = "/parcels: at most {$errorMax} " . ($allPallets && $carrier === Carrier::Dhl ? 'pallets' : 'parcels')
                . " per shipment for {$carrier->value}, got {$count}.";

            return;
        }

        if ($warnMax !== null && $count > $warnMax) {
            $warnings[] = "/parcels: the documentation allows {$warnMax} parcel(s) per shipment for {$carrier->value}"
                . ($carrier === Carrier::Ups ? ' (more only on your own contract)' : '') . ", got {$count}; the API did not refuse it when checked.";
        }
    }

    /**
     * Description and type-dependent fields of every parcel.
     *
     * Verified: a description shorter than 2 characters is refused, DHL allows 30 and UPS 50. The
     * general maximum of 100 was not enforced (DPD and FedEx accepted 101), and the descriptions
     * documented as required for DHL, FedEx and Poczta Polska validated when missing, so those are
     * warnings. The type-dependent fields could not be tried (no carrier of a fresh account accepts
     * furniture, machines, other or vehicles), so they are warnings too.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkParcels(ShipmentRequest $request, ?Carrier $carrier, array &$errors, array &$warnings): void
    {
        $name = $carrier->value ?? '';
        $requiresDescription = in_array($carrier, [Carrier::Dhl, Carrier::Fedex, Carrier::Poczta], true);
        $enforcedMax = match ($carrier) {
            Carrier::Dhl => 30,
            Carrier::Ups => 50,
            default => null,
        };

        foreach ($request->parcels as $index => $parcel) {
            $description = trim($parcel->description ?? '');

            if ($description === '') {
                if ($requiresDescription) {
                    $warnings[] = "/parcels/{$index}/description: documented as required for {$name}, but the API validated a shipment without it.";
                }
            } else {
                $length = mb_strlen($description);

                if ($length < 2) {
                    $errors[] = "/parcels/{$index}/description: must have at least 2 characters.";
                } elseif ($enforcedMax !== null && $length > $enforcedMax) {
                    $errors[] = "/parcels/{$index}/description: is too long, at most {$enforcedMax} characters for {$name}.";
                } elseif ($length > 100) {
                    $warnings[] = "/parcels/{$index}/description: the documentation limits it to 100 characters, the API accepted more when checked.";
                }
            }

            if (in_array($parcel->type, [ParcelType::Furniture, ParcelType::Machines, ParcelType::Other], true) && $parcel->quantity === null) {
                $warnings[] = "/parcels/{$index}/quantity: documented as needed for the type {$parcel->type->value}.";
            }

            if ($parcel->type === ParcelType::Vehicles) {
                foreach (['brand' => $parcel->brand, 'model' => $parcel->model] as $field => $value) {
                    if (trim($value ?? '') === '') {
                        $warnings[] = "/parcels/{$index}/{$field}: documented as needed for the type vehicles.";
                    }
                }
            }
        }
    }

    // -- Additional services -----------------------------------------------------

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkServices(ShipmentRequest $request, ?Carrier $carrier, array &$errors, array &$warnings): void
    {
        $services = $request->additionalServices;

        if ($services === null) {
            return;
        }

        $set = $services->toArray();

        // Verified: Furgonetka answers "Only one of the services can be selected." (`noTwo`).
        $chosen = array_values(array_filter(AdditionalServices::POCZTA_EXCLUSIVE, static fn (string $key): bool => ($set[$key] ?? false) === true));

        if (count($chosen) > 1) {
            $errors[] = '/additional_services: only one of the Poczta services can be selected, got ' . implode(', ', $chosen) . '.';
        }

        // Verified for DPD: `disableForTwo`.
        if (($set['cud'] ?? false) === true && ($set['rod'] ?? false) === true) {
            $errors[] = '/additional_services/cud: excludes rod, only one of them can be selected.';
        }

        // Not verified: only documented, and not part of the shipment.
        if (($set['saturday_delivery'] ?? false) === true) {
            $warnings[] = '/additional_services/saturday_delivery: the shipment must be handed over on a Friday; '
                . 'the date is not part of the shipment, so check it yourself.';
        }

        $this->checkCod($request, $carrier, $services, $errors, $warnings);
    }

    /**
     * The COD must be covered by the declared value of the parcels. DHL, FedEx and Poczta Polska
     * refuse a COD above the sum (`invalidValueWithoutInsurance`), a shipment with no value at all
     * included; DPD accepted it. A COD in another currency cannot be compared.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkCod(ShipmentRequest $request, ?Carrier $carrier, AdditionalServices $services, array &$errors, array &$warnings): void
    {
        $cod = $services->cod;

        if ($cod === null || ($cod->currency !== null && strtoupper($cod->currency) !== 'PLN')) {
            return;
        }

        $sum = array_sum(array_map(static fn (Parcel $parcel): float => $parcel->value ?? 0.0, $request->parcels));

        if ($sum + 0.000001 >= $cod->amount) {
            return;
        }

        if (in_array($carrier, self::COD_VALUE_ENFORCED, true)) {
            $errors[] = "/additional_services/cod/amount: exceeds the declared value of the parcels ({$sum} PLN), which {$carrier->value} refuses; set Parcel::\$value to at least the COD amount.";

            return;
        }

        $warnings[] = '/additional_services/cod/amount: exceeds the declared value of the parcels; the documentation says it must not, '
            . 'and DHL, FedEx and Poczta Polska refuse it (DPD did not when checked).';
    }

    // -- Duty --------------------------------------------------------------------

    /**
     * @param list<string> $dutyCountries
     * @param list<string> $errors
     */
    private function checkDuty(ShipmentRequest $request, array $dutyCountries, array &$errors): void
    {
        $country = strtoupper(trim($request->receiver->countryCode ?? '')) ?: 'PL';
        $required = array_merge(self::DUTY_COUNTRIES, array_map(static fn (string $code): string => strtoupper($code), $dutyCountries));

        // Verified for Great Britain, with UPS and GLS: "/duty isEmpty".
        if ($request->duty === null && in_array($country, $required, true)) {
            $errors[] = "/duty: is required for shipments to {$country}.";
        }

        // Verified: "/duty/eori isEmpty" for a sale, and not for a gift.
        if ($request->duty?->type === DutyType::Sale && trim($request->duty->eori ?? '') === '') {
            $errors[] = '/duty/eori: is required when the purpose is a sale.';
        }
    }

    // -- Reference number --------------------------------------------------------

    /**
     * The documented limits (DPD 27, FedEx 30, UPS 35, InPost 28, GLS 25) are not enforced by the
     * API on validation, so they are warnings. DPD's real limit, 36, is refused.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkReference(ShipmentRequest $request, ?Carrier $carrier, array &$errors, array &$warnings): void
    {
        $length = mb_strlen($request->userReferenceNumber ?? '');
        $name = $carrier->value ?? '';
        $limit = $carrier !== null ? (self::REFERENCE_LIMITS[$name] ?? null) : null;

        if ($carrier === Carrier::Dpd && $length > self::DPD_REFERENCE_MAX) {
            $errors[] = '/user_reference_number: is too long, DPD accepts at most ' . self::DPD_REFERENCE_MAX . ' characters.';

            return;
        }

        if ($limit !== null && $length > $limit) {
            $warnings[] = "/user_reference_number: the documentation limits it to {$limit} characters for {$name}, "
                . 'the API accepted more when checked; the carrier may cut it on the label.';
        }
    }

    // -- Label sender ------------------------------------------------------------

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkLabelSender(ShipmentRequest $request, ?Carrier $carrier, array &$errors, array &$warnings): void
    {
        $sender = $request->labelSender;

        if ($sender === null) {
            return;
        }

        $this->checkParty('sender', $sender->name, $sender->company, $carrier, $errors, $warnings);

        foreach (['street' => $sender->street, 'postcode' => $sender->postcode, 'city' => $sender->city] as $field => $value) {
            if (trim($value) === '') {
                $errors[] = "/sender/{$field}: is required.";
            }
        }

        // The two lists in the specification disagree, and the account settings add Poczta
        // Ekspres24, so an unlisted carrier is a warning; the API refused it for GLS.
        $documented = $carrier === null
            || in_array($carrier, self::LABEL_SENDER_CARRIERS, true)
            || ($carrier === Carrier::Poczta && ($request->additionalServices->pocztaEkspres24 ?? false) === true);

        if (!$documented) {
            $warnings[] = "/sender: the documentation does not list {$carrier->value} for other data on the label, and the API refuses it for some carriers (GLS).";
        }

        if ($this->senderDiffersFromPickup($sender, $request->pickup)) {
            $warnings[] = '/sender: differs from the pickup address, so returns go to the address on the label, not to the pickup address.';
        }
    }

    private function senderDiffersFromPickup(LabelSender $sender, Pickup $pickup): bool
    {
        $normalise = static fn (?string $value): string => mb_strtolower(trim($value ?? ''));

        foreach ([
            [$sender->name, $pickup->name],
            [$sender->company, $pickup->company],
            [$sender->street, $pickup->street],
            [$sender->postcode, $pickup->postcode],
            [$sender->city, $pickup->city],
            [$sender->countryCode ?? 'PL', $pickup->countryCode ?? 'PL'],
        ] as [$label, $real]) {
            if ($normalise($label) !== $normalise($real)) {
                return true;
            }
        }

        return false;
    }
}
