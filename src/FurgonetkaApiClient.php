<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeryCodeCom\FurgonetkaApi\Auth\OAuthClient;
use VeryCodeCom\FurgonetkaApi\Auth\ReauthorizerInterface;
use VeryCodeCom\FurgonetkaApi\Auth\StaticTokenProvider;
use VeryCodeCom\FurgonetkaApi\Auth\Store\TokenStoreInterface;
use VeryCodeCom\FurgonetkaApi\Auth\TokenManager;
use VeryCodeCom\FurgonetkaApi\Auth\TokenProviderInterface;
use VeryCodeCom\FurgonetkaApi\Command\CommandType;
use VeryCodeCom\FurgonetkaApi\Command\PollingPolicy;
use VeryCodeCom\FurgonetkaApi\Command\SleeperInterface;
use VeryCodeCom\FurgonetkaApi\Command\UsleepSleeper;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookEntry;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookPage;
use VeryCodeCom\FurgonetkaApi\Dto\AddressBookRequest;
use VeryCodeCom\FurgonetkaApi\Dto\AddToTrackingCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\AllowedCountries;
use VeryCodeCom\FurgonetkaApi\Dto\ApiError;
use VeryCodeCom\FurgonetkaApi\Dto\Balance;
use VeryCodeCom\FurgonetkaApi\Dto\CancelCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\CommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\Company;
use VeryCodeCom\FurgonetkaApi\Dto\CreatePackageCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\CsvExportResult;
use VeryCodeCom\FurgonetkaApi\Dto\Dictionary;
use VeryCodeCom\FurgonetkaApi\Dto\DocumentFile;
use VeryCodeCom\FurgonetkaApi\Dto\DocumentsCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\FileUploadResult;
use VeryCodeCom\FurgonetkaApi\Dto\GeneralNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\HsCodeSearchResult;
use VeryCodeCom\FurgonetkaApi\Dto\Label;
use VeryCodeCom\FurgonetkaApi\Dto\LabelOptions;
use VeryCodeCom\FurgonetkaApi\Dto\NotificationSettingsRequest;
use VeryCodeCom\FurgonetkaApi\Dto\OrderCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\Package;
use VeryCodeCom\FurgonetkaApi\Dto\PackagePage;
use VeryCodeCom\FurgonetkaApi\Dto\PaymentRequest;
use VeryCodeCom\FurgonetkaApi\Dto\PickupCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\PickupDate;
use VeryCodeCom\FurgonetkaApi\Dto\PickupProposalGroup;
use VeryCodeCom\FurgonetkaApi\Dto\PointList;
use VeryCodeCom\FurgonetkaApi\Dto\PriceEstimate;
use VeryCodeCom\FurgonetkaApi\Dto\PricelistChange;
use VeryCodeCom\FurgonetkaApi\Dto\PriceQuoteList;
use VeryCodeCom\FurgonetkaApi\Dto\PriceScope;
use VeryCodeCom\FurgonetkaApi\Dto\RateLimitStatus;
use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingReceiver;
use VeryCodeCom\FurgonetkaApi\Dto\RevalidateResult;
use VeryCodeCom\FurgonetkaApi\Dto\Regulation;
use VeryCodeCom\FurgonetkaApi\Dto\SendToMeLink;
use VeryCodeCom\FurgonetkaApi\Dto\SendToMeRequest;
use VeryCodeCom\FurgonetkaApi\Dto\ServiceAgreement;
use VeryCodeCom\FurgonetkaApi\Dto\ServiceList;
use VeryCodeCom\FurgonetkaApi\Dto\ShipmentRequest;
use VeryCodeCom\FurgonetkaApi\Dto\ShippingNotifications;
use VeryCodeCom\FurgonetkaApi\Dto\TrackedPackage;
use VeryCodeCom\FurgonetkaApi\Dto\TrackingResult;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookCommandResult;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookLog;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookLogPage;
use VeryCodeCom\FurgonetkaApi\Dto\WebhookSettings;
use VeryCodeCom\FurgonetkaApi\Enum\AgreementOwner;
use VeryCodeCom\FurgonetkaApi\Enum\Carrier;
use VeryCodeCom\FurgonetkaApi\Enum\CsvRange;
use VeryCodeCom\FurgonetkaApi\Enum\DocumentType;
use VeryCodeCom\FurgonetkaApi\Enum\HsCodeService;
use VeryCodeCom\FurgonetkaApi\Enum\LabelFileFormat;
use VeryCodeCom\FurgonetkaApi\Enum\LabelPageFormat;
use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiLabelNotReadyException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiTransportException;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiValidationException;
use VeryCodeCom\FurgonetkaApi\Internal\Command\CommandRunner;
use VeryCodeCom\FurgonetkaApi\Internal\Http\Endpoint;
use VeryCodeCom\FurgonetkaApi\Internal\Http\EndpointAuth;
use VeryCodeCom\FurgonetkaApi\Internal\Http\ErrorMapper;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RateLimitReader;
use VeryCodeCom\FurgonetkaApi\Internal\Http\RequestBuilder;
use VeryCodeCom\FurgonetkaApi\Internal\Json\DateFormat;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Payload;
use VeryCodeCom\FurgonetkaApi\Internal\Json\ResponseParser;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;
use VeryCodeCom\FurgonetkaApi\Internal\Uuid;
use VeryCodeCom\FurgonetkaApi\Internal\Validator\ShipmentValidator;
use VeryCodeCom\FurgonetkaApi\Query\AddressBookQuery;
use VeryCodeCom\FurgonetkaApi\Query\BalanceQuery;
use VeryCodeCom\FurgonetkaApi\Query\PackageListQuery;
use VeryCodeCom\FurgonetkaApi\Query\PointsQuery;
use VeryCodeCom\FurgonetkaApi\Transport\CurlTransport;
use VeryCodeCom\FurgonetkaApi\Transport\TransportInterface;
use VeryCodeCom\FurgonetkaApi\Transport\TransportRequest;
use VeryCodeCom\FurgonetkaApi\Transport\TransportResponse;

/**
 * Client for the Furgonetka.pl REST API.
 *
 * It is a facade: every method validates what it can locally, builds the request from the registry
 * of operations ({@see Endpoint}, which knows each operation's media type version), sends it
 * through the transport, and turns the answer into a DTO or the error into the narrowest exception.
 *
 *   $client = FurgonetkaApiClient::create(
 *       FurgonetkaApiConfig::fromEnv(),
 *       new FileTokenStore('/var/lib/shop/furgonetka/token.json'),
 *   );
 *
 *   $client->connectWithPassword($login, $password);        // once; the tokens are kept from now on
 *
 *   $dpd     = $client->listServices()->first(Carrier::Dpd);
 *   $package = $client->createPackage($request);
 *   $order   = $client->orderPackages([$package->id]);
 *   file_put_contents('label.pdf', $client->fetchLabel($package->id)->bytes);
 *
 * Things that hold for the whole client:
 *  - **Tokens** are refreshed by the {@see TokenManager} under a lock, and a request that gets a 401
 *    (Furgonetka ends the old access token the moment another process refreshes) is repeated once
 *    with the token that is current then. A second 401 is an error.
 *  - **Commands** (ordering, pickups, cancellations, documents) are asynchronous. The methods named
 *    after the action (`orderPackages()`) submit and wait; `submit*()` returns the UUID at once and
 *    `fetch*Command()` reads the result later. A finished command that failed is a result to read,
 *    not an exception, see {@see CommandResult}.
 *  - **Nothing is accepted for you**: regulations are read and accepted only on your call.
 *  - **No hidden requests**: `listServices()` and `fetchAllowedCountries()` are remembered by this
 *    instance once you have called them, and local validation uses them only then.
 *  - **Rate limit**: `lastRateLimit()` holds the quota of the last answer, and a warning is logged
 *    when less than 10% is left.
 *  - **Sandbox only**: `simulateTracking()` throws on a production configuration before it sends anything.
 *
 * The client is not shared between threads and holds the two remembered lists; `withCompany()`
 * gives a copy for another company.
 */
final class FurgonetkaApiClient
{
    /** Furgonetka accepts at most this many shipments in one command. */
    private const MAX_PACKAGES_PER_COMMAND = 100;

    private readonly LoggerInterface $logger;
    private readonly RequestBuilder $builder;
    private readonly ErrorMapper $errors;
    private readonly ResponseParser $parser;
    private readonly RateLimitReader $rateLimits;
    private readonly ShipmentValidator $validator;
    private readonly CommandRunner $runner;

    private ?ServiceList $services = null;
    private ?AllowedCountries $allowedCountries = null;
    private ?RateLimitStatus $rateLimit = null;

    /**
     * Prefer {@see self::create()}; this constructor is for dependency injection and tests.
     *
     * @param FurgonetkaApiConfig                   $config    Environment, keys, language, company, timeouts, debug.
     * @param TransportInterface                    $transport Sends the requests.
     * @param TokenProviderInterface|null           $tokens    Where bearer tokens come from; null for a client that only calls public endpoints.
     * @param LoggerInterface|null                  $logger    PSR-3 logger; nothing is logged without one.
     * @param OAuthClient|null                      $oauth     For `oauth()` and the `connectWith*()` methods; needs application keys in the config.
     * @param SleeperInterface|null                 $sleeper   How to wait between two reads of a command.
     * @param PollingPolicy                         $polling   Default waiting policy of the commands.
     * @param (\Closure(): \DateTimeImmutable)|null $clock     Source of "now" for the command timeout; tests only.
     */
    public function __construct(
        private readonly FurgonetkaApiConfig $config,
        private readonly TransportInterface $transport,
        private readonly ?TokenProviderInterface $tokens = null,
        ?LoggerInterface $logger = null,
        private readonly ?OAuthClient $oauth = null,
        private readonly ?SleeperInterface $sleeper = null,
        private readonly PollingPolicy $polling = new PollingPolicy(),
        private readonly ?\Closure $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->builder = new RequestBuilder($config);
        $this->errors = new ErrorMapper();
        $this->parser = new ResponseParser();
        $this->rateLimits = new RateLimitReader();
        $this->validator = new ShipmentValidator();
        $this->runner = new CommandRunner($sleeper ?? new UsleepSleeper(), $clock);
    }

    /**
     * Build a client with the default transport (cURL) and, when given a token store, a
     * {@see TokenManager} that keeps the session alive.
     *
     * @param TokenStoreInterface|TokenProviderInterface|null $tokens       A store (tokens are refreshed for you), a token provider of
     *                                                                       your own, or null for public endpoints only.
     * @param TransportInterface|null                         $transport    Replaces cURL.
     * @param ReauthorizerInterface|null                      $reauthorizer Opt-in re-login when the refresh token is gone; only with a store.
     *
     * @throws \InvalidArgumentException when a store is given without the application keys, or a reauthorizer without a store.
     */
    public static function create(
        FurgonetkaApiConfig $config,
        TokenStoreInterface|TokenProviderInterface|null $tokens = null,
        ?LoggerInterface $logger = null,
        ?TransportInterface $transport = null,
        ?ReauthorizerInterface $reauthorizer = null,
        ?SleeperInterface $sleeper = null,
        ?PollingPolicy $polling = null,
    ): self {
        $transport ??= new CurlTransport();
        $oauth = $config->hasCredentials() ? new OAuthClient($config, $transport, $logger) : null;

        if ($reauthorizer !== null && !$tokens instanceof TokenStoreInterface) {
            throw new \InvalidArgumentException('A reauthorizer works through the token manager built from a token store; pass a store.');
        }

        if ($tokens instanceof TokenStoreInterface) {
            if ($oauth === null) {
                throw new \InvalidArgumentException('A token store needs the OAuth application keys (Client ID and Client Secret) in the config.');
            }

            $tokens = new TokenManager($oauth, $tokens, $reauthorizer, logger: $logger);
        }

        return new self($config, $transport, $tokens, $logger, $oauth, $sleeper, $polling ?? new PollingPolicy());
    }

