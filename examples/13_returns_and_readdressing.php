<?php

declare(strict_types=1);

/**
 * Returns, readdressing and the "send to me" link.
 *
 *   php examples/13_returns_and_readdressing.php [package-id]
 *   php examples/13_returns_and_readdressing.php <package-id> --return         # asks for the return
 *   php examples/13_returns_and_readdressing.php <package-id> --readdress      # asks for another receiver
 *
 * Both --return and --readdress order and charge, so without them the script only asks for prices. Without an
 * id it takes the newest ordered shipment.
 *
 * READ THIS FIRST: this part of the API has not been seen accepting anything. The Furgonetka sandbox answers
 * 400 `notPossible` ("This package cannot be returned / readdressed") for every shipment we tried, so the
 * successful answers of these calls are built from the specification and marked as such in the tests. Expect
 * a `notPossible` here unless your account has a shipment that Furgonetka allows to be returned.
 *
 * What it shows:
 *   - calculateReturnPrice() and calculateReaddressingPrice() give a price (PriceEstimate); a price that needs
 *     individual pricing has no number, see requiresIndividualPricing();
 *   - returnPackage() and readdressPackage() do not wait for the payment: they return the payment links
 *     (PaymentRequest) at once. A repeat under the same UUID is refused (`commandExists`), because the links
 *     cannot be read a second time, so keep the first answer;
 *   - fetchReaddressing() reads the state and cancelReaddressing() withdraws the order;
 *   - createSendToMeLink() builds a link to Furgonetka's shipment form with the recipient's data filled in,
 *     which a shop can put in an e-mail to a customer who should send something back.
 */

require __DIR__ . '/bootstrap.php';

use VeryCodeCom\FurgonetkaApi\Dto\ReaddressingReceiver;
use VeryCodeCom\FurgonetkaApi\Dto\SendToMeRequest;
use VeryCodeCom\FurgonetkaApi\Exception\FurgonetkaApiBadRequestException;

exampleRun(static function (): void {
    $client = exampleClient();
    $id = exampleArgument(1) ?? exampleLatestOrdered($client)->id ?? throw new \RuntimeException('The account has no ordered shipment.');
    $receiver = new ReaddressingReceiver('31-021', 'Krakow', 'Florianska 15', '600700800', name: 'Piotr Zielinski', email: 'piotr@example.com');

    exampleHeading("Prices for shipment {$id}");
    foreach (['return' => static fn () => $client->calculateReturnPrice($id), 'readdressing' => static fn () => $client->calculateReaddressingPrice($id, $receiver)] as $what => $ask) {
        try {
            $estimate = $ask();
            echo ucfirst($what) . ': ' . ($estimate->requiresIndividualPricing() ? 'priced individually' : sprintf('%.2f PLN', (float) $estimate->price)) . PHP_EOL;
        } catch (FurgonetkaApiBadRequestException $e) {
            echo ucfirst($what) . ': ' . ($e->hasErrorCode('notPossible') ? 'not possible for this shipment (Furgonetka: contact customer service)' : $e->getMessage()) . PHP_EOL;
        }
    }

    if (exampleFlag('--return')) {
        exampleHeading('Return');
        $payment = $client->returnPackage($id);
        echo "Pay here: {$payment->paymentUrl}\nSummary: {$payment->summaryUrl}\n";
    }

    if (exampleFlag('--readdress')) {
        exampleHeading('Readdressing');
        $payment = $client->readdressPackage($id, $receiver);
        echo "Pay here: {$payment->paymentUrl}\n";
        $state = $client->fetchReaddressing((string) $payment->uuid);
        echo 'State: ' . ($state->details->status->value ?? $state->details->statusValue ?? $state->statusValue ?? '?') . PHP_EOL;
    }

    exampleHeading('A "send to me" link');
    $link = $client->createSendToMeLink(new SendToMeRequest(
        email: 'customer@example.com',
        phone: '500200300',
        countryCode: 'PL',
        name: 'Anna Nowak',
        street: 'Florianska 15',
        postcode: '31-021',
        city: 'Krakow',
    ));
    echo "Send it to the customer: {$link->url}\n";
});
