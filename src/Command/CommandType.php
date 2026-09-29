<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Command;

/**
 * Which kind of command to abort with `abortCommand()`.
 *
 * Furgonetka has an abort endpoint for each kind and a generic one, "Usuniecie zlecenia wykonania
 * zadania". Whether the generic one reaches every kind is not documented (open check W11), so when
 * you know the kind, name it.
 */
enum CommandType
{
    /** `DELETE /generic-command/{uuid}`. */
    case Generic;

    /** `DELETE /order-commands/{uuid}`. */
    case Order;

    /** `DELETE /pickup-commands/{uuid}`. */
    case Pickup;

    /** `DELETE /cancel-command/{uuid}`. */
    case Cancel;

    /** `DELETE /documents-command/{uuid}`. */
    case Documents;

    /** `DELETE /create-package-command/{uuid}`. */
    case CreatePackage;

    /** `DELETE /csv-command/{uuid}`. */
    case CsvExport;

    /** `DELETE /revalidate-command/{uuid}`. */
    case Revalidate;

    /** `DELETE /add-package-to-tracking-command/{uuid}`. */
    case AddToTracking;
}