    /** The same client acting for another company (or for none, with null); tokens, transport and logger are shared. */
    public function withCompany(?int $companyId): self
    {
        return new self(
            $this->config->withCompanyId($companyId),
            $this->transport,
            $this->tokens,
            $this->logger,
            $this->oauth,
            $this->sleeper,
            $this->polling,
            $this->clock,
        );
    }

    // -- OAuth ------------------------------------------------------------------

    /**
     * The OAuth client, for the grants that do not store anything (authorization URL, client
     * credentials) and for a token you want to manage yourself.
     *
     * @throws \LogicException when the config has no OAuth application keys.
     */
    public function oauth(): OAuthClient
    {
        return $this->oauth ?? throw new \LogicException('This client has no OAuth application keys; put them in the FurgonetkaApiConfig.');
    }

    /**
     * Log in with the account's username and password and keep the tokens. For a shop on its own
     * account, once; every call opens one of the 20 sessions Furgonetka allows, so never per request.
     *
     * @throws Exception\FurgonetkaApiTwoFactorRequiredException when the account has 2FA; finish with {@see self::connectWithTwoFactor()}.
     */
    public function connectWithPassword(string $username, #[\SensitiveParameter] string $password): void
    {
        $this->tokenManager()->save($this->oauth()->passwordGrant($username, $password));
    }

    /** Finish a login that stopped at the two-factor request and keep the tokens. */
    public function connectWithTwoFactor(string $hint, string $username, #[\SensitiveParameter] string $twoFactorCode): void
    {
        $this->tokenManager()->save($this->oauth()->completeTwoFactor($hint, $username, $twoFactorCode));
    }

    /**
     * Finish the authorization code flow in your callback and keep the tokens. Use a client with a
     * token store of its own for each connected account.
     */
    public function connectWithAuthorizationCode(#[\SensitiveParameter] string $code, string $state, string $expectedState): void
    {
        $this->tokenManager()->save($this->oauth()->exchangeAuthorizationCode($code, $state, $expectedState));
    }

    // -- Account and configuration --------------------------------------------------

    /**
     * The carrier contracts of the account. Remembered by this instance, and local validation uses
     * them from then on to know the carrier and the owner of a `service_id`.
     */
    public function listServices(): ServiceList
    {
        $response = $this->request(Endpoint::ListServices);

        return $this->services = ServiceList::fromArray($this->json($response, 'the service list'));
    }

    /**
     * The companies of the user. Use an `id` with {@see self::withCompany()}.
     *
     * @return list<Company>
     */
    public function listCompanies(): array
    {
        $response = $this->request(Endpoint::ListCompanies);

        return array_map(Company::fromArray(...), Value::objectList($this->json($response, 'the company list'), 'companies'));
    }

    /** Countries a shipment can go from and to. Needs no login. Remembered by this instance for local validation. */
    public function fetchAllowedCountries(): AllowedCountries
    {
        $response = $this->request(Endpoint::FetchAllowedCountries);

        return $this->allowedCountries = AllowedCountries::fromArray($this->json($response, 'the allowed countries'));
    }

    /** Furgonetka's dictionary of carriers, services and field names. Needs no login. */
    public function fetchDictionary(): Dictionary
    {
        return Dictionary::fromArray($this->json($this->request(Endpoint::FetchDictionary), 'the dictionary'));
    }

    // -- Regulations -------------------------------------------------------------

    /**
     * Every regulation and consent of the account, accepted or not. It holds about forty entries,
     * most of them no shipment depends on, so do not accept "everything not accepted": see
     * {@see self::pendingRegulations()}.
     *
     * @param string|null $type                     `settings` or `mobileRegister`; all when null.
     * @param bool|null   $companyOwnerRegulations  Read the regulations of the company owner's account.
     * @return list<Regulation>
     */
    public function listRegulations(?string $type = null, ?bool $companyOwnerRegulations = null): array
    {
        $response = $this->request(Endpoint::ListRegulations, [], ['type' => $type, 'companyOwnerRegulations' => $companyOwnerRegulations]);

        return array_map(Regulation::fromArray(...), Value::objectList($this->json($response, 'the regulations'), 'regulations'));
    }

    /**
     * The services whose regulations must be accepted before these shipments can be ordered
     * (`servicesRegulation`); empty when nothing is pending.
     *
     * @param list<string|int|Package> $packageIds
     * @return list<string>
     */
    public function regulationsForPackages(array $packageIds): array
    {
        $ids = array_map(static fn (array $ref): string => $ref['id'], self::packageRefs($packageIds, requireDigits: true));
        $response = $this->request(Endpoint::RegulationsForPackages, [], ['packagesIds' => $ids]);

        return Value::stringList($this->json($response, 'the regulations for the shipments'), 'servicesRegulation');
    }

    /**
     * The regulations that stand between these shipments and ordering: the ones of the services
     * that {@see self::regulationsForPackages()} names and that the account has not accepted.
     * Show their documents to whoever has the authority to agree, then pass the accepted ones to
     * {@see self::acceptRegulations()}.
     *
     * There is no variant without shipments on purpose: the answer would be every consent that is
     * not accepted, and accepting all of them means agreeing to about forty documents nobody asked for.
     *
     * @param list<string|int|Package> $packageIds
     * @return list<Regulation>
     */
    public function pendingRegulations(array $packageIds): array
    {
        $services = $this->regulationsForPackages($packageIds);

        if ($services === []) {
            return [];
        }

        return array_values(array_filter(
            $this->listRegulations(),
            static fn (Regulation $regulation): bool => $regulation->service !== null
                && in_array($regulation->service, $services, true)
                && $regulation->accepted !== true,
        ));
    }

    /**
     * Accept these regulations, in the exact version Furgonetka sent. **This is a legal act of the
     * account, so it only ever happens on your explicit call.**
     *
     * @param list<Regulation> $regulations What to accept; not empty.
     * @return list<Regulation> The regulations after the acceptance.
     *
     * @throws \InvalidArgumentException when the list is empty.
     */
    public function acceptRegulations(array $regulations): array
    {
        if ($regulations === []) {
            throw new \InvalidArgumentException('acceptRegulations() needs at least one regulation.');
        }

        $body = ['regulations' => array_map(static fn (Regulation $regulation): array => $regulation->toAcceptance(), $regulations)];

        $this->logger->info('Furgonetka: accepting regulations', ['services' => array_map(static fn (Regulation $r): ?string => $r->service, $regulations)]);
        $response = $this->request(Endpoint::AcceptRegulations, [], [], $body);

        return array_map(Regulation::fromArray(...), Value::objectList($this->json($response, 'the accepted regulations'), 'regulations'));
    }

    // -- Validation ------------------------------------------------------------------

    /**
     * Run the local rules without any request. An error is a rule Furgonetka enforces too; the rest
     * is in {@see self::warningsFor()}. The carrier is taken from the argument, else from
     * {@see self::listServices()} if you called it, else the carrier rules are skipped.
     *
     * @return list<string> Errors; empty means nothing is certainly wrong.
     */
    public function validateLocally(ShipmentRequest $request, ?Carrier $carrier = null): array
    {
        [$carrier, $owner] = $this->contextOf($request, $carrier);

        return $this->validator->validate($request, $carrier, $owner, $this->allowedCountries?->dutyCountryCodes() ?? []);
    }

    /**
     * Rules the documentation states that the API accepts a violation of. Never thrown; logged at
     * every create and update.
     *
     * @return list<string>
     */
    public function warningsFor(ShipmentRequest $request, ?Carrier $carrier = null): array
    {
        [$carrier, $owner] = $this->contextOf($request, $carrier);

        return $this->validator->warnings($request, $carrier, $owner, $this->allowedCountries?->dutyCountryCodes() ?? []);
    }

    /**
     * Ask Furgonetka whether it would accept the shipment (`POST /packages/validate`). Nothing is created.
     *
     * The answer is the list of field errors: empty when the shipment is valid. Anything else that
     * goes wrong (no login, no network) is an exception as usual.
     *
     * @return list<ApiError>
     */
    public function validateRemotely(ShipmentRequest $request): array
    {
        [$sent, $response] = $this->exchange(Endpoint::ValidatePackage, [], [], $request->toArray());

        if ($response->statusCode === 204) {
            return [];
        }

        if ($response->statusCode === 400) {
            return $this->errors->map($sent, $response)->errors;
        }

        if ($response->isSuccess()) {
            // Seen on the sandbox for an empty body: HTTP 200 and a legacy `{"status": "ERROR"}`. Only 204 means valid.
            $this->fail(new FurgonetkaApiResponseParseException(
                "Furgonetka answered {$sent->describe()} with HTTP {$response->statusCode}; only 204 means the shipment is valid."
            ), $response);
        }

        $this->fail($this->errors->map($sent, $response), $response);
    }

    // -- Prices ---------------------------------------------------------------------------

    /**
     * Price a shipment with several carriers at once (`POST /packages/calculate-price`).
     *
     * **A carrier that cannot carry the shipment is not an error**: it comes back marked unavailable
     * with the reason. Use {@see PriceQuoteList::cheapest()}, which skips those. No local validation
     * runs: Furgonetka answers per carrier, and a price does not need the data a shipment does.
     */
    public function calculatePrices(ShipmentRequest $request, PriceScope $scope): PriceQuoteList
    {
        $response = $this->request(Endpoint::CalculatePrices, [], [], ['services' => $scope->toArray(), 'package' => $request->toArray()]);

        return PriceQuoteList::fromArray($this->json($response, 'the price calculation'));
    }

    // -- Shipments --------------------------------------------------------------------------

    /**
     * Create a shipment (`POST /packages`). It is in the "waiting" state until it is ordered.
     *
     * @throws FurgonetkaApiValidationException when a local rule that Furgonetka enforces too is broken; nothing is sent.
     */
    public function createPackage(ShipmentRequest $request): Package
    {
        $this->guard($request);
        $this->logger->info('Furgonetka: creating a shipment', ['serviceId' => $request->serviceId, 'parcels' => count($request->parcels)]);

        return Package::fromArray($this->json($this->request(Endpoint::CreatePackage, [], [], $request->toArray()), 'the created shipment'));
    }

    /**
     * Replace the data of a shipment that has not been ordered (`PUT /packages/{id}`). The whole
     * shipment is sent again, not a difference.
     *
     * @throws FurgonetkaApiValidationException as {@see self::createPackage()}.
     */
    public function updatePackage(string|int|Package $packageId, ShipmentRequest $request): Package
    {
        $id = self::packageId($packageId);
        $this->guard($request);
        $this->logger->info('Furgonetka: updating a shipment', ['packageId' => $id]);

        return Package::fromArray($this->json($this->request(Endpoint::UpdatePackage, ['package_id' => $id], [], $request->toArray()), 'the updated shipment'));
    }

    /**
     * Read one shipment (`GET /packages/{id}`). Furgonetka answers 400, not 404, for a shipment
     * that is not yours or has been deleted.
     *
     * @param string|int|Package $packageId      Furgonetka's id or the carrier's waybill number.
     * @param bool|null          $originalServices Return the services as they were when the shipment was created.
     * @param string|null        $type           Which list the shipment is looked up in: `sent`, `waiting`, `tracking`, `storage` or `auction`.
     */
    public function fetchPackage(string|int|Package $packageId, ?bool $originalServices = null, ?string $type = null): Package
    {
        $response = $this->request(Endpoint::FetchPackage, ['id' => self::packageId($packageId)], ['original_services' => $originalServices, 'type' => $type]);

        return Package::fromArray($this->json($response, 'the shipment'));
    }

    /** One page of shipments (`GET /packages`); see {@see PackageListQuery} for paging and filters. */
    public function listPackages(?PackageListQuery $query = null): PackagePage
    {
        $response = $this->request(Endpoint::ListPackages, [], ($query ?? new PackageListQuery())->toArray());

        return PackagePage::fromArray($this->json($response, 'the shipment list'));
    }

    /** Delete a shipment that has not been ordered (`DELETE /packages/{id}`). An ordered one has to be cancelled. */
    public function deletePackage(string|int|Package $packageId): void
    {
        $this->request(Endpoint::DeletePackage, ['package_id' => self::packageId($packageId)]);
    }

    /**
     * Delete several shipments that have not been ordered (`DELETE /packages`).
     *
     * @param list<string|int|Package> $packageIds
     */
    public function deletePackages(array $packageIds): void
    {
        $this->request(Endpoint::DeletePackages, [], [], ['packages' => self::packageRefs($packageIds, requireDigits: true)]);
    }

    // -- Ordering -----------------------------------------------------------------------------

    /**
     * Order shipments and wait for the result.
     *
     * Submits the order under a fresh UUID and reads it until it is finished, see
     * {@see self::submitOrder()} and {@see self::fetchOrderCommand()} for the two halves. **A carrier
     * that refuses a shipment does not throw**: look at {@see OrderCommandResult::isSuccessful()} and
     * `errorsFor($id)`. What does throw: a 409 when the carrier's regulations are not accepted
     * ({@see self::pendingRegulations()}), a 400 `noSignature` for a shipment that leaves the EU until
     * the sender has signed the customs documents on Furgonetka's own page ({@see Package::signatureRequired()}
     * and {@see Package::documentsSigned()} say where it stands; the API has no call for a signature), a 400 without a code on `/packages` when the price is above the balance, and a
     * timeout, which carries the UUID so the order is read later instead of being placed twice.
     *
     * @param list<string|int|Package> $packageIds Up to 100 shipments.
     * @param LabelOptions|null        $label      Label format; the account's printing settings when null. The file format cannot be changed afterwards.
     * @param bool                     $skipEmail  Do not send the confirmation e-mail; send it later with {@see self::sendDocumentsEmail()}.
     * @param string|null              $uuid       Your own UUID, to make a retry safe, see {@see self::submitOrder()}.
     */
    public function orderPackages(array $packageIds, ?LabelOptions $label = null, bool $skipEmail = false, ?string $uuid = null, ?PollingPolicy $policy = null): OrderCommandResult
    {
        $uuid = $this->submitOrder($packageIds, $label, $skipEmail, $uuid);

        return $this->wait($uuid, fn (): OrderCommandResult => $this->fetchOrderCommand($uuid), $policy);
    }

    /**
     * Submit an order and return its UUID without waiting.
     *
     * **A retry is safe when you pass the UUID.** If the request fails on the network you cannot know
     * whether Furgonetka got it. Submit again with the same UUID: Furgonetka answers 400 with the code
     * `commandExists` when it did, and this method then just returns the UUID (checked on the
     * sandbox on 2026-09-29). A UUID the client generates is never reused.
     *
     * @param list<string|int|Package> $packageIds
     */
    public function submitOrder(array $packageIds, ?LabelOptions $label = null, bool $skipEmail = false, ?string $uuid = null): string
    {
        $body = ['packages' => self::packageRefs($packageIds, self::MAX_PACKAGES_PER_COMMAND, true)];
        $labelData = $label?->toArray() ?? [];

        if ($labelData !== []) {
            $body['label'] = $labelData;
        }

        if ($skipEmail) {
            $body['skip_email_send'] = true;
        }

        return $this->submit(Endpoint::SubmitOrder, $body, $uuid, 'order');
    }

    /** Read the state of an order (`GET /order-commands/{uuid}`) without waiting. */
    public function fetchOrderCommand(string $uuid): OrderCommandResult
    {
        return OrderCommandResult::fromArray($this->json($this->request(Endpoint::FetchOrderCommand, ['uuid' => self::uuid($uuid)]), 'the order status'));
    }

    /**
     * Send the confirmation e-mail of an ordered shipment (`POST /packages/send-email`): for
     * shipments ordered with `skipEmail`, or after a pickup was added. Once, up to 18 hours after ordering.
     */
    public function sendDocumentsEmail(string|int|Package $packageId): void
    {
        $this->request(Endpoint::SendDocumentsEmail, [], [], ['packages' => self::packageRefs([$packageId], 1, true)]);
    }

    // -- Labels and documents ----------------------------------------------------------------------

    /**
     * Download the label of a shipment (`GET /packages/{id}/label`): a PDF, or a ZPL or EPL text.
     *
     * @param LabelPageFormat|null $pageFormat Sheet size of a PDF; the account's setting when null. The file format itself was chosen when the shipment was ordered.
     *
     * A cancelled shipment is a 404 (path `/package_id`, message "Package cancelled"), and a tracked package,
     * which never had a label of its own, answers 204 like a label that is not ready.
     *
     * @throws FurgonetkaApiLabelNotReadyException when Furgonetka has no label (HTTP 204): expected just after ordering, ask again; for a tracked package it stays so.
     * @throws Exception\FurgonetkaApiGoneException when the label cannot be produced any more (410; never seen on the sandbox, W9).
     * @throws FurgonetkaApiException when Furgonetka refuses the request (404 for a cancelled or unknown shipment), the network fails or the answer is not a label.
     */
    public function fetchLabel(string|int|Package $packageId, ?LabelPageFormat $pageFormat = null): Label
    {
        $response = $this->request(
            Endpoint::FetchLabel,
            ['package_id' => self::packageId($packageId)],
            $pageFormat === null ? [] : ['label' => ['page_format' => $pageFormat->value]],
        );

        if ($response->statusCode === 204) {
            $this->logger->info('Furgonetka: the label is not ready yet', ['packageId' => self::packageId($packageId)]);

            throw new FurgonetkaApiLabelNotReadyException('Furgonetka has no label for this shipment (HTTP 204): not ready yet, or a tracked package that has none. Ask again in a moment.');
        }

        if ($response->body === '') {
            $this->fail(new FurgonetkaApiResponseParseException('Furgonetka answered the label request with an empty body.'), $response);
        }

        if ($response->isPdf()) {
            return new Label($response->body, 'application/pdf', LabelFileFormat::Pdf);
        }

        if (str_starts_with($response->contentType(), 'text/')) {
            return new Label($response->body, $response->contentType(), str_contains($response->body, '^XA') ? LabelFileFormat::Zpl : null);
        }

        $this->fail(new FurgonetkaApiResponseParseException(
            'Furgonetka answered the label request with ' . ($response->contentType() ?: 'no content type') . ', not a PDF or a ZPL/EPL text.'
        ), $response);
    }

    /**
     * Prepare documents of shipments and wait for the file, see {@see self::submitDocuments()}.
     *
     * @param list<string|int|Package> $packageIds Up to 100 shipments.
     * @param list<DocumentType>       $types      What to prepare; not empty.
     * @param LabelOptions|null        $label      Only its page format and cutting line are used here; the labels come in the format they were ordered in.
     */
    public function requestDocuments(array $packageIds, array $types, ?LabelOptions $label = null, ?string $uuid = null, ?PollingPolicy $policy = null): DocumentsCommandResult
    {
        $uuid = $this->submitDocuments($packageIds, $types, $label, $uuid);

        return $this->wait($uuid, fn (): DocumentsCommandResult => $this->fetchDocumentsCommand($uuid), $policy);
    }

    /**
     * Submit a documents command and return its UUID without waiting; a repeat with the same UUID is safe, see {@see self::submitOrder()}.
     *
     * @param list<string|int|Package> $packageIds
     * @param list<DocumentType>       $types
     */
    public function submitDocuments(array $packageIds, array $types, ?LabelOptions $label = null, ?string $uuid = null): string
    {
        if ($types === []) {
            throw new \InvalidArgumentException('submitDocuments() needs at least one document type.');
        }

        $body = [
            'packages' => self::packageRefs($packageIds, self::MAX_PACKAGES_PER_COMMAND, true),
            'documents_types' => array_map(static fn (DocumentType $type): string => $type->value, $types),
        ];
        $labelData = array_intersect_key($label?->toArray() ?? [], ['page_format' => true, 'add_cutting_line' => true]);

        if ($labelData !== []) {
            $body['label'] = $labelData;
        }

        return $this->submit(Endpoint::SubmitDocuments, $body, $uuid, 'documents');
    }

    /** Read the state of a documents command (`GET /documents-command/{uuid}`) without waiting. */
    public function fetchDocumentsCommand(string $uuid): DocumentsCommandResult
    {
        return DocumentsCommandResult::fromArray($this->json($this->request(Endpoint::FetchDocumentsCommand, ['uuid' => self::uuid($uuid)]), 'the documents status'));
    }

    /**
     * Download the file of a finished documents command.
     *
     * The link is signed and needs no login, and no token is sent: the file lives on another host
     * than the API, and a bearer token must not leave the API. Only https links are followed.
     *
     * @param DocumentsCommandResult|string $source The finished result, or its `url`.
     *
     * @throws \InvalidArgumentException when there is no https URL to download.
     * @throws FurgonetkaApiException     when the file cannot be fetched: the link has expired (an HTTP error status), the network fails, or the file is empty.
     */
    public function downloadDocuments(DocumentsCommandResult|string $source): DocumentFile
    {
        return $this->downloadSigned($source instanceof DocumentsCommandResult ? $source->url : $source, 'documents');
    }

    /**
     * Download the CSV of a finished export, the way {@see self::downloadDocuments()} downloads
     * documents: a signed https link on another host, no token sent.
     *
     * @param CsvExportResult|string $source The finished result, or its `url`.
     *
     * @throws \InvalidArgumentException when there is no https URL to download.
     * @throws FurgonetkaApiException     when the file cannot be fetched: the link has expired (an HTTP error status), the network fails, or the file is empty.
     */
    public function downloadCsvExport(CsvExportResult|string $source): DocumentFile
    {
        return $this->downloadSigned($source instanceof CsvExportResult ? $source->url : $source, 'CSV export');
    }

    /**
     * Fetch a file from a signed link. Its host is not the API's, so no token and no company header go
     * with the request, and only https is followed.
     *
     * @param string $what What the file is, for the messages: "documents" or "CSV export".
     */
    private function downloadSigned(?string $url, string $what): DocumentFile
    {
        if ($url === null || $url === '') {
            throw new \InvalidArgumentException("The {$what} has no url yet: wait until it is finished and successful.");
        }

        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new \InvalidArgumentException("The {$what} URL must be https.");
        }

        $request = new TransportRequest($url, headers: ['Accept' => '*/*'], timeout: $this->config->timeout, connectTimeout: $this->config->connectTimeout);

        try {
            $response = $this->transport->send($request);
        } catch (FurgonetkaApiTransportException $e) {
            $this->fail($e);
        }

        if (!$response->isSuccess()) {
            $this->fail($this->errors->map($request, $response), $response);
        }

        if ($response->body === '') {
            $this->fail(new FurgonetkaApiResponseParseException("Furgonetka served an empty {$what} file."), $response);
        }

        return new DocumentFile($response->body, $response->contentType());
    }

    /**
     * The pickup protocol (a PDF) for shipments (`POST /packages/protocol`). Available for DPD
     * (domestic), DHL, InPost Kurier and InPost parcel lockers on an own contract, FedEx (domestic) and GLS (own contract).
     *
     * @param list<string|int|Package> $packageIds Up to 100 shipments.
     *
     * @throws FurgonetkaApiLabelNotReadyException when Furgonetka has no protocol yet (HTTP 204).
     */
    public function fetchProtocol(array $packageIds): DocumentFile
    {
        $response = $this->request(Endpoint::FetchProtocol, [], [], ['packages' => self::packageRefs($packageIds, self::MAX_PACKAGES_PER_COMMAND, true)]);

        if ($response->statusCode === 204) {
            throw new FurgonetkaApiLabelNotReadyException('Furgonetka has no protocol for these shipments yet (HTTP 204); ask again in a moment.');
        }

        if (!$response->isPdf()) {
            $this->fail(new FurgonetkaApiResponseParseException(
                'Furgonetka answered the protocol request with ' . ($response->contentType() ?: 'no content type') . ', not a PDF.'
            ), $response);
        }

        return new DocumentFile($response->body, 'application/pdf');
    }

    // -- Courier pickup -------------------------------------------------------------------------------

    /**
     * The days and time windows in which the courier could collect (`POST /packages/pickup-date-proposals`).
     *
     * @param list<string|int|Package>       $packageIds
     * @param string|\DateTimeInterface|null $readyDate    Day the shipments will be ready; proposals count from then.
     * @param bool|null                      $onlyRepickup Ask for a repeated pickup only.
     * @param int|null                       $daysAhead    How many days of proposals, 1 to 7.
     * @return list<PickupProposalGroup>
     */
    public function fetchPickupProposals(array $packageIds, string|\DateTimeInterface|null $readyDate = null, ?bool $onlyRepickup = null, ?int $daysAhead = null): array
    {
        if ($daysAhead !== null && ($daysAhead < 1 || $daysAhead > 7)) {
            throw new \InvalidArgumentException('daysAhead must be between 1 and 7.');
        }

        $body = array_filter([
            'packages' => self::packageRefs($packageIds, self::MAX_PACKAGES_PER_COMMAND, true),
            'ready_date' => DateFormat::date($readyDate),
            'only_repickup' => $onlyRepickup,
            'days_ahead' => $daysAhead,
        ], static fn (mixed $value): bool => $value !== null);
        $response = $this->request(Endpoint::FetchPickupProposals, [], [], $body);

        return array_map(PickupProposalGroup::fromArray(...), Value::objectList($this->json($response, 'the pickup proposals'), 'packages'));
    }

    /**
     * Book a courier pickup and wait for the result, see {@see self::submitPickup()}.
     *
     * Takes a slot from {@see self::fetchPickupProposals()} through {@see Dto\PickupProposal::toPickupDate()}.
     * Like ordering, it answers 409 when the carrier's regulations are not accepted.
     *
     * @param list<string|int|Package> $packageIds
     */
    public function orderPickup(array $packageIds, PickupDate $pickupDate, ?string $uuid = null, ?PollingPolicy $policy = null): PickupCommandResult
    {
        $uuid = $this->submitPickup($packageIds, $pickupDate, $uuid);

        return $this->wait($uuid, fn (): PickupCommandResult => $this->fetchPickupCommand($uuid), $policy);
    }

    /**
     * Submit a pickup command and return its UUID without waiting; a repeat with the same UUID is safe, see {@see self::submitOrder()}.
     *
     * @param list<string|int|Package> $packageIds
     */
    public function submitPickup(array $packageIds, PickupDate $pickupDate, ?string $uuid = null): string
    {
        $body = ['packages' => self::packageRefs($packageIds, self::MAX_PACKAGES_PER_COMMAND, true), 'pickup_date' => $pickupDate->toArray()];

        return $this->submit(Endpoint::SubmitPickup, $body, $uuid, 'pickup');
    }

    /** Read the state of a pickup command (`GET /pickup-commands/{uuid}`) without waiting. */
    public function fetchPickupCommand(string $uuid): PickupCommandResult
    {
        return PickupCommandResult::fromArray($this->json($this->request(Endpoint::FetchPickupCommand, ['uuid' => self::uuid($uuid)]), 'the pickup status'));
    }

    // -- Cancellation --------------------------------------------------------------------------------------

    /**
     * Cancel ordered shipments and wait for the result, see {@see self::submitCancel()}. Cancelling can
     * cost money (GLS), and may be scheduled instead of done at once: read each detail of the result.
     *
     * @param list<string|int|Package> $packageIds
     */
    public function cancelPackages(array $packageIds, ?string $uuid = null, ?PollingPolicy $policy = null): CancelCommandResult
    {
        $uuid = $this->submitCancel($packageIds, $uuid);

        return $this->wait($uuid, fn (): CancelCommandResult => $this->fetchCancelCommand($uuid), $policy);
    }

    /**
     * Submit a cancellation and return its UUID without waiting; a repeat with the same UUID is safe, see {@see self::submitOrder()}.
     *
     * @param list<string|int|Package> $packageIds
     */
    public function submitCancel(array $packageIds, ?string $uuid = null): string
    {
        return $this->submit(Endpoint::SubmitCancel, ['packages' => self::packageRefs($packageIds, self::MAX_PACKAGES_PER_COMMAND, true)], $uuid, 'cancel');
    }

    /** Read the state of a cancellation (`GET /cancel-command/{uuid}`) without waiting. */
    public function fetchCancelCommand(string $uuid): CancelCommandResult
    {
        return CancelCommandResult::fromArray($this->json($this->request(Endpoint::FetchCancelCommand, ['uuid' => self::uuid($uuid)]), 'the cancellation status'));
    }

    /**
     * Mark a shipment on an own contract as cancelled without telling the carrier
     * (`POST /packages/mark-as-cancelled`), for a shipment you cancelled with the carrier yourself.
     */
    public function markAsCancelled(string|int|Package $packageId): void
    {
        $this->request(Endpoint::MarkAsCancelled, [], [], ['packages' => self::packageRefs([$packageId], 1, true)]);
    }

    /**
     * Abort a command that has not started. On the sandbox a command that was still queued answered 204 and
     * ended as `cancelled` ({@see CommandResult::isCancelled()}); one that had started (commands take
     * seconds) or had finished answered 400 (`wrongState`), and an unknown one 400 (`commandNotExists`).
     * Both the typed endpoint and the generic one aborted a CSV export.
     *
     * @param CommandType $type The kind of command, when you know it; whether the generic endpoint
     *                          reaches every kind is not established: it reached the CSV export, documents, shipment creation, file and webhook check commands.
     */
    public function abortCommand(string $uuid, CommandType $type = CommandType::Generic): void
    {
        $endpoint = match ($type) {
            CommandType::Generic => Endpoint::AbortGenericCommand,
            CommandType::Order => Endpoint::AbortOrderCommand,
            CommandType::Pickup => Endpoint::AbortPickupCommand,
            CommandType::Cancel => Endpoint::AbortCancelCommand,
            CommandType::Documents => Endpoint::AbortDocumentsCommand,
            CommandType::CreatePackage => Endpoint::AbortCreatePackageCommand,
            CommandType::CsvExport => Endpoint::AbortCsvExport,
            CommandType::Revalidate => Endpoint::AbortRevalidate,
            CommandType::AddToTracking => Endpoint::AbortAddToTracking,
        };

        $this->request($endpoint, ['uuid' => self::uuid($uuid)]);
    }

    // -- Tracking ------------------------------------------------------------------------------------------

    /**
     * Read the tracking of a shipment (`GET /packages/{id}/tracking`, version 2): one history per
     * parcel, keyed by waybill number.
     *
     * The endpoint takes Furgonetka's number of the shipment. A waybill number is a 404 there, so it
     * is refused before a request is made. A shipment that was ordered but has not moved answers
     * with its parcels and empty histories.
     *
     * @param string|int|Package $packageId The number Furgonetka gave the shipment (or a tracked package, see {@see self::addToTracking()}).
     *
     * @throws \InvalidArgumentException when the id is not that number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors, 404), the network fails or the answer cannot be read.
     */
    public function fetchTracking(string|int|Package $packageId): TrackingResult
    {
        $response = $this->request(Endpoint::FetchTracking, ['package_id' => self::numericPackageId($packageId)]);

        return TrackingResult::fromArray($this->json($response, 'the tracking'));
    }

    /**
     * Follow a shipment that did not go through Furgonetka (`POST /packages/add-to-tracking`).
     *
     * The answer carries the id to give {@see self::fetchTracking()}. Furgonetka answers 400 with
     * the code `exists` for a number that is already on the list and `existsSent` for one that is
     * among the account's ordered shipments. A tracked package cannot be removed through the API.
     *
     * @param string              $waybillNumber The carrier's waybill number.
     * @param Carrier|string|null $carrier       The carrier; without it Furgonetka tries to recognise it.
     * @param string|null         $name          A name of your own for the list.
     *
     * @throws \InvalidArgumentException when the number is empty.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors, 404), the network fails or the answer cannot be read.
     */
    public function addToTracking(string $waybillNumber, Carrier|string|null $carrier = null, ?string $name = null): TrackedPackage
    {
        $number = trim($waybillNumber);

        if ($number === '') {
            throw new \InvalidArgumentException('A waybill number must not be empty.');
        }

        $service = $carrier instanceof Carrier ? $carrier->value : trim((string) $carrier);
        $body = Payload::compact(['package_no' => $number, 'service' => $service !== '' ? $service : null, 'name' => $name]);

        return TrackedPackage::fromArray($this->json($this->request(Endpoint::AddToTracking, [], [], $body), 'the tracked package'));
    }

    /**
     * Change the name of a tracked package (`PUT /packages/edit-tracked-package/{package_id}`).
     *
     * @param string|int|Package $packageId The id {@see self::addToTracking()} answered with.
     *
     * @throws \InvalidArgumentException when the id is not a number or the name is empty.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors, 404), the network fails or the answer cannot be read.
     */
    public function renameTrackedPackage(string|int|Package $packageId, string $name): TrackedPackage
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A tracked package needs a name.');
        }

        $response = $this->request(Endpoint::RenameTrackedPackage, ['package_id' => self::numericPackageId($packageId)], [], ['name' => $name]);

        return TrackedPackage::fromArray($this->json($response, 'the renamed package'));
    }

