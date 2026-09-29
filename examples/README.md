# Examples

Eighteen runnable scripts, one topic each, and a [cookbook](recipes) of about two hundred small recipes. They are
documentation: read the comment at the top of a script before you run it, because it says what the script does to your
account.

## Before you run anything

The scripts read their settings from the environment (the library itself never reads `.env` files):

```bash
export FURGONETKA_CLIENT_ID=...
export FURGONETKA_CLIENT_SECRET=...
export FURGONETKA_ENV=sandbox          # sandbox or production; production is the default
```

Then log in once, which stores a token in a file (`FURGONETKA_TOKEN_FILE`, or a file in the temporary directory
named after the environment):

```bash
php examples/02_password_grant_and_2fa.php
```

Every password login is one of the 20 OAuth sessions Furgonetka allows per application and user. Run it once, not in
a loop. `01_authorize_user.php` does the same for an application that connects other people's accounts.

**Use the sandbox.** The scripts that order, book or cancel are dry unless you give `--order` (or a similar flag), and
`04_create_and_order.php` refuses `--order` on production without `--i-mean-production`. They still act on your
account when you do.

`bootstrap.php` is shared by the scripts; it is not part of the library.

## The scripts

| Script | Shows | Network | Changes anything |
|--------|-------|---------|------------------|
| `01_authorize_user.php` | the authorization code flow: a button, the callback, `state`, storing the token; run as `php -S localhost:8080 examples/01_authorize_user.php` | sandbox or production | stores a token |
| `02_password_grant_and_2fa.php` | connecting your own account once from a terminal, with two-factor | sandbox or production | stores a token, opens a session |
| `03_compare_carriers.php` | prices of several carriers, `available: false`, `cheapest()` | yes | no |
| `04_create_and_order.php` | validate, price, create, regulations, order, label | yes | with `--order` |
| `05_courier_pickup.php` | pickup proposals, booking, cancelling | yes | with `--order`, `--cancel` |
| `06_documents_and_protocol.php` | labels in A4 and A6, documents for many shipments, the protocol | yes | no (writes files) |
| `07_tracking.php` | the history of a shipment, following other packages, simulation | yes | with `--simulate` (sandbox) |
| `08_pickup_points.php` | point search, addressing a shipment to a point | yes, no login | no |
| `09_di_and_testing.php` | a scripted transport, a PSR-3 logger, redaction of secrets | **offline** | no |
| `10_validation_and_errors.php` | local rules, warnings, every exception class | **offline** | no |
| `11_webhook_endpoint.php` | receiving and verifying notifications, the reply; also runs as `php -S ... examples/11_webhook_endpoint.php` | **offline** | no |
| `12_label_sender.php` | other data on the label and its consequences for returns | yes | with `--create` (deleted again) |
| `13_returns_and_readdressing.php` | prices and orders of returns and readdressing, the "send to me" link | yes | with `--return`, `--readdress` |
| `14_address_book.php` | list, add, change, delete, validate | yes | adds and deletes its own entry |
| `15_async_create_csv_and_files.php` | commands: creation, CSV export, cart re-check, HS codes, upload | yes | creates and deletes a shipment; leaves an uploaded file |
| `16_notifications_and_billing.php` | notification settings, the webhook, the balance | yes | with `--save` |
| `17_background_commands.php` | commands in a queue or cron job: your own UUID, a safe retry, reading later, aborting | yes | `submit` prepares documents; `submit --order` orders (sandbox only) |
| `18_manage_shipments.php` | companies, changing and deleting waiting shipments, regulations, the confirmation e-mail, tracking packages from elsewhere | yes | creates and deletes two shipments; with `--send-email`, `--track` (for good), `--rename` |

The three offline scripts (09, 10 and 11) need neither keys nor a network, and CI runs them. The others were run
against the sandbox for 1.0.0 (01 through the browser flow).

Returns and readdressing (13) have not been seen succeeding: the sandbox refuses them for every shipment, so expect
"not possible" there.

## The cookbook

[recipes](recipes) holds one file per scenario: a shipment for one carrier and one special thing (a service, a parcel
type, a country, a point, a customs declaration) or one read-only query. Shipment recipes are validated and priced
on the sandbox, never created. About a third show a refusal and name its error code. `php examples/recipes/run.php`
lists them; the [cookbook README](recipes/README.md) explains how they are written and run.
