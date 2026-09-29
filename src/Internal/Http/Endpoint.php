<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Internal\Http;

/**
 * The registry of every Furgonetka operation the client calls: one case per operation, and the
 * one place that knows its method, path, media type version and authentication.
 *
 * Why a registry: Furgonetka picks the version of an operation from the `Accept` and
 * `Content-Type` media type (`application/vnd.furgonetka.v2+json`), and an unknown version is
 * silently ignored (verified on the sandbox: `v9` answers 200), so a wrong version gives no
 * signal, only a different shape of data. Keeping every version here, next to a test that each
 * operation sends it and a weekly job (`tools/spec-drift.php`) that compares this file with the
 * published specification, is what makes "always the newest version of each endpoint" checkable.
 * Bumping an operation is one line here, one fixture and one test.
 *
 * The backing value is the operation as the specification writes it, `METHOD /path/{param}`,
 * so the drift tool can look every case up in the specification without a translation table.
 *
 * Only the newest version of an operation is registered and deprecated operations are not
 * registered at all. The OAuth endpoints are not here: they take no media type version and live
 * in {@see \VeryCodeCom\FurgonetkaApi\Auth\OAuthClient}.
 *
 * Every `match` below lists all cases on purpose, with no `default`: a new case that is missing
 * from one of them is an error in static analysis instead of a silent wrong default.
 *
 * @internal
 */
enum Endpoint: string
{
    // -- Account and configuration -----------------------------------------------
    case ListServices = 'GET /account/services';
    case ListCompanies = 'GET /account/companies';
    case FetchAllowedCountries = 'GET /configuration/allowed-countries';
    case FetchDictionary = 'GET /configuration/dictionary';

    // -- Regulations -------------------------------------------------------------
    case ListRegulations = 'GET /regulations';
    case AcceptRegulations = 'POST /regulations';
    case RegulationsForPackages = 'GET /packages/regulations';

    // -- Shipments ---------------------------------------------------------------
    case ValidatePackage = 'POST /packages/validate';
    case CalculatePrices = 'POST /packages/calculate-price';
    case CreatePackage = 'POST /packages';
    case UpdatePackage = 'PUT /packages/{package_id}';
    case FetchPackage = 'GET /packages/{id}';
    case ListPackages = 'GET /packages';
    case DeletePackage = 'DELETE /packages/{package_id}';
    case DeletePackages = 'DELETE /packages';

    // -- Ordering ----------------------------------------------------------------
    case SubmitOrder = 'PUT /order-commands/{uuid}';
    case FetchOrderCommand = 'GET /order-commands/{uuid}';
    case AbortOrderCommand = 'DELETE /order-commands/{uuid}';
    case SendDocumentsEmail = 'POST /packages/send-email';

    // -- Labels and documents ----------------------------------------------------
    case FetchLabel = 'GET /packages/{package_id}/label';
    case SubmitDocuments = 'PUT /documents-command/{uuid}';
    case FetchDocumentsCommand = 'GET /documents-command/{uuid}';
    case AbortDocumentsCommand = 'DELETE /documents-command/{uuid}';
    case FetchProtocol = 'POST /packages/protocol';

    // -- Courier pickup ----------------------------------------------------------
    case FetchPickupProposals = 'POST /packages/pickup-date-proposals';
    case SubmitPickup = 'PUT /pickup-commands/{uuid}';
    case FetchPickupCommand = 'GET /pickup-commands/{uuid}';
    case AbortPickupCommand = 'DELETE /pickup-commands/{uuid}';

    // -- Cancellation ------------------------------------------------------------
    case SubmitCancel = 'PUT /cancel-command/{uuid}';
    case FetchCancelCommand = 'GET /cancel-command/{uuid}';
    case AbortCancelCommand = 'DELETE /cancel-command/{uuid}';
    case MarkAsCancelled = 'POST /packages/mark-as-cancelled';
    case AbortGenericCommand = 'DELETE /generic-command/{uuid}';

    // -- Tracking ----------------------------------------------------------------
    case FetchTracking = 'GET /packages/{package_id}/tracking';
    case AddToTracking = 'POST /packages/add-to-tracking';
    case RenameTrackedPackage = 'PUT /packages/edit-tracked-package/{package_id}';
    case SimulateTracking = 'PUT /packages/tracking/simulate';