    /**
     * Set the tracking state of an ordered shipment, to try tracking and webhooks without a real
     * courier (`PUT /packages/tracking/simulate`). **Sandbox only.**
     *
     * The specification lets either the shipment id or the waybill number do; the sandbox wants
     * both, so both are sent. With a {@see Package} the first waybill number is used; with an id
     * the number has to be given. The sandbox replaces the whole history with events leading to the
     * state, ten minutes apart, so the state you set last is the latest one. It stays ordered: once
     * a delivery state was set, cancelling the shipment is refused ("In package tracking found
     * delivery status").
     *
     * @param string|int|Package $package      The ordered shipment.
     * @param TrackingState      $state        The state to set; not `ready-for-transport` or `waiting-for-receiver`, which the API refuses.
     * @param string|null        $waybillNumber The parcel's waybill number; required unless a Package with one is given.
     *
     * @throws \LogicException            on a production configuration, before any request is made.
     * @throws \InvalidArgumentException  when the state is one the API refuses, or the id or the number is missing.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors, 404), the network fails or the answer cannot be read.
     */
    public function simulateTracking(string|int|Package $package, TrackingState $state, ?string $waybillNumber = null): void
    {
        if (!$this->config->sandbox) {
            throw new \LogicException('simulateTracking() exists only on the sandbox; this client is configured for production.');
        }

        if ($state === TrackingState::ReadyForTransport || $state === TrackingState::WaitingForReceiver) {
            throw new \InvalidArgumentException("The API does not accept the state \"{$state->value}\" for a simulation.");
        }

        $id = self::numericPackageId($package);
        $number = $waybillNumber !== null ? trim($waybillNumber) : ($package instanceof Package ? ($package->waybillNumbers()[0] ?? '') : '');

        if ($number === '') {
            throw new \InvalidArgumentException(
                $package instanceof Package
                    ? 'This shipment has no waybill number yet; order it first.'
                    : 'Give the waybill number too: the sandbox needs it together with the shipment id.'
            );
        }

        $this->logger->info('Furgonetka: simulating a tracking state', ['packageId' => $id, 'state' => $state->value]);
        $this->request(Endpoint::SimulateTracking, [], [], ['packages_id' => (int) $id, 'package_no' => $number, 'tracking_state' => $state->value]);
    }

