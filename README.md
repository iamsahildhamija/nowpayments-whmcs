# NOWPayments WHMCS Gateway — Compatibility Rebuild v1.0.2

This package is a compatibility/security rebuild of the old 2019 NOWPayments WHMCS gateway for a modern WHMCS 8/9 + PHP 8.x environment.

## Disclaimer

This is an independent community-maintained compatibility rebuild of the
NOWPayments WHMCS gateway and is not an official NOWPayments product.

NOWPayments and WHMCS are trademarks of their respective owners.

This project is provided as-is without warranty. Please review the upstream
software licensing terms before redistributing or relicensing any code derived
from the original gateway.

## Changelog

- Uses WHMCS gateway API metadata version `1.1` (the currently documented third-party gateway API version).
- Keeps the NOWPayments API key server-side. The old module exposed it in a browser URL.
- Creates a modern NOWPayments hosted invoice through `POST https://api.nowpayments.io/v1/invoice` only after the customer clicks Pay Now.
- Uses the current WHMCS callback workflow (`checkCbInvoiceID`, `checkCbTransID`, `logTransaction`, `addInvoicePayment`).
- Reads JSON IPN data correctly instead of relying on `$_POST`.
- Performs deep-key HMAC-SHA512 verification of `X-NOWPAYMENTS-SIG`.
- Preserves original JSON number tokens during IPN canonicalisation, avoiding PHP float/scientific-notation signature mismatches.
- Uses constant-time signature comparison with `hash_equals()`.
- Does not mark `partially_paid`, `waiting`, `confirming`, `confirmed`, or `sending` states as paid. WHMCS is credited only after NOWPayments reports `finished`.
- Prevents duplicate payment application using WHMCS `checkCbTransID()`.
- Uses proper HTTPS/cURL verification and reasonable request timeouts.
- Removes macOS `.DS_Store` / `__MACOSX` junk from the package.

## Environment

Designed for WHMCS 8x / 9.0 and PHP 8.2 / 8.3. The code intentionally avoids Composer or third-party PHP dependencies.

## Installation

Copy the included `modules/` directory into the root of your WHMCS installation, preserving paths:

- `modules/gateways/nowpayments.php`
- `modules/gateways/nowpayments/lib.php`
- `modules/gateways/nowpayments/pay.php`
- `modules/gateways/nowpayments/logo.png`
- `modules/gateways/nowpayments/whmcs.json`
- `modules/gateways/callback/nowpayments.php`

Then in WHMCS:

1. Go to **Configuration / System Settings > Payment Gateways** (wording depends on WHMCS theme/version).
2. Activate **NOWPayments**.
3. Enter the **API Key** and **IPN Secret** from your NOWPayments dashboard.
4. Ensure WHMCS **System URL** is correct and uses HTTPS.
5. Save changes.

The module passes the callback URL automatically when it creates each NOWPayments invoice:

`https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/nowpayments.php`

## Test

Create a low-value WHMCS invoice and complete one real payment. Confirm all four items:

1. Customer is redirected to the hosted NOWPayments invoice.
2. Payment appears in NOWPayments.
3. WHMCS **Gateway Log** receives status callbacks.
4. After NOWPayments status becomes `finished`, the WHMCS invoice becomes Paid exactly once.

If status callbacks do not arrive, verify that Cloudflare/server firewall rules allow NOWPayments webhook requests and that the callback URL is publicly reachable over HTTPS.

## Behavior

The module deliberately does **not** credit `partially_paid` callbacks. NOWPayments can later move the same `payment_id` to `finished`; applying a partial transaction too early can cause duplicate-transaction conflicts or incorrect invoice balances.

## Note

This gateway only creates customer payment invoices and receives IPNs. Custody/primary balance/payout wallet/withdrawal whitelisting remain account-level NOWPayments settings and do not need to be embedded in this module.
