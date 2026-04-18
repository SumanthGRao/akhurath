<?php

declare(strict_types=1);

/**
 * @var string $adminConsoleActive One of: tasks, create, clients, editors.
 *            Use '' on Overview, Attendance, Account, etc. (no console link marked active).
 */

$adminBase = base_path('admin/');
$active = $adminConsoleActive ?? '';
?>
<details class="admin-console-dd">
  <summary class="admin-console-dd__summary btn btn--ghost btn--sm">Console</summary>
  <div class="admin-console-dd__panel">
    <nav class="admin-console-dd__nav" aria-label="Task console">
      <a class="admin-console-dd__link<?php echo $active === 'tasks' ? ' is-active' : ''; ?>" href="<?php echo h($adminBase . 'tasks.php'); ?>">All tasks</a>
      <a class="admin-console-dd__link<?php echo $active === 'create' ? ' is-active' : ''; ?>" href="<?php echo h($adminBase . 'tasks.php?view=create'); ?>">Create task</a>
      <a class="admin-console-dd__link<?php echo $active === 'clients' ? ' is-active' : ''; ?>" href="<?php echo h($adminBase . 'clients.php'); ?>">Clients</a>
      <a class="admin-console-dd__link<?php echo $active === 'editors' ? ' is-active' : ''; ?>" href="<?php echo h($adminBase . 'editors.php'); ?>">Editors</a>
    </nav>
  </div>
</details>
<script>
(function () {
  document.querySelectorAll(".admin-console-dd").forEach(function (dd) {
    dd.addEventListener("toggle", function () {
      if (!dd.open) return;
      function onDoc(ev) {
        if (!dd.contains(ev.target)) {
          dd.open = false;
          document.removeEventListener("click", onDoc);
        }
      }
      requestAnimationFrame(function () {
        document.addEventListener("click", onDoc);
      });
    });
  });
  document.addEventListener("keydown", function (ev) {
    if (ev.key !== "Escape") return;
    document.querySelectorAll(".admin-console-dd[open]").forEach(function (dd) {
      dd.open = false;
    });
  });
})();
</script>
