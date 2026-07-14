<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/chat-dashboard-model.php';

$pageTitle = 'Chat dashboard — ' . SITE_NAME;
$bodyClass = 'page-portal page-chat-dashboard';
$vm = akh_chat_dashboard_view_model();

$tasks = $vm['tasks'];
$taskPool = $vm['task_pool'];
$whatsappTasks = $vm['whatsapp_tasks'];
$attendance = $vm['attendance'];
$editors = $vm['editors'];
$logs = $vm['logs'];
$meetings = $vm['meetings'];
$alerts = $vm['alerts'];
$counters = $vm['counters'];
$editorsClockedIn = $vm['editors_clocked_in'];
$receivedKeys = $vm['received_keys'];
$dashboardError = (string) ($vm['error'] ?? '');

$displayTasks = $whatsappTasks !== [] ? $whatsappTasks : $tasks;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo h($pageTitle); ?></title>
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/site.css')); ?>" />
  <style>
    .chat-dash { max-width: 1200px; margin: 2rem auto; padding: 0 1rem 3rem; }
    .chat-dash__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin: 1.5rem 0; }
    .chat-dash__card { background: #fff; border: 1px solid #e8e4df; border-radius: 12px; padding: 1rem; }
    .chat-dash__card strong { display: block; font-size: 1.5rem; }
    .chat-dash__panel { background: #fff; border: 1px solid #e8e4df; border-radius: 12px; padding: 1rem; margin-top: 1rem; }
    .chat-dash__list { list-style: none; margin: 0; padding: 0; }
    .chat-dash__list li { padding: .55rem 0; border-bottom: 1px solid #f0ece6; }
    .chat-dash__empty { color: #6b6560; margin: .5rem 0 0; }
    .chat-dash__banner { background: #fff4e5; border: 1px solid #f0d2a6; color: #6a4a12; border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
    .chat-dash__meta { color: #6b6560; font-size: .9rem; margin-top: .25rem; }
    .chat-dash table { width: 100%; border-collapse: collapse; }
    .chat-dash th, .chat-dash td { text-align: left; padding: .6rem .4rem; border-bottom: 1px solid #f0ece6; vertical-align: top; }
    .chat-dash__pill { display: inline-block; padding: .15rem .45rem; border-radius: 999px; font-size: .75rem; background: #eef6ff; color: #1d4f91; }
    .chat-dash__pill--alert { background: #fff0f0; color: #9b1c1c; }
  </style>
</head>
<body class="<?php echo h($bodyClass); ?>">
  <main class="chat-dash" id="main">
    <h1>Chat dashboard</h1>
    <p>Grouped payload from n8n → <code>$dashboard_data</code></p>

    <?php if ($dashboardError !== ''): ?>
      <p class="chat-dash__banner" role="alert"><?php echo h($dashboardError); ?></p>
    <?php endif; ?>

    <?php if ($receivedKeys !== []): ?>
      <p class="chat-dash__meta">Received sections: <?php echo h(implode(', ', $receivedKeys)); ?></p>
    <?php endif; ?>

    <div class="chat-dash__grid" aria-label="Layout counters">
      <div class="chat-dash__card">
        <span>Total tasks</span>
        <strong><?php echo (int) ($counters['total_tasks'] ?? count($displayTasks)); ?></strong>
      </div>
      <div class="chat-dash__card">
        <span>Pool (unassigned)</span>
        <strong><?php echo (int) ($counters['pool_count'] ?? count($taskPool)); ?></strong>
      </div>
      <div class="chat-dash__card">
        <span>Unread messages</span>
        <strong><?php echo (int) ($counters['unread_messages'] ?? 0); ?></strong>
      </div>
      <div class="chat-dash__card">
        <span>Editors online</span>
        <strong><?php echo (int) ($counters['editors_online'] ?? count($editorsClockedIn)); ?></strong>
      </div>
    </div>

    <section class="chat-dash__panel" aria-label="Editor status">
      <h2>Editor status</h2>
      <?php if ($editorsClockedIn !== []): ?>
        <p>Clocked in now: <strong><?php echo h(implode(', ', $editorsClockedIn)); ?></strong></p>
      <?php endif; ?>
      <?php if ($editors === [] && $editorsClockedIn === []): ?>
        <p class="chat-dash__empty">No <code>editors</code> array in payload. n8n should query <code>users</code> (role=editor) and set <code>clocked_in</code> from attendance.</p>
      <?php else: ?>
        <ul class="chat-dash__list">
          <?php foreach ($editors as $ed): ?>
            <?php if (!is_array($ed)) { continue; } ?>
            <li><?php echo h(akh_chat_dashboard_editor_label($ed)); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="chat-dash__panel" aria-label="Attendance tracker">
      <h2>Attendance</h2>
      <?php if ($attendance === []): ?>
        <p class="chat-dash__empty">No <code>attendance</code> events. n8n should return punches from <code>app_kv</code> key <code>editor_attendance</code> → <code>events[]</code>.</p>
      <?php else: ?>
        <ul class="chat-dash__list">
          <?php foreach (array_slice($attendance, -20) as $event): ?>
            <?php if (!is_array($event)) { continue; } ?>
            <li><?php echo h(akh_chat_dashboard_attendance_label($event)); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="chat-dash__panel" aria-label="Task pool">
      <h2>Task pool <span class="chat-dash__empty">(<?php echo count($taskPool); ?>)</span></h2>
      <?php if ($taskPool === []): ?>
        <p class="chat-dash__empty">No unassigned tasks in <code>task_pool</code> or <code>tasks</code> with status=new.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>ID</th><th>Title</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($taskPool as $row): ?>
              <?php if (!is_array($row)) { continue; } ?>
              <tr>
                <td><?php echo h(akh_chat_dashboard_task_label($row)); ?></td>
                <td><?php echo h(akh_chat_dashboard_task_title($row)); ?></td>
                <td><?php echo h(trim((string) ($row['status'] ?? 'new'))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="chat-dash__panel" aria-label="Tasks">
      <h2>Tasks <span class="chat-dash__empty">(<?php echo count($displayTasks); ?>)</span></h2>
      <?php if ($displayTasks === []): ?>
        <p class="chat-dash__empty">No <code>whatsapp_tasks</code> or <code>tasks</code> arrays in payload.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Code</th><th>Customer / title</th><th>Editor</th><th>Status</th><th>Alert</th></tr></thead>
          <tbody>
            <?php foreach ($displayTasks as $row): ?>
              <?php if (!is_array($row)) { continue; }
              $code = akh_chat_dashboard_task_label($row);
              $alert = $alerts[$code] ?? null;
              ?>
              <tr>
                <td><?php echo h($code); ?></td>
                <td><?php echo h(akh_chat_dashboard_task_title($row)); ?></td>
                <td><?php echo h(akh_chat_dashboard_task_editor($row)); ?></td>
                <td><?php echo h(trim((string) ($row['status'] ?? '—'))); ?></td>
                <td>
                  <?php if (is_array($alert)): ?>
                    <span class="chat-dash__pill chat-dash__pill--alert"><?php echo h((string) ($alert['kind'] ?? 'alert')); ?></span>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="chat-dash__panel" aria-label="Activity logs">
      <h2>Logs <span class="chat-dash__empty">(<?php echo count($logs); ?>)</span></h2>
      <?php if ($logs === []): ?>
        <p class="chat-dash__empty">No <code>logs</code> / <code>whatsapp_messages</code> in payload.</p>
      <?php else: ?>
        <ul class="chat-dash__list">
          <?php foreach (array_slice($logs, 0, 30) as $log): ?>
            <?php if (!is_array($log)) { continue; } ?>
            <li><?php echo h(akh_chat_dashboard_log_label($log)); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($meetings !== []): ?>
    <section class="chat-dash__panel" aria-label="Meetings">
      <h2>Meetings (<?php echo count($meetings); ?>)</h2>
      <ul class="chat-dash__list">
        <?php foreach ($meetings as $m): ?>
          <?php if (!is_array($m)) { continue; } ?>
          <li><?php echo h(trim((string) ($m['task_code'] ?? '')) . ' · ' . trim((string) ($m['start_time'] ?? ''))); ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </main>
</body>
</html>
