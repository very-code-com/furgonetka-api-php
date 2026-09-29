<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;

/**
 * The additional services of a shipment (`additional_services`, version 2).
 *
 * Every flag is nullable on purpose: null leaves the field out and lets Furgonetka's own default
 * apply, while `false` is sent and says "explicitly off". Which service exists for which carrier,
 * and on which kind of contract, is not something the API answers with an error you can rely on,
 * so each flag says where it is available in the words of the specification.
 *
 * Not modelled, on purpose: `declaredvalue` (deprecated, the insurance goes through
 * `Parcel::$value`), and `guarantee_0800`, `delivery_after_1700`, `check_content` and
 * `delivery_confirmation`, which Poczta Polska withdrew on 2026-08-24. Tire insurance goes with
 * the tires structure, which is out of scope.
 *
 * The specification is behind the API here: the sandbox dictionary lists 102 services and the
 * specification about fifty. A service that is not a parameter below goes through `$extra`.
 * That is entirely your responsibility: nothing in `$extra` is checked locally, and a name that
 * is also a typed field is refused, so there is one way to set each service.
 *
 * @see https://furgonetka.pl/api/rest
 */
final class AdditionalServices
{
    /** JSON keys of the typed fields, to keep `$extra` from shadowing them. */
    public const KEYS = [
        'cod',
        'rod',
        'cud',
        'private_shipping',
        'guarantee_0900',
        'guarantee_0930',
        'guarantee_1200',
        'guarantee_next_day',
        'delivery_on_day',
        'delivery_on_time',
        'saturday_delivery',
        'saturday_sunday_delivery',
        'additional_handling',
        'sms_predelivery_information',
        'receiver_email_notification',
        'documents_supply',
        'digital_label',
        'mini_or_standard',
        'ups_ship_notification',
        'ups_exception_notification',
        'ups_delivery_notification',
        'ups_saver',
        'fedex_priority',
        'fedex_first',
        'fedex_regional_economy',
        'fedex_priority_express',
        'fedex_priority_express_freight',
        'fedex_priority_freight',
        'valuable_shipment',
        'fragile',
        'personal_delivery',
        'pocztex',
        'poczta_kurier48',
        'poczta_ekspres24',
        'registered_letter',
        'registered_letter_priority',
        'registered_company_letter',
        'registered_letter_international',
        'poczta_globalexpres',
        'deligoo_express',
        'ambro_size20',
        'premium',
        'letterprint_color_print',
        'food',
        'xpress_service',
    ];

    /**
     * Services that exclude each other: Furgonetka lets at most one of them be selected.
     * They all belong to Poczta Polska.
     */
    public const POCZTA_EXCLUSIVE = [
        'pocztex',
        'poczta_kurier48',
        'poczta_ekspres24',
        'registered_letter',
        'registered_letter_priority',
        'registered_company_letter',
        'registered_letter_international',
        'poczta_globalexpres',
    ];