    // -- Pickup points -------------------------------------------------------------------------------------

    /**
     * Search pickup points (`POST /points/map`). Needs no login.
     *
     * The points come nearest first. A phrase Furgonetka cannot place is not an error: the sandbox
     * fell back to a default centre in Warsaw, so read {@see PointList::$coordinates} and the
     * `distance` of the points. A request without a usable location answers 404 with the path
     * `/location`, which is an exception like any other. Use `Point::identifier()` as
     * `Receiver::$point` to send a shipment to a point.
     */
    public function findPoints(PointsQuery $query): PointList
    {
        return PointList::fromArray($this->json($this->request(Endpoint::FindPoints, [], [], $query->toArray()), 'the points'));
    }

    // -- Webhook -----------------------------------------------------------------------------------------------

    /**
     * The webhook the account has set up (`GET /account/settings/notification/webhook`). An account
     * with none answers with everything null, see {@see WebhookSettings::isConfigured()}. The
     * token in the answer is a secret.
     */
    public function fetchWebhookSettings(): WebhookSettings
    {
        return WebhookSettings::fromArray($this->json($this->request(Endpoint::FetchWebhookSettings), 'the webhook settings'));
    }

    /**
     * The calls Furgonetka made to the webhook and how the receiver answered
     * (`GET /account/settings/notification/webhook/logs`).
     *
     * @param WebhookLog|null $after The last entry of the previous page ({@see WebhookLogPage::nextCursor()}); null for the first page.
     *
     * @throws \InvalidArgumentException when that entry has no id or no time: Furgonetka wants the two together.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors, 404), the network fails or the answer cannot be read.
     */
    public function listWebhookLogs(?WebhookLog $after = null): WebhookLogPage
    {
        $query = [];

        if ($after !== null) {
            if ($after->id === null || $after->datetimeSend === null) {
                throw new \InvalidArgumentException('The entry to continue after needs an id and a time: Furgonetka wants lastId and lastDatetimeSend together.');
            }

            $query = ['lastId' => $after->id, 'lastDatetimeSend' => $after->datetimeSend->format(\DATE_ATOM)];
        }

        return WebhookLogPage::fromArray($this->json($this->request(Endpoint::ListWebhookLogs, [], $query), 'the webhook log'));
    }

