(function () {
  'use strict';

  var cfg = window.WA_BOARD;
  if (!cfg || !cfg.apiUrl) {
    return;
  }

  var tasks = Array.isArray(cfg.tasks) ? cfg.tasks.slice() : [];
  var meetings = Array.isArray(cfg.meetings) ? cfg.meetings.slice() : [];
  var currentSig = cfg.initialSig || '';
  var activeCode = '';
  var taskSearch = '';
  var pollTimer = null;

  var els = {
    tasksList: document.getElementById('wab-tasks-list'),
    meetingsList: document.getElementById('wab-meetings-list'),
    tasksCount: document.getElementById('wab-tasks-count'),
    meetingsCount: document.getElementById('wab-meetings-count'),
    taskSearch: document.getElementById('wab-task-search'),
    detail: document.getElementById('wab-detail'),
    detailTitle: document.getElementById('wab-detail-title'),
    detailBody: document.getElementById('wab-detail-body'),
    detailClose: document.getElementById('wab-detail-close'),
    live: document.getElementById('wab-live'),
    liveLabel: document.getElementById('wab-live-label'),
  };

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normalizeCode(code) {
    return String(code || '').trim().toUpperCase();
  }

  function pillForTask(task) {
    if (!task.has_update) {
      return '';
    }
    var kind = String(task.update_kind || '');
    var cls = 'wab-card__pill';
    if (kind.indexOf('meeting') === 0) {
      cls += ' wab-card__pill--meeting';
    } else if (kind === 'whatsapp_message') {
      cls += ' wab-card__pill--message';
    }
    var label = task.update_label || 'Update';
    return '<span class="' + esc(cls) + '">' + esc(label) + '</span>';
  }

  function taskMatchesSearch(task) {
    if (!taskSearch) {
      return true;
    }
    var hay = [
      task.task_code,
      task.customer_name,
      task.project_name,
      task.task_type,
      task.status_label,
      task.assigned_editor_name,
    ]
      .join(' ')
      .toLowerCase();
    return hay.indexOf(taskSearch) !== -1;
  }

  function renderTasks() {
    if (!els.tasksList) {
      return;
    }
    if (!tasks.length) {
      els.tasksList.innerHTML = '<p class="wab-list__empty">No tasks yet.</p>';
      return;
    }
    var html = '';
    var visible = 0;
    tasks.forEach(function (task) {
      if (!taskMatchesSearch(task)) {
        return;
      }
      visible += 1;
      var code = String(task.task_code || '');
      var active = normalizeCode(code) === normalizeCode(activeCode);
      var unread = task.has_update ? ' wab-card--unread' : '';
      html +=
        '<button type="button" class="wab-card' +
        (active ? ' wab-card--active' : '') +
        unread +
        '" data-task-code="' +
        esc(code) +
        '" role="listitem">' +
        '<div class="wab-card__row">' +
        '<span class="wab-card__code">' +
        esc(code) +
        '</span>' +
        pillForTask(task) +
        '</div>' +
        '<p class="wab-card__main">' +
        esc(task.customer_name || '—') +
        (task.project_name ? ' · ' + esc(task.project_name) : '') +
        '</p>' +
        '<div class="wab-card__meta">' +
        '<span class="wa-badge wa-badge--' +
        esc(task.status) +
        '">' +
        esc(task.status_label || task.status) +
        '</span>' +
        (task.assigned_editor_name
          ? '<span>Editor: ' + esc(task.assigned_editor_name) + '</span>'
          : '<span>Unassigned</span>') +
        (task.unread_messages > 0
          ? '<span>' + esc(String(task.unread_messages)) + ' new msg</span>'
          : '') +
        '</div>' +
        '</button>';
    });
    els.tasksList.innerHTML =
      visible === 0
        ? '<p class="wab-list__empty">No tasks match your search.</p>'
        : html;
    if (els.tasksCount) {
      els.tasksCount.textContent = String(tasks.length);
    }
  }

  function renderMeetings() {
    if (!els.meetingsList) {
      return;
    }
    if (!meetings.length) {
      els.meetingsList.innerHTML = '<p class="wab-list__empty">No meetings scheduled.</p>';
      return;
    }
    var html = '';
    meetings.forEach(function (m) {
      var code = String(m.task_code || '');
      var active = normalizeCode(code) === normalizeCode(activeCode);
      html +=
        '<button type="button" class="wab-card' +
        (active ? ' wab-card--active' : '') +
        (m.is_unread ? ' wab-card--unread' : '') +
        '" data-task-code="' +
        esc(code) +
        '" role="listitem">' +
        '<div class="wab-card__row">' +
        '<span class="wab-card__code">' +
        esc(code) +
        '</span>' +
        (m.is_unread ? '<span class="wab-card__pill wab-card__pill--meeting">New</span>' : '') +
        '</div>' +
        '<p class="wab-card__main">' +
        esc(m.customer_name || '—') +
        (m.project_name ? ' · ' + esc(m.project_name) : '') +
        '</p>' +
        '<div class="wab-card__meta">' +
        (m.when_label ? '<span>When: ' + esc(m.when_label) + '</span>' : '') +
        (m.meet_link ? '<span>Meet link available</span>' : '') +
        '</div>' +
        '</button>';
    });
    els.meetingsList.innerHTML = html;
    if (els.meetingsCount) {
      els.meetingsCount.textContent = String(meetings.length);
    }
  }

  function renderDetail(detail) {
    if (!els.detail || !els.detailBody || !els.detailTitle) {
      return;
    }
    if (!detail) {
      els.detail.hidden = true;
      return;
    }
    els.detail.hidden = false;
    els.detailTitle.textContent =
      detail.task_code +
      (detail.project_name ? ' — ' + detail.project_name : '');

    var facts =
      '<dl class="wab-detail__facts">' +
      '<div><dt>Customer</dt><dd>' +
      esc(detail.customer_name || '—') +
      '</dd></div>' +
      '<div><dt>Status</dt><dd>' +
      esc(detail.status_label || detail.status || '—') +
      '</dd></div>' +
      '<div><dt>Editor</dt><dd>' +
      esc(detail.assigned_editor_name || 'Unassigned') +
      '</dd></div>' +
      '<div><dt>Type</dt><dd>' +
      esc(detail.task_type || '—') +
      '</dd></div>' +
      '<div><dt>Updated</dt><dd>' +
      esc(detail.updated_at || '—') +
      '</dd></div>' +
      '</dl>';

    var instructions = '';
    if (detail.instructions) {
      instructions =
        '<section class="wab-detail__section"><h3>Brief</h3><p class="wab-detail__prose">' +
        esc(detail.instructions) +
        '</p></section>';
    }

    var updatesHtml = '';
    var updates = Array.isArray(detail.updates) ? detail.updates : [];
    if (updates.length) {
      updatesHtml = '<section class="wab-detail__section"><h3>Updates</h3>';
      updates.forEach(function (u) {
        var cls = 'wab-update';
        if (String(u.kind || '').indexOf('meeting') >= 0) {
          cls += ' wab-update--meeting';
        }
        updatesHtml +=
          '<article class="' +
          cls +
          '">' +
          '<p class="wab-update__label">' +
          esc(u.label || 'Update') +
          '</p>' +
          '<p class="wab-update__text">' +
          esc(u.preview || '') +
          '</p>';
        if (u.when_label) {
          updatesHtml +=
            '<p class="wab-update__when"><strong>When:</strong> ' + esc(u.when_label) + '</p>';
        }
        if (u.meet_link) {
          updatesHtml +=
            '<p class="wab-update__when"><a class="wa-btn wa-btn--sm wa-btn--ghost" href="' +
            esc(u.meet_link) +
            '" target="_blank" rel="noopener noreferrer">Join Google Meet</a></p>';
        }
        updatesHtml += '</article>';
      });
      updatesHtml += '</section>';
    } else if (detail.unread_messages > 0) {
      updatesHtml =
        '<section class="wab-detail__section"><h3>Updates</h3><p class="wab-detail__prose">' +
        esc(String(detail.unread_messages)) +
        ' unread WhatsApp message(s). Sign in to read and reply.</p></section>';
    } else {
      updatesHtml =
        '<section class="wab-detail__section"><h3>Updates</h3><p class="wab-detail__prose">No new updates for this task.</p></section>';
    }

    els.detailBody.innerHTML = facts + instructions + updatesHtml;
    els.detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function selectTask(code) {
    activeCode = String(code || '');
    renderTasks();
    renderMeetings();
    if (!activeCode) {
      renderDetail(null);
      return;
    }
    fetch(cfg.apiUrl + '?action=detail&task_code=' + encodeURIComponent(activeCode), {
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok && data.detail) {
          renderDetail(data.detail);
        }
      })
      .catch(function () {});
  }

  function applyPayload(data) {
    if (!data) {
      return;
    }
    tasks = Array.isArray(data.tasks) ? data.tasks : tasks;
    meetings = Array.isArray(data.meetings) ? data.meetings : meetings;
    currentSig = data.sig || currentSig;
    renderTasks();
    renderMeetings();
    if (activeCode) {
      selectTask(activeCode);
    }
  }

  function setLive(syncing) {
    if (!els.live) {
      return;
    }
    els.live.classList.toggle('wab-live--sync', !!syncing);
    if (els.liveLabel) {
      els.liveLabel.textContent = syncing ? 'Syncing…' : 'Live';
    }
  }

  function refreshFull() {
    setLive(true);
    return fetch(cfg.apiUrl + '?action=list', { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          applyPayload(data);
        }
      })
      .catch(function () {})
      .finally(function () {
        setLive(false);
      });
  }

  function poll() {
    fetch(cfg.apiUrl + '?action=poll', { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.ok && data.sig && data.sig !== currentSig) {
          return refreshFull();
        }
      })
      .catch(function () {});
  }

  function bindLists() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.wab-card[data-task-code]') : null;
      if (!btn) {
        return;
      }
      selectTask(btn.getAttribute('data-task-code') || '');
    });
  }

  if (els.taskSearch) {
    els.taskSearch.addEventListener('input', function () {
      taskSearch = String(els.taskSearch.value || '')
        .toLowerCase()
        .trim();
      renderTasks();
    });
  }

  if (els.detailClose) {
    els.detailClose.addEventListener('click', function () {
      selectTask('');
    });
  }

  bindLists();
  renderTasks();
  renderMeetings();

  var pollMs = Math.max(3000, parseInt(cfg.pollMs, 10) || 5000);
  pollTimer = setInterval(poll, pollMs);
})();
