<?php

declare(strict_types=1);

require_once AKH_ROOT . '/includes/admin-meta.php';

/** @var string $adminNavActive */

$adminLeaveBellCount = 0;
if (AKH_EDITOR_ATTENDANCE_ENABLED) {
    require_once AKH_ROOT . '/includes/editor-leave.php';
    $adminLeaveBellCount = akh_editor_leave_pending_count();
}

$items = [
    'index.php' => 'Overview',
    'tasks.php' => 'Tasks',
    'attendance.php' => 'Attendance',
    'dashboard-access.php' => 'Access',
    'account.php' => 'Account',
];
$base = base_path('admin/');
$am = akh_admin_meta();
$showVerifyBanner = AKH_SMTP_ENABLED
    && trim((string) ($am['email'] ?? '')) !== ''
    && !($am['email_verified'] ?? false);
?>
<nav class="admin-nav" aria-label="Admin console">
  <?php if ($adminLeaveBellCount > 0): ?>
    <a class="admin-leave-bell" href="<?php echo h($base . 'attendance.php#leave-queue'); ?>" title="Pending leave requests">
      <span class="admin-leave-bell__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
        </svg>
      </span>
      <span class="admin-leave-bell__count"><?php echo (int) $adminLeaveBellCount; ?></span>
      <span class="visually-hidden"><?php echo (int) $adminLeaveBellCount; ?> pending leave requests</span>
    </a>
  <?php endif; ?>
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
