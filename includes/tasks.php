<?php

declare(strict_types=1);

function akh_tasks_file(): string
{
    return AKH_ROOT . '/data/tasks.json';
}

function akh_task_notify_dir(): string
{
    return AKH_ROOT . '/data/task-notifications';
}

function akh_task_seq_state_file(): string
{
    return AKH_ROOT . '/data/task-seq.json';
}

function akh_task_editor_seen_file(): string
{
    return AKH_ROOT . '/data/editor-seen-tasks.json';
}

/**
 * Next AS_#### id from counter + existing tasks (locked).
 */
function akh_task_generate_id(): string
{
    $path = akh_task_seq_state_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tasks = akh_tasks_load();
    $boot = akh_task_seq_bootstrap_next($tasks);
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return 'AS_' . strtoupper(bin2hex(random_bytes(3)));
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return 'AS_' . strtoupper(bin2hex(random_bytes(3)));
    }
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $next = $boot;
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $next = max($next, max(1, (int) ($j['next'] ?? 1)));
            }
        }
        $id = sprintf('AS_%04d', $next);
        $nextWrite = $next + 1;
        $out = json_encode(['next' => $nextWrite], JSON_THROW_ON_ERROR);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $out);
        fflush($fp);

        return $id;
    } catch (\Throwable $e) {
        return 'AS_' . strtoupper(bin2hex(random_bytes(3)));
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function akh_task_seq_bootstrap_next(array $tasks): int
{
    $max = 0;
    foreach ($tasks as $t) {
        $id = (string) ($t['id'] ?? '');
        if (preg_match('/^AS_(\d+)$/', $id, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    return $max + 1;
}

/**
 * @return array<string, list<string>>
 */
function akh_task_editor_seen_load(): array
{
    $path = akh_task_editor_seen_file();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $k => $v) {
        if (!is_string($k) || !is_array($v)) {
            continue;
        }
        $out[strtolower($k)] = array_values(array_filter($v, 'is_string'));
    }

    return $out;
}

/**
 * @param array<string, list<string>> $data
 */
function akh_task_editor_seen_save(array $data): bool
{
    $path = akh_task_editor_seen_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        return false;
    }

    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function akh_task_editor_mark_new_seen(string $editorUsername, string $taskId): bool
{
    $editorUsername = strtolower(trim($editorUsername));
    $taskId = trim($taskId);
    if ($editorUsername === '' || $taskId === '') {
        return false;
    }
    $data = akh_task_editor_seen_load();
    if (!isset($data[$editorUsername])) {
        $data[$editorUsername] = [];
    }
    if (!in_array($taskId, $data[$editorUsername], true)) {
        $data[$editorUsername][] = $taskId;
    }
    if (count($data[$editorUsername]) > 500) {
        $data[$editorUsername] = array_slice($data[$editorUsername], -500);
    }

    return akh_task_editor_seen_save($data);
}

function akh_task_editor_unseen_new_count(string $editorUsername): int
{
    $editorUsername = strtolower(trim($editorUsername));
    if ($editorUsername === '') {
        return 0;
    }
    $seen = akh_task_editor_seen_load()[$editorUsername] ?? [];
    $n = 0;
    foreach (akh_tasks_load() as $t) {
        if (!akh_task_editor_pool_eligible($t)) {
            continue;
        }
        $id = (string) ($t['id'] ?? '');
        if ($id === '' || in_array($id, $seen, true)) {
            continue;
        }
        ++$n;
    }

    return $n;
}

function akh_task_editor_board_bell_count(string $editorUsername): int
{
    return akh_task_editor_unseen_new_count($editorUsername) + akh_task_editor_unread_feedback_count($editorUsername);
}

/**
 * @return list<array{at: string, role: string, who: string, text: string}>
 */
function akh_task_conversation_list(array $task): array
{
    $c = $task['conversation'] ?? null;
    if (!is_array($c)) {
        return [];
    }
    $out = [];
    foreach ($c as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'at' => (string) ($row['at'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'who' => (string) ($row['who'] ?? ''),
            'text' => (string) ($row['text'] ?? ''),
        ];
    }

    return $out;
}

function akh_task_status_hue(string $status): int
{
    $map = [
        'new' => 42,
        'assigned' => 218,
        'in_progress' => 205,
        'review' => 262,
        'delivered' => 132,
        'reverted' => 28,
        'closed' => 268,
    ];

    return $map[$status] ?? 200;
}

/**
 * Inline HSL variables for subtle per-client / per-type / per-status tint on tickets.
 *
 * @param array<string, mixed> $task
 */
function akh_task_ticket_style_attr(array $task): string
{
    $client = (string) ($task['client_username'] ?? '');
    $et = (string) ($task['edit_type'] ?? '');
    $st = (string) ($task['status'] ?? 'new');
    $h = $client !== '' ? (crc32($client) % 360) : 210;
    $typeTweak = (int) (abs(crc32($et)) % 28);
    $stHue = akh_task_status_hue($st);

    return sprintf(
        'style="--ticket-h:%d;--type-tweak:%d;--st-h:%d"',
        $h,
        $typeTweak,
        $stHue
    );
}

/**
 * @return string|null error
 */
function akh_task_client_append_thread(string $taskId, string $clientUsername, string $body): ?string
{
    $clientUsername = strtolower(trim($clientUsername));
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 2000) {
        return 'Message must be between 1 and 2000 characters.';
    }
    $list = akh_tasks_load();
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (strtolower((string) ($t['client_username'] ?? '')) !== $clientUsername) {
            return 'Task not found.';
        }
        if (($t['assigned_editor'] ?? null) === null || (string) ($t['assigned_editor'] ?? '') === '') {
            return 'An editor must be assigned before you can send messages.';
        }
        $conv = akh_task_conversation_list($t);
        $conv[] = ['at' => gmdate('c'), 'role' => 'client', 'who' => $clientUsername, 'text' => $body];
        if (count($conv) > 100) {
            $conv = array_slice($conv, -100);
        }
        $list[$i]['conversation'] = $conv;
        $list[$i]['editor_feedback_notify'] = true;
        $list[$i]['updated_at'] = gmdate('c');
        if (!akh_tasks_save_locked($list)) {
            return 'Could not save.';
        }

        return null;
    }

    return 'Task not found.';
}

/**
 * @return string|null error
 */
function akh_task_editor_append_thread(string $taskId, string $editorUsername, string $body): ?string
{
    $editorUsername = strtolower(trim($editorUsername));
    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 2000) {
        return 'Message must be between 1 and 2000 characters.';
    }
    $list = akh_tasks_load();
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (($t['assigned_editor'] ?? null) !== $editorUsername) {
            return 'Task not found.';
        }
        $conv = akh_task_conversation_list($t);
        $conv[] = ['at' => gmdate('c'), 'role' => 'editor', 'who' => $editorUsername, 'text' => $body];
        if (count($conv) > 100) {
            $conv = array_slice($conv, -100);
        }
        $list[$i]['conversation'] = $conv;
        $list[$i]['client_editor_notify'] = true;
        $list[$i]['updated_at'] = gmdate('c');
        if (!akh_tasks_save_locked($list)) {
            return 'Could not save.';
        }

        return null;
    }

    return 'Task not found.';
}

