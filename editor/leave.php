<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/editor-leave.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_editor();

$pageTitle = 'Apply leave — ' . SITE_NAME;
$bodyClass = 'page-portal page-portal--board';
$editor = (string) akh_editor_current();

$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $date = trim((string) ($_POST['leave_date'] ?? ''));
        $leavePart = trim((string) ($_POST['leave_part'] ?? 'full'));
        $note = trim((string) ($_POST['note'] ?? ''));
        $err = akh_editor_leave_apply($editor, $date, $note, $leavePart);
        if ($err !== null) {
            $error = $err;
        } else {
            $flash = 'Leave request sent. Your admin will approve it.';
        }
    }
}

$myLeaves = akh_editor_leave_for_editor($editor);
$pageCsrf = akh_csrf_token();

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks" style="max-width:36rem;margin:0 auto">
      <header class="desk-head desk-head--editor">
        <div>
          <h1 class="portal-title">Apply leave</h1>
          <p class="portal-lead" style="margin-bottom:0">Request full-day or half-day leave (Mon–Sat). Sundays are already off.</p>
        </div>
        <p class="desk-head__actions">
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('editor/dashboard.php')); ?>">← Task board</a>
        </p>
      </header>

      <?php if ($flash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($flash); ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <?php if (!AKH_EDITOR_ATTENDANCE_ENABLED): ?>
        <p class="banner banner--info">Leave requests are unavailable while attendance is disabled.</p>
      <?php else: ?>
        <form class="portal-form" method="post" action="" style="margin-top:1rem">
          <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
          <label class="field">
            <span>Date</span>
            <input type="date" name="leave_date" required value="<?php echo h(date('Y-m-d')); ?>" />
          </label>
          <label class="field">
            <span>Leave type</span>
            <select name="leave_part" required>
              <option value="full">Full day</option>
              <option value="first_half">Half day — first half</option>
              <option value="second_half">Half day — second half</option>
            </select>
          </label>
          <label class="field">
            <span>Note (optional)</span>
            <textarea name="note" rows="3" maxlength="500" placeholder="Reason or context for your admin"></textarea>
          </label>
          <button type="submit" class="btn btn--primary">Submit request</button>
        </form>
      <?php endif; ?>

      <section class="portal-section" style="margin-top:1.5rem" aria-labelledby="my-leaves">
        <h2 id="my-leaves" class="portal-section__title">Your requests</h2>
        <?php if ($myLeaves === []): ?>
          <p class="portal-muted">None yet.</p>
        <?php else: ?>
          <ul class="editor-leave-list">
            <?php foreach ($myLeaves as $r): ?>
              <li class="editor-leave-list__item">
                <span class="editor-leave-list__date"><?php echo h((string) ($r['date'] ?? '')); ?></span>
                <span class="editor-leave-list__part"><?php echo h(akh_editor_leave_part_label((string) ($r['leave_part'] ?? 'full'))); ?></span>
                <span class="editor-leave-list__st editor-leave-list__st--<?php echo h(preg_replace('/[^a-z]/', '', (string) ($r['status'] ?? ''))); ?>"><?php echo h((string) ($r['status'] ?? '')); ?></span>
                <?php if (trim((string) ($r['note'] ?? '')) !== ''): ?>
                  <span class="editor-leave-list__note"><?php echo h((string) ($r['note'] ?? '')); ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
