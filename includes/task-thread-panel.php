<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $t
 * @return list<array{at: string, role: string, who: string, text: string, source: string}>
 */
function akh_task_thread_conversation_for(array $t): array
{
    require_once __DIR__ . '/whatsapp-messages.php';

    return akh_task_merged_conversation_list($t);
}

/**
 * Fingerprint for live thread sync (portal + WhatsApp message counts / latest WA id).
 *
 * @param array<string, mixed> $t
 */
function akh_task_merged_conversation_sig(array $t): string
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-messages.php';

    $tid = akh_task_normalize_id((string) ($t['id'] ?? ''));
    $parts = [(string) count(akh_task_conversation_list($t))];
    if ($tid !== '' && akh_wa_messages_table_exists()) {
        $waRows = akh_wa_messages_list_for_task($tid);
        $maxId = 0;
        foreach ($waRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }
        $parts[] = (string) count($waRows);
        $parts[] = (string) $maxId;
    }

    return hash('sha256', implode('|', $parts));
}

/**
 * @param array{at?: string, role?: string, who?: string, text?: string, source?: string, media_url?: string, media_filename?: string, media_kind?: string} $row
 */
function akh_render_task_thread_message_body(array $row): void
{
    $text = trim((string) ($row['text'] ?? ''));
    $mediaUrl = trim((string) ($row['media_url'] ?? ''));
    $mediaFilename = trim((string) ($row['media_filename'] ?? ''));
    $mediaKind = strtolower(trim((string) ($row['media_kind'] ?? '')));

    if ($mediaUrl !== '') {
        if ($mediaKind === 'image') {
            ?>
            <a class="ticket__msg-media-link" href="<?php echo h($mediaUrl); ?>" target="_blank" rel="noopener noreferrer">
              <img
                class="ticket__msg-media ticket__msg-media--image"
                src="<?php echo h($mediaUrl); ?>"
                alt="<?php echo h($mediaFilename !== '' ? $mediaFilename : 'Image from client'); ?>"
                loading="lazy"
                decoding="async"
              />
            </a>
            <?php
        } elseif ($mediaKind === 'video') {
            ?>
            <video class="ticket__msg-media ticket__msg-media--video" controls preload="metadata" playsinline>
              <source src="<?php echo h($mediaUrl); ?>" />
            </video>
            <?php
        } elseif ($mediaKind === 'audio') {
            ?>
            <audio class="ticket__msg-media ticket__msg-media--audio" controls preload="metadata">
              <source src="<?php echo h($mediaUrl); ?>" />
            </audio>
            <?php
        } else {
            ?>
            <a class="ticket__msg-media-link ticket__msg-media-link--file" href="<?php echo h($mediaUrl); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo h($mediaFilename !== '' ? $mediaFilename : 'Open attachment'); ?>
            </a>
            <?php
        }
    }

    if ($text !== '') {
        ?>
        <div class="ticket__msg-body"><?php echo nl2br(h($text)); ?></div>
        <?php
    }
}

/**
 * @param list<array{at: string, role: string, who: string, text: string, source?: string, media_url?: string, media_filename?: string, media_kind?: string}> $conv
 */
function akh_render_task_thread_bubbles(array $conv, string $portal): void
{
    $isClient = $portal === 'client';
    foreach ($conv as $row) {
        if (!is_array($row)) {
            continue;
        }
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
            $whoLabel = $role === 'editor' ? 'You' : (string) ($row['who'] ?? 'Client');
        }
        ?>
        <div class="<?php echo h($bubbleClass); ?>">
          <div class="ticket__msg-meta">
            <span><?php echo h($whoLabel); ?></span>
            <span class="ticket__msg-time"><?php echo h((string) ($row['at'] ?? '')); ?></span>
          </div>
          <?php akh_render_task_thread_message_body($row); ?>
        </div>
        <?php
    }
}

/**
 * @param array<string, mixed> $t
 */
function akh_render_task_thread_scroll_html(array $t, string $portal): string
{
    $tid = (string) ($t['id'] ?? '');
    $assigned = trim((string) ($t['assigned_editor'] ?? ''));
    if ($tid === '' || $assigned === '') {
        return '';
    }

    ob_start();
    $conv = akh_task_thread_conversation_for($t);
    if ($conv === []) {
        echo '<p class="ticket__thread-empty">No messages yet.</p>';
    } else {
        akh_render_task_thread_bubbles($conv, $portal);
    }

    return (string) ob_get_clean();
}

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
        <?php
        $conv = akh_task_thread_conversation_for($t);
        if ($conv === []): ?>
          <p class="ticket__thread-empty">No messages yet.</p>
        <?php else:
            akh_render_task_thread_bubbles($conv, $portal);
        endif; ?>
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
