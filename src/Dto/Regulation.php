<?php

declare(strict_types=1);

namespace VeryCodeCom\FurgonetkaApi\Dto;

use VeryCodeCom\FurgonetkaApi\Internal\Json\Value;

/**
 * A carrier's regulations, or another consent the account can give (`GET /regulations`).
 *
 * Furgonetka refuses to order or book a pickup for a carrier whose current regulations the account
 * has not accepted (HTTP 409, `terms_and_conditions_not_valid`). **This library never accepts
 * anything for you**: read what is pending, show {@see self::$documents} to whoever has the
 * authority to agree, and pass the ones they accepted to `acceptRegulations()`.
 *
 * `service` is a string on purpose: the list holds thirty-odd more kinds than the specification's
 * enum (`dpd_international`, `portmonetka`, `allegro_finanse`, ...).
 */
final class Regulation
{
    /**
     * @param string|null               $service          Kind of regulation, by the carrier or service it is about.
     * @param string|null               $name             Name.
     * @param string|null               $content          Text to show, which can contain HTML links.
     * @param int|null                  $version          Version; a new version needs a new acceptance.
     * @param \DateTimeImmutable|null   $datetime         When this version took (or takes) effect.
     * @param list<RegulationDocument>  $documents        The documents to read before accepting.
     * @param bool|null                 $accepted         Whether the account has accepted this version.
     * @param bool|null                 $withdrawable     Whether the consent can be withdrawn.
     * @param \DateTimeImmutable|null   $datetimeAccepted When it was accepted.
     * @param bool|null                 $marketing        True for a marketing consent, which no shipment depends on.
     * @param string|null               $info             Extra information.
     * @param string|null               $datetimeValue    `datetime` as sent, which an acceptance must send back unchanged.
     */
    public function __construct(
        public readonly ?string $service = null,
        public readonly ?string $name = null,
        public readonly ?string $content = null,
        public readonly ?int $version = null,
        public readonly ?\DateTimeImmutable $datetime = null,
        public readonly array $documents = [],
        public readonly ?bool $accepted = null,
        public readonly ?bool $withdrawable = null,
        public readonly ?\DateTimeImmutable $datetimeAccepted = null,
        public readonly ?bool $marketing = null,
        public readonly ?string $info = null,
        public readonly ?string $datetimeValue = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            service: Value::string($data, 'service'),
            name: Value::string($data, 'name'),
            content: Value::string($data, 'content'),
            version: Value::int($data, 'version'),
            datetime: Value::dateTime($data, 'datetime'),
            documents: array_map(RegulationDocument::fromArray(...), Value::objectList($data, 'documents')),
            accepted: Value::bool($data, 'accepted'),
            withdrawable: Value::bool($data, 'withdrawable'),
            datetimeAccepted: Value::dateTime($data, 'datetime_accepted'),
            marketing: Value::bool($data, 'marketing'),
            info: Value::string($data, 'info'),
            datetimeValue: Value::string($data, 'datetime'),
        );
    }

    /**
     * The entry of an acceptance request for this exact version (`service`, `name`, `version` and
     * `datetime` as Furgonetka sent them, `accepted` true).
     *
     * @return array<string, mixed>
     * @throws \LogicException when the regulation lacks a field an acceptance needs.
     */
    public function toAcceptance(): array
    {
        if ($this->service === null || $this->version === null || $this->datetimeValue === null) {
            throw new \LogicException('This regulation has no service, version or datetime, so it cannot be accepted.');
        }

        return [
            'service' => $this->service,
            'name' => $this->name ?? $this->service,
            'version' => $this->version,
            'datetime' => $this->datetimeValue,
            'accepted' => true,
        ];
    }
}
