# DOKU channel source-of-truth matrix

Collected 8 November 2026 from the official DOKU developer pages listed at the end. Nothing here comes from a live merchant call.
`DOCUMENTED` means the page states it. `BLOCKED_EXTERIOR` means the official page does not state it (or contradicts itself), so the
code must not guess it; the channel stays unavailable until DOKU supplies the contract or a Sandbox fixture. Merchant IDs, terminal IDs,
BINs and sample statuses on the pages are examples, never live configuration.

## Shared transport (DOCUMENTED)

| Item | Value |
| --- | --- |
| Hosts | Sandbox `https://api-sandbox.doku.com`, Production `https://api.doku.com` |
| B2B token | `POST /authorization/v1/access-token/b2b` (already implemented for BNI) |
| Request signature | Symmetric `HMAC_SHA512(clientSecret, "POST:<path>:<accessToken>:<hex sha256(minified body)>:<timestamp>")` |
| Headers | `X-PARTNER-ID`, `X-EXTERNAL-ID` (numeric, unique per day), `X-TIMESTAMP`, `X-SIGNATURE`, `Authorization: Bearer`, `CHANNEL-ID: H2H` (VA, QRIS) |
| Status check | `POST /orders/v1.0/transfer-va/status` (VA), `POST /orders/v1.0/debit/status` with `serviceCode 55` (e-wallet). Call 60 s after payment completes. |
| Status values (e-wallet `latestTransactionStatus`) | 00 Success, 01 Initiated, 02 Paying, 03 Pending, 04 Refunded, 05 Canceled, 06 Failed, 07 Not Found |
| Expiry value for status | No code or label for expiry on any channel. BLOCKED_EXTERIOR |

## Per channel

