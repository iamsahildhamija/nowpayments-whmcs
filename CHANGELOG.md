# Changelog

## 1.0.1 - 2026-09-14

- Rebuilt legacy 2019 gateway for modern WHMCS 8/9 conventions.
- Corrected WHMCS gateway API metadata from undocumented `1.2` to documented `1.1`.
- Replaced legacy `nowpayments.io/payment?data=...` client-side flow with server-side Invoice API creation.

## 1.0.2 - 2026-09-15

- Added signed server-side checkout handoff so invoice id/amount/currency cannot be altered in the browser.
- Added robust IPN JSON parsing and HMAC-SHA512 verification.
- Added deep object-key canonicalisation with raw-number preservation.
- Added duplicate transaction protection.
- Added strict `finished` settlement policy.
- Added callback/request validation, safer logging and HTTP status handling.
- Removed obsolete/macOS metadata files.