function akh_task_client_unread_editor_count(string $clientUsername): int
{
    $c = strtolower(trim($clientUsername));
    if ($c === '') {
        return 0;
    }
    $n = 0;
    foreach (akh_tasks_load() as $t) {
        if (strtolower((string) ($t['client_username'] ?? '')) !== $c) {
            continue;
        }
        if (akh_task_is_bundle_parent($t)) {
            continue;
        }
        if (($t['client_editor_notify'] ?? false) === true) {
            ++$n;
        }
    }

    return $n;
}

function akh_task_client_clear_editor_notify(string $taskId, string $clientUsername): bool
{
    $c = strtolower(trim($clientUsername));
    $list = akh_tasks_load();
    $changed = false;
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (strtolower((string) ($t['client_username'] ?? '')) !== $c) {
            return false;
        }
        if (akh_task_is_bundle_parent($t)) {
            foreach (akh_task_bundle_children($taskId, $list) as $ch) {
                $cid = (string) ($ch['id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                foreach ($list as $j => $u) {
                    if (($u['id'] ?? '') === $cid) {
                        $list[$j]['client_editor_notify'] = false;
                        $list[$j]['updated_at'] = gmdate('c');
                        $changed = true;
                        break;
                    }
                }
            }
        }
        $list[$i]['client_editor_notify'] = false;
        $list[$i]['updated_at'] = gmdate('c');
        $changed = true;
        break;
    }
    if (!$changed) {
        return false;
    }

    return akh_tasks_save_locked($list);
}

/**
 * AJAX: editor opened a ticket — mark new task seen and/or clear assigned-editor notify.
 *
 * @return array{ok: bool, bell?: int, error?: string}
 */
function akh_task_ajax_editor_view_ack(string $editorUsername, string $taskId, string $ackKind): array
{
    $editorUsername = strtolower(trim($editorUsername));
    $taskId = trim($taskId);
    $ackKind = trim($ackKind);
    if ($taskId === '') {
        return ['ok' => false, 'error' => 'bad_task'];
    }
    $t = akh_task_by_id($taskId);
    if ($t === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if ($ackKind === 'new') {
        if (($t['status'] ?? '') !== 'new' || ($t['assigned_editor'] ?? null) !== null) {
            return ['ok' => false, 'error' => 'not_new'];
        }
        akh_task_editor_mark_new_seen($editorUsername, $taskId);
    } elseif ($ackKind === 'editor_task') {
        if (strtolower((string) ($t['assigned_editor'] ?? '')) !== $editorUsername) {
            return ['ok' => false, 'error' => 'not_yours'];
        }
        akh_task_editor_clear_feedback_notify($taskId, $editorUsername);
    } else {
        return ['ok' => false, 'error' => 'bad_kind'];
    }

    return ['ok' => true, 'bell' => akh_task_editor_board_bell_count($editorUsername)];
}

/**
 * @return array{ok: bool, bell?: int, error?: string}
 */
function akh_task_ajax_client_view_ack(string $clientUsername, string $taskId): array
{
    $clientUsername = strtolower(trim($clientUsername));
    $taskId = trim($taskId);
    if ($taskId === '' || !akh_task_client_clear_editor_notify($taskId, $clientUsername)) {
        return ['ok' => false, 'error' => 'bad_task'];
    }

    return ['ok' => true, 'bell' => akh_task_client_unread_editor_count($clientUsername)];
}

/**
 * @return list<array<string, mixed>>
 */
function akh_tasks_load(): array
{
    $path = akh_tasks_file();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, 'is_array'));
}

/**
 * @param list<array<string, mixed>> $tasks
 */
function akh_tasks_save_locked(array $tasks): bool
{
    $path = akh_tasks_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return false;
    }
    try {
        rewind($fp);
        $json = json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ftruncate($fp, 0);
        rewind($fp);
        $ok = fwrite($fp, $json) !== false;
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return $ok;
}

