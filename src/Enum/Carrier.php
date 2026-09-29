<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * A carrier Furgonetka brokers, by the identifier it uses (`service` of a service, tracking event,
 * price quote or webhook).
 *
 * The members are the keys of `GET /configuration/dictionary` on 2026-09-28, which is the most
 * complete list Furgonetka publishes. The OpenAPI specification is behind it: its `Service` enum
 * has ten values and the dictionary thirty. Furgonetka adds carriers without notice, and the account
 * service list already holds one that is in neither list (`furgonetka_gielda`), so this enum is
 * deliberately not the last word.
 *
 * Two things follow. Read a carrier with `tryFrom()`: an identifier that is not a member reads as
 * null and the response DTO keeps the raw string next to it. And what you send may be a plain
 * string as well, see {@see \VeryCodeCom\FurgonetkaApi\Dto\PriceScope::carriers()}.
 *
 * A carrier is not a contract. `service_id`, from `GET /account/services`, names one contract with
 * one carrier, and the same carrier can appear twice, once on Furgonetka's contract and once on the
 * customer's own ({@see AgreementOwner}).
 */
enum Carrier: string
{
    /** DHL. */
    case Dhl = 'dhl';

    /** DHL Express. */
    case DhlInternational = 'dhlinternational';

    /** DPD. */
    case Dpd = 'dpd';

    /** FedEx. */
    case Fedex = 'fedex';

    /** GLS. */
    case Gls = 'gls';

    /** InPost parcel lockers. */
    case InPost = 'inpost';

    /** InPost courier. */
    case InPostKurier = 'inpostkurier';

    /** Poczta Polska. */
    case Poczta = 'poczta';

    /** RUCH. */
    case Ruch = 'ruch';

    /** ORLEN Paczka. */
    case Orlen = 'orlen';

    /** UPS. */
    case Ups = 'ups';

    /** UPS Access Point. */
    case UpsAccessPoint = 'upsaccesspoint';

    /** X-press. */
    case Xpress = 'xpress';

    /** Meest. */
    case Meest = 'meest';

    /** Ambro Express. */
    case AmbroExpress = 'ambroexpress';

    /** Allegro Punkt. */
    case Allegro = 'allegro';

    /** Furgonetka. */
    case Furgonetka = 'furgonetka';

    /** Xpress Delivery. */
    case XpressDelivery = 'xpressdelivery';

    /** DeliGoo. */
    case Deligoo = 'deligoo';

    /** Pall-Ex. */
    case Pallex = 'pallex';

    /** SPX. */
    case SwiatPrzesylek = 'swiatprzesylek';

    /** Allegro (WZA). */
    case Wza = 'wza';

    /** DHL eCommerce. */
    case DhlParcel = 'dhlparcel';

    /** Postivo, letter print. */
    case Postivo = 'postivo';

    /** Nova Post. */
    case NovaPost = 'novapost';

    /** FOXPOST. */
    case Foxpost = 'foxpost';

    /** Correos. */
    case Correos = 'correos';

    /** Paczka na telefon. */
    case Pnt = 'pnt';

    /** Zabka. */
    case Izidrop = 'izidrop';

    /** La Poste. */
    case LaPoste = 'laposte';
}
