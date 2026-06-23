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
  var notices = cfg.notices || [];
  var meetings = cfg.meetings || [];
  var reminderTasks = {};
  var activeTab = 'tasks';
  var bellOpen = false;
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
    notifyDropdown: document.getElementById('wa-notify-dropdown'),
    notifyList: document.getElementById('wa-notify-list'),
    notifySub: document.getElementById('wa-notify-dropdown-sub'),
    notifyMarkAll: document.getElementById('wa-notify-mark-all'),
    panelTasks: document.getElementById('wa-panel-tasks'),
    panelMeetings: document.getElementById('wa-panel-meetings'),
    meetingsBody: document.getElementById('wa-meetings-body'),
    meetingsBadge: document.getElementById('wa-meetings-badge'),
    meetingBanner: document.getElementById('wa-meeting-banner'),
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

  function alertPillMeta(alert) {
    if (!alert) {
      return { className: 'wa-alert-pill', label: 'Update' };
    }
    var kind = String(alert.kind || '');
    if (kind === 'meeting_request') {
      return { className: 'wa-alert-pill wa-alert-pill--meeting', label: 'Meeting' };
    }
    if (kind === 'meeting_reminder') {
      return { className: 'wa-alert-pill wa-alert-pill--reminder', label: 'Soon' };
    }
    return { className: 'wa-alert-pill', label: 'Update' };
  }

  function alertPriority(alert) {
    if (!alert) return 0;
    return typeof alert.priority === 'number' ? alert.priority : 0;
  }

  function alertForTask(task) {
    var code = normalizeTaskCode(task.task_code);
    if (clientAlerts[code]) return clientAlerts[code];
    if (clientAlerts[task.task_code]) return clientAlerts[task.task_code];
    var keys = Object.keys(clientAlerts || {});
    for (var i = 0; i < keys.length; i++) {
      if (normalizeTaskCode(keys[i]) === code) return clientAlerts[keys[i]];
    }
    return null;
  }

  function isReminderTask(taskCode) {
    var code = normalizeTaskCode(taskCode);
    return !!(reminderTasks[code] || reminderTasks[taskCode]);
  }

  function rebuildReminderTasks(reminders) {
    reminderTasks = {};
    (reminders || []).forEach(function (rem) {
      var mins = parseInt(rem.minutes_until, 10);
      if (isNaN(mins) || mins > 10) return;
      var code = normalizeTaskCode(rem.task_code);
      if (code) reminderTasks[code] = true;
    });
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
    renderNoticeDropdown();
  }

  function renderNoticeDropdown() {
    if (!els.notifyList) return;

    var list = notices || [];
    if (els.notifySub) {
      els.notifySub.textContent = list.length === 0
        ? 'Nothing new'
        : list.length + ' unread update' + (list.length === 1 ? '' : 's');
    }
    if (els.notifyMarkAll) {
      els.notifyMarkAll.hidden = list.length === 0;
    }

    if (list.length === 0) {
      els.notifyList.innerHTML = '<li class="wa-bell-dropdown__empty">No unread updates right now.</li>';
      return;
    }

    els.notifyList.innerHTML = list.map(function (n) {
      var code = escHtml(n.task_code || '');
      var label = escHtml(n.label || 'Update');
      var preview = escHtml(n.preview || '');
      var kind = String(n.kind || '');
      var kindClass = kind.indexOf('meeting_') === 0 ? ' wa-bell-dropdown__item--meeting' : '';
      var linkBtn = n.meet_link
        ? ' <a class="wa-bell-dropdown__link" href="' + escHtml(n.meet_link) + '" target="_blank" rel="noopener noreferrer">Meet link</a>'
        : '';
      return (
        '<li class="wa-bell-dropdown__item' + kindClass + '">' +
        '<div class="wa-bell-dropdown__item-head">' +
        '<strong class="wa-bell-dropdown__code">' + code + '</strong>' +
        '<span class="wa-bell-dropdown__label">' + label + '</span>' +
        '</div>' +
        '<p class="wa-bell-dropdown__preview">' + preview + linkBtn + '</p>' +
        '<div class="wa-bell-dropdown__item-actions">' +
        '<button type="button" class="wa-btn wa-btn--sm wa-btn--ghost" data-wa-notice-open="' + code + '">Open task</button>' +
        '<button type="button" class="wa-btn wa-btn--sm wa-btn--ghost" data-wa-notice-read="' + escHtml(n.task_code || '') + '">Mark read</button>' +
        '</div>' +
        '</li>'
      );
    }).join('');
  }

  function setBellOpen(open) {
    bellOpen = !!open;
    if (els.notifyDropdown) {
      els.notifyDropdown.hidden = !bellOpen;
    }
    if (els.notifyBell) {
      els.notifyBell.setAttribute('aria-expanded', bellOpen ? 'true' : 'false');
    }
  }

  function applyNotifyPayload(data) {
    if (data && data.alerts) {
      clientAlerts = data.alerts;
    }
    if (data && data.notices) {
      notices = data.notices;
    }
    if (data && data.meetings) {
      meetings = data.meetings;
      renderMeetingsTable();
      updateMeetingsBadge();
      updateMeetingBanner();
    }
    if (data && typeof data.notify_count === 'number') {
      setNotifyUi(data.notify_count);
    } else {
      renderNoticeDropdown();
    }
    if (data && data.notify_sig) {
      if (window.AkhMeetingAlerts) {
        AkhMeetingAlerts.onNotifyChange(data.notify_sig, data.notify_count);
      }
      currentNotifySig = data.notify_sig;
    }
    if (data && data.reminders) {
      rebuildReminderTasks(data.reminders);
      if (window.AkhMeetingAlerts) {
        AkhMeetingAlerts.processReminders(data.reminders);
      }
    }
  }

  function updateMeetingsBadge() {
    if (!els.meetingsBadge) return;
    var n = (meetings || []).length;
    els.meetingsBadge.textContent = String(n);
    els.meetingsBadge.classList.toggle('wa-tabs__badge--hidden', n === 0);
  }

  function updateMeetingBanner() {
    if (!els.meetingBanner) return;
    var pending = (meetings || []).filter(function (m) { return m.is_unread; });
    els.meetingBanner.hidden = pending.length === 0;
    if (pending.length === 0) return;
    var list = els.meetingBanner.querySelector('.wa-meeting-banner__list');
    if (!list) return;
    list.innerHTML = pending.map(function (m) {
      return (
        '<li class="wa-meeting-banner__item">' +
        '<strong class="wa-meeting-banner__code">' + escHtml(m.task_code) + '</strong>' +
        '<span class="wa-meeting-banner__text">' + escHtml(m.preview || '') + '</span>' +
        '</li>'
      );
    }).join('');
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
      var rowClass = '';
      if (alert) {
        rowClass += ' wa-table__row--unread';
      }
      if (isReminderTask(t.task_code)) {
        rowClass += ' wa-table__row--meeting-reminder';
      }
      var pill = alertPillMeta(alert);
      var alertBadge = alert
        ? '<span class="' + pill.className + '" title="' + escHtml(alert.preview || pill.label) + '">' + escHtml(pill.label) + '</span> '
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

  function renderMeetingsTable() {
    if (!els.meetingsBody) return;

    var list = meetings || [];
    if (list.length === 0) {
      els.meetingsBody.innerHTML = '<tr class="wa-table__empty"><td colspan="7">No meetings scheduled yet.</td></tr>';
      return;
    }

    els.meetingsBody.innerHTML = list.map(function (m) {
      var rowClass = m.is_unread ? ' wa-meetings-row--unread' : '';
      var when = escHtml(m.start_time || m.requested_time_text || m.slot_selected || '—');
      var status = escHtml(m.status || 'pending');
      var link = String(m.meet_link || '').trim();
      var linkCell = link
        ? '<a class="wa-meetings-link" href="' + escHtml(link) + '" target="_blank" rel="noopener noreferrer">Open Meet</a>'
        : '<span class="wa-cell-muted">—</span>';
      var phone = m.phone ? ' · ' + escHtml(m.phone) : '';
      var actions = '<button type="button" class="wa-btn wa-btn--sm wa-btn--ghost" data-wa-meeting-task="' + escHtml(m.task_code) + '">Find task</button>';
      if (m.is_unread) {
        actions += ' <button type="button" class="wa-btn wa-btn--sm wa-btn--ghost" data-wa-meeting-read="' + escHtml(m.task_code) + '">Mark read</button>';
      }
      return (
        '<tr class="wa-meetings-row' + rowClass + '">' +
        '<td><code class="wa-code">' + escHtml(m.task_code) + '</code></td>' +
        '<td>' + escHtml(m.customer_name || '—') + phone + '</td>' +
        '<td>' + escHtml(m.project_name || '—') + '</td>' +
        '<td>' + when + '</td>' +
        '<td><span class="wa-badge wa-badge--meeting">' + status + '</span></td>' +
        '<td>' + linkCell + '</td>' +
        '<td class="wa-meetings-actions">' + actions + '</td>' +
        '</tr>'
      );
    }).join('');
  }

  function switchTab(tab) {
    activeTab = tab === 'meetings' ? 'meetings' : 'tasks';
    document.querySelectorAll('[data-wa-tab]').forEach(function (btn) {
      var t = btn.getAttribute('data-wa-tab');
      btn.classList.toggle('is-active', t === activeTab);
    });
    if (els.panelTasks) {
      els.panelTasks.hidden = activeTab !== 'tasks';
      els.panelTasks.classList.toggle('wa-panel--hidden', activeTab !== 'tasks');
    }
    if (els.panelMeetings) {
      els.panelMeetings.hidden = activeTab !== 'meetings';
      els.panelMeetings.classList.toggle('wa-panel--hidden', activeTab !== 'meetings');
    }
    if (activeTab === 'meetings') {
      renderMeetingsTable();
    }
  }

  function findTaskIdByCode(code) {
    var norm = normalizeTaskCode(code);
    var ids = Object.keys(tasksById);
    for (var i = 0; i < ids.length; i++) {
      var t = tasksById[ids[i]];
      if (normalizeTaskCode(t.task_code) === norm) {
        return t.id;
      }
    }
    return 0;
  }

  function applyFiltersLocally() {
    var list = Object.keys(tasksById).map(function (id) { return tasksById[id]; });
    list.sort(function (a, b) {
      var pa = alertPriority(alertForTask(a));
      var pb = alertPriority(alertForTask(b));
      if (pa !== pb) return pb - pa;
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
        if (needsNotify) {
          applyNotifyPayload(data);
        }
        if (data.reminders) {
          rebuildReminderTasks(data.reminders);
          if (window.AkhMeetingAlerts) {
            AkhMeetingAlerts.processReminders(data.reminders);
          }
          applyFiltersLocally();
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

    document.querySelectorAll('[data-wa-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        switchTab(btn.getAttribute('data-wa-tab') || 'tasks');
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

    if (els.meetingsBody) {
      els.meetingsBody.addEventListener('click', function (ev) {
        var readBtn = ev.target.closest('[data-wa-meeting-read]');
        if (readBtn) {
          var code = readBtn.getAttribute('data-wa-meeting-read');
          if (code) ackNotifications(code).catch(function () {});
          return;
        }
        var taskBtn = ev.target.closest('[data-wa-meeting-task]');
        if (taskBtn) {
          var taskCode = taskBtn.getAttribute('data-wa-meeting-task');
          switchTab('tasks');
          var tid = findTaskIdByCode(taskCode);
          if (tid) openEdit(tid);
        }
      });
    }

    if (els.editForm) {
      els.editForm.addEventListener('submit', saveEdit);
    }
    if (els.editClose) els.editClose.addEventListener('click', closeEdit);
    if (els.editCancel) els.editCancel.addEventListener('click', closeEdit);

    if (els.notifyBell) {
      els.notifyBell.addEventListener('click', function (ev) {
        ev.stopPropagation();
        setBellOpen(!bellOpen);
      });
    }

    if (els.notifyMarkAll) {
      els.notifyMarkAll.addEventListener('click', function () {
        ackNotifications(0).then(function () {
          setBellOpen(false);
        }).catch(function () {});
      });
    }

    if (els.notifyList) {
      els.notifyList.addEventListener('click', function (ev) {
        var readBtn = ev.target.closest('[data-wa-notice-read]');
        if (readBtn) {
          var code = readBtn.getAttribute('data-wa-notice-read');
          if (code) ackNotifications(code).catch(function () {});
          return;
        }
        var openBtn = ev.target.closest('[data-wa-notice-open]');
        if (openBtn) {
          var taskCode = openBtn.getAttribute('data-wa-notice-open');
          setBellOpen(false);
          switchTab('tasks');
          var tid = findTaskIdByCode(taskCode);
          if (tid) openEdit(tid);
        }
      });
    }

    document.addEventListener('click', function (ev) {
      if (!bellOpen) return;
      if (ev.target.closest('.wa-bell-wrap')) return;
      setBellOpen(false);
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && bellOpen) {
        setBellOpen(false);
      }
    });
  }

  if (window.AkhMeetingAlerts) {
    AkhMeetingAlerts.init({ notifySig: cfg.notifySig || '', reminders: cfg.reminders || [] });
  }
  rebuildReminderTasks(cfg.reminders || []);
  indexTasks(cfg.tasks || []);
  if (cfg.alerts) {
    clientAlerts = cfg.alerts;
  }
  if (cfg.notices) {
    notices = cfg.notices;
  }
  if (cfg.meetings) {
    meetings = cfg.meetings;
  }
  setNotifyUi(parseInt(cfg.notifyCount, 10) || 0);
  renderMeetingsTable();
  updateMeetingsBadge();
  updateMeetingBanner();
  applyFiltersLocally();
  bindEvents();
  resetCountdown();
  countdownTimer = setInterval(tickCountdown, 1000);
  refreshTimer = setInterval(function () {
    pollChanges();
  }, 30000);
})();