function akh_task_by_id(string $id): ?array
{
    foreach (akh_tasks_load() as $t) {
        if (($t['id'] ?? '') === $id) {
            return $t;
        }
    }

    return null;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_tasks_for_client(string $username): array
{
    $out = [];
    foreach (akh_tasks_load() as $t) {
        if (($t['client_username'] ?? '') === $username) {
            $out[] = $t;
        }
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_tasks_all_sorted(): array
{
    $all = akh_tasks_load();
    usort($all, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $all;
}

function akh_task_status_label(string $status): string
{
    $map = [
        'new' => 'New — awaiting editor',
        'assigned' => 'Assigned',
        'in_progress' => 'In progress',
        'review' => 'Internal review',
        'delivered' => 'Delivered',
        'reverted' => 'Returned for revision',
        'closed' => 'Closed',
    ];

    return $map[$status] ?? $status;
}

/**
 * Edit types shown on the client dashboard (slug => label).
 *
 * @return array<string, string>
 */
function akh_task_client_edit_types(): array
{
    return [
        'teaser_1min' => '1 min teaser',
        'doc_teaser_2_3min' => '2–3 min documentary teaser',
        'highlights_3_5min' => '3–5 min highlights / film',
        'highlights_5_10min' => '5–10 min highlights / film',
        'film_30min' => '30 min film',
        'traditional_video' => 'Traditional video',
        'other_details' => 'Other (please specify in project details)',
    ];
}

/**
 * Labels for any stored edit_type slug (includes admin-only types).
 */
function akh_task_edit_type_label(string $slug): string
{
    $extra = [
        'studio_admin' => 'Studio (admin entry)',
        'bundle_parent' => 'Multi-part job (overview)',
    ];

    return akh_task_client_edit_types()[$slug] ?? ($extra[$slug] ?? $slug);
}

function akh_task_is_bundle_parent(array $t): bool
{
    return ($t['task_role'] ?? '') === 'bundle_parent';
}

function akh_task_is_bundle_child(array $t): bool
{
    return ($t['task_role'] ?? '') === 'bundle_child';
}

/**
 * Editors may claim normal “new” tasks and bundle children, never the bundle coordinator row.
 */
function akh_task_editor_pool_eligible(array $t): bool
{
    if (akh_task_is_bundle_parent($t)) {
        return false;
    }
    if (($t['status'] ?? '') !== 'new' || ($t['assigned_editor'] ?? null) !== null) {
        return false;
    }

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_task_bundle_children(string $parentId, array $tasks): array
{
    $parentId = trim($parentId);
    if ($parentId === '') {
        return [];
    }
    $out = [];
    foreach ($tasks as $t) {
        if (!is_array($t)) {
            continue;
        }
        if ((string) ($t['parent_task_id'] ?? '') === $parentId) {
            $out[] = $t;
        }
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });

    return $out;
}

/** Lower = earlier in pipeline (less complete). */
function akh_task_pipeline_rank(string $status): int
{
    static $map = [
        'new' => 0,
        'assigned' => 1,
        'in_progress' => 2,
        'review' => 3,
        'reverted' => 4,
        'delivered' => 5,
        'closed' => 6,
    ];

    return $map[$status] ?? 0;
}

/** @return list<string> */
function akh_task_pipeline_statuses_in_order(): array
{
    return ['new', 'assigned', 'in_progress', 'review', 'reverted', 'delivered', 'closed'];
}

function akh_task_pipeline_status_from_rank(int $rank): string
{
    $order = akh_task_pipeline_statuses_in_order();

    return $order[max(0, min($rank, count($order) - 1))] ?? 'new';
}

/**
 * Keeps the bundle parent row aligned with the least-advanced child (one client-facing job).
 */
function akh_task_bundle_sync_parent(string $parentId): bool
{
    $parentId = trim($parentId);
    if ($parentId === '') {
        return false;
    }
    $list = akh_tasks_load();
    $pIdx = null;
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') === $parentId && akh_task_is_bundle_parent($t)) {
            $pIdx = $i;
            break;
        }
    }
    if ($pIdx === null) {
        return false;
    }
    $children = akh_task_bundle_children($parentId, $list);
    if ($children === []) {
        return false;
    }
    $minRank = 99;
    foreach ($children as $c) {
        $minRank = min($minRank, akh_task_pipeline_rank((string) ($c['status'] ?? 'new')));
    }
    $newStatus = akh_task_pipeline_status_from_rank($minRank);
    $list[$pIdx]['status'] = $newStatus;
    $list[$pIdx]['updated_at'] = gmdate('c');

    return akh_tasks_save_locked($list);
}

/**
 * When an editor or status change touches a child, mirror client bell to the bundle parent.
 */
function akh_task_bundle_flag_parent_client_notify(string $parentId): bool
{
    $parentId = trim($parentId);
    if ($parentId === '') {
        return false;
    }
    $list = akh_tasks_load();
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $parentId || !akh_task_is_bundle_parent($t)) {
            continue;
        }
        $list[$i]['client_editor_notify'] = true;
        $list[$i]['updated_at'] = gmdate('c');

        return akh_tasks_save_locked($list);
    }

    return false;
}

function akh_task_bundle_build_parent_title(string $coupleName, int $partCount): string
{
    $suffix = ' — Multi-part request (' . $partCount . ' deliverables)';
    $title = trim($coupleName);
    if (mb_strlen($title . $suffix) > 200) {
        $budget = 200 - mb_strlen($suffix);
        if ($budget < 8) {
            $budget = 8;
        }
        $title = mb_substr(trim($coupleName), 0, $budget) . '…';
    }

    return $title . $suffix;
}

/**
 * @param list<string> $editTypeSlugs
 * @return array{0: string, 1: bool}
 */
function akh_task_bundle_build_parent_description(
    string $coupleName,
    array $editTypeSlugs,
    string $projectDetails,
    string $referenceLink,
    string $deliveryMode,
    string $driveLink
): array {
    $labels = [];
    foreach ($editTypeSlugs as $slug) {
        $labels[] = akh_task_edit_type_label((string) $slug);
    }
    $typeLine = 'Types of edit: ' . implode('; ', $labels);
    $descParts = [
        'Couple / project name: ' . $coupleName,
        $typeLine,
        '',
        'Project details:',
        $projectDetails,
        '',
    ];
    if ($referenceLink !== '') {
        $descParts[] = 'Reference / style link: ' . $referenceLink;
    } else {
        $descParts[] = 'Reference / style link: — (not supplied)';
    }
    $descParts[] = '';
    $descParts[] = 'Delivery: ' . akh_task_delivery_description_sentence($deliveryMode);
    if ($deliveryMode === 'google_drive' && $driveLink !== '') {
        $descParts[] = 'Drive link: ' . $driveLink;
    }
    $descParts[] = '';
    $descParts[] = 'This job is split into separate editor tasks (one per type). Each part has its own task ID.';
    $description = implode("\n", $descParts);
    if (mb_strlen($description) > 8000) {
        return ['', false];
    }

    return [$description, true];
}

/**
 * @param list<string> $editTypes ordered unique client slugs
 * @return array<string, mixed>|null parent task on success
 */