    // -- Pickup points and webhook -----------------------------------------------
    case FindPoints = 'POST /points/map';
    case FetchWebhookSettings = 'GET /account/settings/notification/webhook';
    case ListWebhookLogs = 'GET /account/settings/notification/webhook/logs';

    // -- Address book ------------------------------------------------------------------
    case ListAddressBook = 'GET /account/address-book';
    case CreateAddressBookEntry = 'POST /account/address-book';
    case DeleteAddressBookEntries = 'DELETE /account/address-book';
    case FetchAddressBookEntry = 'GET /account/address-book/{id}';
    case ReplaceAddressBookEntry = 'PUT /account/address-book/{id}';
    case ValidateAddressBookEntry = 'POST /account/address-book/validate';

    // -- Helper commands -------------------------------------------------------------------
    case SubmitCsvExport = 'PUT /csv-command/{uuid}';
    case FetchCsvExport = 'GET /csv-command/{uuid}';
    case AbortCsvExport = 'DELETE /csv-command/{uuid}';
    case SubmitRevalidate = 'PUT /revalidate-command/{uuid}';
    case FetchRevalidate = 'GET /revalidate-command/{uuid}';
    case AbortRevalidate = 'DELETE /revalidate-command/{uuid}';
    case SubmitAddToTracking = 'PUT /add-package-to-tracking-command/{uuid}';
    case FetchAddToTracking = 'GET /add-package-to-tracking-command/{uuid}';
    case AbortAddToTracking = 'DELETE /add-package-to-tracking-command/{uuid}';
    case SubmitHsCodes = 'PUT /packages/duty/hs-codes-autocomplete-command/{uuid}';
    case FetchHsCodes = 'GET /packages/duty/hs-codes-autocomplete-command/{uuid}';

    // -- Notifications ----------------------------------------------------------------------
    case SaveNotificationSettings = 'POST /account/settings/notification';
    case FetchGeneralNotifications = 'GET /account/settings/notification/general';
    case FetchShippingNotifications = 'GET /account/settings/notification/shipping';
    case SubmitWebhookCheck = 'PUT /account/settings/notification/webhook/check-command/{uuid}';
    case FetchWebhookCheck = 'GET /account/settings/notification/webhook/check-command/{uuid}';
    case SubmitWebhookResend = 'PUT /account/settings/notification/webhook/resend-command/{uuid}';
    case FetchWebhookResend = 'GET /account/settings/notification/webhook/resend-command/{uuid}';

    // -- Billing -------------------------------------------------------------------------------
    case FetchBalance = 'GET /account/balance';
    case ListServiceAgreements = 'GET /account/services-agreements';
    case ListPlannedPricelistChanges = 'GET /account/planned-pricelist-changes';

    // -- Returns, readdressing and sending to yourself ---------------------------
    case CalculateReturnPrice = 'POST /packages/return/calculate-price';
    case ReturnPackage = 'PUT /packages/return/{uuid}';
    case CalculateReaddressingPrice = 'POST /packages/readdressing/calculate-price';
    case ReaddressPackage = 'PUT /packages/readdressing/{uuid}';
    case FetchReaddressing = 'GET /packages/readdressing/{uuid}';
    case CancelReaddressing = 'DELETE /packages/readdressing/{uuid}';
    case CreateSendToMeLink = 'POST /packages/send-to-me-link';

    // -- Creating a shipment as a command ------------------------------------------
    case SubmitCreatePackage = 'PUT /create-package-command/{uuid}';
    case FetchCreatePackageCommand = 'GET /create-package-command/{uuid}';
    case AbortCreatePackageCommand = 'DELETE /create-package-command/{uuid}';

    // -- Files -------------------------------------------------------------------------
    case UploadFile = 'POST /file-command/{uuid}';
    case FetchFileCommands = 'GET /file-command';

    /** @return non-empty-string HTTP method, upper case. */
    public function method(): string
    {
        return $this->parts()[0];
    }

    /** @return non-empty-string Path as the specification writes it, with `{name}` placeholders. */
    public function pathTemplate(): string
    {
        return $this->parts()[1];
    }

    /**
     * Names of the `{placeholders}` in the path, in order.
     *
     * @return list<string>
     */
    public function pathParameters(): array
    {
        preg_match_all('/\{([A-Za-z_]+)}/', $this->pathTemplate(), $matches);

        return $matches[1];
    }

