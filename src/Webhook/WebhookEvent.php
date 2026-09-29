<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Webhook;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Enum\WebhookFormat;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiResponseParseException;
use VeryCodeCom\FurgonetkaApi\Internal\Json\ResponseParser;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A tracking notification Furgonetka posted to the account's webhook address, JSON or XML.
 *
 *   $event = WebhookEvent::fromJson(file_get_contents('php://input'));
 *   $reply = $event->acknowledgement($event->verify($salt));
 *
 *   http_response_code($reply->statusCode);
 *   header('Content-Type: ' . $reply->contentType);
 *   echo $reply->body;
 *
 * Where the shape comes from: Furgonetka's webhook documentation, checked on the sandbox with a real
 * JSON notification and real XML and JSON address checks. The notification does not come at once: on
 * the sandbox the call arrived about a minute after the change, and five simulated changes in a row
 * produced one call, for the last state.
 *
 * `verify()` recomputes the checksum with the account's webhook token, so an address that is public
 * can tell Furgonetka's calls from anyone else's. The checksum is Furgonetka's own md5 of the
 * fields and the token, not a signature scheme, so treat the notification as a hint to ask
 * `fetchTracking()` when the state matters (money, stock), not as the record.
 *
 * Nothing here is a secret: the token is passed to `verify()` and never kept.
 */
final class WebhookEvent
{
    /** Fields of the notification, in the order the checksum takes them (see {@see self::verify()}). */
    private const CHECKSUM_TOP = ['package_id', 'package_no', 'partner_order_id'];

    private const CHECKSUM_TRACKING = ['state', 'description', 'datetime', 'branch'];

    /**
     * @param WebhookFormat           $format         Format the notification came in, which the reply has to match.
     * @param string|null             $packageId      Furgonetka's id of the shipment (what `fetchTracking()` takes).
     * @param string|null             $packageNo      Waybill number.
     * @param string|null             $partnerOrderId The shipment's `order_number` ("12" on a sandbox shipment whose `user_reference_number` is null); null when empty.
     * @param TrackingState|null      $state          New state; null when it is not a member.
     * @param string|null             $stateValue     State as sent.
     * @param string|null             $description    The carrier's wording of the event, in Polish; show it, never match on it.
     * @param \DateTimeImmutable|null $datetime       When the event happened, read as Polish time.
     * @param string|null             $branch         Depot; often empty.
     * @param string|null             $control        The checksum as sent.
     * @param array<string, mixed>    $raw            The notification as decoded; the checksum is computed from these values.
     */
    private function __construct(
        public readonly WebhookFormat $format,
        public readonly ?string $packageId,
        public readonly ?string $packageNo,
        public readonly ?string $partnerOrderId,
        public readonly ?TrackingState $state,
        public readonly ?string $stateValue,
        public readonly ?string $description,
        public readonly ?\DateTimeImmutable $datetime,
        public readonly ?string $branch,
        public readonly ?string $control,
        private readonly array $raw,
    ) {
    }

    /**
     * @throws FurgonetkaApiResponseParseException when the body is not a JSON object.
     */
    public static function fromJson(string $body): self
    {
        return self::fromArray((new ResponseParser())->decode($body, 'a webhook notification'), WebhookFormat::Json);
    }

    /**
     * Only a `Notification` document without a DOCTYPE is taken: Furgonetka sends none, and refusing it
     * closes the door on entity tricks from whoever else can reach a public address. The check reads the
     * parsed document back (`asXML()` writes a DOCTYPE whatever encoding it came in), not the text, which
     * a UTF-7 or UTF-16 body would slip past. Nothing is fetched and no entity is expanded while parsing.
     *
     * @throws FurgonetkaApiResponseParseException when the body is not well-formed XML with a `Notification` root and no DOCTYPE.
     */
    public static function fromXml(string $body): self
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false || $document->getName() !== 'Notification') {
            throw new FurgonetkaApiResponseParseException('A webhook notification in XML must be well-formed and have a Notification root element.');
        }

        if (str_contains((string) $document->asXML(), '<!DOCTYPE')) {
            throw new FurgonetkaApiResponseParseException('A webhook notification in XML must not carry a DOCTYPE or an entity declaration.');
        }

        return self::fromArray(self::elementToArray($document), WebhookFormat::Xml);
    }

    /**
     * For a body a framework has decoded already (`$request->toArray()`). XML values are all text, so
     * pass the format the notification really came in: the reply depends on it.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, WebhookFormat $format = WebhookFormat::Json): self
    {
        $tracking = Value::object($payload, 'tracking') ?? [];

        return new self(
            format: $format,
            packageId: Value::string($payload, 'package_id'),
            packageNo: Value::string($payload, 'package_no'),
            partnerOrderId: Value::string($payload, 'partner_order_id'),
            state: Value::enum($tracking, 'state', TrackingState::class),
            stateValue: Value::string($tracking, 'state'),
            description: Value::string($tracking, 'description'),
            datetime: Value::dateTime($tracking, 'datetime'),
            branch: Value::string($tracking, 'branch'),
            control: Value::string($payload, 'control'),
            raw: $payload,
        );
    }

    /**
     * Whether the checksum is the one Furgonetka computes with this token:
     * `md5(package_id . package_no . partner_order_id . state . description . datetime . branch . token)`,
     * over the values as they came, empty for a missing one. Confirmed on a real notification and on
     * the example of the documentation.
     *
     * An account without a token has a checksum without one: pass an empty string. That only catches a
     * damaged notification, nobody who can guess the fields is kept out.
     */
    public function verify(#[\SensitiveParameter] string $token): bool
    {
        if ($this->control === null) {
            return false;
        }

        $tracking = Value::object($this->raw, 'tracking') ?? [];
        $text = '';

        foreach (self::CHECKSUM_TOP as $key) {
            $text .= self::text($this->raw, $key);
        }

        foreach (self::CHECKSUM_TRACKING as $key) {
            $text .= self::text($tracking, $key);
        }

        return hash_equals(md5($text . $token), $this->control);
    }

    /**
     * The answer to send back, in the format the notification came in. Pass `false` for a notification
     * that failed `verify()`: Furgonetka logs the answer, and the reply then says `ERROR`.
     */
    public function acknowledgement(bool $accepted = true): WebhookReply
    {
        $status = $accepted ? 'OK' : 'ERROR';

        return match ($this->format) {
            WebhookFormat::Json => new WebhookReply('{"status":"' . $status . '"}', 'application/json'),
            WebhookFormat::Xml => new WebhookReply('<?xml version="1.0" encoding="UTF-8"?><status>' . $status . '</status>', 'text/xml'),
        };
    }

    /**
     * The notification as decoded: the JSON object, or the XML elements as nested arrays of text.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Child elements as an array: an element with children becomes an array, any other one its text.
     * Text is kept exactly as written (the checksum covers it); a repeated element keeps the last.
     *
     * @return array<string, mixed>
     */
    private static function elementToArray(\SimpleXMLElement $element): array
    {
        $data = [];

        foreach ($element->children() as $name => $child) {
            $data[$name] = $child->count() > 0 ? self::elementToArray($child) : (string) $child;
        }

        return $data;
    }
}