function akh_task_create_bundle(
    string $clientUsername,
    string $coupleName,
    array $editTypes,
    string $projectDetails,
    string $referenceLink,
    string $deliveryMode,
    string $driveLink,
    bool $allowEmptyReference = false
): ?array {
    $clientUsername = strtolower(trim($clientUsername));
    if ($clientUsername === '') {
        return null;
    }
    $editTypes = array_values(array_unique(array_filter(array_map('trim', $editTypes), static fn ($s) => $s !== '')));
    if (count($editTypes) < 2) {
        return null;
    }

    $coupleName = trim($coupleName);
    if ($coupleName === '' || mb_strlen($coupleName) > 200) {
        return null;
    }

    $clientTypes = array_keys(akh_task_client_edit_types());
    $allowedTypes = $allowEmptyReference ? array_merge($clientTypes, ['studio_admin']) : $clientTypes;
    foreach ($editTypes as $et) {
        if (!in_array($et, $allowedTypes, true)) {
            return null;
        }
    }

    $projectDetails = trim($projectDetails);
    if ($projectDetails === '' || mb_strlen($projectDetails) > 8000) {
        return null;
    }

    $referenceLink = trim($referenceLink);
    if ($allowEmptyReference) {
        if ($referenceLink !== '' && !akh_task_is_valid_reference_link($referenceLink)) {
            return null;
        }
    } elseif (!akh_task_is_valid_reference_link($referenceLink)) {
        return null;
    }

    if (!in_array($deliveryMode, akh_task_valid_delivery_modes(), true)) {
        return null;
    }
    $driveLink = trim($driveLink);
    if ($deliveryMode === 'google_drive') {
        if ($driveLink === '' || mb_strlen($driveLink) > 2000) {
            return null;
        }
        if (!preg_match('#^https?://#i', $driveLink)) {
            return null;
        }
    }

    $parentTitle = akh_task_bundle_build_parent_title($coupleName, count($editTypes));
    [$parentDesc, $pOk] = akh_task_bundle_build_parent_description(
        $coupleName,
        $editTypes,
        $projectDetails,
        $referenceLink,
        $deliveryMode,
        $driveLink
    );
    if (!$pOk) {
        return null;
    }

    $childDescPreview = [];
    $suffixStub = "\n\n— Bundle root: AS_PLACEHOLDER — This is one part of a multi-type request; claim only this row for this deliverable.";
    foreach ($editTypes as $slug) {
        [$cd, $ok] = akh_task_build_description(
            $coupleName,
            $slug,
            $projectDetails,
            $referenceLink,
            $deliveryMode,
            $driveLink
        );
        if (!$ok) {
            return null;
        }
        if (mb_strlen($cd . $suffixStub) > 8000) {
            return null;
        }
        $childDescPreview[] = $cd;
    }

    $parentId = akh_task_generate_id();
    $childIds = [];
    foreach ($editTypes as $_) {
        $childIds[] = akh_task_generate_id();
    }

    $now = gmdate('c');
    $path = akh_tasks_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return null;
    }
    $parent = null;
    $childRows = [];
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $list = [];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = array_values(array_filter($decoded, 'is_array'));
            }
        }

        foreach ($editTypes as $i => $slug) {
            $cid = $childIds[$i];
            $cdesc = $childDescPreview[$i] . "\n\n— Bundle root: " . $parentId . ' — This is one part of a multi-type request; claim only this row for this deliverable.';
            $childRows[] = [
                'id' => $cid,
                'task_role' => 'bundle_child',
                'parent_task_id' => $parentId,
                'client_username' => $clientUsername,
                'title' => akh_task_build_title($coupleName, $slug),
                'description' => $cdesc,
                'couple_name' => $coupleName,
                'edit_type' => $slug,
                'project_details' => $projectDetails,
                'reference_link' => $referenceLink,
                'delivery_mode' => $deliveryMode,
                'drive_link' => $deliveryMode === 'google_drive' ? $driveLink : '',
                'deliverable_output' => '',
                'client_feedback' => '',
                'client_meeting_date' => '',
                'client_meeting_link' => '',
                'created_at' => $now,
                'updated_at' => $now,
                'status' => 'new',
                'assigned_editor' => null,
                'editor_feedback_notify' => false,
                'client_editor_notify' => false,
                'conversation' => [],
            ];
        }

        $parent = [
            'id' => $parentId,
            'task_role' => 'bundle_parent',
            'child_task_ids' => $childIds,
            'bundle_edit_types' => $editTypes,
            'client_username' => $clientUsername,
            'title' => $parentTitle,
            'description' => $parentDesc,
            'couple_name' => $coupleName,
            'edit_type' => 'bundle_parent',
            'project_details' => $projectDetails,
            'reference_link' => $referenceLink,
            'delivery_mode' => $deliveryMode,
            'drive_link' => $deliveryMode === 'google_drive' ? $driveLink : '',
            'deliverable_output' => '',
            'client_feedback' => '',
            'client_meeting_date' => '',
            'client_meeting_link' => '',
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'new',
            'assigned_editor' => null,
            'editor_feedback_notify' => false,
            'client_editor_notify' => false,
            'conversation' => [],
        ];

        $list = array_merge($list, [$parent], $childRows);
        $json = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ftruncate($fp, 0);
        rewind($fp);
        if (fwrite($fp, $json) === false) {
            $parent = null;
        }
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    if ($parent === null) {
        return null;
    }

    akh_task_write_studio_notification($parent);
    foreach ($childRows as $ch) {
        akh_task_write_studio_notification($ch);
    }

    return $parent;
}

function akh_task_is_valid_reference_link(string $link): bool
{
    if ($link === '' || mb_strlen($link) > 2000) {
        return false;
    }

    return (bool) preg_match('#^https?://#i', $link);
}

function akh_task_valid_delivery_modes(): array
{
    return ['google_drive', 'nas_storage', 'courier_hdd'];
}

function akh_task_delivery_mode_label(string $mode): string
{
    if ($mode === 'nas_storage') {
        return 'NAS / Nextcloud';
    }
    if ($mode === 'courier_hdd') {
        return 'Courier — hard drive / copy locally';
    }

    return 'Google Drive';
}

/** Long sentence used inside composed task descriptions. */
function akh_task_delivery_description_sentence(string $mode): string
{
    if ($mode === 'nas_storage') {
        return 'NAS / Nextcloud (client will upload via drive portal)';
    }
    if ($mode === 'courier_hdd') {
        return 'Courier — hard drive / copy locally (partner will ship media to the studio; no Drive link required)';
    }

    return 'Google Drive';
}

function akh_task_build_title(string $coupleName, string $editType): string
{
    $typeLabel = akh_task_edit_type_label($editType);
    $suffix = ' — ' . $typeLabel;
    $title = trim($coupleName);
    if (mb_strlen($title . $suffix) > 200) {
        $budget = 200 - mb_strlen($suffix);
        if ($budget < 8) {
            $budget = 8;
        }
        $title = mb_substr(trim($coupleName), 0, $budget) . '…';
    }

    return $title . $suffix;
}

/**
 * @return array{0: string, 1: bool} [description, ok]
 */
function akh_task_build_description(
    string $coupleName,
    string $editType,
    string $projectDetails,
    string $referenceLink,
    string $deliveryMode,
    string $driveLink
): array {
    $typeLabel = akh_task_edit_type_label($editType);
    $descParts = [
        'Couple / project name: ' . $coupleName,
        'Type of edit: ' . $typeLabel,
        '',
        'Project details:',
        $projectDetails,
        '',
    ];
    if ($referenceLink !== '') {
        $descParts[] = 'Reference / style link: ' . $referenceLink;
    } else {
        $descParts[] = 'Reference / style link: — (not supplied)';
    }
    $descParts[] = '';
    $descParts[] = 'Delivery: ' . akh_task_delivery_description_sentence($deliveryMode);
    if ($deliveryMode === 'google_drive' && $driveLink !== '') {
        $descParts[] = 'Drive link: ' . $driveLink;
    }
    $description = implode("\n", $descParts);
    if (mb_strlen($description) > 8000) {
        return ['', false];
    }

    return [$description, true];
}

function akh_task_client_may_edit(array $t): bool
{
    if (($t['status'] ?? '') !== 'new' || ($t['assigned_editor'] ?? null) !== null) {
        return false;
    }
    if (akh_task_is_bundle_parent($t)) {
        $kids = akh_task_bundle_children((string) ($t['id'] ?? ''), akh_tasks_load());
        foreach ($kids as $c) {
            if (!akh_task_client_may_edit($c)) {
                return false;
            }
        }

        return true;
    }

    return true;
}

function akh_task_is_valid_google_meet_url(string $url): bool
{
    if ($url === '' || mb_strlen($url) > 2000) {
        return false;
    }
    if (!preg_match('#^https://#i', $url)) {
        return false;
    }

    return (bool) preg_match('#^https://meet\.google\.com/\S+#i', $url);
}

/**
 * @return array<string, mixed>|null task or null on validation failure
 */