    // -- Returns and readdressing -----------------------------------------------------------------------

    /**
     * What returning an ordered shipment to its sender would cost
     * (`POST /packages/return/calculate-price`).
     *
     * Furgonetka answers 400 with the code `notPossible` ("This package cannot be returned") for a
     * shipment that cannot be returned; on the sandbox that was every shipment it was tried on, so
     * a price itself has not been seen. A shipment the API does not know is `packageDoNotExist`.
     *
     * @param string|int|Package $packageId The number Furgonetka gave the shipment.
     *
     * @throws \InvalidArgumentException when the id is not that number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors), the network fails or the answer cannot be read.
     */
    public function calculateReturnPrice(string|int|Package $packageId): PriceEstimate
    {
        $response = $this->request(Endpoint::CalculateReturnPrice, [], [], ['packageId' => (int) self::numericPackageId($packageId)]);

        return PriceEstimate::fromArray($this->json($response, 'the return price'));
    }

    /**
     * Ask for the return of an ordered shipment (`PUT /packages/return/{uuid}`).
     *
     * **This does not pay or confirm anything, and it does not wait.** The answer carries the links
     * where a person pays and confirms. Furgonetka has no status call for it (the one for a return
     * from a point is out of scope), so a repeated request with the same UUID is a 400
     * `commandExists` and the links are not sent again.
     *
     * @param string|int|Package $packageId         The number Furgonetka gave the shipment.
     * @param string|int|null    $regulationsAccept The timestamp of the regulation to accept, in the specification's words ("Datetime regulaminu do akceptacji"). Furgonetka's regulations carry `version`, a Unix timestamp, which is very likely the value; the sandbox never got far enough to confirm it (W27). Passing it is your acceptance.
     * @param string|null        $uuid              Your own UUID; one is made when null.
     *
     * @throws \InvalidArgumentException when the id is not a number or the UUID is malformed.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the answer cannot be read.
     */
    public function returnPackage(string|int|Package $packageId, string|int|null $regulationsAccept = null, ?string $uuid = null): PaymentRequest
    {
        $body = Payload::compact([
            'packageId' => self::numericPackageId($packageId),
            'regulationsAccept' => self::regulationsAccept($regulationsAccept),
        ]);

        return PaymentRequest::fromArray($this->submitForAnswer(Endpoint::ReturnPackage, $body, $uuid, 'the return'));
    }

    /**
     * What sending an ordered shipment to another receiver would cost
     * (`POST /packages/readdressing/calculate-price`). It also validates the receiver, so the field
     * errors come back before anything is ordered.
     *
     * Not answered with a price on the sandbox: every shipment was "not possible" (`notPossible`
     * on `/packageId`), and the field errors of the receiver came back next to it.
     *
     * @param string|int|Package $packageId         The number Furgonetka gave the shipment.
     * @param string|int|null    $regulationsAccept See {@see self::returnPackage()}.
     *
     * @throws \InvalidArgumentException when the id is not a number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (400 with the field errors), the network fails or the answer cannot be read.
     */
    public function calculateReaddressingPrice(string|int|Package $packageId, ReaddressingReceiver $receiver, string|int|null $regulationsAccept = null): PriceEstimate
    {
        $body = Payload::compact([
            'packageId' => self::numericPackageId($packageId),
            'receiver' => $receiver->toArray(),
            'regulationsAccept' => self::regulationsAccept($regulationsAccept),
        ]);

        return PriceEstimate::fromArray($this->json($this->request(Endpoint::CalculateReaddressingPrice, [], [], $body), 'the readdressing price'));
    }

    /**
     * Ask for a shipment to go to another receiver (`PUT /packages/readdressing/{uuid}`).
     *
     * Like {@see self::returnPackage()} it returns the payment and confirmation links at once and
     * waits for nothing. Read where it stands with {@see self::fetchReaddressing()}, withdraw it
     * with {@see self::cancelReaddressing()}.
     *
     * @param string|int|Package  $packageId         The number Furgonetka gave the shipment.
     * @param Carrier|string|null $service           The carrier of the shipment ("dpd" in the specification's example); optional there.
     * @param string|int|null     $regulationsAccept See {@see self::returnPackage()}.
     * @param string|null         $uuid              Your own UUID; one is made when null.
     *
     * @throws \InvalidArgumentException when the id is not a number or the UUID is malformed.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the answer cannot be read.
     */
    public function readdressPackage(string|int|Package $packageId, ReaddressingReceiver $receiver, Carrier|string|null $service = null, string|int|null $regulationsAccept = null, ?string $uuid = null): PaymentRequest
    {
        $name = $service instanceof Carrier ? $service->value : trim((string) $service);
        $body = Payload::compact([
            'packageId' => self::numericPackageId($packageId),
            'receiver' => $receiver->toArray(),
            'regulationsAccept' => self::regulationsAccept($regulationsAccept),
            'service' => $name !== '' ? $name : null,
        ]);

        return PaymentRequest::fromArray($this->submitForAnswer(Endpoint::ReaddressPackage, $body, $uuid, 'the readdressing'));
    }

    /**
     * Read the state of a readdressing (`GET /packages/readdressing/{uuid}`): the new receiver, the
     * price and whether it is paid.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request (an unknown UUID is a 400 `commandNotExists`), the network fails or the answer cannot be read.
     */
    public function fetchReaddressing(string $uuid): ReaddressingCommandResult
    {
        return ReaddressingCommandResult::fromArray($this->json($this->request(Endpoint::FetchReaddressing, ['uuid' => self::uuid($uuid)]), 'the readdressing'));
    }

    /**
     * Withdraw a readdressing order (`DELETE /packages/readdressing/{uuid}`).
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request (an unknown UUID is a 400 `commandNotExists`) or the network fails.
     */
    public function cancelReaddressing(string $uuid): void
    {
        $this->request(Endpoint::CancelReaddressing, ['uuid' => self::uuid($uuid)]);
    }

    // -- Send to me ------------------------------------------------------------------------------------------

    /**
     * A link to the shipment form with the receiver's data already filled in
     * (`POST /packages/send-to-me-link`). Needs no login: Furgonetka validates the data and builds
     * the link, and the person who opens it logs in and finishes the shipment.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the data (400 with the field errors), the network fails or the answer cannot be read.
     */
    public function createSendToMeLink(SendToMeRequest $request): SendToMeLink
    {
        return SendToMeLink::fromArray($this->json($this->request(Endpoint::CreateSendToMeLink, [], [], $request->toArray()), 'the send to me link'));
    }

    // -- Creating a shipment as a command -----------------------------------------------------------------

    /**
     * Create a shipment as a command and wait for the result (`PUT /create-package-command/{uuid}`).
     *
     * The same shipment as {@see self::createPackage()}, made the way the other actions are: submitted
     * under a UUID and read until it is finished, so a retry after a network failure cannot create it
     * twice (submit again with the same UUID, see {@see self::submitOrder()}). The local rules run
     * first, as for `createPackage()`. The command can answer with `redirectUrl` instead of a
     * shipment, where a person finishes it in the browser; the sandbox never did.
     *
     * @param string|null $uuid Your own UUID, to make a retry safe.
     *
     * @throws FurgonetkaApiValidationException when a local rule that Furgonetka enforces too is broken; nothing is sent.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function createPackageAsync(ShipmentRequest $request, ?string $uuid = null, ?PollingPolicy $policy = null): CreatePackageCommandResult
    {
        $uuid = $this->submitCreatePackage($request, $uuid);

        return $this->wait($uuid, fn (): CreatePackageCommandResult => $this->fetchCreatePackageCommand($uuid), $policy);
    }

    /**
     * Submit the creation of a shipment and return the UUID without waiting; a repeat with the same
     * UUID is safe, see {@see self::submitOrder()}.
     *
     * @throws FurgonetkaApiValidationException when a local rule that Furgonetka enforces too is broken; nothing is sent.
     */
    public function submitCreatePackage(ShipmentRequest $request, ?string $uuid = null): string
    {
        $this->guard($request);
        $this->logger->info('Furgonetka: creating a shipment as a command', ['serviceId' => $request->serviceId, 'parcels' => count($request->parcels)]);

        return $this->submit(Endpoint::SubmitCreatePackage, $request->toArray(), $uuid, 'create shipment');
    }

    /**
     * Read the state of a shipment creation (`GET /create-package-command/{uuid}`) without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request (an unknown UUID is a 400 `commandNotExists`), the network fails or the answer cannot be read.
     */
    public function fetchCreatePackageCommand(string $uuid): CreatePackageCommandResult
    {
        return CreatePackageCommandResult::fromArray($this->json($this->request(Endpoint::FetchCreatePackageCommand, ['uuid' => self::uuid($uuid)]), 'the shipment creation status'));
    }

    // -- Files ----------------------------------------------------------------------------------------------------

