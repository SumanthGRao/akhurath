# UPI invoice payment page and n8n workflow

## Deployment state

This repository contains the public payment page, invoice-scoped claim endpoint, migration, and n8n/WhatsApp setup instructions. The UPI ID, payee name, and HTTPS base URL are configured in the project config; the token secret is deliberately not stored in Git. Payment-link creation remains `setup_required` until a unique token secret is configured on the live PHP server and the migration below has been applied. No n8n workflow export is present here, and this repository does not connect to or modify the production n8n instance.

## Hostinger setup

1. Make sure the studio invoice tables from `sql/migrations/014_invoices_and_completed_tasks.sql`, billing invoice-link migration `003_link_invoices.sql`, grouping migration `005_billing_groups.sql`, and audit migration `004_billing_audit_log.sql` are already applied.
2. In phpMyAdmin, select the site database and run `billing-dashboard/sql/006_upi_payment_claims.sql` once. The migration extends `payment_status` with `payment_claimed`, creates encrypted public-link storage and rate-limit tables, and creates the idempotency table used by n8n.
3. Install the local SVG QR dependency in `billing-dashboard`: run `composer install --no-dev --optimize-autoloader`. Commit and deploy the resulting `composer.lock`, then deploy `billing-dashboard/vendor/` to the same directory on Hostinger (the `vendor/` directory is intentionally ignored by Git). The QR image is generated locally by PHP; the invoice URI is not sent to a QR service.
4. On the live Hostinger server, add `AKH_PUBLIC_PAYMENT_TOKEN_SECRET` to the PHP config loaded by the billing dashboard. Generate a unique secret with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Keep it private, do not reuse the n8n webhook secret, and do not commit it to Git. The configured UPI ID, payee name, and HTTPS base URL must also be present in the live runtime. The route redirects to the configured HTTPS origin.
5. Deploy `billing-dashboard/pay.php`, `payment-claim.php`, `payment-common.php`, the migration, and the Composer vendor files. Open an accountant session and load the billing queue; the client claim status and unverified UTR appear within the dashboard's five-second polling interval.

Invoice number, total, currency, and due date are read from the existing studio `invoices` row. The public page does not accept an amount or invoice number from the browser, and it never queries or renders the final video URL. A token is 256 bits of randomness; only its SHA-256 hash is used for lookup, with an encrypted copy retained server-side so the stable URL can be resent. Links expire after 90 days. A claim changes the linked billing records to `payment_claimed`, leaves delivery `awaiting_payment`, and writes `payment_claimed` audit rows. It does not mark the studio invoice paid or make any delivery eligible.

## Invoice webhook payload additions

The existing authenticated webhook continues to receive `billing.invoice.sent_manual`. The email-send action now emits `billing.invoice.sent` after the invoice is successfully emailed and the billing audit succeeds. Both events include:

```json
{
  "payment_url_status": "ready",
  "payment_url": "https://akhurathstudio.com/billing-dashboard/pay.php?t=<opaque-token>",
  "payment_url_suffix": "<opaque-token>",
  "invoice_pdf_url": "https://akhurathstudio.com/billing-dashboard/invoice-download.php?t=<opaque-token>"
}
```

When payment configuration is incomplete, `payment_url_status` is `setup_required`, all three URL fields are empty, and `payment_url_missing_config` lists only the names of missing settings. If the link table cannot be read or written, the status is `unavailable`; check the PHP error log for the invoice-specific cause. A non-sent or non-INR invoice reports `not_eligible`. n8n must require `payment_url_status === 'ready'`, `payment_url_suffix`, and `invoice_pdf_url` before sending. The PDF endpoint uses the same opaque token, only serves a linked invoice in `sent` status, rejects revoked/expired links, and never exposes a final video URL. The token is a bearer secret: do not log it, include it in analytics, or forward it to unrelated nodes. Restrict n8n execution retention and editor access.

## n8n workflow changes

The actual workflow JSON is not in this repository. In the existing published workflow whose Webhook node uses the production URL:

1. Keep its existing Header Auth credential. The credential's header name must be `X-Webhook-Secret`, with the same private value as `AKH_N8N_INVOICE_WEBHOOK_SECRET` in Hostinger `includes/config.php`.
2. Add a Switch node on `{{$json.event}}`:
   - `billing.invoice.sent_manual` and `billing.invoice.sent` → client invoice WhatsApp template branch.
   - `billing.invoice.payment_claimed` → accountant notification branch (the accountant dashboard already updates directly from MariaDB polling; this branch is an extra alert only).
3. Before a WhatsApp send, insert this event into MariaDB using the existing restricted DB credential:

```sql
INSERT IGNORE INTO billing_webhook_events (event_id, event_type, invoice_id, status)
VALUES (?, ?, ?, 'processing');
```

Continue only when the insert node reports one affected row. If it reports zero, this event ID was already seen; stop without sending. On WhatsApp success, update the event to `sent`, save the provider message ID, and set `completed_at=UTC_TIMESTAMP(3)`. On a definite failure, set `status='failed'` and `last_error`; do not retry automatically after an ambiguous provider timeout because that could send a duplicate. Review failed events and consciously reset them for a manual retry.
4. In the client branch, add an IF node requiring `{{$json.payment_url_status === 'ready' && !!$json.payment_url_suffix && !!$json.invoice_pdf_url}}`. If false, do not send; alert the accountant/setup owner.
5. Add a WhatsApp Cloud API HTTP Request node using the existing WhatsApp credential, not a literal access token. Use the approved template configuration below. Normalize the recipient to digits with country code and no plus sign.
6. Keep workflow execution-data retention restricted. The incoming payload includes a bearer-like payment-link suffix; disable saving successful execution payloads if the n8n retention policy permits, restrict workflow editors, and do not copy the token into execution notes.

### Meta template to create and submit for approval

- Template name: `akhurath_invoice_document_payment_v1`
- Category: `UTILITY`
- Language: `English (US)` / `en_US` (use the exact language code approved in Meta)
- Header: `DOCUMENT` media header. Submit a valid sample PDF when Meta requests a template example. The n8n send supplies `invoice_pdf_url` as the document link and the invoice number as the filename.
- Body: `Hi {{1}}, invoice {{2}} for {{3}} is ready. Amount due: INR {{4}}. Due date: {{5}}. Open the attached invoice and use the secure payment button below to pay by UPI.`
- Body examples for approval: `R.K Photography`; `INV-2026-0020`; `Karthik prafulla`; `2000.00`; `06 Nov 2026`.
- One URL button: label `Pay invoice`; static URL prefix `https://akhurathstudio.com/billing-dashboard/pay.php?t=`; dynamic suffix `{{1}}`.
- Do not use a `upi://` URL in the WhatsApp button. The HTTPS payment page provides the UPI URI and QR.

Configure the WhatsApp HTTP Request `POST` URL as `https://graph.facebook.com/<configured-version>/<phone-number-id>/messages`; supply the phone-number ID and bearer token from the existing n8n credential. JSON body:

```json
{
  "messaging_product": "whatsapp",
  "to": "={{ String($json.phone || '').replace(/\\D/g, '') }}",
  "type": "template",
  "template": {
    "name": "akhurath_invoice_document_payment_v1",
    "language": { "code": "en_US" },
    "components": [
      {
        "type": "header",
        "parameters": [
          {
            "type": "document",
            "document": {
              "link": "={{ $json.invoice_pdf_url }}",
              "filename": "={{ $json.invoice_number + '.pdf' }}"
            }
          }
        ]
      },
      {
        "type": "body",
        "parameters": [
          { "type": "text", "text": "={{ $json.customer_name }}" },
          { "type": "text", "text": "={{ $json.invoice_number }}" },
          { "type": "text", "text": "={{ ($json.deliveries || []).map(x => x.project_name).filter(Boolean).join(', ') || $json.project_name }}" },
          { "type": "text", "text": "={{ $json.total_inr }}" },
          { "type": "text", "text": "={{ $json.due_at || 'As agreed' }}" }
        ]
      },
      {
        "type": "button",
        "sub_type": "url",
        "index": "0",
        "parameters": [
          { "type": "text", "text": "={{ $json.payment_url_suffix }}" }
        ]
      }
    ]
  }
}
```

Use n8n's expression editor for the `to` and `text` values; keep the remaining JSON as ordinary values. Do not send a payment URL to a customer if the template has not been approved.

For `billing.invoice.payment_claimed`, use the same event-id deduplication. Route an internal alert with invoice number, customer, `client_utr_unverified`, and claim time; do not clear payment. The dashboard reads the same claim row directly and shows it in the accountant detail panel.

## Accountant verification and delivery gate

The billing detail panel displays claim time, client-submitted UTR (explicitly unverified), and verification state. The accountant must enter a bank/UPI reference and check “I have confirmed the incoming credit in the bank account.” The authenticated, CSRF-protected clear endpoint records the accountant username and time, verifies the claim, updates all billing records linked to the invoice to `cleared`, and sets delivery `ready`. For invoices with no client claim, the same explicit bank confirmation is still required. The delivery workflow is triggered immediately by the payment-cleared webhook, then claims and re-checks each record immediately before granting Drive access; it does not need scheduled polling.

