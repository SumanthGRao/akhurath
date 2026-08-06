<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-board.php';

if (!akh_wa_board_enabled()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'WhatsApp board is disabled.';
    exit;
}

$pageTitle = 'WhatsApp board — ' . SITE_NAME;
$metaDescription = 'Live WhatsApp tasks and meetings — read-only view.';
$bodyClass = 'page-wa-board';

$dbError = '';
$board = ['sig' => 'empty', 'tasks' => [], 'meetings' => [], 'notify_count' => 0];

try {
    if (!akh_wa_tasks_table_exists()) {
        $dbError = 'Table whatsapp_tasks was not found. Import sql/migrations/004_whatsapp_tasks.sql in phpMyAdmin.';
    } else {
        $board = akh_wa_board_payload();
    }
} catch (Throwable $e) {
    $dbError = trim((string) $e->getMessage()) !== '' ? $e->getMessage() : 'Could not load board.';
}

$apiUrl = base_path('whatsapp/board-api.php');
$loginUrl = base_path('whatsapp/login.php');
$waCssVer = is_file(AKH_ROOT . '/assets/css/whatsapp-dashboard.css') ? (string) filemtime(AKH_ROOT . '/assets/css/whatsapp-dashboard.css') : '';
$boardCssVer = is_file(AKH_ROOT . '/assets/css/whatsapp-board.css') ? (string) filemtime(AKH_ROOT . '/assets/css/whatsapp-board.css') : '';
$boardJsVer = is_file(AKH_ROOT . '/assets/js/whatsapp-board.js') ? (string) filemtime(AKH_ROOT . '/assets/js/whatsapp-board.js') : '';
$refreshSec = defined('AKH_WA_DASHBOARD_REFRESH_SECONDS') ? max(30, (int) AKH_WA_DASHBOARD_REFRESH_SECONDS) : 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="<?php echo h($metaDescription); ?>" />
  <title><?php echo h($pageTitle); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@600;700;800&family=Sometype+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/whatsapp-dashboard.css') . ($waCssVer !== '' ? '?v=' . rawurlencode($waCssVer) : '')); ?>" />
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/whatsapp-board.css') . ($boardCssVer !== '' ? '?v=' . rawurlencode($boardCssVer) : '')); ?>" />
  <?php
    require_once AKH_ROOT . '/includes/theme-mode.php';
    akh_theme_mode_head($bodyClass);
  ?>
</head>
<body class="<?php echo h(akh_theme_mode_body_class($bodyClass)); ?>">
  <a class="skip-link" href="#main">Skip to content</a>

  <header class="wab-topbar">
    <div class="wab-topbar__inner">
      <div class="wab-topbar__brand">
        <p class="wab-topbar__kicker"><?php echo h(SITE_NAME); ?></p>
        <h1 class="wab-topbar__title">WhatsApp board</h1>
        <p class="wab-topbar__sub">Live tasks &amp; meetings · read-only</p>
      </div>
      <div class="wab-topbar__actions">
        <?php akh_theme_mode_toggle(); ?>
        <div class="wab-live" id="wab-live" aria-live="polite">
          <span class="wab-live__dot" aria-hidden="true"></span>
          <span class="wab-live__label" id="wab-live-label">Live</span>
        </div>
        <a class="wa-btn wa-btn--primary" href="<?php echo h($loginUrl); ?>">Sign in to manage</a>
      </div>
    </div>
  </header>

  <main id="main" class="wab-main">
    <?php if ($dbError !== ''): ?>
      <p class="wa-banner wa-banner--err" role="alert"><?php echo h($dbError); ?></p>
    <?php else: ?>

    <div class="wab-columns">
      <section class="wab-col" aria-labelledby="wab-tasks-heading">
        <header class="wab-col__head">
          <h2 class="wab-col__title" id="wab-tasks-heading">Tasks</h2>
          <span class="wab-col__count" id="wab-tasks-count"><?php echo count($board['tasks']); ?></span>
        </header>
        <label class="wab-search">
          <span class="visually-hidden">Search tasks</span>
          <input type="search" id="wab-task-search" placeholder="Search tasks…" autocomplete="off" />
        </label>
        <div class="wab-list" id="wab-tasks-list" role="list"></div>
      </section>

      <section class="wab-col" aria-labelledby="wab-meetings-heading">
        <header class="wab-col__head">
          <h2 class="wab-col__title" id="wab-meetings-heading">Meetings</h2>
          <span class="wab-col__count" id="wab-meetings-count"><?php echo count($board['meetings']); ?></span>
        </header>
        <div class="wab-list" id="wab-meetings-list" role="list"></div>
      </section>
    </div>

    <section class="wab-detail" id="wab-detail" hidden aria-labelledby="wab-detail-title">
      <header class="wab-detail__head">
        <div>
          <p class="wab-detail__kicker">Task details</p>
          <h2 class="wab-detail__title" id="wab-detail-title">—</h2>
        </div>
        <button type="button" class="wab-detail__close" id="wab-detail-close" aria-label="Close details">×</button>
      </header>
      <div class="wab-detail__body" id="wab-detail-body"></div>
      <p class="wab-detail__hint">To assign editors, reply to clients, or mark updates read, <a href="<?php echo h($loginUrl); ?>">sign in to the dashboard</a>.</p>
    </section>

    <?php endif; ?>
  </main>

  <script>
    window.WA_BOARD = <?php echo json_encode([
        'apiUrl' => $apiUrl,
        'loginUrl' => $loginUrl,
        'initialSig' => (string) ($board['sig'] ?? ''),
        'tasks' => $board['tasks'] ?? [],
        'meetings' => $board['meetings'] ?? [],
        'notifyCount' => (int) ($board['notify_count'] ?? 0),
        'refreshSeconds' => $refreshSec,
        'pollMs' => 5000,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  </script>
  <script src="<?php echo h(base_path('assets/js/whatsapp-board.js') . ($boardJsVer !== '' ? '?v=' . rawurlencode($boardJsVer) : '')); ?>" defer></script>
</body>
</html>