    /**
     * Upload a file and wait until Furgonetka has stored it (`POST /file-command/{uuid}`). A shipment
     * refers to an uploaded file by UUID (`ShipmentRequest::$photos`, `ShipmentRequest::$filesUuids`,
     * `Duty::$documentsUuids`); the result carries both the UUID of the upload and the one of the
     * stored file. For photos (`type` `packagePhoto`, the one Furgonetka's own panel sends) a shipment takes the
     * stored file's UUID, see {@see FileUploadResult}.
     *
     * The upload is sent with your token although the specification lists it as public: a file
     * uploaded without it belongs to nobody and its status cannot be read afterwards (checked on the
     * sandbox). Storing succeeds for any file and any `type`, so a successful result says nothing
     * about whether a shipment will accept the file: a photo uploaded without the type `packagePhoto` was
     * refused by a shipment under both UUIDs.
     *
     * @param string      $filename    The file's name, for Furgonetka's records; unsafe characters are replaced.
     * @param string      $contents    The file's bytes; not empty.
     * @param string|null $type        What the file is: `packagePhoto` for a shipment photo (what the panel sends), `invoice` in the specification's example; the API does not list the values.
     * @param string|null $contentType Media type of the file; taken from the extension of `$filename` when null.
     * @param string|null $uuid        Your own UUID, to make a retry safe.
     *
     * @throws \InvalidArgumentException when the file is empty or the media type is malformed.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function uploadFile(string $filename, #[\SensitiveParameter] string $contents, ?string $type = null, ?string $contentType = null, ?string $uuid = null, ?PollingPolicy $policy = null): FileUploadResult
    {
        $uuid = $this->submitFileUpload($filename, $contents, $type, $contentType, $uuid);

        return $this->wait($uuid, fn (): FileUploadResult => $this->fetchFileUpload($uuid), $policy);
    }

    /**
     * Send a file and return the UUID of the upload without waiting; a repeat with the same UUID is
     * safe, see {@see self::submitOrder()}. Read the result with {@see self::fetchFileUpload()}.
     *
     * @throws \InvalidArgumentException when the file is empty or the media type is malformed.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the answer cannot be read.
     */
    public function submitFileUpload(string $filename, #[\SensitiveParameter] string $contents, ?string $type = null, ?string $contentType = null, ?string $uuid = null): string
    {
        if ($contents === '') {
            throw new \InvalidArgumentException('A file to upload must not be empty.');
        }

        $type = $type !== null && trim($type) !== '' ? trim($type) : null;
        $mediaType = $contentType !== null && trim($contentType) !== '' ? trim($contentType) : self::mediaTypeOf($filename);

        // The closure below is an argument of submitVia() and shows in the trace of any exception
        // thrown from there, with the variables it captured. `#[\SensitiveParameter]` does not reach
        // those, so the bytes are captured in a value that dumps and traces as empty.
        $file = new \SensitiveParameterValue($contents);

        return $this->submitVia(
            Endpoint::UploadFile,
            $uuid,
            'file upload',
            fn (string $id, ?string $token): TransportRequest => $this->builder->buildUpload(Endpoint::UploadFile, ['uuid' => $id], (string) $file->getValue(), $filename, $mediaType, $type, (string) $token),
        );
    }

    /**
     * Read the state of one upload (`GET /file-command`) without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer does not contain the upload.
     */
    public function fetchFileUpload(string $uuid): FileUploadResult
    {
        $uuid = self::uuid($uuid);
        $results = $this->fetchFileUploads([$uuid]);

        if (!isset($results[$uuid])) {
            $this->fail(new FurgonetkaApiResponseParseException("Furgonetka's answer about the file upload {$uuid} does not contain it."));
        }

        return $results[$uuid];
    }

    /**
     * Read the state of several uploads at once (`GET /file-command`, up to 50).
     *
     * **One UUID the account does not know fails the whole call**: Furgonetka answers 400
     * (`valueTypeNotValid` on `/uuid`) and names that UUID. That is also what happens to a file
     * uploaded without a token. The entries are not in the order you asked in, so they come keyed by UUID.
     *
     * @param list<string> $uuids The UUIDs of the uploads, 1 to 50.
     * @return array<string, FileUploadResult>
     *
     * @throws \InvalidArgumentException when there are none, more than 50, or one is malformed.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchFileUploads(array $uuids): array
    {
        if ($uuids === []) {
            throw new \InvalidArgumentException('At least one upload UUID is required.');
        }

        if (count($uuids) > 50) {
            throw new \InvalidArgumentException('At most 50 upload UUIDs can be read in one call.');
        }

        $response = $this->request(Endpoint::FetchFileCommands, [], ['uuid' => array_map(self::uuid(...), $uuids)]);
        $results = [];

        foreach (Value::objectList($this->json($response, 'the file uploads'), 'responses') as $entry) {
            $result = FileUploadResult::fromArray($entry);

            if ($result->uuid !== null) {
                $results[strtolower($result->uuid)] = $result;
            }
        }

        return $results;
    }

    // -- Address book -----------------------------------------------------------------------------------------

    /**
     * A page of the address book (`GET /account/address-book`), 50 entries a page.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function listAddressBook(?AddressBookQuery $query = null): AddressBookPage
    {
        $response = $this->request(Endpoint::ListAddressBook, [], ($query ?? new AddressBookQuery())->toArray());

        return AddressBookPage::fromArray($this->json($response, 'the address book'));
    }

    /**
     * One entry of the address book (`GET /account/address-book/{id}`). An entry that does not exist is
     * a 400 with the code `notExists`.
     *
     * @throws \InvalidArgumentException when the id is not a positive number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchAddressBookEntry(int|AddressBookEntry $id): AddressBookEntry
    {
        $response = $this->request(Endpoint::FetchAddressBookEntry, ['id' => self::addressBookId($id)]);

        return AddressBookEntry::fromArray($this->json($response, 'the address book entry'));
    }

    /**
     * Add an entry to the address book (`POST /account/address-book`). The answer is the entry as
     * Furgonetka stored it, normalised (phone with its prefix, city with its accents).
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the data (400 with the field errors), the network fails or the answer cannot be read.
     */
    public function createAddressBookEntry(AddressBookRequest $request): AddressBookEntry
    {
        $response = $this->request(Endpoint::CreateAddressBookEntry, [], [], $request->toArray());

        return AddressBookEntry::fromArray($this->json($response, 'the new address book entry'));
    }

    /**
     * Replace an entry (`PUT /account/address-book/{id}`). **It is replaced whole**: what the request
     * does not carry is emptied, which the sandbox showed by wiping the name, e-mail, address and phone
     * of an entry that was sent only a new company name. Start from the entry and change what you need:
     *
     *   $entry = $client->fetchAddressBookEntry(380861);
     *   $client->replaceAddressBookEntry($entry, $entry->toRequest()->with(['city' => 'Krakow']));
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the data or the entry (400), or the network fails.
     */
    public function replaceAddressBookEntry(int|AddressBookEntry $id, AddressBookRequest $request): void
    {
        $this->request(Endpoint::ReplaceAddressBookEntry, ['id' => self::addressBookId($id)], [], $request->toArray());
    }

    /**
     * Delete entries (`DELETE /account/address-book`). Furgonetka refuses the whole call when one id is
     * unknown or when the list is empty (400 on `/entries/0/id` or `/entries/0`).
     *
     * @param list<int|AddressBookEntry> $entries The entries or their ids; at least one.
     *
     * @throws \InvalidArgumentException when the list is empty or an id is not a positive number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request or the network fails.
     */
    public function deleteAddressBookEntries(array $entries): void
    {
        if ($entries === []) {
            throw new \InvalidArgumentException('At least one address book entry is required.');
        }

        $refs = array_map(static fn (int|AddressBookEntry $entry): array => ['id' => (int) self::addressBookId($entry)], $entries);

        $this->request(Endpoint::DeleteAddressBookEntries, [], [], ['entries' => $refs]);
    }

    /**
     * Ask Furgonetka whether it would take an entry (`POST /account/address-book/validate`). Nothing is
     * stored. The answer is the list of field errors, empty when the entry is fine, like
     * {@see self::validateRemotely()}.
     *
     * @return list<ApiError>
     *
     * @throws FurgonetkaApiException when the request fails for a reason other than the data, such as a refused token or the network.
     */
    public function validateAddressBookEntry(AddressBookRequest $request): array
    {
        [$sent, $response] = $this->exchange(Endpoint::ValidateAddressBookEntry, [], [], $request->toArray());

        if ($response->statusCode === 204) {
            return [];
        }

        if ($response->statusCode === 400) {
            return $this->errors->map($sent, $response)->errors;
        }

        $this->fail($this->errors->map($sent, $response), $response);
    }

    // -- CSV export, cart check, tracking by command, HS codes ---------------------------------------------

    /**
     * Export sent shipments as a CSV and wait for the file (`PUT /csv-command/{uuid}`). The result's `url`
     * is a signed link; {@see self::downloadCsvExport()} fetches it.
     *
     * @param CsvRange                 $range      Which shipments; `Selected` needs `$packageIds`.
     * @param list<string|int|Package> $packageIds The shipments of a `Selected` export.
     * @param string|null              $uuid       Your own UUID, to make a retry safe, see {@see self::submitOrder()}.
     *
     * @throws \InvalidArgumentException when `Selected` has no shipments, or an id is not a number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function exportPackagesCsv(CsvRange $range, array $packageIds = [], ?string $uuid = null, ?PollingPolicy $policy = null): CsvExportResult
    {
        $uuid = $this->submitCsvExport($range, $packageIds, $uuid);

        return $this->wait($uuid, fn (): CsvExportResult => $this->fetchCsvExport($uuid), $policy);
    }

    /**
     * Submit a CSV export and return the UUID without waiting; a repeat with the same UUID is safe, see
     * {@see self::submitOrder()}.
     *
     * @param list<string|int|Package> $packageIds
     *
     * @throws \InvalidArgumentException when `Selected` has no shipments, or an id is not a number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request or the network fails (the exception carries the UUID).
     */
    public function submitCsvExport(CsvRange $range, array $packageIds = [], ?string $uuid = null): string
    {
        if ($range === CsvRange::Selected && $packageIds === []) {
            throw new \InvalidArgumentException('A CSV export of the range "selected" needs the shipments it covers.');
        }

        $body = ['range' => $range->value];

        if ($packageIds !== []) {
            $body['packages'] = self::packageRefs($packageIds, 0, true);
        }

        return $this->submit(Endpoint::SubmitCsvExport, $body, $uuid, 'CSV export');
    }

    /**
     * Read the state of a CSV export (`GET /csv-command/{uuid}`) without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request (an unknown UUID is a 400 `commandNotExists`), the network fails or the answer cannot be read.
     */
    public function fetchCsvExport(string $uuid): CsvExportResult
    {
        return CsvExportResult::fromArray($this->json($this->request(Endpoint::FetchCsvExport, ['uuid' => self::uuid($uuid)]), 'the CSV export status'));
    }

    /**
     * Check the shipments in the cart again and wait for the verdict (`PUT /revalidate-command/{uuid}`).
     * `success` of the result says whether they pass. Furgonetka answers 400 with `packageOwner` to a
     * shipment that is not yours.
     *
     * @param list<string|int|Package> $packageIds The shipments; at least one.
     * @param string|null              $uuid       Your own UUID, to make a retry safe.
     *
     * @throws \InvalidArgumentException when there are none, or an id is not a number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function revalidatePackages(array $packageIds, ?string $uuid = null, ?PollingPolicy $policy = null): RevalidateResult
    {
        $uuid = $this->submitRevalidate($packageIds, $uuid);

        return $this->wait($uuid, fn (): RevalidateResult => $this->fetchRevalidate($uuid), $policy);
    }

    /**
     * Submit a cart check and return the UUID without waiting; a repeat with the same UUID is safe.
     *
     * @param list<string|int|Package> $packageIds
     *
     * @throws \InvalidArgumentException when there are none, or an id is not a number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request or the network fails (the exception carries the UUID).
     */
    public function submitRevalidate(array $packageIds, ?string $uuid = null): string
    {
        return $this->submit(Endpoint::SubmitRevalidate, ['packages' => self::packageRefs($packageIds, 0, true)], $uuid, 'cart check');
    }

    /**
     * Read the state of a cart check (`GET /revalidate-command/{uuid}`) without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchRevalidate(string $uuid): RevalidateResult
    {
        return RevalidateResult::fromArray($this->json($this->request(Endpoint::FetchRevalidate, ['uuid' => self::uuid($uuid)]), 'the cart check status'));
    }

    /**
     * Add a shipment to tracking as a command and wait (`PUT /add-package-to-tracking-command/{uuid}`).
     * The command works out the carrier when you leave it out, which took about five seconds on the
     * sandbox, and answers with the tracked shipment's id. A number that is already tracked ends as
     * `partial_success` with the code `package_already_exists_in_tracking`, not as an exception.
     *
     * @param string              $waybillNumber The carrier's waybill number.
     * @param Carrier|string|null $carrier       The carrier; without it Furgonetka works it out.
     * @param string|null         $name          A name of your own.
     * @param string|null         $uuid          Your own UUID, to make a retry safe.
     *
     * @throws \InvalidArgumentException when the number is empty or the carrier is not an identifier.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function addToTrackingAsync(string $waybillNumber, Carrier|string|null $carrier = null, ?string $name = null, ?string $uuid = null, ?PollingPolicy $policy = null): AddToTrackingCommandResult
    {
        $uuid = $this->submitAddToTracking($waybillNumber, $carrier, $name, $uuid);

        return $this->wait($uuid, fn (): AddToTrackingCommandResult => $this->fetchAddToTrackingCommand($uuid), $policy);
    }

    /**
     * Submit adding a shipment to tracking and return the UUID without waiting; a repeat with the same
     * UUID is safe.
     *
     * @throws \InvalidArgumentException when the number is empty or the carrier is not an identifier.
     * @throws FurgonetkaApiException when Furgonetka refuses the request or the network fails (the exception carries the UUID).
     */
    public function submitAddToTracking(string $waybillNumber, Carrier|string|null $carrier = null, ?string $name = null, ?string $uuid = null): string
    {
        $number = trim($waybillNumber);

        if ($number === '') {
            throw new \InvalidArgumentException('A waybill number must not be empty.');
        }

        $service = $carrier instanceof Carrier ? $carrier->value : trim((string) $carrier);

        if ($service !== '' && preg_match('/^[a-z][a-z0-9_]*$/D', $service) !== 1) {
            throw new \InvalidArgumentException('A carrier is an identifier such as "dpd", not "' . $service . '".');
        }

        $body = Payload::compact(['package_no' => $number, 'service' => $service !== '' ? $service : null, 'name' => $name]);

        return $this->submit(Endpoint::SubmitAddToTracking, $body, $uuid, 'add to tracking');
    }

    /**
     * Read the state of adding a shipment to tracking (`GET /add-package-to-tracking-command/{uuid}`)
     * without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchAddToTrackingCommand(string $uuid): AddToTrackingCommandResult
    {
        return AddToTrackingCommandResult::fromArray($this->json($this->request(Endpoint::FetchAddToTracking, ['uuid' => self::uuid($uuid)]), 'the tracking status'));
    }

    /**
     * Search the customs tariff for HS codes and wait for the list
     * (`PUT /packages/duty/hs-codes-autocomplete-command/{uuid}`). Needs no login. A carrier the account
     * may not use ends as an `error` result, not as an exception.
     *
     * @param HsCodeService|string $service     The carrier whose codes are searched; the API takes `meest` and `novapost`.
     * @param string               $description What the goods are.
     * @param string|null          $uuid        Your own UUID, to make a retry safe.
     *
     * @throws \InvalidArgumentException when the carrier is not an identifier.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function searchHsCodes(HsCodeService|string $service, string $description, ?string $uuid = null, ?PollingPolicy $policy = null): HsCodeSearchResult
    {
        $uuid = $this->submitHsCodeSearch($service, $description, $uuid);

        return $this->wait($uuid, fn (): HsCodeSearchResult => $this->fetchHsCodeSearch($uuid), $policy);
    }

    /**
     * Submit an HS code search and return the UUID without waiting; a repeat with the same UUID is safe.
     *
     * @throws \InvalidArgumentException when the carrier is not an identifier.
     * @throws FurgonetkaApiException when Furgonetka refuses the request or the network fails (the exception carries the UUID).
     */
    public function submitHsCodeSearch(HsCodeService|string $service, string $description, ?string $uuid = null): string
    {
        $name = $service instanceof HsCodeService ? $service->value : trim($service);

        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException('A carrier is an identifier such as "novapost", not "' . $name . '".');
        }

        return $this->submit(Endpoint::SubmitHsCodes, ['description' => $description, 'service' => $name], $uuid, 'HS code search');
    }

