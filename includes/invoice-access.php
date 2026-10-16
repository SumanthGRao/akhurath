<?php

declare(strict_types=1);

/** Allow the accountant role to create billing-linked invoices and open linked invoice previews, without granting admin-console access. */
function akh_require_invoice_access(): bool
{
    if (akh_admin_current() !== null) {
        return false;
    }

    $username = $_SESSION['akh_billing_user'] ?? null;
    if (!is_string($username) || $username === '' || !function_exists('akh_db')) {
        header('Location: ' . base_path('billing-dashboard/'));
        exit;
    }

    try {
        $db = akh_db();
        if (!$db instanceof PDO) {
            header('Location: ' . base_path('billing-dashboard/'));
            exit;
        }
        $st = $db->prepare("SELECT username FROM users WHERE LOWER(username)=LOWER(?) AND role='accountant' LIMIT 1");
        $st->execute([$username]);
        $active = $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('Billing invoice access check failed: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The billing database is temporarily unavailable. Please return to the billing dashboard and try again.';
        exit;
    }
    if (!is_string($active) || $active === '') {
        unset($_SESSION['akh_billing_user'], $_SESSION['akh_billing_role']);
        header('Location: ' . base_path('billing-dashboard/'));
        exit;
    }

    return true;
}