| Code | Create | Notification (DOKU to merchant) | Status / query | Reversal / refund | State in code |
| --- | --- | --- | --- | --- | --- |
| `va_bni` | DOCUMENTED, implemented | DOCUMENTED, verified with `CHANNEL-ID VA004`, implemented | `Pending` only. Terminal shape BLOCKED_EXTERIOR | Notification parser BLOCKED_EXTERIOR | **Implemented, grandfathered** |
| `va_bca` | `POST /virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va`, `additionalInfo.channel = VIRTUAL_ACCOUNT_BCA`. Full body is in an external OpenAPI file (BLOCKED_EXTERIOR for bank-specific fields). Merchant BIN/customer prefix are merchant values | Path `/v1.1/transfer-va/payment`, body fields documented. `CHANNEL-ID` value for BCA not stated. BLOCKED_EXTERIOR | Pending example only | BLOCKED_EXTERIOR | **Implemented** (create, notification). Cannot settle until `DOKU_BCA_NOTIFICATION_CHANNEL_ID` is set; needs `DOKU_VERIFIED_CHANNELS` |
| `va_bri` | Same path, `VIRTUAL_ACCOUNT_BRI` appears only in the limit-amount example. Body external | Path and required fields documented (`additionalInfo.channel` enum `VIRTUAL_ACCOUNT_BRI`). `CHANNEL-ID` for BRI not stated | Pending example only | BLOCKED_EXTERIOR | **Implemented**; same gates with `DOKU_BRI_*` |
| `va_mandiri` | Same path, `VIRTUAL_ACCOUNT_BANK_MANDIRI` in an example. Body external | Page contradicts itself: `/v1.1/transfer-va/payment` in prose, `/v1/transfer-va/payment` in the embedded spec | Pending example only | BLOCKED_EXTERIOR | **Implemented**; same gates with `DOKU_MANDIRI_*`. The `/v1` vs `/v1.1` path conflict is irrelevant to the code because the route is the merchant's configured Notification URL |
| `va_permata` | Not read. Extensibility target only | not read | Acquirer id `PERMATA` appears in status examples | not read | Not defined; never enabled without official configuration |
| `qris` | DOCUMENTED: `POST /snap-adapter/b2b/v1.0/qr/qr-mpm-generate`, body `partnerReferenceNo`, `amount`, `merchantId`, `terminalId` (3-16 alphanumeric), `validityPeriod` (default 30 days when omitted), `additionalInfo.postalCode`, `additionalInfo.feeType = 1`; response `qrContent`, `referenceNo` | **Not documented** (no path, headers, signature, body). BLOCKED_EXTERIOR | `POST /snap-adapter/b2b/v1.0/qr/qr-mpm-query` (`serviceCode 47`); `latestTransactionStatus` values for QRIS not enumerated. BLOCKED_EXTERIOR | `qr-mpm-refund` documented (needs `approvalCode`, issuer support list). `qr-expire` documented. Not sanctioned yet | **Generate implemented** (`DokuQrisAdapter`), `settles=false` so unavailable. `feeType` JSON type is not stated; sent as the string `"1"`, confirm in Sandbox. Status inquiry intentionally maps nothing. `qr-expire` not wired |
| `ewallet_dana` | DOCUMENTED: `POST /direct-debit/core/v1/debit/payment-host-to-host`, `additionalInfo.channel = EMONEY_DANA_SNAP`, `urlParam {url,type=PAY_RETURN,isDeepLink}`, `validUpTo`; response `webRedirectUrl` | Shared e-wallet notify: `POST /v1.0/debit/notify`, service code 56, statuses 00/03/04/05/06; **signature string not specified**. BLOCKED_EXTERIOR | `POST /orders/v1.0/debit/status`, documented | Refund `POST /direct-debit/core/v1/debit/refund` documented; not enabled. Status `04 Refunded` is not applied (no refund timestamp contract) | **Implemented** (`DokuDirectDebitAdapter`); settles from the authenticated status query only. Needs return URL, redirect-host allowlist and `DOKU_VERIFIED_CHANNELS`. Poll latency up to 3 min |
| `ewallet_shopeepay` | `payment-host-to-host`, channel `EMONEY_SHOPEE_PAY_SNAP`, `urlParams` array, `validUpTo` max 24 h. Page headings disagree on `v1` vs `v2` path. BLOCKED_EXTERIOR | Not documented for ShopeePay. BLOCKED_EXTERIOR | Documented via debit status (`serviceCode 54` in ShopeePay examples, `55` stated as the mandatory generic value; code sends `55`) | Refund path documented; fields external | **Implemented**, but the payment path is read from `DOKU_SHOPEEPAY_PAYMENT_PATH` (only the two documented values accepted, no default) so it stays unavailable until DOKU confirms v1 or v2 |
| `ewallet_ovo` | Binding `POST /direct-debit/core/v1/registration-account-binding`, unbinding `.../registration-account-unbinding`, payment `payment-host-to-host` with `paymentType SALE`. Headers and bodies are in embedded widgets that were not readable. BLOCKED_EXTERIOR | Binding notification documented (`/v1.0/debit/notify`, symmetric, `status SUCCESS/FAILED`); payment notification generic e-wallet. Binding token handling not documented | Debit status documented | Refund path documented | **Not implemented.** No binding or SALE field list is readable, so any adapter would be invented. Remains `channel_not_implemented` until the OpenAPI yaml is supplied |

## Consequence for the code

* A channel may settle a booking only if a verified paid signal exists (`settles` in `DokuChannelRegistry`). `va_bni` via notification; DANA and ShopeePay via the documented status query (`latestTransactionStatus 00` plus `paidTime`, `transAmount`); BCA/BRI/Mandiri via notification once their CHANNEL-ID is configured.
* Wallet redirects, return URLs and QR generation are never paid evidence. The only DOKU hosts a customer is redirected to are those the operator lists in `DOKU_EWALLET_REDIRECT_HOSTS`.
* Residual `BLOCKED_EXTERIOR`: QRIS notification and query statuses; e-wallet notification signature string; OVO binding and SALE fields; ShopeePay path version; per-bank CHANNEL-ID values; terminal VA inquiry shape; refund/reversal parsers for every channel; expiry status value.
* To unblock a cell: obtain the OpenAPI file or a Sandbox capture from DOKU, add it as a fixture, then change the registry flag.

## Sources

* QRIS: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/qris
* BCA / BRI / Mandiri / BNI VA: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/virtual-account/{bca,bri,mandiri,bni}-virtual-account
* DANA / ShopeePay / OVO: https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/e-wallet/{dana,shopeepay,ovo}
* Notification samples: https://developers.doku.com/get-started-with-doku-api/notification/http-notification-sample-for-snap
* Check status: https://developers.doku.com/get-started-with-doku-api/check-status-api/snap