    /**
     * The version in `application/vnd.furgonetka.vN+json`, or null for the one operation whose
     * JSON answer is plain `application/json` (the company list).
     *
     * For an operation that answers with a file this is still the version of its JSON side: the
     * request body, when it has one, and the error answers.
     */
    public function version(): ?int
    {
        return match ($this) {
            self::ValidatePackage, self::CalculatePrices, self::CreatePackage, self::UpdatePackage,
            self::FetchPackage, self::ListPackages, self::FetchTracking, self::SubmitCreatePackage
                => 2,
            self::ListServices, self::FetchAllowedCountries, self::FetchDictionary,
            self::ListRegulations, self::AcceptRegulations, self::RegulationsForPackages,
            self::DeletePackage, self::DeletePackages, self::SubmitOrder, self::FetchOrderCommand,
            self::AbortOrderCommand, self::SendDocumentsEmail, self::FetchLabel, self::SubmitDocuments,
            self::FetchDocumentsCommand, self::AbortDocumentsCommand, self::FetchProtocol,
            self::FetchPickupProposals, self::SubmitPickup, self::FetchPickupCommand,
            self::AbortPickupCommand, self::SubmitCancel, self::FetchCancelCommand,
            self::AbortCancelCommand, self::MarkAsCancelled, self::AbortGenericCommand,
            self::AddToTracking, self::RenameTrackedPackage, self::SimulateTracking, self::FindPoints,
            self::FetchWebhookSettings, self::ListWebhookLogs, self::CalculateReturnPrice,
            self::ReturnPackage, self::CalculateReaddressingPrice, self::ReaddressPackage,
            self::FetchReaddressing, self::CancelReaddressing, self::CreateSendToMeLink,
            self::FetchCreatePackageCommand, self::AbortCreatePackageCommand,
            self::ListAddressBook, self::CreateAddressBookEntry, self::DeleteAddressBookEntries,
            self::FetchAddressBookEntry, self::ReplaceAddressBookEntry, self::ValidateAddressBookEntry,
            self::SubmitCsvExport, self::FetchCsvExport, self::AbortCsvExport, self::SubmitRevalidate,
            self::FetchRevalidate, self::AbortRevalidate, self::SubmitAddToTracking,
            self::FetchAddToTracking, self::AbortAddToTracking, self::SubmitHsCodes, self::FetchHsCodes,
            self::SaveNotificationSettings, self::FetchGeneralNotifications,
            self::FetchShippingNotifications, self::SubmitWebhookCheck, self::FetchWebhookCheck,
            self::SubmitWebhookResend, self::FetchWebhookResend, self::FetchBalance,
            self::ListServiceAgreements, self::ListPlannedPricelistChanges
                => 1,
            self::ListCompanies, self::UploadFile, self::FetchFileCommands
                => null,
        };
    }

    /** What the operation expects in the `Authorization` header. */
    public function auth(): EndpointAuth
    {
        return match ($this) {
            self::FetchAllowedCountries, self::FetchDictionary, self::FindPoints, self::CreateSendToMeLink
                => EndpointAuth::None,
            self::ListServices, self::ListCompanies, self::ListRegulations, self::AcceptRegulations,
            self::RegulationsForPackages, self::ValidatePackage, self::CalculatePrices,
            self::CreatePackage, self::UpdatePackage, self::FetchPackage, self::ListPackages,
            self::DeletePackage, self::DeletePackages, self::SubmitOrder, self::FetchOrderCommand,
            self::AbortOrderCommand, self::SendDocumentsEmail, self::FetchLabel, self::SubmitDocuments,
            self::FetchDocumentsCommand, self::AbortDocumentsCommand, self::FetchProtocol,
            self::FetchPickupProposals, self::SubmitPickup, self::FetchPickupCommand,
            self::AbortPickupCommand, self::SubmitCancel, self::FetchCancelCommand,
            self::AbortCancelCommand, self::MarkAsCancelled, self::AbortGenericCommand,
            self::FetchTracking, self::AddToTracking, self::RenameTrackedPackage,
            self::SimulateTracking, self::FetchWebhookSettings, self::ListWebhookLogs,
            self::CalculateReturnPrice, self::ReturnPackage, self::CalculateReaddressingPrice,
            self::ReaddressPackage, self::FetchReaddressing, self::CancelReaddressing,
            self::SubmitCreatePackage, self::FetchCreatePackageCommand, self::AbortCreatePackageCommand,
            self::UploadFile, self::FetchFileCommands, self::SubmitHsCodes, self::FetchHsCodes,
            self::ListAddressBook, self::CreateAddressBookEntry, self::DeleteAddressBookEntries,
            self::FetchAddressBookEntry, self::ReplaceAddressBookEntry, self::ValidateAddressBookEntry,
            self::SubmitCsvExport, self::FetchCsvExport, self::AbortCsvExport, self::SubmitRevalidate,
            self::FetchRevalidate, self::AbortRevalidate, self::SubmitAddToTracking,
            self::FetchAddToTracking, self::AbortAddToTracking, self::SaveNotificationSettings,
            self::FetchGeneralNotifications, self::FetchShippingNotifications, self::SubmitWebhookCheck,
            self::FetchWebhookCheck, self::SubmitWebhookResend, self::FetchWebhookResend,
            self::FetchBalance, self::ListServiceAgreements, self::ListPlannedPricelistChanges
                => EndpointAuth::User,
        };
    }

