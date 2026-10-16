# Hostinger PHP deployment

The main Akhurath site is PHP/Apache hosted. For the website URL `/billing-dashboard/`, use the PHP entry point in this directory. The standalone Node app remains available for environments that can run Node, but pushing it alone does not start a Node process on the PHP website.

## Deploy

1. Push the repository changes and confirm the Hostinger Git deployment has updated the website files.
2. Confirm the live site has `billing-dashboard/index.php`, `billing-dashboard/api.php`, `billing-dashboard/public/`, and the existing root `includes/` files.
3. Confirm the site's existing `includes/config.php` or `config/database.local.php` connects PHP to the MariaDB database.
4. Select the site database in phpMyAdmin and run `billing-dashboard/sql/001_php_hostinger.sql`.
5. Run `billing-dashboard/sql/002_php_hosting_accountant_role.sql` once to allow the dedicated accountant role.
6. Open `/admin/dashboard-access.php` as an administrator and use the **Accountant billing dashboard** section to create or remove accountant logins. Existing administrators can also sign in.
7. Set `AKH_BILLING_N8N_TOKEN` as a server environment secret or define it in the untracked `includes/config.php`. Never add its value to Git.
8. Re-test `https://akhurathstudio.com/billing-dashboard/` and the login, invoice and clearance flows.

The PHP adapter uses the parent site's session, PDO connection and CSRF helper. Accountant sessions are isolated from administrator sessions and only accept the `accountant` role; removing an account revokes its next API request. It does not need npm on Hostinger. Until the billing table is imported, the page loads but the API reports a database configuration/schema message; no live database is claimed to be connected by this commit.

The separate n8n workflow must use `Authorization: Bearer <AKH_BILLING_N8N_TOKEN>` with `api.php?action=n8n-eligible`, `api.php?action=n8n-claim-{id}`, and `api.php?action=n8n-complete-{id}`. Before changing Drive permissions, n8n must re-check that the exact claim still has a sent invoice and cleared payment. Do not use public `anyone` Drive sharing.