function akh_task_create(
    string $clientUsername,
    string $coupleName,
    string $editType,
    string $projectDetails,
    string $referenceLink,
    string $deliveryMode,
    string $driveLink,
    bool $allowEmptyReference = false
): ?array {
    $clientUsername = strtolower(trim($clientUsername));
    if ($clientUsername === '') {
        return null;
    }

    $coupleName = trim($coupleName);
    if ($coupleName === '' || mb_strlen($coupleName) > 200) {
        return null;
    }

    $clientTypes = array_keys(akh_task_client_edit_types());
    $allowedTypes = $allowEmptyReference ? array_merge($clientTypes, ['studio_admin']) : $clientTypes;
    if (!in_array($editType, $allowedTypes, true)) {
        return null;
    }

    $projectDetails = trim($projectDetails);
    if ($projectDetails === '' || mb_strlen($projectDetails) > 8000) {
        return null;
    }

    $referenceLink = trim($referenceLink);
    if ($allowEmptyReference) {
        if ($referenceLink !== '' && !akh_task_is_valid_reference_link($referenceLink)) {
            return null;
        }
    } elseif (!akh_task_is_valid_reference_link($referenceLink)) {
        return null;
    }

    if (!in_array($deliveryMode, akh_task_valid_delivery_modes(), true)) {
        return null;
    }
    $driveLink = trim($driveLink);
    if ($deliveryMode === 'google_drive') {
        if ($driveLink === '' || mb_strlen($driveLink) > 2000) {
            return null;
        }
        if (!preg_match('#^https?://#i', $driveLink)) {
            return null;
        }
    }

    $title = akh_task_build_title($coupleName, $editType);
    [$description, $descOk] = akh_task_build_description(
        $coupleName,
        $editType,
        $projectDetails,
        $referenceLink,
        $deliveryMode,
        $driveLink
    );
    if (!$descOk) {
        return null;
    }

    $now = gmdate('c');
    $task = [
        'id' => akh_task_generate_id(),
        'client_username' => $clientUsername,
        'title' => $title,
        'description' => $description,
        'couple_name' => $coupleName,
        'edit_type' => $editType,
        'project_details' => $projectDetails,
        'reference_link' => $referenceLink,
        'delivery_mode' => $deliveryMode,
        'drive_link' => $deliveryMode === 'google_drive' ? $driveLink : '',
        'deliverable_output' => '',
        'client_feedback' => '',
        'client_meeting_date' => '',
        'client_meeting_link' => '',
        'created_at' => $now,
        'updated_at' => $now,
        'status' => 'new',
        'assigned_editor' => null,
        'editor_feedback_notify' => false,
        'client_editor_notify' => false,
        'conversation' => [],
    ];

    $path = akh_tasks_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return null;
    }
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $list = [];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = array_values(array_filter($decoded, 'is_array'));
            }
        }
        $list[] = $task;
        $json = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ftruncate($fp, 0);
        rewind($fp);
        if (fwrite($fp, $json) === false) {
            return null;
        }
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    akh_task_write_studio_notification($task);

    return $task;
}

/**
 * Client may update a task only while it is still unassigned (new).
 *
 * @return array<string, mixed>|null
 */
function akh_task_client_update(
    string $taskId,
    string $clientUsername,
    string $coupleName,
    string $editType,
    string $projectDetails,
    string $referenceLink,
    string $deliveryMode,
    string $driveLink,
    bool $allowEmptyReference = false
): ?array {
    $clientUsername = strtolower(trim($clientUsername));
    $coupleName = trim($coupleName);
    if ($coupleName === '' || mb_strlen($coupleName) > 200) {
        return null;
    }
    $projectDetails = trim($projectDetails);
    if ($projectDetails === '' || mb_strlen($projectDetails) > 8000) {
        return null;
    }
    $referenceLink = trim($referenceLink);
    if ($allowEmptyReference) {
        if ($referenceLink !== '' && !akh_task_is_valid_reference_link($referenceLink)) {
            return null;
        }
    } elseif (!akh_task_is_valid_reference_link($referenceLink)) {
        return null;
    }
    if (!in_array($deliveryMode, akh_task_valid_delivery_modes(), true)) {
        return null;
    }
    $driveLink = trim($driveLink);
    if ($deliveryMode === 'google_drive') {
        if ($driveLink === '' || mb_strlen($driveLink) > 2000 || !preg_match('#^https?://#i', $driveLink)) {
            return null;
        }
    }

    $list = akh_tasks_load();
    $existing = null;
    $existingIdx = null;
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') === $taskId) {
            $existing = $t;
            $existingIdx = $i;
            break;
        }
    }
    if ($existing === null || $existingIdx === null) {
        return null;
    }
    if (strtolower((string) ($existing['client_username'] ?? '')) !== $clientUsername) {
        return null;
    }
    if (!akh_task_client_may_edit($existing)) {
        return null;
    }

    if (akh_task_is_bundle_parent($existing)) {
        $bundleTypes = $existing['bundle_edit_types'] ?? null;
        if (!is_array($bundleTypes) || count($bundleTypes) < 2) {
            return null;
        }
        $bundleTypes = array_values(array_filter($bundleTypes, 'is_string'));
        if (count($bundleTypes) < 2) {
            return null;
        }
        $parentTitle = akh_task_bundle_build_parent_title($coupleName, count($bundleTypes));
        [$parentDesc, $pOk] = akh_task_bundle_build_parent_description(
            $coupleName,
            $bundleTypes,
            $projectDetails,
            $referenceLink,
            $deliveryMode,
            $driveLink
        );
        if (!$pOk) {
            return null;
        }
        $pid = (string) ($existing['id'] ?? '');
        $list[$existingIdx]['title'] = $parentTitle;
        $list[$existingIdx]['description'] = $parentDesc;
        $list[$existingIdx]['couple_name'] = $coupleName;
        $list[$existingIdx]['edit_type'] = 'bundle_parent';
        $list[$existingIdx]['project_details'] = $projectDetails;
        $list[$existingIdx]['reference_link'] = $referenceLink;
        $list[$existingIdx]['delivery_mode'] = $deliveryMode;
        $list[$existingIdx]['drive_link'] = $deliveryMode === 'google_drive' ? $driveLink : '';
        $list[$existingIdx]['updated_at'] = gmdate('c');
        $suffixTpl = "\n\n— Bundle root: " . $pid . ' — This is one part of a multi-type request; claim only this row for this deliverable.';
        foreach ($list as $j => $u) {
            if (!akh_task_is_bundle_child($u) || (string) ($u['parent_task_id'] ?? '') !== $pid) {
                continue;
            }
            $slug = (string) ($u['edit_type'] ?? '');
            if ($slug === '') {
                return null;
            }
            [$cdesc, $cOk] = akh_task_build_description(
                $coupleName,
                $slug,
                $projectDetails,
                $referenceLink,
                $deliveryMode,
                $driveLink
            );
            if (!$cOk) {
                return null;
            }
            $cdesc .= $suffixTpl;
            if (mb_strlen($cdesc) > 8000) {
                return null;
            }
            $list[$j]['title'] = akh_task_build_title($coupleName, $slug);
            $list[$j]['description'] = $cdesc;
            $list[$j]['couple_name'] = $coupleName;
            $list[$j]['project_details'] = $projectDetails;
            $list[$j]['reference_link'] = $referenceLink;
            $list[$j]['delivery_mode'] = $deliveryMode;
            $list[$j]['drive_link'] = $deliveryMode === 'google_drive' ? $driveLink : '';
            $list[$j]['updated_at'] = gmdate('c');
        }
        if (!akh_tasks_save_locked($list)) {
            return null;
        }
        akh_task_bundle_sync_parent($pid);

        return $list[$existingIdx];
    }

    $clientTypes = array_keys(akh_task_client_edit_types());
    $allowedTypes = $allowEmptyReference ? array_merge($clientTypes, ['studio_admin']) : $clientTypes;
    if (!in_array($editType, $allowedTypes, true)) {
        return null;
    }

    $title = akh_task_build_title($coupleName, $editType);
    [$description, $descOk] = akh_task_build_description(
        $coupleName,
        $editType,
        $projectDetails,
        $referenceLink,
        $deliveryMode,
        $driveLink
    );
    if (!$descOk) {
        return null;
    }

    $out = null;
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (strtolower((string) ($t['client_username'] ?? '')) !== $clientUsername) {
            return null;
        }
        if (!akh_task_client_may_edit($t)) {
            return null;
        }
        $list[$i]['title'] = $title;
        $list[$i]['description'] = $description;
        $list[$i]['couple_name'] = $coupleName;
        $list[$i]['edit_type'] = $editType;
        $list[$i]['project_details'] = $projectDetails;
        $list[$i]['reference_link'] = $referenceLink;
        $list[$i]['delivery_mode'] = $deliveryMode;
        $list[$i]['drive_link'] = $deliveryMode === 'google_drive' ? $driveLink : '';
        $list[$i]['updated_at'] = gmdate('c');
        $out = $list[$i];
        break;
    }
    if ($out === null) {
        return null;
    }
    if (!akh_tasks_save_locked($list)) {
        return null;
    }

    return $out;
}

