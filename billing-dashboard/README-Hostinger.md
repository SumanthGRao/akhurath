# Hostinger PHP deployment

The main Akhurath site is PHP/Apache hosted. For the website URL `/billing-dashboard/`, use the PHP entry point in this directory. The standalone Node app remains available for environments that can run Node, but pushing it alone does not start a Node process on the PHP website.

## Deploy

1. Push the repository changes and confirm the Hostinger Git deployment has updated the website files.
2. Confirm the live site has `billing-dashboard/index.php`, `billing-dashboard/api.php`, `billing-dashboard/public/`, and the existing root `includes/` files.
3. Confirm the site's existing `includes/config.php` or `config/database.local.php` connects PHP to the MariaDB database.
4. Select the site database in phpMyAdmin and run `billing-dashboard/sql/001_php_hostinger.sql` for a new billing installation.
5. For an existing billing table created before invoice linking, run `billing-dashboard/sql/003_link_invoices.sql` once; skip it on a fresh installation where step 4 created the column. The Access page attempts the additive accountant-role migration automatically. If the site database user cannot alter `users.role`, run `billing-dashboard/sql/002_php_hosting_accountant_role.sql` once in phpMyAdmin.
   If an invoice or payment action says its audit entry could not be saved, run `billing-dashboard/sql/004_billing_audit_log.sql` in phpMyAdmin and confirm the site database user has `INSERT` permission on `billing_audit_log`.
6. Open `/admin/dashboard-access.php` as an administrator and use the **Accountant billing dashboard** section to create or remove accountant logins. Existing administrators can also sign in. Accounts created before the role migration may appear under **Accounts needing a role**; explicitly choose **Make accountant** for the intended account to preserve its existing password hash.
7. Set `AKH_BILLING_N8N_TOKEN` as a server environment secret or define it in the untracked `includes/config.php`. Never add its value to Git.
8. Re-test `https://akhurathstudio.com/billing-dashboard/` and the login, invoice and clearance flows.

The accountant invoice builder is restricted to the billing delivery flow. It reuses the studio invoice generator for tax, PDF previews/downloads and email, and updates the billing row only after the invoice email succeeds or an accountant records the manual send reference. The accountant role does not receive access to the full admin invoice-management screen.

When an accountant confirms that an invoice was sent manually, the builder POSTs a `billing.invoice.sent_manual` JSON event to n8n. Configure `AKH_N8N_INVOICE_CREATED_WEBHOOK_URL` (the existing config constant name is retained) and `AKH_N8N_INVOICE_WEBHOOK_SECRET` in the private `includes/config.php`, and configure the n8n Webhook node for Header Auth using the header name `X-Webhook-Secret` and the same secret. The payload includes `event_id`, billing and invoice IDs, customer name and phone, project/task fields, invoice number/status, INR totals/tax/due date, line items, and `send_reference`. `invoice_status` is `sent`. The current PDF preview URL is accountant-protected and is intentionally not included as a customer link. The event is sent after the manual-send audit entry succeeds; n8n should use `event_id` for idempotency. Creating a draft does not send a webhook.

The PHP adapter uses the parent site's session, PDO connection and CSRF helper. Accountant sessions are isolated from administrator sessions and only accept the `accountant` role; removing an account revokes its next API request. It does not need npm on Hostinger. Until the billing table is imported, the page loads but the API reports a database configuration/schema message; no live database is claimed to be connected by this commit.

The separate n8n workflow must use `Authorization: Bearer <AKH_BILLING_N8N_TOKEN>` with `api.php?action=n8n-eligible`, `api.php?action=n8n-claim-{id}`, and `api.php?action=n8n-complete-{id}`. Before changing Drive permissions, n8n must re-check that the exact claim still has a sent invoice and cleared payment. Do not use public `anyone` Drive sharing.
