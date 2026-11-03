# Payment Integration

## Bangladesh gateways (bKash, Nagad, SSLCommerz) — set up from the admin panel

Go to **Admin → Booking → Business → Payment Gateways**. Each business enters its **own** merchant credentials, picks Sandbox or Live, switches the gateway on, and can press **Test connection**. Credentials are stored in `abs_payment_gateways.credentials`, encrypted with `APP_KEY`. Secret fields are write-only: they are never shown again, and an empty field keeps the saved value. Then choose an online payment mode on **Booking Settings**.

| Gateway | Flow | How payment is confirmed | Refunds | Status |
|---|---|---|---|---|
| bKash (Tokenized Checkout v1.2.0-beta) | grant token → create → bKash page → callback | server calls **execute** / **payment status**; `transactionStatus = Completed`, amount and currency must match | API (`/payment/refund`) | Implemented, tested with fakes. **Not verified on bKash sandbox or live.** |
| SSLCommerz (v4) | session → SSLCommerz page → POST back + IPN | **merchantTransIDvalidationAPI** / **validationserverAPI** must return VALID/VALIDATED; risk_level 1 is held | API (refund request accepted = completed in this app; the bank settles later) | Implemented, tested with fakes. **Not verified on sandbox or live.** IPN URL: shown on the page (per business). |
| Nagad (check-out API v-0.2.0) | initialize (RSA-encrypted, signed) → complete → Nagad page → callback | server calls **verify/payment**; status Success, amount must match | **Manual**: refund in the Nagad merchant portal, then use “already refunded from the merchant portal” on the refund form | Implemented; encryption and signing verified in tests with real RSA keys. **Not verified against Nagad.** |

bKash and Nagad accept **BDT only**, so they are hidden for businesses in other currencies.

All gateways return to `/book/pay/{payment}/{token}/callback`. The token is an HMAC of the payment id, placed in the path so that query parameters added by the provider do not break it. The route accepts GET and POST without a CSRF check, because SSLCommerz posts back. Nothing is marked paid from the redirect itself: the provider is always asked server-side. Webhook URLs are now per business: `/abs/webhooks/{provider}/{business id}`.

## What is implemented

| Method | Status |
|---|---|
| Pay later / cash recorded by staff | Implemented and tested |
| Other manual payment (bank transfer, etc.) | Implemented and tested |
| Stripe Checkout (hosted page): full payment, deposit, balance | Implemented. Tested with HTTP fakes and real HMAC signatures. **Not verified against live or test Stripe accounts.** |
| Stripe refunds (full or partial) through the API plus webhooks | Implemented as above, not verified live |
| PayPal Checkout (Orders v2): capture after approval, refunds, verified webhooks | Implemented. Tested with HTTP fakes. **Not verified against a PayPal sandbox or live account.** |
| bKash, Nagad, SSLCommerz | Implemented, see above. Other providers: add a class that implements `ME\Abs\Payments\PaymentGateway` and register it with `GatewayManager::extend()` |

Card data never reaches the server, because Stripe hosts the card form. The server stores provider ids and amounts only.

## Configuration (`.env`)

```env
ABS_PAYMENT_GATEWAY=stripe
STRIPE_SECRET=sk_live_or_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

For PayPal instead:

```env
ABS_PAYMENT_GATEWAY=paypal
PAYPAL_CLIENT_ID=...
PAYPAL_SECRET=...
PAYPAL_WEBHOOK_ID=...                         # from the PayPal developer dashboard
PAYPAL_API_BASE=https://api-m.sandbox.paypal.com   # sandbox; omit for live
```

The PayPal webhook URL is `https://your-domain/abs/webhooks/paypal`. Subscribe to `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.REFUND.*`.

PayPal approval is not payment. After the customer approves, the signed return page (or the `CHECKOUT.ORDER.APPROVED` webhook) captures the order **server-side**. It is marked paid only when PayPal returns a `COMPLETED` capture with a matching amount and currency. A payment that was already cancelled (for example, its hold expired) is never captured. Webhooks are verified through PayPal's `verify-webhook-signature` API.

In the Stripe dashboard, add a webhook endpoint `https://your-domain/abs/webhooks/stripe` with these events:
`checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `refund.created`, `refund.updated`, `refund.failed`.

Then, in **Booking Settings → Payment mode**, choose online optional, full payment required, or deposit required. Without the keys, only "Pay later" can be saved. Set **Unpaid hold** to at least 30 minutes, because Stripe Checkout sessions last a minimum of 30 minutes.

## Flow and safeguards

1. Booking with online payment required → appointment `awaiting_payment`, slot held until `hold_expires_at`.
2. `CheckoutService` computes the amount on the server (the deposit, or the outstanding balance), cancels older pending checkouts, creates a `pending` payment, and creates the Stripe session with `Idempotency-Key: abs-checkout-{payment id}`.
3. The customer returns to a **signed** return URL. That page only says the payment is being confirmed. It never marks anything paid.
4. The webhook: the signature (`Stripe-Signature`, HMAC-SHA256, 300 s tolerance) is verified, and the event id is stored once (unique `provider + event_id`). **The checkout is then re-fetched from the Stripe API**, and the amount and currency must match the payment row. A mismatch marks the payment failed for manual review.
5. `PaymentLedger::markSucceeded` locks the payment, which makes it idempotent. It moves `awaiting_payment` → `pending` (approval mode) or `confirmed` (instant). Payment never skips owner approval.
6. Out-of-order events: a late "expired" or "failed" event never overrides a captured payment.
7. A payment that arrives after the booking expired, was cancelled or rejected, or lost its slot is **refunded automatically in full**.
8. Rejecting a paid request refunds its online payments automatically. Cancelling never refunds automatically; staff decide with the Refund button (`abs_payment.refund`).
9. Refunds go requested → pending → completed or failed. Money is applied to the payment exactly once, on completion. Webhooks are matched by `metadata.refund_id`, then by the Stripe refund id.

Appointment status and payment status are separate fields. `payment_status` (unpaid, partially paid, paid, partially refunded, refunded) is always recomputed from the payment rows.

## Remaining live verification

- A real Stripe test-mode run: checkout, webhook delivery, refund, an async payment method.
- A PayPal sandbox run: approve, capture, webhook verification, refund.
- Currencies with 0 or 3 decimals against Stripe (minor units are sent as-is; Stripe has special cases, for example HUF and ISK).