/**
 * Client feedback + optional Google Meet while delivered or returned for revision.
 * Saves feedback, sets status to reverted, and flags the assigned editor for in-app + file notification.
 *
 * @return string|null error message, null on success
 */
function akh_task_client_save_post_delivery(
    string $taskId,
    string $clientUsername,
    string $feedback,
    string $meetingDate,
    string $meetingLink
): ?string {
    $clientUsername = strtolower(trim($clientUsername));
    $feedback = trim($feedback);
    if (mb_strlen($feedback) > 4000) {
        return 'Feedback is too long.';
    }
    $meetingDate = trim($meetingDate);
    $meetingLink = trim($meetingLink);
    $hasMeeting = $meetingDate !== '' || $meetingLink !== '';
    if ($feedback === '' && !$hasMeeting) {
        return 'Add written feedback and/or a meeting date with a Google Meet link.';
    }
    if ($hasMeeting) {
        if ($meetingDate === '' || $meetingLink === '') {
            return 'To schedule a Google Meet, provide both the meeting date and the Meet link.';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $meetingDate);
        if ($dt === false || $dt->format('Y-m-d') !== $meetingDate) {
            return 'Please use a valid meeting date.';
        }
        if (!akh_task_is_valid_google_meet_url($meetingLink)) {
            return 'Meet link must be a https://meet.google.com/… URL.';
        }
    }

    $list = akh_tasks_load();
    $found = false;
    $savedRow = null;
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (strtolower((string) ($t['client_username'] ?? '')) !== $clientUsername) {
            return 'Task not found.';
        }
        $st = (string) ($t['status'] ?? '');
        if ($st !== 'delivered' && $st !== 'reverted') {
            return 'Feedback and meeting options are only available after delivery or while the task is returned for revision.';
        }
        $found = true;
        $list[$i]['client_feedback'] = $feedback;
        if ($hasMeeting) {
            $list[$i]['client_meeting_date'] = $meetingDate;
            $list[$i]['client_meeting_link'] = $meetingLink;
        }
        $list[$i]['status'] = 'reverted';
        $list[$i]['editor_feedback_notify'] = (($list[$i]['assigned_editor'] ?? null) !== null && (string) ($list[$i]['assigned_editor'] ?? '') !== '');
        $list[$i]['updated_at'] = gmdate('c');
        $savedRow = $list[$i];
        break;
    }
    if (!$found || $savedRow === null) {
        return 'Task not found.';
    }
    if (!akh_tasks_save_locked($list)) {
        return 'Could not save.';
    }
    if (($savedRow['editor_feedback_notify'] ?? false) === true) {
        akh_task_write_editor_feedback_notification($savedRow);
    }

    return null;
}

/**
 * Filesystem ping for the assigned editor (same folder as new-task notifications).
 *
 * @param array<string, mixed> $task
 */
function akh_task_write_editor_feedback_notification(array $task): void
{
    $dir = akh_task_notify_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $id = (string) ($task['id'] ?? 'unknown');
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
    $file = $dir . '/' . gmdate('Y-m-d_His') . '_' . $safe . '_CLIENT_FEEDBACK.txt';
    $editor = (string) ($task['assigned_editor'] ?? '');
    $fb = trim((string) ($task['client_feedback'] ?? ''));
    $snippet = $fb === '' ? '(meeting only / no written feedback)' : (mb_strlen($fb) > 600 ? mb_substr($fb, 0, 600) . '…' : $fb);
    $block = str_repeat('=', 72) . "\n"
        . 'CLIENT UPDATE — TASK RETURNED FOR REVISION (UTC): ' . gmdate('c') . "\n"
        . 'Task ID: ' . $id . "\n"
        . 'Assigned editor: ' . $editor . "\n"
        . 'Client login: ' . ($task['client_username'] ?? '') . "\n"
        . 'Title: ' . ($task['title'] ?? '') . "\n"
        . 'Status after save: reverted' . "\n\n"
        . 'Feedback (snippet):' . "\n" . $snippet . "\n";
    @file_put_contents($file, $block, LOCK_EX);
}

function akh_task_editor_unread_feedback_count(string $editorUsername): int
{
    $e = strtolower(trim($editorUsername));
    if ($e === '') {
        return 0;
    }
    $n = 0;
    foreach (akh_tasks_load() as $t) {
        if (strtolower((string) ($t['assigned_editor'] ?? '')) !== $e) {
            continue;
        }
        if (($t['editor_feedback_notify'] ?? false) === true) {
            ++$n;
        }
    }

    return $n;
}