    /**
     * Read the state of an HS code search (`GET /packages/duty/hs-codes-autocomplete-command/{uuid}`)
     * without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchHsCodeSearch(string $uuid): HsCodeSearchResult
    {
        return HsCodeSearchResult::fromArray($this->json($this->request(Endpoint::FetchHsCodes, ['uuid' => self::uuid($uuid)]), 'the HS code search'));
    }

    // -- Notifications ------------------------------------------------------------------------------------------

    /**
     * The general notification options (`GET /account/settings/notification/general`).
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchGeneralNotifications(): GeneralNotifications
    {
        return GeneralNotifications::fromArray($this->json($this->request(Endpoint::FetchGeneralNotifications), 'the general notification settings'));
    }

    /**
     * The shipping notification settings, one group per tracking state
     * (`GET /account/settings/notification/shipping`).
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchShippingNotifications(): ShippingNotifications
    {
        return ShippingNotifications::fromArray($this->json($this->request(Endpoint::FetchShippingNotifications), 'the shipping notification settings'));
    }

    /**
     * Save the notification settings (`POST /account/settings/notification`). **This replaces all of
     * them**, the webhook included, so build the request from a read, see {@see NotificationSettingsRequest}.
     * It is logged; the webhook token is not.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the settings (400 with the field errors), the network fails, or a token is refused.
     */
    public function saveNotificationSettings(NotificationSettingsRequest $request): void
    {
        $this->logger->info('Furgonetka: saving the notification settings', ['webhook' => $request->webhookUrl !== null]);
        $this->request(Endpoint::SaveNotificationSettings, [], [], $request->toArray());
    }

