# GCash payments with PayMongo

When `PAYMONGO_SECRET_KEY` is set, the payment page shows **Pay with GCash**. Without it, the old manual reference-number form is used.

## Flow
1. Commuter reserves seats (`Pending`, 10-minute hold) and opens the payment page.
2. **Pay with GCash** creates a PayMongo Checkout Session (GCash only) from the server-side quote and redirects to its `checkout_url`. The 10-minute hold restarts at this moment.
3. The commuter approves in GCash and returns to `Commuter/payment_return.php`.
4. A payment is final only when PayMongo says so: `api/paymongo_webhook.php` (signed `checkout_session.payment.paid` event) or the return page asking PayMongo directly. Both call `mbus_finalize_payment()`, which is safe to run twice.
5. Seats become `Paid`, a `payment` row and ticket code are created, and the commuter lands on the ticket.

If a payment finishes after the hold lapsed, the seats are revived when still free; if someone else took one, the checkout is flagged `Needs refund` in `paymongo_checkout`.

## Setup (test mode)
1. Create a PayMongo account and copy the **test secret key** (`sk_test_...`).
2. In Render, set `PAYMONGO_SECRET_KEY` and `PAYMONGO_WEBHOOK_SECRET`.
3. Register the webhook (once):
   ```bash
   curl -u sk_test_XXXX: https://api.paymongo.com/v1/webhooks \
     -H 'Content-Type: application/json' \
     -d '{"data":{"attributes":{"url":"https://mbus-bnf9.onrender.com/api/paymongo_webhook.php","events":["checkout_session.payment.paid"]}}}'
   ```
   The response contains a `secret` (`whsk_...`): put it in `PAYMONGO_WEBHOOK_SECRET`.
4. Pay with the PayMongo test GCash page; no real money moves. Going live needs a verified PayMongo business account and live keys.

The `paymongo_checkout` table is created automatically.

## Free option (no PayMongo account): GCash QR + receipt
When `PAYMONGO_SECRET_KEY` is empty, the payment page shows the admin's GCash QR and number. The commuter pays, enters the reference number and uploads the receipt. Admin → GCash payments (profile menu) shows each receipt to approve (ticket issued) or reject (seats released). Set the QR, account name and number in the "Your GCash details" section of that page. Reference numbers can't be reused.
