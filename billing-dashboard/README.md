# Akhurath Studio billing dashboard

> **Existing Akhurath website (Hostinger/PHP):** the live website URL is served by `index.php` and `api.php` in this folder. Follow [README-Hostinger.md](README-Hostinger.md) to apply the MariaDB schema and role migration. The standalone Node instructions below are for a separate Node-capable deployment.

A deployable accountant dashboard for final video billing. It includes a Node.js backend, MariaDB schema and adapter, role-aware sign-in, invoice and payment clearance actions, and a separate authenticated n8n delivery handoff. Demo mode works without a database. No live MariaDB or n8n connection is configured by this project.

## Workflow and safety boundary

1. The existing upload workflow inserts each finished video into `final_delivery_billing`.
2. An accountant signs in and records the bill amount, invoice number, and optional HTTPS invoice attachment reference.
3. The accountant records payment clearance and a payment reference.
4. A distinct n8n delivery workflow, using its own bearer token, polls only records where the invoice is sent, payment is cleared, and delivery is ready. It claims the record before processing.
5. n8n re-checks clearance immediately before changing Drive permissions. It shares the file only at that point, sends the link to WhatsApp, and calls the completion endpoint.

The dashboard has no Google Drive or WhatsApp integration and never makes Drive permissions public. Remove or disable any existing upload-stage Google Drive permission node configured as `type=anyone`; place the appropriate restricted sharing operation only in the delivery workflow after clearance verification. Do not expose a `drive_file_id` or final link to customers before delivery.

## Requirements

- Node.js 20 or newer
- MariaDB 10.5+ (only when using live database mode)
- A private HTTPS n8n instance for production delivery

## Run in demo mode

```sh
cp .env.example .env
```

Edit `.env`: set `SESSION_SECRET` to at least 32 random characters and set `ACCOUNTANT_PASSWORD_HASH` to a bcrypt hash. Generate one with:

```sh
node -e "console.log(require('bcryptjs').hashSync('your-password', 12))"
```

Then install and run:

```sh
npm install
npm start
```

Open `http://localhost:3000`. Demo records and edits are in memory and reset when the server restarts. Demo credentials are not preconfigured; supply the username and bcrypt hash through `.env`.

## Configure MariaDB

1. Apply `sql/schema.sql` using a database administrator.
2. Create a least-privilege application database user with access to the two application tables.
3. Configure `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` and set `DEMO_MODE=false`.
4. Configure `ACCOUNTANT_USERNAME`, `ACCOUNTANT_PASSWORD_HASH`, and a long random `SESSION_SECRET`.
5. Restart the server. `/api/health` reports `mariadb` only after the startup connection succeeds.

The MariaDB adapter uses parameterized queries, row locks for delivery claims, conditional updates, and an audit log. The upload automation should insert rows with `invoice_status='pending'`, `payment_status='unpaid'`, and `delivery_status='awaiting_payment'`.

## Authentication and deployment

- Accountant credentials are configured as a username and bcrypt hash. Optional viewer credentials are read-only.
- Sessions use an HMAC-signed, `HttpOnly`, `SameSite=Strict` cookie. Production cookies also set `Secure`.
- Mutating browser routes enforce same-origin requests, JSON body size is limited, and login attempts are rate-limited.
- Put the app behind HTTPS, use a process manager, restrict database network access, and store `.env` in a secret manager. Never commit `.env`.
- The built-in HMAC cookie store is process-local and users are logged out when the process restarts; it is suitable for a single app instance. Use a shared session store before running multiple instances.
- Back up the database and audit table according to your operations policy.

## n8n integration contract

Configure the n8n workflow with an HTTP Request credential using `Authorization: Bearer <N8N_API_TOKEN>`. Do not put this token in browser code or a customer-facing flow.

1. Poll `GET /api/n8n/eligible` on a schedule. The response includes only cleared, sent, ready records and contains the Drive file ID needed for internal processing.
2. For each record, call `POST /api/n8n/claim/{id}`. Process only a successful claim. The claim response includes a `delivery_claim_token`.
3. Immediately before altering Drive permissions, query the dashboard's eligible/clearance state with the shared token and verify this exact record remains payment-cleared and invoice-sent. Add your deployment-specific way to re-check the row, or create a protected clearance-verification endpoint for n8n. Never interpret a queued record or a stale claim alone as payment authorization.
4. Use Google Drive sharing with the intended recipient or restricted access. Never use `anyone` access unless the business has explicitly decided that policy outside this dashboard.
5. Send the resulting final link via the configured WhatsApp provider.
6. Call `POST /api/n8n/complete/{id}` with `{ "claim_token": "...", "status": "delivered", "final_link": "https://..." }`. For failure, send `{ "claim_token": "...", "status": "failed", "error": "..." }`.

The `eligible` endpoint stops listing a row after it is claimed. Failed records require an operator retry/requeue process; do not retry by directly changing a row to delivered. Keep n8n execution history and credentials access restricted.

### Important implementation note

The claim endpoint checks clearance atomically when n8n claims the row, and the completion endpoint refuses to update delivery unless clearance still holds. To satisfy the additional re-check immediately before Drive sharing, implement that verification in your n8n workflow against MariaDB using a read-only credential, or add a dedicated authenticated verification endpoint. This project does not claim the separate n8n workflow is configured or connected.

## API summary

- `POST /api/login`, `POST /api/logout`, `GET /api/me`
- `GET /api/billing`
- `POST /api/billing/:id/invoice` (accountant)
- `POST /api/billing/:id/clear` (accountant; requires sent invoice and payment reference)
- `GET /api/n8n/eligible`, `POST /api/n8n/claim/:id`, `POST /api/n8n/complete/:id` (separate bearer credential)
- `GET /api/health`

## Checks

Run `npm run check` for JavaScript syntax checks. `npm start` also validates required configuration and attempts a database connection when live mode is selected.