    /** True when the operation takes a JSON request body. */
    public function hasBody(): bool
    {
        return match ($this) {
            self::AcceptRegulations, self::ValidatePackage, self::CalculatePrices, self::CreatePackage,
            self::UpdatePackage, self::DeletePackages, self::SubmitOrder, self::SendDocumentsEmail,
            self::SubmitDocuments, self::FetchProtocol, self::FetchPickupProposals, self::SubmitPickup,
            self::SubmitCancel, self::MarkAsCancelled, self::AddToTracking, self::RenameTrackedPackage,
            self::SimulateTracking, self::FindPoints, self::CalculateReturnPrice, self::ReturnPackage,
            self::CalculateReaddressingPrice, self::ReaddressPackage, self::CreateSendToMeLink,
            self::SubmitCreatePackage,
            self::CreateAddressBookEntry, self::DeleteAddressBookEntries, self::ReplaceAddressBookEntry,
            self::ValidateAddressBookEntry, self::SubmitCsvExport, self::SubmitRevalidate,
            self::SubmitAddToTracking, self::SubmitHsCodes, self::SaveNotificationSettings,
            self::SubmitWebhookCheck, self::SubmitWebhookResend
                => true,
            self::ListServices, self::ListCompanies, self::FetchAllowedCountries, self::FetchDictionary,
            self::ListRegulations, self::RegulationsForPackages, self::FetchPackage, self::ListPackages,
            self::DeletePackage, self::FetchOrderCommand, self::AbortOrderCommand, self::FetchLabel,
            self::FetchDocumentsCommand, self::AbortDocumentsCommand, self::FetchPickupCommand,
            self::AbortPickupCommand, self::FetchCancelCommand, self::AbortCancelCommand,
            self::AbortGenericCommand, self::FetchTracking, self::FetchWebhookSettings,
            self::ListWebhookLogs, self::FetchReaddressing, self::CancelReaddressing,
            self::FetchCreatePackageCommand, self::AbortCreatePackageCommand, self::UploadFile,
            self::FetchFileCommands,
            self::ListAddressBook, self::FetchAddressBookEntry, self::FetchCsvExport, self::AbortCsvExport,
            self::FetchRevalidate, self::AbortRevalidate, self::FetchAddToTracking,
            self::AbortAddToTracking, self::FetchHsCodes, self::FetchGeneralNotifications,
            self::FetchShippingNotifications, self::FetchWebhookCheck, self::FetchWebhookResend,
            self::FetchBalance, self::ListServiceAgreements, self::ListPlannedPricelistChanges
                => false,
        };
    }

    /**
     * True for the one operation whose request body is a file upload (`multipart/form-data`)
     * instead of JSON. It has no {@see self::hasBody()}: the body is built by
     * {@see RequestBuilder::buildUpload()}, with the boundary in the `Content-Type`.
     */
    public function isUpload(): bool
    {
        return $this === self::UploadFile;
    }

    /**
     * True for the operations that the specification lists without a security scheme but that
     * only work with the user's token. A file uploaded without it belongs to nobody, and the
     * account that asks for its status is told the UUID does not exist. The HS code search
     * answered without a token on 2026-09-29 in the morning and, from the early afternoon of the
     * same day, with 500 "Unhandled error" on the sandbox (with a token it answers as before), so
     * it is sent with one too. The registry says `User`; the drift tool knows this is on purpose.
     */
    public function sendsTokenBeyondSpec(): bool
    {
        return match ($this) {
            self::UploadFile, self::FetchFileCommands, self::SubmitHsCodes, self::FetchHsCodes => true,
            default => false,
        };
    }