function akh_task_editor_clear_feedback_notify(string $taskId, string $editorUsername): bool
{
    $e = strtolower(trim($editorUsername));
    $list = akh_tasks_load();
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (strtolower((string) ($t['assigned_editor'] ?? '')) !== $e) {
            return false;
        }
        $list[$i]['editor_feedback_notify'] = false;
        $list[$i]['updated_at'] = gmdate('c');

        return akh_tasks_save_locked($list);
    }

    return false;
}

/**
 * @param array<string, mixed> $task
 */
function akh_task_write_studio_notification(array $task): void
{
    $dir = akh_task_notify_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $id = (string) ($task['id'] ?? 'unknown');
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
    $file = $dir . '/' . gmdate('Y-m-d_His') . '_' . $safe . '.txt';
    $mode = akh_task_delivery_description_sentence((string) ($task['delivery_mode'] ?? 'google_drive'));
    $link = (string) ($task['drive_link'] ?? '');
    $ref = trim((string) ($task['reference_link'] ?? ''));
    $couple = trim((string) ($task['couple_name'] ?? ''));
    $editSlug = (string) ($task['edit_type'] ?? '');
    $block = str_repeat('=', 72) . "\n"
        . 'NEW TASK (UTC): ' . gmdate('c') . "\n"
        . 'Task ID: ' . $id . "\n"
        . 'Client login: ' . ($task['client_username'] ?? '') . "\n"
        . 'Title: ' . ($task['title'] ?? '') . "\n";
    if ($couple !== '') {
        $block .= 'Couple / project name: ' . $couple . "\n";
    }
    if ($editSlug !== '') {
        $block .= 'Edit type: ' . akh_task_edit_type_label($editSlug) . "\n";
    }
    if ($ref !== '') {
        $block .= 'Reference / style: ' . $ref . "\n";
    }
    $block .= 'Delivery: ' . $mode . "\n"
        . ($link !== '' ? 'Drive: ' . $link . "\n" : '')
        . "\nFull notes:\n" . ($task['description'] ?? '') . "\n";
    @file_put_contents($file, $block, LOCK_EX);
}

/**
 * @return array<string, mixed>|null updated task or null
 */
function akh_task_claim(string $taskId, string $editorUsername): ?array
{
    $list = akh_tasks_load();
    $found = false;
    $parentId = '';
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (!akh_task_editor_pool_eligible($t)) {
            return null;
        }
        $list[$i]['assigned_editor'] = $editorUsername;
        $list[$i]['status'] = 'assigned';
        $list[$i]['updated_at'] = gmdate('c');
        $list[$i]['client_editor_notify'] = true;
        if (akh_task_is_bundle_child($t)) {
            $parentId = trim((string) ($t['parent_task_id'] ?? ''));
        }
        $found = true;
        $out = $list[$i];
        break;
    }
    if (!$found) {
        return null;
    }
    if (!akh_tasks_save_locked($list)) {
        return null;
    }
    if ($parentId !== '') {
        akh_task_bundle_sync_parent($parentId);
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function akh_task_set_status(string $taskId, string $editorUsername, string $newStatus, string $deliverableOutput = ''): ?array
{
    $allowed = ['assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed'];
    if (!in_array($newStatus, $allowed, true)) {
        return null;
    }
    $deliverableOutput = trim($deliverableOutput);
    if (mb_strlen($deliverableOutput) > 4000) {
        return null;
    }
    $list = akh_tasks_load();
    $out = null;
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (($t['assigned_editor'] ?? null) !== $editorUsername) {
            return null;
        }
        $prevSt = (string) ($t['status'] ?? '');
        $prevDel = trim((string) ($t['deliverable_output'] ?? ''));
        $list[$i]['status'] = $newStatus;
        $list[$i]['deliverable_output'] = $deliverableOutput;
        $list[$i]['updated_at'] = gmdate('c');
        if ($newStatus === 'delivered') {
            $list[$i]['editor_feedback_notify'] = false;
        }
        $notifyClient = ($prevSt !== $newStatus) || ($deliverableOutput !== $prevDel);
        if ($notifyClient) {
            $list[$i]['client_editor_notify'] = true;
        }
        $out = $list[$i];
        break;
    }
    if ($out === null) {
        return null;
    }
    if (!akh_tasks_save_locked($list)) {
        return null;
    }
    if (akh_task_is_bundle_child($out)) {
        $pid = trim((string) ($out['parent_task_id'] ?? ''));
        if ($pid !== '') {
            akh_task_bundle_sync_parent($pid);
        }
    }

    return $out;
}

/**
 * @return array<string, int>
 */
function akh_task_status_counts(): array
{
    $counts = [
        'new' => 0,
        'assigned' => 0,
        'in_progress' => 0,
        'review' => 0,
        'delivered' => 0,
        'reverted' => 0,
        'closed' => 0,
        'other' => 0,
    ];
    foreach (akh_tasks_load() as $t) {
        if (akh_task_is_bundle_parent($t)) {
            continue;
        }
        $s = (string) ($t['status'] ?? 'new');
        if (isset($counts[$s])) {
            ++$counts[$s];
        } else {
            ++$counts['other'];
        }
    }

    return $counts;
}

function akh_task_count_for_client(string $clientUsername): int
{
    $n = 0;
    $c = strtolower(trim($clientUsername));
    foreach (akh_tasks_load() as $t) {
        if (strtolower((string) ($t['client_username'] ?? '')) === $c) {
            ++$n;
        }
    }

    return $n;
}

function akh_task_count_for_editor(string $editorUsername): int
{
    $n = 0;
    $e = strtolower(trim($editorUsername));
    foreach (akh_tasks_load() as $t) {
        if (strtolower((string) ($t['assigned_editor'] ?? '')) === $e) {
            ++$n;
        }
    }

    return $n;
}

/**
 * Admin: assign or unassign editor. Empty $editorUsername → unclaim (new, no editor).
 *
 * @return string|null error or null on success
 */
function akh_task_admin_assign(string $taskId, ?string $editorUsername): ?string
{
    $editorUsername = $editorUsername !== null ? strtolower(trim($editorUsername)) : '';
    if ($editorUsername !== '') {
        require_once __DIR__ . '/editor-auth.php';
        $editors = akh_editor_accounts();
        if (!isset($editors[$editorUsername])) {
            return 'Unknown editor username.';
        }
    }
    $list = akh_tasks_load();
    $found = false;
    $parentForSync = '';
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (akh_task_is_bundle_parent($t)) {
            return 'Use the child task rows to assign editors for each deliverable.';
        }
        $found = true;
        if ($editorUsername === '') {
            $list[$i]['assigned_editor'] = null;
            $list[$i]['status'] = 'new';
        } else {
            $list[$i]['assigned_editor'] = $editorUsername;
            $list[$i]['status'] = 'assigned';
        }
        $list[$i]['updated_at'] = gmdate('c');
        if (akh_task_is_bundle_child($t)) {
            $parentForSync = trim((string) ($t['parent_task_id'] ?? ''));
        }
        break;
    }
    if (!$found) {
        return 'Task not found.';
    }
    if (!akh_tasks_save_locked($list)) {
        return 'Could not save tasks.';
    }
    if ($parentForSync !== '') {
        akh_task_bundle_sync_parent($parentForSync);
    }

    return null;
}

