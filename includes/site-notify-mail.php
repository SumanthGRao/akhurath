<?php

declare(strict_types=1);

require_once __DIR__ . '/smtp-mail.php';
require_once __DIR__ . '/customer-email-store.php';

function akh_site_notify_smtp_enabled(): bool
{
    return AKH_SMTP_ENABLED
        && trim(AKH_SMTP_FROM_EMAIL) !== ''
        && trim(AKH_SMTP_USER) !== ''
        && AKH_SMTP_PASS !== '';
}

/**
 * @return array{ok: bool, error: string, skipped: bool}
 */
function akh_site_notify_skip_result(string $error): array
{
    return ['ok' => false, 'error' => $error, 'skipped' => true];
}

/**
 * @param array{ok: bool, error: string} $r
 * @return array{ok: bool, error: string, skipped: bool}
 */
function akh_site_notify_sent_result(array $r): array
{
    return ['ok' => (bool) ($r['ok'] ?? false), 'error' => (string) ($r['error'] ?? ''), 'skipped' => false];
}

/** @param array{ok: bool, error: string} $r */
function akh_site_notify_log_mail_failure(string $context, array $r): void
{
    if ($r['ok']) {
        return;
    }
    $dir = AKH_ROOT . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = gmdate('c') . " [{$context}] " . $r['error'] . "\n";
    @file_put_contents($dir . '/mail-notify-errors.log', $line, FILE_APPEND | LOCK_EX);
}

