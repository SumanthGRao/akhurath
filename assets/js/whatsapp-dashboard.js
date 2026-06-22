(function () {
  'use strict';

  var cfg = window.WA_DASHBOARD;
  if (!cfg || !cfg.apiUrl) {
    return;
  }

  var tasksById = {};
  var currentSig = cfg.initialSig || '';
  var currentNotifySig = cfg.notifySig || '';
  var clientAlerts = cfg.alerts || {};
  var filterStatus = cfg.filterStatus || '';
  var filterQ = cfg.filterQ || '';
  var refreshSeconds = Math.max(60, parseInt(cfg.refreshSeconds, 10) || 300);
  var countdown = refreshSeconds;
  var countdownTimer = null;
  var refreshTimer = null;
  var searchDebounce = null;
  var loading = false;
  var baseTitle = document.title;

  var els = {
    body: document.getElementById('wa-tasks-body'),
    totalCount: document.getElementById('wa-total-count'),
    search: document.getElementById('wa-search'),
    clearFilters: document.getElementById('wa-clear-filters'),
    refreshNow: document.getElementById('wa-refresh-now'),
    refreshIndicator: document.getElementById('wa-refresh-indicator'),
    refreshCountdown: document.getElementById('wa-refresh-countdown'),
    modal: document.getElementById('wa-edit-modal'),
    editForm: document.getElementById('wa-edit-form'),
    editClose: document.getElementById('wa-edit-close'),
    editCancel: document.getElementById('wa-edit-cancel'),
    editSave: document.getElementById('wa-edit-save'),
    editError: document.getElementById('wa-edit-error'),
    editMeta: document.getElementById('wa-edit-meta'),
    editTitle: document.getElementById('wa-edit-title'),
    notifyBell: document.getElementById('wa-notify-bell'),
    notifyCount: document.getElementById('wa-notify-count'),
  };

  function normalizeTaskCode(code) {
    var s = String(code || '').trim().toUpperCase();
    var m = s.match(/^AS_?(\d+)$/i);
    if (m) {
      var n = parseInt(m[1], 10);
      return 'AS' + String(n).padStart(4, '0');
    }
    return s;
  }

  function alertForTask(task) {
    var code = normalizeTaskCode(task.task_code);
    return clientAlerts[code] || clientAlerts[task.task_code] || null;
  }

  function setNotifyUi(count) {
    var n = typeof count === 'number' ? count : 0;
    if (els.notifyCount) els.notifyCount.textContent = String(n);
    if (els.notifyBell) {
      els.notifyBell.classList.toggle('wa-bell--active', n > 0);
    }
    if (n > 0) {
      document.title = '🔔 ' + n + ' · ' + baseTitle;
    } else {
      document.title = baseTitle;
    }
  }

  function applyNotifyPayload(data) {
    if (data && data.alerts) {
      clientAlerts = data.alerts;
    }
    if (data && typeof data.notify_count === 'number') {
      setNotifyUi(data.notify_count);
    }
    if (data && data.notify_sig) {
      currentNotifySig = data.notify_sig;
    }
  }

  function ackNotifications(taskCode) {
    var payload = {};
    if (taskCode) {
      payload.task_code = normalizeTaskCode(taskCode);
    }
    return post('notify_ack', payload).then(function (data) {
      applyNotifyPayload(data);
      applyFiltersLocally();
    });
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function indexTasks(list) {
    tasksById = {};
    (list || []).forEach(function (t) {
      tasksById[t.id] = t;
    });
  }

  function post(action, extra) {
    var fd = new FormData();
    fd.append('csrf_token', cfg.csrf);
    fd.append('ajax_action', action);
    if (extra) {
      Object.keys(extra).forEach(function (k) {
        fd.append(k, extra[k]);
      });
    }
    return fetch(cfg.apiUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok || !j.ok) {
          throw new Error((j && j.error) || 'Request failed');
        }
        return j;
      });
    });
  }

  function setLoading(on) {
    loading = on;
    if (els.refreshIndicator) {
      els.refreshIndicator.classList.toggle('is-loading', on);
    }
    if (els.refreshNow) {
      els.refreshNow.disabled = on;
    }
  }

  function updateCounts(counts) {
    if (!counts) return;
    Object.keys(counts).forEach(function (st) {
      var node = document.querySelector('[data-wa-count="' + st + '"]');
      if (node) node.textContent = String(counts[st] || 0);
    });
    if (els.totalCount) {
      var total = 0;
      Object.keys(counts).forEach(function (k) { total += counts[k] || 0; });
      els.totalCount.textContent = String(total);
    }
  }

  function renderTable(tasks) {
    if (!els.body) return;

    if (!tasks || tasks.length === 0) {
      els.body.innerHTML = '<tr class="wa-table__empty"><td colspan="8">No tasks match your filters.</td></tr>';
      return;
    }

    els.body.innerHTML = tasks.map(function (t) {
      var editor = t.assigned_editor_name ? escHtml(t.assigned_editor_name) : '—';
      var alert = alertForTask(t);
      var rowClass = alert ? ' wa-table__row--alert' : '';
      var alertBadge = alert
        ? '<span class="wa-alert-pill" title="' + escHtml(alert.preview || 'Client update') + '">Update</span> '
        : '';
      return (
        '<tr class="wa-table__row' + rowClass + '" data-task-id="' + t.id + '">' +
        '<td>' + alertBadge + '<code class="wa-code">' + escHtml(t.task_code) + '</code></td>' +
        '<td><span class="wa-cell-main">' + escHtml(t.customer_name || '—') + '</span></td>' +
        '<td>' + escHtml(t.project_name || '—') + '</td>' +
        '<td>' + escHtml(t.task_type || '—') + '</td>' +
        '<td><span class="wa-badge wa-badge--' + escHtml(t.status) + '">' + escHtml(t.status_label) + '</span></td>' +
        '<td>' + editor + '</td>' +
        '<td class="wa-cell-muted">' + escHtml(t.updated_at) + '</td>' +
        '<td><button type="button" class="wa-btn wa-btn--sm wa-btn--edit" data-wa-edit="' + t.id + '">Edit</button></td>' +
        '</tr>'
      );
    }).join('');
  }

  function applyFiltersLocally() {
    var list = Object.keys(tasksById).map(function (id) { return tasksById[id]; });
    list.sort(function (a, b) {
      var aa = alertForTask(a) ? 1 : 0;
      var ab = alertForTask(b) ? 1 : 0;
      if (aa !== ab) return ab - aa;
      if (aa && ab) {
        var ta = alertForTask(a);
        var tb = alertForTask(b);
        var cmp = String(tb.created_at || '').localeCompare(String(ta.created_at || ''));
        if (cmp !== 0) return cmp;
      }
      return String(b.updated_at).localeCompare(String(a.updated_at));
    });

    var q = filterQ.toLowerCase().trim();
    var filtered = list.filter(function (t) {
      if (filterStatus && t.status !== filterStatus) return false;
      if (!q) return true;
      var hay = [
        t.task_code, t.customer_name, t.project_name, t.task_type,
      ].join(' ').toLowerCase();
      return hay.indexOf(q) !== -1;
    });

    renderTable(filtered);
  }

  function loadTasks(silent) {
    if (loading) return Promise.resolve();
    if (!silent) setLoading(true);

    return post('list', {
      status: filterStatus,
      q: filterQ,
    })
      .then(function (data) {
        indexTasks(data.tasks || []);
        updateCounts(data.counts || {});
        if (data.sig) currentSig = data.sig;
        applyNotifyPayload(data);
        applyFiltersLocally();
        resetCountdown();
      })
      .catch(function (err) {
        if (!silent) {
          console.error(err);
        }
      })
      .finally(function () {
        if (!silent) setLoading(false);
      });
  }

  function pollChanges() {
    return post('poll', {})
      .then(function (data) {
        var needsReload = data.sig && data.sig !== currentSig;
        var needsNotify = data.notify_sig && data.notify_sig !== currentNotifySig;
        if (needsNotify && typeof data.notify_count === 'number') {
          setNotifyUi(data.notify_count);
          currentNotifySig = data.notify_sig;
        }
        if (needsReload || needsNotify) {
          return loadTasks(true);
        }
      })
      .catch(function () { /* ignore poll errors */ });
  }

  function resetCountdown() {
    countdown = refreshSeconds;
    if (els.refreshCountdown) {
      els.refreshCountdown.textContent = String(countdown);
    }
  }

  function tickCountdown() {
    countdown -= 1;
    if (els.refreshCountdown) {
      els.refreshCountdown.textContent = String(Math.max(0, countdown));
    }
    if (countdown <= 0) {
      loadTasks(true).then(function () {
        pollChanges();
      });
      resetCountdown();
    }
  }

  function openEdit(id) {
    var t = tasksById[id];
    if (!t || !els.modal) return;

    var fields = [
      'id', 'task_code', 'status', 'customer_name', 'customer_id',
      'project_name', 'task_type', 'delivery_type', 'assigned_editor',
      'instructions', 'drive_link', 'reference_link', 'comments',
    ];

    fields.forEach(function (name) {
      var el = document.getElementById('wa-field-' + name);
      if (!el) return;
      if (name === 'customer_id' || name === 'assigned_editor') {
        el.value = t[name] != null && t[name] !== '' ? String(t[name]) : '';
      } else {
        el.value = t[name] != null ? String(t[name]) : '';
      }
    });

    if (els.editTitle) {
      els.editTitle.textContent = t.task_code ? 'Edit ' + t.task_code : 'Edit task';
    }
    if (els.editMeta) {
      var phoneLine = t.phone ? ' · Phone ' + t.phone : '';
      els.editMeta.textContent = 'Created ' + (t.created_at || '—') + ' · Updated ' + (t.updated_at || '—') + phoneLine;
    }
    if (els.editError) {
      els.editError.textContent = '';
      els.editError.classList.add('wa-banner--hidden');
    }

    if (typeof els.modal.showModal === 'function') {
      els.modal.showModal();
    }

    var alert = alertForTask(t);
    if (alert && t.task_code) {
      ackNotifications(t.task_code).catch(function () {});
    }
  }

  function closeEdit() {
    if (els.modal && typeof els.modal.close === 'function') {
      els.modal.close();
    }
  }

  function saveEdit(ev) {
    if (ev) ev.preventDefault();

    var idEl = document.getElementById('wa-field-id');
    var id = idEl ? parseInt(idEl.value, 10) : 0;
    if (!id) return;

    var payload = {
      id: String(id),
      task_code: val('task_code'),
      status: val('status'),
      customer_name: val('customer_name'),
      customer_id: val('customer_id'),
      project_name: val('project_name'),
      task_type: val('task_type'),
      delivery_type: val('delivery_type'),
      assigned_editor: val('assigned_editor'),
      instructions: val('instructions'),
      drive_link: val('drive_link'),
      reference_link: val('reference_link'),
      comments: val('comments'),
    };

    if (els.editSave) els.editSave.disabled = true;

    post('update', payload)
      .then(function (data) {
        if (data.task) {
          tasksById[data.task.id] = data.task;
        }
        if (data.counts) updateCounts(data.counts);
        if (data.sig) currentSig = data.sig;
        applyFiltersLocally();
        closeEdit();
      })
      .catch(function (err) {
        if (els.editError) {
          els.editError.textContent = err.message || 'Could not save.';
          els.editError.classList.remove('wa-banner--hidden');
        }
      })
      .finally(function () {
        if (els.editSave) els.editSave.disabled = false;
      });
  }

  function val(name) {
    var el = document.getElementById('wa-field-' + name);
    return el ? el.value : '';
  }

  function bindEvents() {
    document.querySelectorAll('[data-wa-filter-status]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var st = btn.getAttribute('data-wa-filter-status') || '';
        filterStatus = filterStatus === st ? '' : st;
        document.querySelectorAll('[data-wa-filter-status]').forEach(function (b) {
          b.classList.toggle('is-active', b.getAttribute('data-wa-filter-status') === filterStatus && filterStatus !== '');
        });
        loadTasks(false);
      });
    });

    if (els.search) {
      els.search.addEventListener('input', function () {
        filterQ = els.search.value;
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function () {
          loadTasks(false);
        }, 350);
      });
    }

    if (els.clearFilters) {
      els.clearFilters.addEventListener('click', function () {
        filterStatus = '';
        filterQ = '';
        if (els.search) els.search.value = '';
        document.querySelectorAll('[data-wa-filter-status]').forEach(function (b) {
          b.classList.remove('is-active');
        });
        loadTasks(false);
      });
    }

    if (els.refreshNow) {
      els.refreshNow.addEventListener('click', function () {
        loadTasks(false);
      });
    }

    if (els.body) {
      els.body.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-wa-edit]');
        if (!btn) return;
        var id = parseInt(btn.getAttribute('data-wa-edit'), 10);
        if (id) openEdit(id);
      });
    }

    if (els.editForm) {
      els.editForm.addEventListener('submit', saveEdit);
    }
    if (els.editClose) els.editClose.addEventListener('click', closeEdit);
    if (els.editCancel) els.editCancel.addEventListener('click', closeEdit);

    if (els.notifyBell) {
      els.notifyBell.addEventListener('click', function () {
        ackNotifications(0).catch(function () {});
      });
    }
  }

  indexTasks(cfg.tasks || []);
  setNotifyUi(parseInt(cfg.notifyCount, 10) || 0);
  bindEvents();
  resetCountdown();
  countdownTimer = setInterval(tickCountdown, 1000);
  refreshTimer = setInterval(function () {
    pollChanges();
  }, Math.min(refreshSeconds * 1000, 60000));
})();
