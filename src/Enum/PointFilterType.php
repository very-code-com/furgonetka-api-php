<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * A kind of point to ask for or to leave out (`filters.point_types` of the points search).
 *
 * Furgonetka lists these values without a description, so the docblocks give what the name says
 * and what the sandbox showed. `no_*` values leave the named kind out, the others narrow the
 * search to it. Checked on the sandbox (a phrase in Warsaw, InPost and DPD): `cod_only` and `food`
 * narrow the result, `digital_label` did not, and `send_points` and `delivery_points` could not be
 * told apart because every point found was both. A value that is not here can still be sent as a
 * string.
 */
enum PointFilterType: string
{
    /** Points that take cash on delivery; every point the sandbox returned with it did. */
    case CodOnly = 'cod_only';

    /** Without ORLEN Paczka points. */
    case NoOrlen = 'no_orlen';

    /** All points. */
    case AllPoints = 'all_points';

    /** Points where a shipment can be handed in. */
    case SendPoints = 'send_points';

    /** Points where a shipment can be collected. */
    case DeliveryPoints = 'delivery_points';

    /** Without DPD Pickup stations. */
    case NoDpdPickupStation = 'no_dpd_pickup_station';

    /** Without DHL parcel stations. */
    case NoDhlParcelstation = 'no_dhl_parcelstation';

    /** Without DHL Cainiao points. */
    case NoDhlCainiao = 'no_dhl_cainiao';

    /** DHL POP points. */
    case DhlPop = 'dhl_pop';

    /** Points that accept a shipment without a printed label; the sandbox still returned points that do not. */
    case DigitalLabel = 'digital_label';

    /** Points of the Food service; every point the sandbox returned with it was one. */
    case Food = 'food';

    /** Without Happy Pack points. */
    case NoHappyPack = 'no_happy_pack';

    /** Without PUDO partner points. */
    case NoPudoPartners = 'no_pudo_partners';
}
