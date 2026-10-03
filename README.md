# NOWPayments Module for WHMCS 8.x / 9.x

This package is a compatibility and security rebuild of the original 2019 NOWPayments WHMCS gateway for modern WHMCS 8/9 and PHP 8.x environments.

## Disclaimer

This is an independent, community-maintained compatibility rebuild of the NOWPayments WHMCS gateway.

It is **not** an official NOWPayments product.

NOWPayments and WHMCS are trademarks of their respective owners.

This project is provided as-is without warranty. Review the applicable upstream software licensing terms before redistributing or relicensing code derived from the original gateway.

## Architecture

This is an **invoice-driven** cryptocurrency payment gateway.

WHMCS remains responsible for products, billing cycles, renewals, invoice totals, coupons, taxes, and service provisioning. NOWPayments is used to create the hosted payment invoice and process the cryptocurrency payment.

The NOWPayments API key remains server-side.

A hosted NOWPayments invoice is created through:

```text
POST https://api.nowpayments.io/v1/invoice
```

The API request is made only after the customer clicks **Pay Now**.

The module automatically sends the WHMCS callback URL when creating the NOWPayments invoice:

```text
https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/nowpayments.php
```

Payment status updates are received through NOWPayments IPN callbacks.

WHMCS is credited only after NOWPayments reports the payment status as:

```text
finished
```

Intermediate statuses such as `partially_paid`, `waiting`, `confirming`, `confirmed`, and `sending` do **not** mark the WHMCS invoice as Paid.

## Environment

The module is designed for:

* WHMCS 8.x
* WHMCS 9.0
* PHP 8.2
* PHP 8.3

The code intentionally avoids Composer and other third-party PHP dependencies.

## Installation

Upload the contents of the included `modules/` directory into the matching WHMCS `modules/` directory while preserving the paths:

* `modules/gateways/nowpayments.php`
* `modules/gateways/nowpayments/lib.php`
* `modules/gateways/nowpayments/pay.php`
* `modules/gateways/nowpayments/logo.png`
* `modules/gateways/nowpayments/whmcs.json`
* `modules/gateways/callback/nowpayments.php`

Then configure the gateway in WHMCS:

1. Go to **Configuration / System Settings > Payment Gateways**. The exact wording may vary depending on the WHMCS theme or version.
2. Activate **NOWPayments**.
3. Enter the **API Key** from the NOWPayments dashboard.
4. Enter the **IPN Secret** from the NOWPayments dashboard.
5. Confirm that the WHMCS **System URL** is correct and uses HTTPS.
6. Save the gateway configuration.

No Composer package or vendor directory is required.

## Testing

Create a low-value WHMCS invoice and complete one real payment.

Confirm all of the following:

1. The customer is redirected to the hosted NOWPayments invoice.
2. The payment appears correctly in the NOWPayments dashboard.
3. WHMCS **Gateway Log** receives the NOWPayments status callbacks.
4. Intermediate payment statuses do not mark the WHMCS invoice as Paid.
5. After NOWPayments reports `finished`, the WHMCS invoice becomes Paid.
6. The payment is applied to the WHMCS invoice exactly once.
7. A duplicate or retried IPN does not create a second payment.

If status callbacks do not arrive, verify that:

* The callback URL is publicly reachable over HTTPS.
* Cloudflare rules are not blocking NOWPayments.
* Server firewall rules allow NOWPayments webhook requests.

## Security

The module includes several changes to improve compatibility and payment integrity compared with the original gateway:

* Uses WHMCS gateway API metadata version `1.1`, the currently documented third-party gateway API version.
* Keeps the NOWPayments API key server-side instead of exposing it through a browser URL.
* Uses the current WHMCS callback workflow:

  * `checkCbInvoiceID`
  * `checkCbTransID`
  * `logTransaction`
  * `addInvoicePayment`
* Reads JSON IPN request bodies instead of relying on `$_POST`.
* Performs deep-key HMAC-SHA512 verification of `X-NOWPAYMENTS-SIG`.
* Preserves original JSON number tokens during IPN canonicalisation to avoid PHP float or scientific-notation signature mismatches.
* Uses constant-time signature comparison through `hash_equals()`.
* Uses the NOWPayments `payment_id` with WHMCS duplicate-transaction protection.
* Keeps HTTPS and cURL certificate verification enabled.
* Uses reasonable API request timeouts.

## Behavior

The module deliberately does **not** credit the WHMCS invoice when NOWPayments reports:

* `partially_paid`
* `waiting`
* `confirming`
* `confirmed`
* `sending`

WHMCS is credited only when NOWPayments reports:

```text
finished
```

NOWPayments can later move the same `payment_id` from an intermediate state such as `partially_paid` to `finished`.

Applying a partial transaction too early can cause duplicate-transaction conflicts or incorrect WHMCS invoice balances when the final callback arrives.

For this reason, intermediate payment states are logged but are not treated as completed WHMCS payments.

## Changelog

This compatibility/security rebuild includes the following changes:

* Updated the module for modern WHMCS 8/9 and PHP 8.x environments.
* Uses WHMCS gateway API metadata version `1.1`.
* Keeps the NOWPayments API key server-side.
* Replaced the old browser-URL payment flow with the current hosted NOWPayments invoice API.
* Creates invoices through `POST https://api.nowpayments.io/v1/invoice` only after the customer clicks **Pay Now**.
* Updated the callback implementation to use the current WHMCS callback workflow.
* Reads NOWPayments IPNs from the JSON request body.
* Added deep-key HMAC-SHA512 signature verification.
* Added preservation of original JSON number tokens during signature canonicalisation.
* Added constant-time signature comparison with `hash_equals()`.
* Changed payment handling so only the `finished` status credits WHMCS.
* Added duplicate-payment protection through `checkCbTransID()`.
* Keeps proper HTTPS/cURL verification enabled.
* Added reasonable API request timeouts.
* Removed macOS `.DS_Store` and `__MACOSX` files from the package.

## Note

This module only creates customer payment invoices and processes NOWPayments IPN callbacks.

NOWPayments account-level settings such as custody, primary balance, payout wallet, and withdrawal whitelisting remain outside the WHMCS gateway module and do not need to be embedded in the code.