    /**
     * @param array<string, mixed> $extra Services this release does not model, by their API name.
     *                                    Sent as they are, after the typed fields; a name that is a
     *                                    typed field is refused. Nulls are left out.
     *
     * @param Cod|null     $cod Cash on delivery. Needs the amount; see {@see Cod}.
     * @param bool|null    $rod Returned documents. DPD, UPS, FedEx, InPost Kurier, Poczta Polska (own contracts). Excludes `cud`.
     * @param bool|null    $cud Return shipment: the courier takes another parcel addressed to the sender when delivering. DPD. Excludes `rod`.
     * @param bool|null    $privateShipping Delivery to a private person. DPD, UPS.
     * @param bool|null    $guarantee0900 Delivery by 9:00. Poczta Polska.
     * @param bool|null    $guarantee0930 Delivery by 9:30 (10:30). DPD.
     * @param bool|null    $guarantee1200 Delivery by 12:00. DPD, Poczta Polska (own contracts).
     * @param bool|null    $guaranteeNextDay Delivery on the next working day. DPD.
     * @param string|null  $deliveryOnDay Delivery on a given day, `YYYY-MM-DD`. Poczta Polska, together with Pocztex.
     * @param string|null  $deliveryOnTime Delivery at a given hour, 13:00 to 20:00. Poczta Polska (own contracts), together with Ekspres24 or Pocztex.
     * @param bool|null    $saturdayDelivery Saturday delivery. DPD, InPost Kurier. The shipment must be handed over on a Friday, and that date is not part of the shipment, so the client can only warn.
     * @param bool|null    $saturdaySundayDelivery Weekend parcel: delivery to a parcel locker on Saturday or Sunday. InPost.
     * @param bool|null    $additionalHandling Non-standard item.
     * @param bool|null    $smsPredeliveryInformation SMS notice before delivery. InPost Kurier.
     * @param bool|null    $receiverEmailNotification E-mail to the receiver when the shipment is created.
     * @param bool|null    $documentsSupply The courier delivers the transport documents. DPD, DHL.
     * @param bool|null    $digitalLabel Shipping without a printed label. DPD, InPost, DHL.
     * @param bool|null    $miniOrStandard "Mini" or "Standard" size. ORLEN Paczka on an own contract that has no S, M and L variants.
     * @param bool|null    $upsShipNotification QV message about the shipment. UPS (own contracts).
     * @param bool|null    $upsExceptionNotification QV message about exceptional events. UPS (own contracts).
     * @param bool|null    $upsDeliveryNotification QV message about the delivery. UPS (own contracts).
     * @param bool|null    $upsSaver UPS Express Saver.
     * @param bool|null    $fedexPriority FedEx Priority, up to twice as fast as Economy. International FedEx shipments.
     * @param bool|null    $fedexFirst FedEx First.
     * @param bool|null    $fedexRegionalEconomy FedEx Regional Economy.
     * @param bool|null    $fedexPriorityExpress FedEx Priority Express.
     * @param bool|null    $fedexPriorityExpressFreight FedEx Priority Express Freight.
     * @param bool|null    $fedexPriorityFreight FedEx Priority Freight.
     * @param bool|null    $valuableShipment Valuable shipment. Poczta Polska.
     * @param bool|null    $fragile "Handle with care". Poczta Polska.
     * @param bool|null    $personalDelivery Delivery into the receiver's own hands after an identity check. DPD, Poczta Polska.
     * @param bool|null    $pocztex Pocztex, the default service of a domestic shipment on the broker contract. One of the eight mutually exclusive Poczta services.
     * @param bool|null    $pocztaKurier48 Kurier48, the default on own contracts. One of the eight mutually exclusive Poczta services.
     * @param bool|null    $pocztaEkspres24 Ekspres24, delivery on the second day (zone A). One of the eight mutually exclusive Poczta services.
     * @param bool|null    $registeredLetter Economy registered letter (own contracts). One of the eight mutually exclusive Poczta services.
     * @param bool|null    $registeredLetterPriority Priority registered letter (own contracts). One of the eight mutually exclusive Poczta services.
     * @param bool|null    $registeredCompanyLetter Company registered letter, also called e-commerce registered (own contracts). One of the eight mutually exclusive Poczta services.
     * @param bool|null    $registeredLetterInternational International registered letter (own contracts). One of the eight mutually exclusive Poczta services.
     * @param bool|null    $pocztaGlobalexpres Global Expres (own contracts). One of the eight mutually exclusive Poczta services.
     * @param bool|null    $deligooExpress Deligoo Express.
     * @param bool|null    $ambroSize20 Ambro size 20.
     * @param bool|null    $premium Pall-Ex: delivery of the pallet on the next working day after collection.
     * @param bool|null    $letterprintColorPrint Colour printing. Postivo.
     * @param string|null  $food Food transport in a monitored cold chain, a date `YYYY-MM-DD`. DPD.
     * @param string|null  $xpressService Service identifier in the OpenNet API: 6201, 6202, 6203, 5127, 5128, 5129, 89, 200, 202 or 204. Allegro, X-Press.
     */
    public function __construct(
        public readonly ?Cod $cod = null,
        public readonly ?bool $rod = null,
        public readonly ?bool $cud = null,
        public readonly ?bool $privateShipping = null,
        public readonly ?bool $guarantee0900 = null,
        public readonly ?bool $guarantee0930 = null,
        public readonly ?bool $guarantee1200 = null,
        public readonly ?bool $guaranteeNextDay = null,
        public readonly ?string $deliveryOnDay = null,
        public readonly ?string $deliveryOnTime = null,
        public readonly ?bool $saturdayDelivery = null,
        public readonly ?bool $saturdaySundayDelivery = null,
        public readonly ?bool $additionalHandling = null,
        public readonly ?bool $smsPredeliveryInformation = null,
        public readonly ?bool $receiverEmailNotification = null,
        public readonly ?bool $documentsSupply = null,
        public readonly ?bool $digitalLabel = null,
        public readonly ?bool $miniOrStandard = null,
        public readonly ?bool $upsShipNotification = null,
        public readonly ?bool $upsExceptionNotification = null,
        public readonly ?bool $upsDeliveryNotification = null,
        public readonly ?bool $upsSaver = null,
        public readonly ?bool $fedexPriority = null,
        public readonly ?bool $fedexFirst = null,
        public readonly ?bool $fedexRegionalEconomy = null,
        public readonly ?bool $fedexPriorityExpress = null,
        public readonly ?bool $fedexPriorityExpressFreight = null,
        public readonly ?bool $fedexPriorityFreight = null,
        public readonly ?bool $valuableShipment = null,
        public readonly ?bool $fragile = null,
        public readonly ?bool $personalDelivery = null,
        public readonly ?bool $pocztex = null,
        public readonly ?bool $pocztaKurier48 = null,
        public readonly ?bool $pocztaEkspres24 = null,
        public readonly ?bool $registeredLetter = null,
        public readonly ?bool $registeredLetterPriority = null,
        public readonly ?bool $registeredCompanyLetter = null,
        public readonly ?bool $registeredLetterInternational = null,
        public readonly ?bool $pocztaGlobalexpres = null,
        public readonly ?bool $deligooExpress = null,
        public readonly ?bool $ambroSize20 = null,
        public readonly ?bool $premium = null,
        public readonly ?bool $letterprintColorPrint = null,
        public readonly ?string $food = null,
        public readonly ?string $xpressService = null,
        public readonly array $extra = [],
    ) {
        $shadowed = array_values(array_intersect(array_keys($extra), self::KEYS));

        if ($shadowed !== []) {
            throw new \InvalidArgumentException(sprintf(
                'AdditionalServices: "%s" is a typed field, use the parameter instead of $extra.',
                $shadowed[0],
            ));
        }
    }