## Immediate payment-cleared webhook

The billing clear action now sends one POST after its database transaction commits. For grouped invoices the payload contains every project's `billing_id`, task, phone, customer, Drive folder ID, Drive file ID, and filename in `deliveries`. It also includes `event_id`, `invoice_id`, invoice number, and the cleared/sent statuses. The bank/UPI reference is not sent to n8n. Each audit row stores the same `delivery_event_id` so an ambiguous HTTP timeout can be investigated and deduplicated.

The delivery Webhook node must use **POST**, **Header Auth** with header name `X-Webhook-Secret`, and **Respond Immediately**. Its production URL is `https://n8n.akhurathstudio.com/webhook/c17bf7be-c286-46e6-b270-9914d11e67e4`. Activate the workflow before using the Production URL. Store the matching secret only in the live PHP `includes/config.php` or server environment as `AKH_N8N_DELIVERY_WEBHOOK_SECRET`; this is a separate secret from the invoice webhook secret. The code accepts an optional `AKH_N8N_DELIVERY_WEBHOOK_URL` override.

The incoming JSON is shaped like this (a grouped invoice has one item per delivery):

```json
{
  "event": "billing.payment.cleared",
  "event_id": "payment-cleared-24-<random-suffix>",
  "billing_id": 24,
  "invoice_id": 24,
  "invoice_number": "INV-2026-0024",
  "invoice_status": "sent",
  "payment_status": "cleared",
  "phone": "919XXXXXXXXX",
  "customer_name": "Client Name",
  "deliveries": [
    {
      "billing_id": 24,
      "task_code": "AS0237",
      "phone": "919XXXXXXXXX",
      "customer_name": "Client Name",
      "project_name": "Project Name",
      "task_type": "reel1",
      "folder_id": "DRIVE_FOLDER_ID",
      "drive_file_id": "DRIVE_FILE_ID",
      "file_name": "final.mp4"
    }
  ]
}
```

Recommended workflow nodes: Webhook → deduplicate `event_id` → split `deliveries` into individual records → call the authenticated `api.php?action=n8n-claim-{billing_id}` endpoint for each → grant the intended client access in Drive using `folder_id` for folder sharing or `drive_file_id` for file sharing → send the approved WhatsApp delivery template → call `api.php?action=n8n-complete-{billing_id}` with the claim token and final HTTPS link. Stop if a claim fails, and report a failure through the completion endpoint if processing fails after claim. Do not expose the file before a successful claim. Drive sharing is restricted: the billing row currently has the client's phone but no email, so collect/map a client email for person-specific Drive access; do not switch to public link access without an explicit business decision.

## Safe non-production test plan

Use a staging copy of the database and staging URL only. Clone invoice `INV-2026-0020` into that database, replace the customer phone with a test number, configure a test UPI payee/VPA that the business controls, and disable the live WhatsApp HTTP node. Do not approve a UPI transaction and do not send to the real client.

1. Run `php tests/payment-common.test.php` from `billing-dashboard`; it checks UPI URI fields/encoding, amount/currency/reference, token encryption integrity, token syntax, and HTTPS URL configuration.
2. Open the staging payment URL on desktop and mobile; confirm the displayed amount, due date, business UPI values, local QR and UPI app link. Confirm no browser request goes to a QR vendor and no final video URL appears in page source/network responses.
3. Change one token character, use an expired token, and revoke a token in staging. Each must be rejected; a cleared invoice link must be rejected as already paid.
4. Submit the payment claim once with a test UTR, then submit it again. Confirm one claim timestamp, one audit action per billing row, one `payment_claimed` webhook event ID, no `cleared` status, and no delivery eligibility. Try an invalid UTR and repeat more than ten claim attempts in an hour to confirm validation and throttling.
5. Tamper with browser form fields for amount, invoice number, UPI ID and status. The endpoint ignores those values and continues to use the invoice row. Try the clear API without accountant session, CSRF token, verified reference, and bank-credit checkbox; all must fail. Then authenticate an accountant, confirm a test bank-credit assertion, and verify each grouped project changes together.
6. Confirm `GET api.php?action=n8n-eligible` does not return claimed/unpaid records, and returns only the test invoice rows after the accountant clears them. Exercise webhook duplicate events against a staging MariaDB and a mocked WhatsApp node; no real message should be sent.

This checkout has no PHP runtime, Composer, database, Hostinger, or n8n credentials available, so the test harness and staging plan are provided but have not been executed here. No live migration, payment, WhatsApp message, or deployment was performed.
