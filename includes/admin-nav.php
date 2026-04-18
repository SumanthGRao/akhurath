<?php

declare(strict_types=1);

require_once AKH_ROOT . '/includes/admin-meta.php';

/** @var string $adminNavActive */

$items = [
    'index.php' => 'Overview',
    'tasks.php' => 'Tasks',
    'attendance.php' => 'Attendance',
    'account.php' => 'Account',
];
$base = base_path('admin/');
$am = akh_admin_meta();
$showVerifyBanner = AKH_SMTP_ENABLED
    && trim((string) ($am['email'] ?? '')) !== ''
    && !($am['email_verified'] ?? false);
?>
<nav class="admin-nav" aria-label="Admin console">
  <?php foreach ($items as $file => $label): ?>
    <?php
    $href = $base . $file;
    $isActive = ($adminNavActive ?? '') === $file;
    ?>
    <a class="admin-nav__link<?php echo $isActive ? ' is-active' : ''; ?>" href="<?php echo h($href); ?>"><?php echo h($label); ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($showVerifyBanner && ($adminNavActive ?? '') !== 'account.php'): ?>
  <p class="banner banner--info" role="status" style="margin:0.75rem 0 0">Verify your admin email — open <a class="text-link" href="<?php echo h($base . 'account.php'); ?>">Account</a> to resend the link if needed.</p>
<?php endif; ?>