    /**
     * The request body form: only what was set, then `$extra`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'cod' => $this->cod?->toArray(),
            'rod' => $this->rod,
            'cud' => $this->cud,
            'private_shipping' => $this->privateShipping,
            'guarantee_0900' => $this->guarantee0900,
            'guarantee_0930' => $this->guarantee0930,
            'guarantee_1200' => $this->guarantee1200,
            'guarantee_next_day' => $this->guaranteeNextDay,
            'delivery_on_day' => $this->deliveryOnDay,
            'delivery_on_time' => $this->deliveryOnTime,
            'saturday_delivery' => $this->saturdayDelivery,
            'saturday_sunday_delivery' => $this->saturdaySundayDelivery,
            'additional_handling' => $this->additionalHandling,
            'sms_predelivery_information' => $this->smsPredeliveryInformation,
            'receiver_email_notification' => $this->receiverEmailNotification,
            'documents_supply' => $this->documentsSupply,
            'digital_label' => $this->digitalLabel,
            'mini_or_standard' => $this->miniOrStandard,
            'ups_ship_notification' => $this->upsShipNotification,
            'ups_exception_notification' => $this->upsExceptionNotification,
            'ups_delivery_notification' => $this->upsDeliveryNotification,
            'ups_saver' => $this->upsSaver,
            'fedex_priority' => $this->fedexPriority,
            'fedex_first' => $this->fedexFirst,
            'fedex_regional_economy' => $this->fedexRegionalEconomy,
            'fedex_priority_express' => $this->fedexPriorityExpress,
            'fedex_priority_express_freight' => $this->fedexPriorityExpressFreight,
            'fedex_priority_freight' => $this->fedexPriorityFreight,
            'valuable_shipment' => $this->valuableShipment,
            'fragile' => $this->fragile,
            'personal_delivery' => $this->personalDelivery,
            'pocztex' => $this->pocztex,
            'poczta_kurier48' => $this->pocztaKurier48,
            'poczta_ekspres24' => $this->pocztaEkspres24,
            'registered_letter' => $this->registeredLetter,
            'registered_letter_priority' => $this->registeredLetterPriority,
            'registered_company_letter' => $this->registeredCompanyLetter,
            'registered_letter_international' => $this->registeredLetterInternational,
            'poczta_globalexpres' => $this->pocztaGlobalexpres,
            'deligoo_express' => $this->deligooExpress,
            'ambro_size20' => $this->ambroSize20,
            'premium' => $this->premium,
            'letterprint_color_print' => $this->letterprintColorPrint,
            'food' => $this->food,
            'xpress_service' => $this->xpressService,
        ] + $this->extra);
    }

    /** True when no service is set. */
    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }
}
