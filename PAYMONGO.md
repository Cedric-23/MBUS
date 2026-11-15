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

## Free option (no PayMongo account): GCash QR + automatic SMS matching
When `PAYMONGO_SECRET_KEY` is empty, the payment page shows the admin's GCash QR and number. The commuter pays, then enters only the GCash reference number. No receipt upload.

- The phone with the GCash SIM runs an SMS forwarder app that sends each "You have received PHP ..." SMS to `/api/gcash_sms_webhook.php?token=...` (the exact URL is shown in Admin > GCash payments > "Automatic payment matching"). Send the SMS text in a field named `message`.
- MBUS records the amount and reference. If a commuter's reference matches and the amount covers the fare, the ticket is issued immediately. Each reference works once; an under-payment is not accepted.
- If the SMS is late, the commuter waits on a "Waiting for your GCash payment" page that re-checks every few seconds. Unmatched attempts lapse after 30 minutes and seats are only held 15 minutes, and a user is limited to 3 unconfirmed attempts per hour, so fake reference numbers can't hog seats.
- Admin > GCash payments keeps a fallback queue for unusual cases. Set the QR, name and number in "Your GCash details".
