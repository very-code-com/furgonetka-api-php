<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Enum;

/**
 * Kind of document to request in a documents command (`documents_types`).
 *
 * Read with `tryFrom()`: Furgonetka adds values without notice, and a value this release does not
 * know reads as null while the response DTO keeps the raw string next to it.
 */
enum DocumentType: string
{
    /** Labels. */
    case Labels = 'labels';

    /** Protocols and other documents. */
    case ProtocolsOthers = 'protocols_others';

    /** Labels together with pickup confirmations. */
    case LabelsAndPickupConfirmations = 'labels_and_pickup_confirmations';

    /** Pickup confirmations. */
    case PickupConfirmations = 'pickup_confirmations';

    /** Tire packing instructions. */
    case TiresInstructions = 'tires_instructions';

    /** Everything. */
    case All = 'all';
}