function akh_site_mail_contact_ack_to_submitter(string $toEmail, string $name, string $topicLine): void
{
    if (!akh_site_notify_smtp_enabled() || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $subject = 'We received your message — ' . SITE_NAME;
    $body = 'Hi ' . ($name !== '' ? $name : 'there') . ",\n\n"
        . 'Thank you for contacting ' . SITE_NAME . ". We've received your enquiry"
        . ($topicLine !== '' ? " ({$topicLine})" : '') . ".\n\n"
        . "We'll review it and get back to you soon.\n\n"
        . '— ' . SITE_NAME . "\n"
        . CONTACT_EMAIL . "\n";

    akh_site_notify_log_mail_failure('contact_ack', akh_smtp_send($toEmail, $subject, $body));
}

function akh_site_mail_contact_notify_studio(
    string $name,
    string $company,
    string $phone,
    string $email,
    string $project,
    string $topicLine
): void
{
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = trim(LEADS_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $subject = 'New website enquiry — ' . SITE_NAME;
    $body = "New enquiry (Get in touch form)\n\n"
        . 'When (UTC): ' . gmdate('c') . "\n"
        . 'Name: ' . $name . "\n"
        . 'Company: ' . $company . "\n"
        . 'Phone: ' . $phone . "\n"
        . 'Email: ' . $email . "\n"
        . 'Service topic: ' . ($topicLine !== '' ? $topicLine : '—') . "\n\n"
        . "Project details:\n" . $project . "\n";

    akh_site_notify_log_mail_failure('contact_studio', akh_smtp_send($to, $subject, $body));
}

/**
 * Notify studio of a custom package build from /packages (line amounts for internal reference only).
 *
 * @param array<string, array{label: string, qty: int, unit_inr: int, line_inr: int}> $lines
 */
function akh_site_mail_custom_package_studio(
    string $name,
    string $email,
    string $phone,
    string $notes,
    array $lines,
    int $totalInr
): void {
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = trim(LEADS_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $subject = 'Custom package quote request — ' . SITE_NAME;
    $bodyLines = [
        'Custom package builder (/packages)',
        'When (UTC): ' . gmdate('c'),
        '',
        'Client',
        'Name: ' . $name,
        'Email: ' . $email,
        'Phone: ' . $phone,
        '',
        'Selections (internal reference totals):',
    ];
    foreach ($lines as $row) {
        $label = (string) ($row['label'] ?? '');
        $qty = (int) ($row['qty'] ?? 0);
        $unit = (int) ($row['unit_inr'] ?? 0);
        $line = (int) ($row['line_inr'] ?? 0);
        $bodyLines[] = "  • {$label} × {$qty} @ ₹{$unit} = ₹{$line}";
    }
    $bodyLines[] = '';
    $bodyLines[] = 'Reference list build total: ₹' . (string) $totalInr;
    $bodyLines[] = '';
    $bodyLines[] = 'Notes from client:';
    $bodyLines[] = ($notes !== '' ? $notes : '—');

    akh_site_notify_log_mail_failure(
        'custom_package_studio',
        akh_smtp_send($to, $subject, implode("\n", $bodyLines))
    );
}

/**
 * @return array{ok: bool, error: string, skipped: bool}
 */
function akh_site_mail_client_registration_welcome(string $toEmail, string $username): array
{
    if (!akh_site_notify_smtp_enabled() || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return akh_site_notify_skip_result('SMTP disabled or invalid recipient address.');
    }
    $subject = 'Your ' . SITE_NAME . ' client portal account';
    $login = akh_absolute_url('customer/login.php');
    $body = "Hi,\n\n"
        . 'Your client portal account is ready. Username: ' . $username . "\n\n"
        . "Log in here to submit tasks and track status:\n{$login}\n\n"
        . "We'll use this email for task updates when editors post progress.\n\n"
        . '— ' . SITE_NAME . "\n";

    $r = akh_smtp_send($toEmail, $subject, $body);
    akh_site_notify_log_mail_failure('register_client', $r);

    return akh_site_notify_sent_result($r);
}

/**
 * @return array{ok: bool, error: string, skipped: bool}
 */
function akh_site_mail_studio_new_client(string $username, string $email): array
{
    if (!akh_site_notify_smtp_enabled()) {
        return akh_site_notify_skip_result('SMTP disabled.');
    }
    $to = trim(LEADS_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return akh_site_notify_skip_result('Invalid LEADS_EMAIL recipient.');
    }
    $subject = 'New client registration — ' . SITE_NAME;
    $body = "A new client registered on the website.\n\n"
        . 'When (UTC): ' . gmdate('c') . "\n"
        . 'Username: ' . $username . "\n"
        . 'Email: ' . $email . "\n";

    $r = akh_smtp_send($to, $subject, $body);
    akh_site_notify_log_mail_failure('register_studio', $r);

    return akh_site_notify_sent_result($r);
}

function akh_site_mail_studio_new_task(array $task): void
{
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = trim(LEADS_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $id = (string) ($task['id'] ?? '');
    $subject = 'New task ' . $id . ' — ' . SITE_NAME;
    $body = akh_site_plain_task_summary('New task submitted', $task);

    akh_site_notify_log_mail_failure('task_new_studio', akh_smtp_send($to, $subject, $body));
}

function akh_site_mail_client_new_task(string $clientUsername, array $task): void
{
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = akh_customer_email_get($clientUsername);
    if ($to === null) {
        return;
    }
    $id = (string) ($task['id'] ?? '');
    $subject = 'Task received: ' . $id . ' — ' . SITE_NAME;
    $dash = akh_absolute_url('customer/dashboard.php');
    $body = akh_site_plain_task_summary('We received your new task', $task)
        . "\nView progress in your portal (sign in):\n{$dash}\n";

    akh_site_notify_log_mail_failure('task_new_client', akh_smtp_send($to, $subject, $body));
}

/**
 * @param list<array<string, mixed>> $children
 */
function akh_site_mail_studio_new_bundle(array $parent, array $children): void
{
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = trim(LEADS_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $pid = (string) ($parent['id'] ?? '');
    $subject = 'New multi-part job ' . $pid . ' — ' . SITE_NAME;
    $lines = [
        'New multi-part job (bundle)',
        'When (UTC): ' . gmdate('c'),
        'Parent task ID: ' . $pid,
        'Client: ' . ($parent['client_username'] ?? ''),
        'Title: ' . ($parent['title'] ?? ''),
        '',
        'Child tasks:',
    ];
    foreach ($children as $ch) {
        $lines[] = '  — ' . ($ch['id'] ?? '') . ' · ' . ($ch['title'] ?? '');
    }
    $lines[] = '';
    $lines[] = 'Full parent notes:';
    $lines[] = (string) ($parent['description'] ?? '');

    akh_site_notify_log_mail_failure('task_bundle_studio', akh_smtp_send($to, $subject, implode("\n", $lines)));
}

/**
 * @param list<array<string, mixed>> $children
 */
function akh_site_mail_client_new_bundle(string $clientUsername, array $parent, array $children): void
{
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = akh_customer_email_get($clientUsername);
    if ($to === null) {
        return;
    }
    $pid = (string) ($parent['id'] ?? '');
    $subject = 'Multi-part job received: ' . $pid . ' — ' . SITE_NAME;
    $dash = akh_absolute_url('customer/dashboard.php');
    $lines = [
        'We received your multi-part request.',
        '',
        'Summary task ID: ' . $pid,
        'Title: ' . ($parent['title'] ?? ''),
        '',
        'Editor tasks created:',
    ];
    foreach ($children as $ch) {
        $lines[] = '  — ' . ($ch['id'] ?? '') . ' · ' . ($ch['title'] ?? '');
    }
    $lines[] = '';
    $lines[] = 'Track everything in your client portal:';
    $lines[] = $dash;

    akh_site_notify_log_mail_failure('task_bundle_client', akh_smtp_send($to, $subject, implode("\n", $lines)));
}

function akh_site_mail_client_editor_activity(string $clientUsername, array $task, string $headline, string $detail): void
{
    if (!akh_site_notify_smtp_enabled()) {
        return;
    }
    $to = akh_customer_email_get($clientUsername);
    if ($to === null) {
        return;
    }
    $id = (string) ($task['id'] ?? '');
    $subject = 'Update on task ' . $id . ' — ' . SITE_NAME;
    $dash = akh_absolute_url('customer/dashboard.php?ticket=' . rawurlencode($id));
    $body = $headline . "\n\n"
        . $detail . "\n\n"
        . "Open your task desk:\n{$dash}\n\n"
        . '— ' . SITE_NAME . "\n";

    akh_site_notify_log_mail_failure('task_editor_client', akh_smtp_send($to, $subject, $body));
}

function akh_site_mail_delivery_sentence(string $mode): string
{
    if ($mode === 'nas_storage') {
        return 'NAS / Nextcloud (client will upload via drive portal)';
    }
    if ($mode === 'courier_hdd') {
        return 'Courier — hard drive / copy locally (partner will ship media to the studio; no Drive link required)';
    }

    return 'Google Drive';
}

function akh_site_mail_edit_type_label(string $slug): string
{
    $map = [
        'teaser_1min' => '1 min teaser',
        'doc_teaser_2_3min' => '2–3 min documentary teaser',
        'highlights_3_5min' => '3–5 min highlights / film',
        'highlights_5_10min' => '5–10 min highlights / film',
        'film_30min' => '30 min film',
        'traditional_video' => 'Traditional video',
        'other_details' => 'Other (please specify in project details)',
        'studio_admin' => 'Studio (admin entry)',
        'bundle_parent' => 'Multi-part job (overview)',
    ];

    return $map[$slug] ?? $slug;
}

/**
 * @param array<string, mixed> $task
 */
function akh_site_plain_task_summary(string $introLine, array $task): string
{
    $id = (string) ($task['id'] ?? '');
    $mode = akh_site_mail_delivery_sentence((string) ($task['delivery_mode'] ?? 'google_drive'));
    $link = (string) ($task['drive_link'] ?? '');
    $ref = trim((string) ($task['reference_link'] ?? ''));
    $couple = trim((string) ($task['couple_name'] ?? ''));
    $editSlug = (string) ($task['edit_type'] ?? '');
    $lines = [
        $introLine,
        '',
        'When (UTC): ' . gmdate('c'),
        'Task ID: ' . $id,
        'Client login: ' . ($task['client_username'] ?? ''),
        'Title: ' . ($task['title'] ?? ''),
    ];
    if ($couple !== '') {
        $lines[] = 'Couple / project name: ' . $couple;
    }
    if ($editSlug !== '' && $editSlug !== 'bundle_parent') {
        $lines[] = 'Edit type: ' . akh_site_mail_edit_type_label($editSlug);
    }
    if ($ref !== '') {
        $lines[] = 'Reference / style: ' . $ref;
    }
    $lines[] = 'Delivery: ' . $mode;
    if ($link !== '') {
        $lines[] = 'Drive: ' . $link;
    }
    $lines[] = '';
    $lines[] = 'Full notes:';
    $lines[] = (string) ($task['description'] ?? '');

    return implode("\n", $lines);
}
