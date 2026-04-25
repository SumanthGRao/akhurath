<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/tasks.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && trim((string) ($_POST['ajax_action'] ?? '')) === 'poll') {
    header('Content-Type: application/json; charset=utf-8');
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    try {
        echo json_encode(['ok' => true, 'sig' => akh_task_poll_signature_all()], JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false]);
    }
    exit;
}

$pageTitle = 'Admin overview — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = 'index.php';

$clients = akh_customer_accounts();
$editors = akh_editor_accounts();
$counts = akh_task_status_counts();
$allTasks = akh_tasks_load();
$totalTasks = count($allTasks);

$activePipeline = (int) (($counts['assigned'] ?? 0) + ($counts['in_progress'] ?? 0) + ($counts['review'] ?? 0) + ($counts['reverted'] ?? 0));
$newCount = (int) ($counts['new'] ?? 0);
$deliveredCount = (int) ($counts['delivered'] ?? 0);
$closedCount = (int) ($counts['closed'] ?? 0);

$activePct = $totalTasks > 0 ? min(100, (int) round($activePipeline / $totalTasks * 100)) : 0;
$deliveredPct = $totalTasks > 0 ? min(100, (int) round($deliveredCount / $totalTasks * 100)) : 0;
$newPct = $totalTasks > 0 ? min(100, (int) round($newCount / $totalTasks * 100)) : 0;

$perClient = [];
foreach ($allTasks as $t) {
    $c = strtolower((string) ($t['client_username'] ?? ''));
    if ($c === '') {
        continue;
    }
    $perClient[$c] = ($perClient[$c] ?? 0) + 1;
}
arsort($perClient);
$topClients = array_slice($perClient, 0, 8, true);
$maxClientBar = $topClients !== [] ? max($topClients) : 1;

$adminOverviewCsrf = akh_csrf_token();
$adminOverviewSig = akh_task_poll_signature_all();

$perEditor = [];
foreach ($allTasks as $t) {
    $e = strtolower(trim((string) ($t['assigned_editor'] ?? '')));
    if ($e === '') {
        continue;
    }
    $perEditor[$e] = ($perEditor[$e] ?? 0) + 1;
}
arsort($perEditor);
$topEditors = array_slice($perEditor, 0, 8, true);
$maxEditorBar = $topEditors !== [] ? max($topEditors) : 1;

$editorCount = count($editors);
$flowAwaiting = 0;
$newClaimed = 0;
foreach ($allTasks as $t) {
    $st = (string) ($t['status'] ?? 'new');
    $asg = trim((string) ($t['assigned_editor'] ?? ''));
    if ($st === 'new' && $asg === '') {
        ++$flowAwaiting;
    } elseif ($st === 'new' && $asg !== '') {
        ++$newClaimed;
    }
}
$flowOnEditor = $activePipeline + $newClaimed;
$flowDelivered = (int) ($counts['delivered'] ?? 0);
$flowClosed = (int) ($counts['closed'] ?? 0);
$flowOther = (int) ($counts['other'] ?? 0);

$editorStatusKeys = ['assigned', 'in_progress', 'review', 'reverted', 'new', 'delivered', 'closed', 'other'];
$byEditorStatus = [];
foreach (array_keys($editors) as $ek) {
    $byEditorStatus[strtolower((string) $ek)] = array_fill_keys($editorStatusKeys, 0);
}
foreach ($allTasks as $t) {
    $asg = strtolower(trim((string) ($t['assigned_editor'] ?? '')));
    if ($asg === '' || !isset($byEditorStatus[$asg])) {
        continue;
    }
    $st = (string) ($t['status'] ?? 'new');
    $key = in_array($st, $editorStatusKeys, true) ? $st : 'other';
    ++$byEditorStatus[$asg][$key];
}

$editorsBusy = 0;
foreach (array_keys($editors) as $ek) {
    $row = $byEditorStatus[strtolower((string) $ek)] ?? null;
    if ($row === null) {
        continue;
    }
    $liveOnDesk = (int) $row['assigned'] + (int) $row['in_progress'] + (int) $row['review'] + (int) $row['reverted'];
    if ($liveOnDesk > 0) {
        ++$editorsBusy;
    }
}
$editorsIdle = max(0, $editorCount - $editorsBusy);
$avgLivePerEditor = $editorCount > 0 ? round($activePipeline / $editorCount, 2) : 0.0;

