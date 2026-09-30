<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/admin-task-analytics.php';
require_once AKH_ROOT . '/includes/admin-charts.php';

akh_require_admin();

$pageTitle = 'Task analytics — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board admin-page--analytics';
$adminNavActive = 'analytics.php';

$tz = akh_site_timezone();
$now = new DateTimeImmutable('now', $tz);
$year = (int) $now->format('Y');
$month = (int) $now->format('n');
$monthParam = trim((string) ($_GET['month'] ?? ''));
if (preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
    $year = (int) $m[1];
    $month = (int) $m[2];
}
if ($year < 2000 || $year > 2100) {
    $year = (int) $now->format('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int) $now->format('n');
}

$report = akh_admin_task_analytics_report($year, $month);
$summary = $report['summary'];
$monthValue = sprintf('%04d-%02d', $year, $month);
$tasksBase = base_path('admin/tasks.php');

$chartPayload = [
    'trend' => $report['trend'],
    'clients' => [
        'labels' => array_map(static fn (array $r): string => (string) $r['label'], array_slice($report['by_client'], 0, 12)),
        'incoming' => array_map(static fn (array $r): int => (int) $r['incoming'], array_slice($report['by_client'], 0, 12)),
        'delivered' => array_map(static fn (array $r): int => (int) $r['delivered'], array_slice($report['by_client'], 0, 12)),
    ],
    'editors' => [
        'labels' => array_map(static fn (array $r): string => (string) $r['username'], array_slice($report['by_editor'], 0, 12)),
        'handled' => array_map(static fn (array $r): int => (int) $r['handled'], array_slice($report['by_editor'], 0, 12)),
        'delivered' => array_map(static fn (array $r): int => (int) $r['delivered'], array_slice($report['by_editor'], 0, 12)),
    ],
    'monthCompare' => [
        'labels' => ['Incoming', 'Delivered'],
        'data' => [(int) $summary['incoming'], (int) $summary['delivered']],
        'colors' => ['hsl(218, 38%, 42%)', 'hsl(132, 40%, 36%)'],
    ],
];

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-analytics">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Task analytics</h1>
          <p class="portal-lead admin-head__meta">
            Monthly view of tasks created vs delivered, broken down by client and editor.
            Times use <strong><?php echo h($report['timezone']); ?></strong> (site timezone).
          </p>
        </div>
        <div class="admin-head__actions">
          <?php $adminConsoleActive = ''; require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <form class="admin-analytics__toolbar admin-fade-stagger" method="get" action="">
        <label class="admin-analytics__month">
          <span class="admin-analytics__month-label">Report month</span>
          <input type="month" name="month" id="admin-analytics-month" value="<?php echo h($monthValue); ?>" />
        </label>
        <button type="submit" class="btn btn--primary btn--sm">Apply</button>
        <p class="portal-muted admin-analytics__hint">
          <strong>Incoming</strong> = tasks created in the month.
          <strong>Delivered</strong> = tasks marked delivered or closed with a last update in the month.
        </p>
      </form>

      <div class="admin-overview__hero admin-fade-stagger">
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value"><?php echo (int) $summary['incoming']; ?></span>
          <span class="admin-stat__label">Incoming — <?php echo h($report['month_label']); ?></span>
        </div>
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value"><?php echo (int) $summary['delivered']; ?></span>
          <span class="admin-stat__label">Delivered / closed</span>
        </div>
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value"><?php echo $summary['delivery_rate'] !== null ? h((string) $summary['delivery_rate']) . '%' : '—'; ?></span>
          <span class="admin-stat__label">Delivery rate (delivered ÷ incoming)</span>
        </div>
        <div class="admin-stat admin-stat--lift">
          <span class="admin-stat__value"><?php echo (int) $summary['cancelled']; ?></span>
          <span class="admin-stat__label">Cancelled (updated in month)</span>
        </div>
      </div>

      <section class="admin-charts-grid admin-fade-stagger" aria-label="Monthly charts">
        <article class="admin-chart-card admin-panel admin-panel--wide">
          <h2 class="admin-panel__title">Incoming vs delivered (12 months)</h2>
          <p class="admin-panel__lead">Rolling year ending <?php echo h($report['month_label']); ?>.</p>
          <div class="admin-chart-canvas-wrap admin-chart-canvas-wrap--tall">
            <canvas id="akh-analytics-trend" role="img" aria-label="Incoming vs delivered trend"></canvas>
          </div>
        </article>
        <article class="admin-chart-card admin-panel">
          <h2 class="admin-panel__title"><?php echo h($report['month_label']); ?> snapshot</h2>
          <p class="admin-panel__lead">Tasks created vs completed in the selected month.</p>
          <div class="admin-chart-canvas-wrap admin-chart-canvas-wrap--doughnut">
            <canvas id="akh-analytics-month" role="img" aria-label="Month incoming vs delivered"></canvas>
          </div>
        </article>
        <article class="admin-chart-card admin-panel">
          <h2 class="admin-panel__title">Top clients</h2>
          <p class="admin-panel__lead">Incoming vs delivered for the month (up to 12 clients).</p>
          <?php if ($report['by_client'] === []): ?>
            <p class="portal-muted admin-chart-card__empty">No client activity this month.</p>
          <?php else: ?>
            <div class="admin-chart-canvas-wrap admin-chart-canvas-wrap--tall">
              <canvas id="akh-analytics-clients" role="img" aria-label="Tasks by client"></canvas>
            </div>
          <?php endif; ?>
        </article>
        <article class="admin-chart-card admin-panel">
          <h2 class="admin-panel__title">Editors</h2>
          <p class="admin-panel__lead">Handled (touched) vs delivered in the month.</p>
          <?php if ($report['by_editor'] === []): ?>
            <p class="portal-muted admin-chart-card__empty">No editor activity this month.</p>
          <?php else: ?>
            <div class="admin-chart-canvas-wrap admin-chart-canvas-wrap--tall">
              <canvas id="akh-analytics-editors" role="img" aria-label="Tasks by editor"></canvas>
            </div>
          <?php endif; ?>
        </article>
      </section>

      <section class="admin-analytics__tables admin-fade-stagger" aria-labelledby="analytics-tables-h">
        <h2 id="analytics-tables-h" class="admin-panel__title">Detail tables — <?php echo h($report['month_label']); ?></h2>
        <div class="admin-analytics__table-grid">
          <article class="admin-panel">
            <h3 class="admin-panel__subtitle">By client</h3>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th scope="col">Client</th>
                    <th scope="col">Incoming</th>
                    <th scope="col">Delivered</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($report['by_client'] === []): ?>
                    <tr><td colspan="3" class="portal-muted">No rows for this month.</td></tr>
                  <?php else: ?>
                    <?php foreach ($report['by_client'] as $row): ?>
                      <tr>
                        <td>
                          <?php if ($row['username'] !== ''): ?>
                            <a class="text-link" href="<?php echo h($tasksBase . '?f_client=' . rawurlencode((string) $row['username'])); ?>"><?php echo h((string) $row['label']); ?></a>
                          <?php else: ?>
                            <?php echo h((string) $row['label']); ?>
                          <?php endif; ?>
                        </td>
                        <td><?php echo (int) $row['incoming']; ?></td>
                        <td><?php echo (int) $row['delivered']; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </article>
          <article class="admin-panel">
            <h3 class="admin-panel__subtitle">By editor</h3>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th scope="col">Editor</th>
                    <th scope="col">New in month</th>
                    <th scope="col">Handled</th>
                    <th scope="col">Delivered</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($report['by_editor'] === []): ?>
                    <tr><td colspan="4" class="portal-muted">No rows for this month.</td></tr>
                  <?php else: ?>
                    <?php foreach ($report['by_editor'] as $row): ?>
                      <tr>
                        <td>
                          <a class="text-link" href="<?php echo h($tasksBase . '?f_editor=' . rawurlencode((string) $row['username'])); ?>"><?php echo h((string) $row['username']); ?></a>
                        </td>
                        <td><?php echo (int) $row['incoming']; ?></td>
                        <td><?php echo (int) $row['handled']; ?></td>
                        <td><?php echo (int) $row['delivered']; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>
    </div>
  </main>
  <?php
  $analyticsJs = AKH_ROOT . '/assets/js/admin-analytics.js';
  $analyticsVer = is_file($analyticsJs) ? (string) filemtime($analyticsJs) : '1';
  ?>
  <script>
    window._akhAdminAnalytics = <?php echo json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  </script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script defer src="<?php echo h(base_path('assets/js/admin-analytics.js')); ?>?v=<?php echo h($analyticsVer); ?>"></script>
<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
