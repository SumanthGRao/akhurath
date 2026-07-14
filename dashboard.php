<?php

declare(strict_types=1);

/**
 * Task message viewer — reads WhatsApp messages from the n8n bridge (akh_db array).
 *
 * Usage: /dashboard.php?task_code=AS0060
 *        /dashboard.php?ticket=as0060   (case-insensitive)
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/tasks.php';
require_once AKH_ROOT . '/includes/whatsapp-messages.php';

$pageTitle = 'Task messages';
$taskCode = trim((string) ($_GET['task_code'] ?? $_GET['ticket'] ?? ''));
$error = '';
$messages = [];
$displayCode = '';

if ($taskCode === '') {
    $error = 'Add a task code to the URL, e.g. ?task_code=AS0060';
} else {
    $data = akh_db();
    if (!is_array($data)) {
        $error = 'Data bridge is not available. Check config/database.local.php and getAkhurathChatData().';
    } else {
        $displayCode = akh_task_normalize_id($taskCode);
        if ($displayCode === '') {
            $displayCode = $taskCode;
        }
        $messages = akh_db_messages_for_task($taskCode);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo h($pageTitle . ($displayCode !== '' ? ' — ' . $displayCode : '')); ?></title>
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/site.css')); ?>" />
  <style>
    .msg-dash { max-width: 720px; margin: 2rem auto; padding: 0 1rem 3rem; }
    .msg-dash__head { margin-bottom: 1.25rem; }
    .msg-dash__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .75rem; }
    .msg-dash__item { background: #fff; border: 1px solid #e8e4df; border-radius: 10px; padding: .85rem 1rem; }
    .msg-dash__meta { display: flex; justify-content: space-between; gap: 1rem; font-size: .82rem; color: #6b6560; margin-bottom: .35rem; }
    .msg-dash__body { white-space: pre-wrap; line-height: 1.5; }
    .msg-dash__empty, .msg-dash__err { color: #6b6560; }
    .msg-dash__err { background: #fff0f0; border: 1px solid #f0c4c4; color: #9b1c1c; border-radius: 8px; padding: .75rem 1rem; }
    .msg-dash__item--out { border-color: #d4e8ff; background: #f6fbff; }
    .msg-dash__item--in { border-color: #e8e4df; }
  </style>
</head>
<body>
  <main class="msg-dash" id="main">
    <header class="msg-dash__head">
      <h1>Task messages</h1>
      <?php if ($displayCode !== ''): ?>
        <p>Task: <strong><?php echo h($displayCode); ?></strong> · <?php echo count($messages); ?> message<?php echo count($messages) === 1 ? '' : 's'; ?></p>
      <?php endif; ?>
    </header>

    <?php if ($error !== ''): ?>
      <p class="msg-dash__err" role="alert"><?php echo h($error); ?></p>
    <?php elseif ($messages === []): ?>
      <p class="msg-dash__empty">No messages found for this task.</p>
    <?php else: ?>
      <ul class="msg-dash__list">
        <?php foreach ($messages as $row): ?>
          <?php
          if (!is_array($row)) {
              continue;
          }
          $direction = strtolower(trim((string) ($row['direction'] ?? 'incoming')));
          $sender = trim((string) ($row['sender'] ?? 'client'));
          $who = trim((string) ($row['editor_name'] ?? $row['customer_name'] ?? $sender));
          $isOut = $direction === 'outbound' || $direction === 'outgoing';
          ?>
          <li class="msg-dash__item<?php echo $isOut ? ' msg-dash__item--out' : ' msg-dash__item--in'; ?>">
            <div class="msg-dash__meta">
              <span><?php echo h($who !== '' ? $who : ($isOut ? 'Editor' : 'Client')); ?> · <?php echo h($direction !== '' ? $direction : 'message'); ?></span>
              <span><?php echo h((string) ($row['created_at'] ?? '')); ?></span>
            </div>
            <div class="msg-dash__body"><?php echo nl2br(h((string) ($row['message'] ?? ''))); ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </main>
</body>
</html>