/**
 * @return string|null error
 */
function akh_task_admin_set_status(string $taskId, string $newStatus): ?string
{
    $allowed = ['new', 'assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed'];
    if (!in_array($newStatus, $allowed, true)) {
        return 'Invalid status.';
    }
    $list = akh_tasks_load();
    $found = false;
    $parentForSync = '';
    foreach ($list as $i => $t) {
        if (($t['id'] ?? '') !== $taskId) {
            continue;
        }
        if (akh_task_is_bundle_parent($t)) {
            return 'Use the child task rows to change status; the bundle row tracks children automatically.';
        }
        $found = true;
        $list[$i]['status'] = $newStatus;
        if ($newStatus === 'new') {
            $list[$i]['assigned_editor'] = null;
        }
        if ($newStatus === 'delivered') {
            $list[$i]['editor_feedback_notify'] = false;
        }
        $list[$i]['client_editor_notify'] = true;
        $list[$i]['updated_at'] = gmdate('c');
        if (akh_task_is_bundle_child($t)) {
            $parentForSync = trim((string) ($t['parent_task_id'] ?? ''));
        }
        break;
    }
    if (!$found) {
        return 'Task not found.';
    }
    if (!akh_tasks_save_locked($list)) {
        return 'Could not save tasks.';
    }
    if ($parentForSync !== '') {
        akh_task_bundle_sync_parent($parentForSync);
    }

    return null;
}

function akh_task_admin_delete(string $taskId): bool
{
    $taskId = trim($taskId);
    if ($taskId === '') {
        return false;
    }
    $list = akh_tasks_load();
    $target = null;
    foreach ($list as $t) {
        if (($t['id'] ?? '') === $taskId) {
            $target = $t;
            break;
        }
    }
    if ($target === null) {
        return false;
    }

    if (akh_task_is_bundle_parent($target)) {
        $remove = [$taskId => true];
        foreach ($target['child_task_ids'] ?? [] as $cid) {
            if (is_string($cid) && $cid !== '') {
                $remove[$cid] = true;
            }
        }
        $out = [];
        foreach ($list as $t) {
            $id = (string) ($t['id'] ?? '');
            if ($id !== '' && isset($remove[$id])) {
                continue;
            }
            $out[] = $t;
        }

        return akh_tasks_save_locked($out);
    }

    if (akh_task_is_bundle_child($target)) {
        $parentId = trim((string) ($target['parent_task_id'] ?? ''));
        $out = [];
        foreach ($list as $t) {
            if (($t['id'] ?? '') === $taskId) {
                continue;
            }
            $out[] = $t;
        }
        $pIdx = null;
        foreach ($out as $i => $t) {
            if (($t['id'] ?? '') === $parentId && akh_task_is_bundle_parent($t)) {
                $pIdx = $i;
                break;
            }
        }
        if ($pIdx !== null) {
            $kids = [];
            foreach ($out[$pIdx]['child_task_ids'] ?? [] as $cid) {
                if (is_string($cid) && $cid !== '' && $cid !== $taskId) {
                    $kids[] = $cid;
                }
            }
            $out[$pIdx]['child_task_ids'] = $kids;
            $types = [];
            foreach ($kids as $cid) {
                foreach ($out as $u) {
                    if (($u['id'] ?? '') === $cid && akh_task_is_bundle_child($u)) {
                        $types[] = (string) ($u['edit_type'] ?? '');
                        break;
                    }
                }
            }
            $out[$pIdx]['bundle_edit_types'] = $types;
            $out[$pIdx]['updated_at'] = gmdate('c');
            if (count($kids) < 2) {
                $survivorId = $kids[0] ?? '';
                unset($out[$pIdx]);
                $out = array_values($out);
                if ($survivorId !== '') {
                    foreach ($out as $j => $u) {
                        if (($u['id'] ?? '') !== $survivorId) {
                            continue;
                        }
                        $slug = (string) ($u['edit_type'] ?? '');
                        $cn = (string) ($u['couple_name'] ?? '');
                        $pd = (string) ($u['project_details'] ?? '');
                        $rf = (string) ($u['reference_link'] ?? '');
                        $dm = (string) ($u['delivery_mode'] ?? 'google_drive');
                        $dl = (string) ($u['drive_link'] ?? '');
                        unset($out[$j]['task_role'], $out[$j]['parent_task_id']);
                        $out[$j]['title'] = akh_task_build_title($cn, $slug);
                        [$dsc, $ok] = akh_task_build_description($cn, $slug, $pd, $rf, $dm, $dl);
                        if ($ok) {
                            $out[$j]['description'] = $dsc;
                        }
                        $out[$j]['updated_at'] = gmdate('c');
                        break;
                    }
                }
            } else {
                $cn = (string) ($out[$pIdx]['couple_name'] ?? '');
                $out[$pIdx]['title'] = akh_task_bundle_build_parent_title($cn, count($kids));
            }
        }

        if (!akh_tasks_save_locked($out)) {
            return false;
        }
        if ($parentId !== '') {
            $still = akh_task_by_id($parentId);
            if ($still !== null && akh_task_is_bundle_parent($still)) {
                akh_task_bundle_sync_parent($parentId);
            }
        }

        return true;
    }

    $out = [];
    foreach ($list as $t) {
        if (($t['id'] ?? '') === $taskId) {
            continue;
        }
        $out[] = $t;
    }

    return akh_tasks_save_locked($out);
}

/** Remove every task (admin only). Returns whether save succeeded. */
function akh_task_admin_delete_all(): bool
{
    return akh_tasks_save_locked([]);
}

/**
 * Admin creates a task on behalf of a client (same rules as client create).
 */
function akh_task_admin_create_for_client(
    string $clientUsername,
    string $title,
    string $description,
    string $deliveryMode,
    string $driveLink,
    string $referenceLink = ''
): ?array {
    $clientUsername = strtolower(trim($clientUsername));
    if ($clientUsername === '') {
        return null;
    }
    $title = trim($title);
    if ($title === '') {
        return null;
    }

    return akh_task_create(
        $clientUsername,
        $title,
        'studio_admin',
        trim($description) === '' ? '(No notes — admin entry.)' : trim($description),
        trim($referenceLink),
        $deliveryMode,
        $driveLink,
        true
    );
}
