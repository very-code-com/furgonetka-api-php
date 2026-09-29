<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Enum\TrackingState;
use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * One call Furgonetka made to the account's webhook: what it sent it about, and what came back.
 *
 * `httpCode` and `webhookResponse` are the answer of the receiver, so a call that did not get the
 * `200` and `OK` Furgonetka expects shows up here.
 */
final class WebhookLog
{
    /**
     * @param int|null                $id                Id of the log entry; the cursor of the next page.
     * @param \DateTimeImmutable|null $datetimeSend      When Furgonetka made the call.
     * @param string|null             $packageNo         Waybill number the notification was about.
     * @param int|null                $httpCode          HTTP status the receiver answered with.
     * @param TrackingState|null      $trackingState     State that was notified; null when it is not a member.
     * @param string|null             $trackingStateValue State as sent.
     * @param string|null             $trackingStateName State in words.
     * @param float|null              $timeout           How long the call took.
     * @param string|null             $webhookResponse   The receiver's answer, up to 1000 characters.
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?\DateTimeImmutable $datetimeSend = null,
        public readonly ?string $packageNo = null,
        public readonly ?int $httpCode = null,
        public readonly ?TrackingState $trackingState = null,
        public readonly ?string $trackingStateValue = null,
        public readonly ?string $trackingStateName = null,
        public readonly ?float $timeout = null,
        public readonly ?string $webhookResponse = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Value::int($data, 'id'),
            datetimeSend: Value::dateTime($data, 'datetimeSend'),
            packageNo: Value::string($data, 'packageNo'),
            httpCode: Value::int($data, 'httpCode'),
            trackingState: Value::enum($data, 'trackingState', TrackingState::class),
            trackingStateValue: Value::string($data, 'trackingState'),
            trackingStateName: Value::string($data, 'trackingStateName'),
            timeout: Value::float($data, 'timeout'),
            webhookResponse: Value::string($data, 'webhookResponse'),
        );
    }
}