$tasksBase = base_path('admin/tasks.php');

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-overview">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Overview</h1>
          <p class="portal-lead admin-head__meta">Signed in as <strong><?php echo h(akh_admin_current() ?? ''); ?></strong> — live snapshot of accounts and the task pipeline.</p>
        </div>
        <div class="admin-head__actions">
          <?php $adminConsoleActive = ''; require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <div class="admin-overview__hero admin-fade-stagger">
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value" data-count="<?php echo count($clients); ?>"><?php echo count($clients); ?></span>
          <span class="admin-stat__label">Clients</span>
          <a class="text-link admin-stat__link" href="<?php echo h(base_path('admin/clients.php')); ?>">Manage clients</a>
        </div>
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value" data-count="<?php echo count($editors); ?>"><?php echo count($editors); ?></span>
          <span class="admin-stat__label">Editors</span>
          <a class="text-link admin-stat__link" href="<?php echo h(base_path('admin/editors.php')); ?>">Manage editors</a>
        </div>
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value" data-count="<?php echo (int) $totalTasks; ?>"><?php echo (int) $totalTasks; ?></span>
          <span class="admin-stat__label">Tasks total</span>
          <a class="text-link admin-stat__link" href="<?php echo h($tasksBase); ?>">Open task board</a>
        </div>
      </div>

      <div class="admin-overview__gauges admin-fade-stagger">
        <div class="admin-gauge-card">
          <h2 class="admin-gauge-card__title">New &amp; unassigned</h2>
          <p class="admin-gauge-card__meta"><?php echo (int) $newCount; ?> of <?php echo (int) $totalTasks; ?> tasks</p>
          <div class="admin-gauge" data-p="<?php echo $totalTasks > 0 ? $newPct / 100 : 0; ?>" style="--gauge-p: <?php echo $totalTasks > 0 ? $newPct / 100 : 0; ?>;">
            <svg class="admin-gauge__svg" viewBox="0 0 120 72" aria-hidden="true">
              <path class="admin-gauge__track" d="M 12 60 A 48 48 0 0 1 108 60" fill="none" stroke-width="10" stroke-linecap="round" />
              <path class="admin-gauge__fill admin-gauge__fill--new" d="M 12 60 A 48 48 0 0 1 108 60" fill="none" stroke-width="10" stroke-linecap="round" pathLength="1" stroke-dasharray="1" stroke-dashoffset="1" />
              <line class="admin-gauge__needle" x1="60" y1="60" x2="60" y2="22" />
            </svg>
            <div class="admin-gauge__readout"><?php echo (int) $newPct; ?>%</div>
          </div>
          <a class="btn btn--ghost btn--sm btn--inline" href="<?php echo h($tasksBase . '?f_status=new'); ?>">View new tasks</a>
        </div>
        <div class="admin-gauge-card">
          <h2 class="admin-gauge-card__title">Active pipeline</h2>
          <p class="admin-gauge-card__meta">Share of all tasks currently in assigned, in progress, review, or returned</p>
          <div class="admin-gauge" data-p="<?php echo $totalTasks > 0 ? $activePct / 100 : 0; ?>" style="--gauge-p: <?php echo $totalTasks > 0 ? $activePct / 100 : 0; ?>;">
            <svg class="admin-gauge__svg" viewBox="0 0 120 72" aria-hidden="true">
              <path class="admin-gauge__track" d="M 12 60 A 48 48 0 0 1 108 60" fill="none" stroke-width="10" stroke-linecap="round" />
              <path class="admin-gauge__fill admin-gauge__fill--active" d="M 12 60 A 48 48 0 0 1 108 60" fill="none" stroke-width="10" stroke-linecap="round" pathLength="1" stroke-dasharray="1" stroke-dashoffset="1" />
              <line class="admin-gauge__needle" x1="60" y1="60" x2="60" y2="22" />
            </svg>
            <div class="admin-gauge__readout"><?php echo (int) $activePct; ?>%</div>
          </div>
          <p class="admin-gauge-card__foot"><?php echo (int) $activePipeline; ?> of <?php echo (int) $totalTasks; ?> total tasks in motion</p>
        </div>
        <div class="admin-gauge-card">
          <h2 class="admin-gauge-card__title">Delivered</h2>
          <p class="admin-gauge-card__meta">Share of all tasks currently marked delivered</p>
          <div class="admin-gauge" data-p="<?php echo $totalTasks > 0 ? $deliveredPct / 100 : 0; ?>" style="--gauge-p: <?php echo $totalTasks > 0 ? $deliveredPct / 100 : 0; ?>;">
            <svg class="admin-gauge__svg" viewBox="0 0 120 72" aria-hidden="true">
              <path class="admin-gauge__track" d="M 12 60 A 48 48 0 0 1 108 60" fill="none" stroke-width="10" stroke-linecap="round" />
              <path class="admin-gauge__fill admin-gauge__fill--done" d="M 12 60 A 48 48 0 0 1 108 60" fill="none" stroke-width="10" stroke-linecap="round" pathLength="1" stroke-dasharray="1" stroke-dashoffset="1" />
              <line class="admin-gauge__needle" x1="60" y1="60" x2="60" y2="22" />
            </svg>
            <div class="admin-gauge__readout"><?php echo (int) $deliveredPct; ?>%</div>
          </div>
          <a class="btn btn--ghost btn--sm btn--inline" href="<?php echo h($tasksBase . '?f_status=delivered'); ?>">View delivered</a>
        </div>
      </div>

      <section class="admin-pipeline-compare admin-fade-stagger" aria-labelledby="pipe-compare-h">
        <h2 id="pipe-compare-h" class="admin-pipeline-compare__title">Tasks vs editors</h2>
        <p class="admin-pipeline-compare__lead">Where tasks sit in the workflow, and how that work maps onto each editor account.</p>
        <p class="admin-pipeline-compare__summary">
          <span class="admin-pipeline-compare__pill"><?php echo (int) $activePipeline; ?> live tasks</span>
          <span class="admin-pipeline-compare__pill"><?php echo (int) $editorCount; ?> editors</span>
          <span class="admin-pipeline-compare__pill">~<?php echo h((string) $avgLivePerEditor); ?> live tasks / editor</span>
          <span class="admin-pipeline-compare__pill"><?php echo (int) $editorsBusy; ?> with work · <?php echo (int) $editorsIdle; ?> idle</span>
        </p>
        <div class="admin-pipeline-compare__grid">
          <div class="admin-pipeline-col">
            <h3 class="admin-pipeline-col__h">Task pipeline</h3>
            <p class="admin-pipeline-col__meta">Awaiting pick-up, then work on an editor (claimed new + assigned through returned), then delivered or closed. Percent widths are shares of all tasks.</p>
            <?php
            $flowParts = [
                ['key' => 'queue', 'label' => 'Awaiting editor', 'n' => $flowAwaiting, 'class' => 'admin-stackbar__seg--new', 'href' => $tasksBase . '?f_status=new'],
                ['key' => 'desk', 'label' => 'On editor desk', 'n' => $flowOnEditor, 'class' => 'admin-stackbar__seg--assigned', 'href' => $tasksBase],
                ['key' => 'del', 'label' => 'Delivered', 'n' => $flowDelivered, 'class' => 'admin-stackbar__seg--delivered', 'href' => $tasksBase . '?f_status=delivered'],
                ['key' => 'clo', 'label' => 'Closed', 'n' => $flowClosed, 'class' => 'admin-stackbar__seg--closed', 'href' => $tasksBase . '?f_status=closed'],
                ['key' => 'oth', 'label' => 'Other', 'n' => $flowOther, 'class' => 'admin-stackbar__seg--other', 'href' => $tasksBase],
            ];
            ?>
            <div class="admin-flow-wrap">
              <div class="admin-flow-bar admin-stackbar" role="list">
                <?php foreach ($flowParts as $fp): ?>
                  <?php
                  $fn = (int) $fp['n'];
                  if ($fn === 0) {
                      continue;
                  }
                  $fw = $totalTasks > 0 ? max(0.5, (int) round($fn / $totalTasks * 10000) / 100) : 0;
                  ?>
                  <a class="admin-stackbar__seg admin-flow-bar__seg <?php echo h($fp['class']); ?>" style="width: <?php echo $fw; ?>%;" href="<?php echo h($fp['href']); ?>" title="<?php echo h($fp['label']); ?>: <?php echo $fn; ?>">
                    <span class="admin-stackbar__label"><?php echo h($fp['label']); ?></span>
                    <span class="admin-stackbar__n"><?php echo $fn; ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
              <?php if ($totalTasks === 0): ?>
                <p class="portal-muted admin-flow-empty">No tasks yet — pipeline fills when jobs exist.</p>
              <?php elseif ($newClaimed > 0): ?>
                <p class="portal-muted admin-flow-empty"><?php echo (int) $newClaimed; ?> task<?php echo $newClaimed === 1 ? '' : 's'; ?> claimed but still in the new status are counted on the editor desk until work starts.</p>
              <?php endif; ?>
            </div>
          </div>
          <div class="admin-pipeline-col">
            <h3 class="admin-pipeline-col__h">Editor pipeline</h3>
            <p class="admin-pipeline-col__meta">Each row is one editor: segments show how their live tasks split across assigned, in progress, review, and returned.</p>
            <ul class="admin-editor-pipe">
              <?php
              $editorKeys = array_keys($editors);
              sort($editorKeys, SORT_STRING);
              foreach ($editorKeys as $edName):
                  $edKey = strtolower((string) $edName);
                  $row = $byEditorStatus[$edKey] ?? array_fill_keys($editorStatusKeys, 0);
                  $liveAssigned = (int) $row['assigned'] + (int) $row['in_progress'] + (int) $row['review'] + (int) $row['reverted'];
                  $liveSegs = [
                      ['sk' => 'assigned', 'n' => (int) $row['assigned']],
                      ['sk' => 'in_progress', 'n' => (int) $row['in_progress']],
                      ['sk' => 'review', 'n' => (int) $row['review']],
                      ['sk' => 'reverted', 'n' => (int) $row['reverted']],
                  ];
                  ?>
                <li class="admin-editor-pipe__row">
                  <a class="admin-editor-pipe__head" href="<?php echo h($tasksBase . '?f_editor=' . rawurlencode((string) $edName)); ?>">
                    <span class="admin-editor-pipe__name"><?php echo h((string) $edName); ?></span>
                    <span class="admin-editor-pipe__badge" title="Tasks in assigned, in progress, review, or returned"><?php echo (int) $liveAssigned; ?> live</span>
                  </a>
                  <?php if ($liveAssigned === 0): ?>
                    <div class="admin-editor-pipe__track admin-editor-pipe__track--idle"><span class="admin-editor-pipe__idle">No live tasks on desk</span></div>
                  <?php else: ?>
                    <div class="admin-editor-pipe__track admin-stackbar" role="img" aria-label="<?php echo h((string) $edName); ?>: <?php echo (int) $liveAssigned; ?> live tasks by status">
                      <?php foreach ($liveSegs as $seg): ?>
                        <?php if ($seg['n'] === 0) {
                            continue;
                        } ?>
                        <?php
                        $sw = (int) round($seg['n'] / $liveAssigned * 10000) / 100;
                        $sk = $seg['sk'];
                        ?>
                      <a class="admin-stackbar__seg admin-stackbar__seg--<?php echo h(preg_replace('/[^a-z_]/', '', $sk)); ?> admin-editor-pipe__seg" style="width: <?php echo max(0.5, $sw); ?>%;" href="<?php echo h($tasksBase . '?f_editor=' . rawurlencode((string) $edName) . '&f_status=' . rawurlencode($sk)); ?>" title="<?php echo h(akh_task_status_label($sk)); ?>: <?php echo (int) $seg['n']; ?>">
                        <span class="admin-stackbar__label"><?php echo h(akh_task_status_label($sk)); ?></span>
                        <span class="admin-stackbar__n"><?php echo (int) $seg['n']; ?></span>
                      </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
              <?php if ($editorKeys === []): ?>
                <li class="portal-muted">No editor accounts yet.</li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      </section>

      <div class="admin-overview__split admin-fade-stagger">
        <section class="admin-panel" aria-labelledby="status-breakdown-h">
          <h2 id="status-breakdown-h" class="admin-panel__title">Status mix</h2>
          <p class="admin-panel__lead">Click a segment to open the task list filtered by that status.</p>
          <div class="admin-stackbar" role="list">
            <?php
            $statusKeys = ['new', 'assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed', 'other'];
            foreach ($statusKeys as $sk):
                $n = (int) ($counts[$sk] ?? 0);
                if ($totalTasks === 0) {
                    $w = 0;
                } else {
                    $w = max(0.5, (int) round($n / $totalTasks * 10000) / 100);
                }
                if ($n === 0) {
                    continue;
                }
                $lab = $sk === 'other' ? 'Other' : akh_task_status_label($sk);
                $href = $sk === 'other' ? $tasksBase : $tasksBase . '?f_status=' . rawurlencode($sk);
                ?>
            <a class="admin-stackbar__seg admin-stackbar__seg--<?php echo h(preg_replace('/[^a-z_]/', '', $sk)); ?>" style="width: <?php echo $w; ?>%;" href="<?php echo h($href); ?>" title="<?php echo h($lab); ?>: <?php echo $n; ?>">
              <span class="admin-stackbar__label"><?php echo h($lab); ?></span>
              <span class="admin-stackbar__n"><?php echo $n; ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($totalTasks === 0): ?>
              <p class="portal-muted">No tasks to chart yet.</p>
            <?php endif; ?>
          </div>
          <ul class="admin-mini-legend">
            <?php foreach (['new', 'assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed'] as $sk): ?>
              <li><a href="<?php echo h($tasksBase . '?f_status=' . rawurlencode($sk)); ?>"><?php echo h(akh_task_status_label($sk)); ?> — <?php echo (int) ($counts[$sk] ?? 0); ?></a></li>
            <?php endforeach; ?>
            <?php if (($counts['other'] ?? 0) > 0): ?>
              <li><span class="portal-muted">Other — <?php echo (int) $counts['other']; ?></span></li>
            <?php endif; ?>
          </ul>
        </section>
        <section class="admin-panel" aria-labelledby="workload-h">
          <h2 id="workload-h" class="admin-panel__title">Workload</h2>
          <h3 class="admin-panel__sub">Top clients by task count</h3>
          <?php if ($topClients === []): ?>
            <p class="portal-muted">No client-scoped tasks yet.</p>
          <?php else: ?>
            <ul class="admin-barlist">
              <?php foreach ($topClients as $cn => $num): ?>
                <li>
                  <a class="admin-barlist__row" href="<?php echo h($tasksBase . '?f_client=' . rawurlencode((string) $cn)); ?>">
                    <span class="admin-barlist__name"><?php echo h((string) $cn); ?></span>
                    <span class="admin-barlist__track"><span class="admin-barlist__fill" style="width: <?php echo (int) round($num / $maxClientBar * 100); ?>%;"></span></span>
                    <span class="admin-barlist__n"><?php echo (int) $num; ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <h3 class="admin-panel__sub">Editors by assigned tasks</h3>
          <?php if ($topEditors === []): ?>
            <p class="portal-muted">No assigned editors yet.</p>
          <?php else: ?>
            <ul class="admin-barlist">
              <?php foreach ($topEditors as $en => $num): ?>
                <li>
                  <a class="admin-barlist__row" href="<?php echo h($tasksBase . '?f_editor=' . rawurlencode((string) $en)); ?>">
                    <span class="admin-barlist__name"><?php echo h((string) $en); ?></span>
                    <span class="admin-barlist__track"><span class="admin-barlist__fill admin-barlist__fill--ed" style="width: <?php echo (int) round($num / $maxEditorBar * 100); ?>%;"></span></span>
                    <span class="admin-barlist__n"><?php echo (int) $num; ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
  <?php
  $akhPushJs = AKH_ROOT . '/assets/js/portal-push-notify.js';
  $akhPushVer = is_file($akhPushJs) ? (string) filemtime($akhPushJs) : '1';
  ?>
  <script>
    window._akhPortalPush = {
      mode: 'admin_overview',
      siteName: <?php echo json_encode(SITE_NAME, JSON_THROW_ON_ERROR); ?>,
      csrf: <?php echo json_encode($adminOverviewCsrf, JSON_THROW_ON_ERROR); ?>,
      bell: 0,
      pool: 0,
      sig: <?php echo json_encode($adminOverviewSig, JSON_THROW_ON_ERROR); ?>
    };
  </script>
  <script defer src="<?php echo h(base_path('assets/js/portal-push-notify.js')); ?>?v=<?php echo h($akhPushVer); ?>"></script>
  <script>
    (function () {
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) return;
      document.querySelectorAll('.admin-gauge__fill').forEach(function (el) {
        var card = el.closest('.admin-gauge');
        var p = card ? parseFloat(card.getAttribute('data-p') || '0') : 0;
        if (isNaN(p)) p = 0;
        el.style.strokeDashoffset = String(1 - Math.min(1, Math.max(0, p)));
      });
      document.querySelectorAll('.admin-gauge__needle').forEach(function (needle) {
        var card = needle.closest('.admin-gauge');
        if (!card) return;
        var p = parseFloat(card.getAttribute('data-p') || '0');
        if (isNaN(p)) p = 0;
        var deg = -90 + p * 180;
        requestAnimationFrame(function () {
          needle.style.transform = 'rotate(' + deg + 'deg)';
        });
      });
    })();
  </script>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