    /**
     * Ask Furgonetka to call a webhook address and wait for the verdict
     * (`PUT /account/settings/notification/webhook/check-command/{uuid}`). Nothing is saved: the
     * address is only tried. An address that cannot be reached ends as `error` with no errors listed.
     *
     * @param string        $url    The address; Furgonetka refuses one that is not a URL (`regexNotMatch`).
     * @param string        $token  The checksum salt the notifications would be signed with; a secret.
     * @param WebhookFormat $format The format the notifications would come in.
     * @param string|null   $uuid   Your own UUID, to make a retry safe.
     *
     * @throws \InvalidArgumentException when the address or the token is empty.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function checkWebhook(string $url, #[\SensitiveParameter] string $token, WebhookFormat $format, ?string $uuid = null, ?PollingPolicy $policy = null): WebhookCommandResult
    {
        $uuid = $this->submitWebhookCheck($url, $token, $format, $uuid);

        return $this->wait($uuid, fn (): WebhookCommandResult => $this->fetchWebhookCheck($uuid), $policy);
    }

    /**
     * Submit a webhook check and return the UUID without waiting; a repeat with the same UUID is safe.
     *
     * @throws \InvalidArgumentException when the address or the token is empty.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (a bad address is a 400 on `/webhookUrl`) or the network fails (the exception carries the UUID).
     */
    public function submitWebhookCheck(string $url, #[\SensitiveParameter] string $token, WebhookFormat $format, ?string $uuid = null): string
    {
        if (trim($url) === '' || trim($token) === '') {
            throw new \InvalidArgumentException('A webhook check needs an address and a token.');
        }

        return $this->submit(Endpoint::SubmitWebhookCheck, ['webhookUrl' => $url, 'webhookToken' => $token, 'webhookFormat' => $format->value], $uuid, 'webhook check');
    }

    /**
     * Read the state of a webhook check (`GET .../webhook/check-command/{uuid}`) without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchWebhookCheck(string $uuid): WebhookCommandResult
    {
        return WebhookCommandResult::fromArray($this->json($this->request(Endpoint::FetchWebhookCheck, ['uuid' => self::uuid($uuid)]), 'the webhook check status'));
    }

    /**
     * Send a notification from the webhook log again and wait
     * (`PUT /account/settings/notification/webhook/resend-command/{uuid}`). Furgonetka answers 400 with
     * the code `invalid` on `/id` for a log entry it does not have.
     *
     * @param int|WebhookLog $log  The entry of {@see self::listWebhookLogs()} or its id.
     * @param string|null    $uuid Your own UUID, to make a retry safe.
     *
     * @throws \InvalidArgumentException when the id is not a positive number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails (the exception carries the UUID) or the command does not finish in time.
     */
    public function resendWebhook(int|WebhookLog $log, ?string $uuid = null, ?PollingPolicy $policy = null): WebhookCommandResult
    {
        $uuid = $this->submitWebhookResend($log, $uuid);

        return $this->wait($uuid, fn (): WebhookCommandResult => $this->fetchWebhookResend($uuid), $policy);
    }

    /**
     * Submit a resend and return the UUID without waiting; a repeat with the same UUID is safe.
     *
     * @throws \InvalidArgumentException when the id is not a positive number.
     * @throws FurgonetkaApiException when Furgonetka refuses the request (an unknown log entry is a 400 on `/id`) or the network fails (the exception carries the UUID).
     */
    public function submitWebhookResend(int|WebhookLog $log, ?string $uuid = null): string
    {
        $id = $log instanceof WebhookLog ? $log->id : $log;

        if ($id === null || $id < 1) {
            throw new \InvalidArgumentException('A webhook log entry needs a positive id.');
        }

        return $this->submit(Endpoint::SubmitWebhookResend, ['id' => $id], $uuid, 'webhook resend');
    }

    /**
     * Read the state of a resend (`GET .../webhook/resend-command/{uuid}`) without waiting.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchWebhookResend(string $uuid): WebhookCommandResult
    {
        return WebhookCommandResult::fromArray($this->json($this->request(Endpoint::FetchWebhookResend, ['uuid' => self::uuid($uuid)]), 'the webhook resend status'));
    }

    // -- Billing ------------------------------------------------------------------------------------------------------

    /**
     * The account's operations and its balance (`GET /account/balance`), newest first. See
     * {@see BalanceQuery} for the filters and what the sandbox showed about them.
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function fetchBalance(?BalanceQuery $query = null): Balance
    {
        $response = $this->request(Endpoint::FetchBalance, [], ($query ?? new BalanceQuery())->toArray());

        return Balance::fromArray($this->json($response, 'the balance'));
    }

    /**
     * The contracts of your own that the account has with carriers (`GET /account/services-agreements`).
     * Empty for an account that only uses Furgonetka's contracts. Use `serviceId` as the `service_id` of
     * a shipment.
     *
     * @return list<ServiceAgreement>
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function listServiceAgreements(): array
    {
        $response = $this->request(Endpoint::ListServiceAgreements);

        return array_map(ServiceAgreement::fromArray(...), Value::objectList($this->json($response, 'the service agreements'), 'services_agreements'));
    }

    /**
     * The changes of carriers' price lists that Furgonetka has announced
     * (`GET /account/planned-pricelist-changes`). Empty when none is planned.
     *
     * @return list<PricelistChange>
     *
     * @throws FurgonetkaApiException when Furgonetka refuses the request, the network fails or the answer cannot be read.
     */
    public function listPlannedPricelistChanges(): array
    {
        $response = $this->request(Endpoint::ListPlannedPricelistChanges);

        return array_map(PricelistChange::fromArray(...), Value::objectList($this->json($response, 'the planned price list changes'), 'pricelist_changes'));
    }

    // -- Rate limit ------------------------------------------------------------------------------------------

    /** The request quota reported with the last answer, or null before the first one. */
    public function lastRateLimit(): ?RateLimitStatus
    {
        return $this->rateLimit;
    }

    // -- Internals -------------------------------------------------------------------------------------------

    private function tokenManager(): TokenManager
    {
        return $this->tokens instanceof TokenManager
            ? $this->tokens
            : throw new \LogicException('This client keeps no tokens: build it with FurgonetkaApiClient::create() and a token store.');
    }

    private function accessToken(): string
    {
        return ($this->tokens ?? throw new \LogicException(
            'This client has no token source, so it can only call public endpoints. Give create() a token store or a token provider.'
        ))->accessToken();
    }

    /**
     * Send a request and return the answer whatever its status, after the 401 retry and the
     * rate limit bookkeeping.
     *
     * A 401 for an authenticated call means the token is refused, often because another process
     * refreshed it a moment ago; the provider is told and the request is repeated once with the
     * token that is current then. A provider that has no other token to offer (a static one) is not
     * asked, and a second 401 is left to the caller as it is.
     *
     * @param array<string, string|int>               $path
     * @param array<string, scalar|array<mixed>|null> $query
     * @param array<string, mixed>|null               $body
     * @return array{TransportRequest, TransportResponse}
     */
    private function exchange(Endpoint $endpoint, array $path = [], array $query = [], #[\SensitiveParameter] ?array $body = null): array
    {
        // The closure is an argument of exchangeWith(), so a trace shows what it captured: the body (names,
        // addresses, a webhook token) goes in a value that dumps and traces as empty.
        $sealed = new \SensitiveParameterValue($body);

        return $this->exchangeWith($endpoint, function (?string $token) use ($endpoint, $path, $query, $sealed): TransportRequest {
            /** @var array<string, mixed>|null $body */
            $body = $sealed->getValue();

            return $this->builder->build($endpoint, $path, $query, $body, $token);
        });
    }

    /**
     * {@see self::exchange()} for a request that {@see RequestBuilder::build()} does not build, such as an upload.
     *
     * @param \Closure(?string): TransportRequest $build Builds the request from the access token, which is null for a public operation. It is called again after a 401, with the new token.
     * @return array{TransportRequest, TransportResponse}
     */
    private function exchangeWith(Endpoint $endpoint, \Closure $build): array
    {
        $authenticated = $endpoint->auth() === EndpointAuth::User;

        for ($attempt = 0; ; $attempt++) {
            $request = $build($authenticated ? $this->accessToken() : null);

            try {
                $response = $this->transport->send($request);
            } catch (FurgonetkaApiTransportException $e) {
                $this->fail($e);
            }

            $this->noteRateLimit($response);

            if ($response->statusCode === 401 && $authenticated && $attempt === 0 && $this->tokens !== null && !$this->tokens instanceof StaticTokenProvider) {
                $this->logger->info('Furgonetka: the access token was refused, getting the current one and repeating the request', ['operation' => $endpoint->value]);
                $this->tokens->invalidate();

                continue;
            }

            return [$request, $response];
        }
    }

    /**
     * Send a request and return the answer of a success; an error status becomes an exception.
     *
     * @param array<string, string|int>               $path
     * @param array<string, scalar|array<mixed>|null> $query
     * @param array<string, mixed>|null               $body
     */
    private function request(Endpoint $endpoint, array $path = [], array $query = [], #[\SensitiveParameter] ?array $body = null): TransportResponse
    {
        [$request, $response] = $this->exchange($endpoint, $path, $query, $body);

        if (!$response->isSuccess()) {
            $this->fail($this->errors->map($request, $response), $response);
        }

        return $response;
    }

    /**
     * Submit a command under a UUID and return the UUID.
     *
     * A UUID the caller passes marks a retry, so the answer "operation already exists" (400,
     * `commandExists`) is taken as "already submitted" and the UUID is returned: the command is
     * there and only has to be read. A UUID generated here cannot collide, so for it that answer is
     * an error like any other.
     *
     * @param array<string, mixed> $body
     */
    private function submit(Endpoint $endpoint, #[\SensitiveParameter] array $body, ?string $uuid, string $what): string
    {
        $sealed = new \SensitiveParameterValue($body);

        return $this->submitVia(
            $endpoint,
            $uuid,
            $what,
            function (string $id, ?string $token) use ($endpoint, $sealed): TransportRequest {
                /** @var array<string, mixed> $body */
                $body = $sealed->getValue();

                return $this->builder->build($endpoint, ['uuid' => $id], [], $body, $token);
            },
        );
    }

    /**
     * {@see self::submit()} for a request that is not JSON, such as an upload.
     *
     * @param \Closure(string, ?string): TransportRequest $build Builds the request from the command UUID and the access token.
     */
    private function submitVia(Endpoint $endpoint, ?string $uuid, string $what, \Closure $build): string
    {
        $retry = $uuid !== null;
        $uuid = $uuid === null ? Uuid::v4() : self::uuid($uuid);

        try {
            [$request, $response] = $this->exchangeWith($endpoint, static fn (?string $token): TransportRequest => $build($uuid, $token));
        } catch (FurgonetkaApiTransportException $e) {
            throw $e->withCommandUuid($uuid);
        }

        if ($response->isSuccess()) {
            $this->logger->info("Furgonetka: {$what} command submitted", ['uuid' => $uuid]);

            return $uuid;
        }

        $error = $this->errors->map($request, $response);

        if ($retry && $error instanceof FurgonetkaApiBadRequestException && $error->hasErrorCode('commandExists')) {
            $this->logger->info("Furgonetka: the {$what} command already exists, reading it instead of submitting again", ['uuid' => $uuid]);

            return $uuid;
        }

        $this->fail($error, $response);
    }

    /**
     * Wait for a command with the given policy, or the client's default.
     *
     * @template TResult of CommandResult
     * @param \Closure(): TResult $read
     * @return TResult
     */
    private function wait(string $uuid, \Closure $read, ?PollingPolicy $policy): CommandResult
    {
        return $this->runner->wait($uuid, $read, $policy ?? $this->polling);
    }

    /**
     * Decode a JSON object answer; a body that is not one is a parse error.
     *
     * @return array<string, mixed>
     */
    private function json(TransportResponse $response, string $what): array
    {
        try {
            return $this->parser->decode($response->body, $what);
        } catch (FurgonetkaApiResponseParseException $e) {
            $this->fail($e, $response);
        }
    }

    private function noteRateLimit(TransportResponse $response): void
    {
        $status = $this->rateLimits->read($response);

        if ($status === null) {
            return;
        }

        $this->rateLimit = $status;

        if ($status->isLow()) {
            $this->logger->warning('Furgonetka: the request limit is nearly used up', ['remaining' => $status->remaining, 'limit' => $status->limit]);
        }
    }

    /**
     * Throw when a local rule that Furgonetka enforces too is broken, and log the warnings.
     *
     * @throws FurgonetkaApiValidationException
     */
    private function guard(ShipmentRequest $request): void
    {
        $errors = $this->validateLocally($request);

        if ($errors !== []) {
            $exception = new FurgonetkaApiValidationException($errors);
            $this->logger->error($exception->getMessage());

            throw $exception;
        }

        foreach ($this->warningsFor($request) as $warning) {
            $this->logger->warning('Furgonetka: ' . $warning);
        }
    }

    /**
     * The carrier and the owner of the request's service: the carrier from the argument or the
     * remembered service list, the owner from the list.
     *
     * @return array{?Carrier, ?AgreementOwner}
     */
    private function contextOf(ShipmentRequest $request, ?Carrier $carrier): array
    {
        $service = $this->services?->find($request->serviceId);

        return [$carrier ?? $service?->carrier, $service?->owner];
    }

    /**
     * The one place an exception leaves the client: in debug mode the redacted raw answer is
     * attached and the full report is logged, otherwise only the message.
     */
    private function fail(FurgonetkaApiException $exception, ?TransportResponse $response = null): never
    {
        if ($response !== null && $this->config->debug) {
            $exception->withRawResponse($response->redacted()->body);
        }

        $this->logger->error($this->config->debug ? $exception->getDebugReport() : $exception->getMessage());

        throw $exception;
    }

    /**
     * Submit a command whose answer is more than the UUID (a return or a readdressing) and return
     * that answer. A `commandExists` is not taken as "already submitted" here: the answer of the
     * first request, with its links, cannot be read again.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function submitForAnswer(Endpoint $endpoint, #[\SensitiveParameter] array $body, ?string $uuid, string $what): array
    {
        $uuid = $uuid === null ? Uuid::v4() : self::uuid($uuid);

        try {
            $response = $this->request($endpoint, ['uuid' => $uuid], [], $body);
        } catch (FurgonetkaApiTransportException $e) {
            throw $e->withCommandUuid($uuid);
        }

        $this->logger->info("Furgonetka: {$what} requested", ['uuid' => $uuid]);

        return $this->json($response, $what);
    }

    /** The timestamp of a regulation to accept, as text; null when there is none. */
    private static function regulationsAccept(string|int|null $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** The media type a file name suggests; a generic one when the extension is not a common one. */
    private static function mediaTypeOf(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /** The id of a shipment as the digits Furgonetka gave it, for the endpoints that refuse a waybill number. */
    private static function numericPackageId(string|int|Package $package): string
    {
        $id = self::packageId($package);

        if (!ctype_digit($id)) {
            throw new \InvalidArgumentException('A shipment id here is the number Furgonetka gave it (`package_id`), not a waybill number.');
        }

        return $id;
    }

    /** The id of an address book entry as a positive number, for the path and the delete body. */
    private static function addressBookId(int|AddressBookEntry $entry): int
    {
        $id = $entry instanceof AddressBookEntry ? $entry->id : $entry;

        if ($id === null || $id < 1) {
            throw new \InvalidArgumentException('An address book entry id must be a positive number.');
        }

        return $id;
    }

    private static function packageId(string|int|Package $package): string
    {
        $id = $package instanceof Package ? ($package->id ?? '') : trim((string) $package);

        if ($id === '') {
            throw new \InvalidArgumentException('A shipment id must not be empty.');
        }

        return $id;
    }

    /**
     * The `{"id": "..."}` entries a command or a bulk operation takes.
     *
     * @param list<string|int|Package> $packages
     * @return list<array{id: string}>
     */
    private static function packageRefs(array $packages, int $max = 0, bool $requireDigits = false): array
    {
        if ($packages === []) {
            throw new \InvalidArgumentException('At least one shipment is required.');
        }

        if ($max > 0 && count($packages) > $max) {
            throw new \InvalidArgumentException("At most {$max} shipments can be sent in one call.");
        }

        $refs = [];

        foreach ($packages as $package) {
            $id = self::packageId($package);

            if ($requireDigits && !ctype_digit($id)) {
                throw new \InvalidArgumentException('A shipment id here is the number Furgonetka gave it (`package_id`), not a waybill number.');
            }

            $refs[] = ['id' => $id];
        }

        return $refs;
    }

    private static function uuid(string $uuid): string
    {
        if (!Uuid::isValid($uuid)) {
            throw new \InvalidArgumentException('A command UUID must be in the form 8b881c52-6473-467a-a7c9-d0aa4c4f3bec.');
        }

        return strtolower($uuid);
    }
}