    /** True when a successful answer is a file (PDF, or ZPL/EPL text) rather than JSON. */
    public function returnsFile(): bool
    {
        return match ($this) {
            self::FetchLabel, self::FetchProtocol
                => true,
            self::ListServices, self::ListCompanies, self::FetchAllowedCountries, self::FetchDictionary,
            self::ListRegulations, self::AcceptRegulations, self::RegulationsForPackages,
            self::ValidatePackage, self::CalculatePrices, self::CreatePackage, self::UpdatePackage,
            self::FetchPackage, self::ListPackages, self::DeletePackage, self::DeletePackages,
            self::SubmitOrder, self::FetchOrderCommand, self::AbortOrderCommand,
            self::SendDocumentsEmail, self::SubmitDocuments, self::FetchDocumentsCommand,
            self::AbortDocumentsCommand, self::FetchPickupProposals, self::SubmitPickup,
            self::FetchPickupCommand, self::AbortPickupCommand, self::SubmitCancel,
            self::FetchCancelCommand, self::AbortCancelCommand, self::MarkAsCancelled,
            self::AbortGenericCommand, self::FetchTracking, self::AddToTracking,
            self::RenameTrackedPackage, self::SimulateTracking, self::FindPoints,
            self::FetchWebhookSettings, self::ListWebhookLogs, self::CalculateReturnPrice,
            self::ReturnPackage, self::CalculateReaddressingPrice, self::ReaddressPackage,
            self::FetchReaddressing, self::CancelReaddressing, self::CreateSendToMeLink,
            self::SubmitCreatePackage, self::FetchCreatePackageCommand, self::AbortCreatePackageCommand,
            self::UploadFile, self::FetchFileCommands,
            self::ListAddressBook, self::CreateAddressBookEntry, self::DeleteAddressBookEntries,
            self::FetchAddressBookEntry, self::ReplaceAddressBookEntry, self::ValidateAddressBookEntry,
            self::SubmitCsvExport, self::FetchCsvExport, self::AbortCsvExport, self::SubmitRevalidate,
            self::FetchRevalidate, self::AbortRevalidate, self::SubmitAddToTracking,
            self::FetchAddToTracking, self::AbortAddToTracking, self::SubmitHsCodes, self::FetchHsCodes,
            self::SaveNotificationSettings, self::FetchGeneralNotifications,
            self::FetchShippingNotifications, self::SubmitWebhookCheck, self::FetchWebhookCheck,
            self::SubmitWebhookResend, self::FetchWebhookResend, self::FetchBalance,
            self::ListServiceAgreements, self::ListPlannedPricelistChanges
                => false,
        };
    }

    /** True for the one operation that exists only on the sandbox. */
    public function isSandboxOnly(): bool
    {
        return $this === self::SimulateTracking;
    }

    /** The `application/vnd.furgonetka.vN+json` media type of a version. */
    public static function mediaType(int $version): string
    {
        return 'application/vnd.furgonetka.v' . $version . '+json';
    }

    /**
     * The `Accept` header value: every media type the specification declares for the operation's
     * answers, so that a file, an empty answer and an error in JSON are all acceptable.
     */
    public function accept(): string
    {
        $version = $this->version();
        $json = $version === null ? 'application/json' : self::mediaType($version);

        // A label is a PDF, or ZPL/EPL as text, depending on the format it was ordered in.
        return match (true) {
            $this === self::FetchLabel => 'application/pdf, text/plain, ' . $json,
            $this->returnsFile() => 'application/pdf, ' . $json,
            default => $json,
        };
    }

    /** The `Content-Type` of the request body, or null when the operation takes none. */
    public function contentType(): ?string
    {
        $version = $this->version();

        if (!$this->hasBody()) {
            return null;
        }

        return $version === null ? 'application/json' : self::mediaType($version);
    }

    /**
     * The backing value split into method and path. The two checks cannot fail for the cases
     * above; they exist so that a malformed case is a loud error instead of a request to `/`.
     *
     * @return array{non-empty-string, non-empty-string}
     */
    private function parts(): array
    {
        [$method, $path] = explode(' ', $this->value, 2) + [1 => ''];

        if ($method === '' || $path === '') {
            throw new \LogicException("Endpoint \"{$this->value}\" is not written as \"METHOD /path\".");
        }

        return [$method, $path];
    }
}
