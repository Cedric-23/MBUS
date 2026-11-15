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
When `PAYMONGO_SECRET_KEY` is empty, "Pay with GCash" opens a payment page with the admin's QR, number and an **exact amount** (the fare minus 1-99 centavos, unique among open payments). The commuter just sends that amount; there is no reference number to type.

- The phone with the GCash SIM runs an SMS forwarder app that sends each "You have received PHP ..." SMS to `/api/gcash_sms_webhook.php?token=...` (the URL is shown in Admin > GCash payments > "Automatic payment matching"; send the SMS text in a field named `message`).
- MBUS records the amount and reference, finds the one open payment with that exact amount, and issues the ticket. The commuter's page polls every 4 seconds and jumps to the ticket. Each SMS can pay for one booking only.
- Seats are held 15 minutes; open payments lapse after 30 minutes; 3 lapsed payments in an hour pause new attempts.
- Admin > GCash payments shows the phone status (time since the last SMS) and a fallback queue for unusual cases (e.g. the commuter paid a different amount). Set the QR, name and number in "Your GCash details".
