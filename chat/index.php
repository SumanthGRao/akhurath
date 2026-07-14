<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/chat-dashboard-model.php';

$pageTitle = 'Chat dashboard — ' . SITE_NAME;
$bodyClass = 'page-portal page-chat-dashboard';
$vm = akh_chat_dashboard_view_model();

$rows = $vm['rows'];
$attendance = $vm['attendance'];
$logs = $vm['logs'];
$counters = $vm['counters'];
$editorsClockedIn = $vm['editors_clocked_in'];
$dashboardError = (string) ($vm['error'] ?? '');

$totalRows = (int) ($counters['total_rows'] ?? count($rows));
$activeChats = (int) ($counters['active_chats'] ?? $totalRows);
$unreadCount = (int) ($counters['unread'] ?? 0);
$editorsOnline = (int) ($counters['editors_online'] ?? count($editorsClockedIn));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo h($pageTitle); ?></title>
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/site.css')); ?>" />
  <style>
    .chat-dash { max-width: 1100px; margin: 2rem auto; padding: 0 1rem 3rem; }
    .chat-dash__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin: 1.5rem 0; }
    .chat-dash__card { background: #fff; border: 1px solid #e8e4df; border-radius: 12px; padding: 1rem; }
    .chat-dash__card strong { display: block; font-size: 1.5rem; }
    .chat-dash__panel { background: #fff; border: 1px solid #e8e4df; border-radius: 12px; padding: 1rem; margin-top: 1rem; }
    .chat-dash__list { list-style: none; margin: 0; padding: 0; }
    .chat-dash__list li { padding: .55rem 0; border-bottom: 1px solid #f0ece6; }
    .chat-dash__empty { color: #6b6560; margin: .5rem 0 0; }
    .chat-dash__banner { background: #fff4e5; border: 1px solid #f0d2a6; color: #6a4a12; border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
    .chat-dash table { width: 100%; border-collapse: collapse; }
    .chat-dash th, .chat-dash td { text-align: left; padding: .6rem .4rem; border-bottom: 1px solid #f0ece6; vertical-align: top; }
  </style>
</head>
<body class="<?php echo h($bodyClass); ?>">
  <main class="chat-dash" id="main">
    <h1>Chat dashboard</h1>
    <p>Live data from the n8n API bridge (<code>$dashboard_data</code>).</p>

    <?php if ($dashboardError !== ''): ?>
      <p class="chat-dash__banner" role="status"><?php echo h($dashboardError); ?> Layout will stay visible with empty sections.</p>
    <?php endif; ?>

    <div class="chat-dash__grid" aria-label="Layout counters">
      <div class="chat-dash__card">
        <span>Total rows</span>
        <strong><?php echo (int) $totalRows; ?></strong>
      </div>
      <div class="chat-dash__card">
        <span>Active chats</span>
        <strong><?php echo (int) $activeChats; ?></strong>
      </div>
      <div class="chat-dash__card">
        <span>Unread</span>
        <strong><?php echo (int) $unreadCount; ?></strong>
      </div>
      <div class="chat-dash__card">
        <span>Editors online</span>
        <strong><?php echo (int) $editorsOnline; ?></strong>
      </div>
    </div>

    <section class="chat-dash__panel" aria-label="Attendance tracker">
      <h2>Attendance</h2>
      <?php if ($editorsClockedIn !== []): ?>
        <p>Clocked in: <?php echo h(implode(', ', $editorsClockedIn)); ?></p>
      <?php endif; ?>
      <?php if ($attendance === []): ?>
        <p class="chat-dash__empty">No attendance events in the current payload.</p>
      <?php else: ?>
        <ul class="chat-dash__list">
          <?php foreach ($attendance as $event): ?>
            <?php if (!is_array($event)) { continue; } ?>
            <li><?php echo h(akh_chat_dashboard_attendance_label($event)); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="chat-dash__panel" aria-label="Activity logs">
      <h2>Logs</h2>
      <?php if ($logs === []): ?>
        <p class="chat-dash__empty">No log lines in the current payload.</p>
      <?php else: ?>
        <ul class="chat-dash__list">
          <?php foreach ($logs as $log): ?>
            <?php if (!is_array($log)) { continue; } ?>
            <li><?php echo h(akh_chat_dashboard_log_label($log)); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="chat-dash__panel" aria-label="Chat rows">
      <h2>Rows <span class="chat-dash__empty">(<?php echo count($rows); ?>)</span></h2>
      <?php if ($rows === []): ?>
        <p class="chat-dash__empty">No chat/task rows returned.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Label</th>
              <th>Preview</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php if (!is_array($row)) { continue; } ?>
              <tr>
                <td><?php echo h(akh_chat_dashboard_row_label($row)); ?></td>
                <td><?php echo h(akh_chat_dashboard_row_preview($row)); ?></td>
                <td><?php echo h(trim((string) ($row['status'] ?? '—'))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
