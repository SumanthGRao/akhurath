<?php

declare(strict_types=1);

/**
 * Compact client ↔ editor message column (shown when an editor is assigned).
 *
 * @param array<string, mixed> $t
 */
function akh_render_task_thread_panel(array $t, string $portal, string $csrfToken): void
{
    $tid = (string) ($t['id'] ?? '');
    $assigned = trim((string) ($t['assigned_editor'] ?? ''));
    if ($tid === '' || $assigned === '') {
        return;
    }
    require_once __DIR__ . '/whatsapp-messages.php';
    $conv = akh_task_merged_conversation_list($t);
    $isClient = $portal === 'client';
    $actionField = $isClient ? 'task_action' : 'action';
    $actionVal = 'thread_message';
    ?>
    <aside class="ticket__thread" aria-label="Messages">
      <div class="ticket__thread-head">
        <h3 class="ticket__thread-title">Messages</h3>
        <p class="ticket__thread-lead"><?php echo $isClient ? 'Short notes to your editor.' : 'Short notes to the client.'; ?></p>
      </div>
      <div class="ticket__thread-scroll">
        <?php if ($conv === []): ?>
          <p class="ticket__thread-empty">No messages yet.</p>
        <?php else: ?>
          <?php foreach ($conv as $row): ?>
            <?php
            $role = (string) ($row['role'] ?? '');
            $source = (string) ($row['source'] ?? 'portal');
            if ($role === 'system') {
                $bubbleClass = 'ticket__msg ticket__msg--system';
            } else {
                $bubbleClass = $role === 'editor' ? 'ticket__msg ticket__msg--editor' : 'ticket__msg ticket__msg--client';
            }
            if ($source === 'whatsapp') {
                $whoLabel = (string) ($row['who'] ?? ($role === 'editor' ? 'Editor' : 'Client'));
            } elseif ($isClient) {
                $whoLabel = $role === 'editor' ? 'Editor' : 'You';
            } else {
                $whoLabel = $role === 'editor' ? 'You' : 'Client';
            }
            ?>
            <div class="<?php echo h($bubbleClass); ?>">
              <div class="ticket__msg-meta">
                <span><?php echo h($whoLabel); ?></span>
                <span class="ticket__msg-time"><?php echo h((string) ($row['at'] ?? '')); ?></span>
              </div>
              <div class="ticket__msg-body"><?php echo nl2br(h((string) ($row['text'] ?? ''))); ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <form class="ticket__thread-form" method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
        <input type="hidden" name="<?php echo h($actionField); ?>" value="<?php echo h($actionVal); ?>" />
        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
        <label class="visually-hidden" for="thread-<?php echo h($portal); ?>-<?php echo h($tid); ?>">Message</label>
        <textarea id="thread-<?php echo h($portal); ?>-<?php echo h($tid); ?>" name="thread_body" rows="3" maxlength="2000" placeholder="Write a brief message…" class="ticket__thread-input"></textarea>
        <button type="submit" class="btn btn--primary btn--sm">Send</button>
      </form>
    </aside>
    <?php
}
