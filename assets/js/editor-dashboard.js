/**
 * Editor desk — live sync (ClickUp / ServiceNow style), AJAX actions, filters.
 */
(function () {
  'use strict';

  var root = document.getElementById('editor-desk');
  if (!root) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var BellKey = 'akh_editor_bell_last';
  var rowCache = {};
  var activeTaskId = '';
  var activeSection = 'mine';
  var activeFilter = 'all';
  var searchQuery = '';

  function qs(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function qsa(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normId(id) {
    return String(id || '').trim();
  }

  function postAjax(action, fields) {
    var fd = new URLSearchParams();
    fd.set('ajax_action', action);
    fd.set('csrf_token', csrf);
    Object.keys(fields || {}).forEach(function (k) {
      fd.set(k, fields[k]);
    });
    return fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
      credentials: 'same-origin',
    })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('http_' + r.status);
        }
        return r.json();
      })
      .catch(function () {
        return { ok: false, error: 'Request failed. Refresh and try again.' };
      });
  }

  function playNotifyPing() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx();
      var o = ctx.createOscillator();
      var g = ctx.createGain();
      o.type = 'sine';
      o.frequency.value = 784;
      g.gain.setValueAtTime(0.0001, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.07, ctx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
      o.connect(g);
      g.connect(ctx.destination);
      o.start(ctx.currentTime);
      o.stop(ctx.currentTime + 0.2);
      setTimeout(function () { ctx.close(); }, 300);
    } catch (e) {}
  }

  function setDeskBell(n) {
    var b = document.querySelector('.desk-bell-wrap .desk-bell');
    if (!b) return;
    var c = b.querySelector('.desk-bell__count');
    if (c && typeof n === 'number') {
      c.textContent = String(n);
      sessionStorage.setItem(BellKey, String(n));
    }
    b.classList.toggle('desk-bell--zero', typeof n === 'number' && n < 1);
    b.classList.toggle('desk-bell--wiggle', typeof n === 'number' && n > 0);
    b.classList.toggle('desk-bell--pop', typeof n === 'number' && n > 0);
  }

  function updateTabBadges(poolCount, mineCount) {
    qsa('.edesk-tab', root).forEach(function (tab) {
      var section = tab.getAttribute('data-section');
      var n = section === 'pool' ? poolCount : mineCount;
      var badge = tab.querySelector('.edesk-tab__badge');
      if (n > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'edesk-tab__badge';
          tab.appendChild(badge);
        }
        badge.textContent = String(n);
      } else if (badge) {
        badge.remove();
      }
    });
  }

  function showToast(message, kind) {
    var host = qs('#edesk-toasts', root);
    if (!host || !message) return;
    var el = document.createElement('div');
    el.className = 'edesk-toast' + (kind ? ' edesk-toast--' + kind : '');
    el.textContent = message;
    host.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add('edesk-toast--in');
    });
    setTimeout(function () {
      el.classList.remove('edesk-toast--in');
      setTimeout(function () { el.remove(); }, 280);
    }, 4200);
  }

  function setLiveStatus(text, syncing) {
    var live = qs('#edesk-live', root);
    var timeEl = qs('#edesk-live-time', root);
    if (timeEl) timeEl.textContent = text;
    if (live) live.classList.toggle('edesk-live--sync', !!syncing);
  }

  function parseTs(iso) {
    if (!iso) return NaN;
    var t = Date.parse(iso);
    if (!isNaN(t)) return t;
    t = Date.parse(String(iso).replace(' ', 'T'));
    if (!isNaN(t)) return t;
    if (!/Z|[+-]\d{2}:?\d{2}$/.test(String(iso))) {
      t = Date.parse(String(iso).replace(' ', 'T') + 'Z');
    }
    return t;
  }

  function relativeTime(iso) {
    if (!iso) return '';
    var t = parseTs(iso);
    if (isNaN(t)) return iso;
    var sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (sec < 60) return sec + 's ago';
    if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
    if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
    return Math.floor(sec / 86400) + 'd ago';
  }

  function listAtForRow(row) {
    if (row.list_at) return row.list_at;
    if (row.section === 'pool') {
      return row.created_at || row.updated_at || '';
    }
    return row.updated_at || row.created_at || '';
  }

  function refreshRelativeTimes() {
    qsa('.edesk-list__when', root).forEach(function (el) {
      var ts = el.getAttribute('data-ts') || '';
      if (ts) el.textContent = relativeTime(ts);
    });
  }

  function postAck(kind, taskId) {
    return postAjax('view_ack', { ack_kind: kind, task_id: taskId }).then(function (j) {
      if (j && j.ok) {
        if (typeof j.bell === 'number') setDeskBell(j.bell);
        if (j.desk) applyDeskLists(j.desk, true);
      }
      return j;
    });
  }

  function clearMeetingBadge(btn) {
    if (!btn) return;
    btn.removeAttribute('data-ack-meeting');
    btn.classList.remove('edesk-list__item--meeting');
    btn.classList.remove('edesk-list__item--meeting-unread');
    btn.setAttribute('data-meeting', '0');
    btn.setAttribute('data-unread', btn.getAttribute('data-notify') === '1' || btn.getAttribute('data-ack-new') === '1' ? '1' : '0');
    var soon = btn.querySelector('.edesk-list__pill--soon');
    if (soon) soon.remove();
  }

  function ackOpenedItem(btn) {
    if (!btn) return;
    var tid = btn.getAttribute('data-task-id');
    if (!tid) return;
    if (btn.getAttribute('data-ack-new') === '1') {
      btn.removeAttribute('data-ack-new');
      btn.classList.remove('edesk-list__item--new');
      var pill = btn.querySelector('.ticket__pill--new');
      if (pill) pill.remove();
      btn.setAttribute('data-unread', btn.getAttribute('data-notify') === '1' || btn.getAttribute('data-ack-meeting') === '1' ? '1' : '0');
      postAck('new', tid);
    }
    if (btn.getAttribute('data-ack-editor') === '1') {
      btn.removeAttribute('data-ack-editor');
      btn.classList.remove('edesk-list__item--notify');
      btn.setAttribute('data-notify', '0');
      btn.setAttribute('data-unread', btn.getAttribute('data-ack-new') === '1' || btn.getAttribute('data-ack-meeting') === '1' ? '1' : '0');
      var dot = btn.querySelector('.edesk-list__dot');
      if (dot) dot.remove();
      clearMeetingBadge(btn);
      postAck('editor_task', tid);
    } else if (btn.getAttribute('data-ack-meeting') === '1') {
      clearMeetingBadge(btn);
      postAck('meeting', tid);
    }
  }

  function markMeetingRead(taskId) {
    taskId = normId(taskId);
    if (!taskId) return Promise.resolve();
    var item = findListItem(taskId);
    clearMeetingBadge(item);
    return postAck('meeting', taskId);
  }

  var lists = {
    pool: qs('#edesk-list-pool', root),
    mine: qs('#edesk-list-mine', root),
  };
  var panelsHost = qs('#edesk-detail-scroll', root);
  var emptyEl = qs('#edesk-empty', root);
  var searchInput = qs('#edesk-search', root);

  function isMobile() {
    return window.matchMedia('(max-width: 820px)').matches;
  }

  function setMobileDetail(on) {
    document.body.classList.toggle('edesk--mobile-detail', on && isMobile());
  }

  function showList(section) {
    activeSection = section;
    if (activeFilter === 'all') {
      qsa('.edesk-tab', root).forEach(function (tab) {
        var on = tab.getAttribute('data-section') === section;
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.keys(lists).forEach(function (key) {
        if (lists[key]) lists[key].hidden = key !== section;
      });
    }
    var hint = qs('#edesk-sidebar-hint', root);
    if (hint) {
      if (activeFilter === 'unread') {
        hint.textContent = 'Showing unread tasks from pool and My tasks.';
      } else if (activeFilter === 'meeting') {
        hint.textContent = 'Showing meeting-related tasks from pool and My tasks.';
      } else {
        hint.textContent =
          section === 'pool'
            ? 'New jobs appear here in real time — claim to move to My tasks.'
            : 'Live updates for messages, feedback, and status changes.';
      }
    }
    applyListFilters();
  }

  function setFilterMode(filter) {
    activeFilter = filter || 'all';
    qsa('.edesk-filter', root).forEach(function (b) {
      b.classList.toggle('edesk-filter--active', b.getAttribute('data-filter') === activeFilter);
    });
    if (activeFilter === 'unread' || activeFilter === 'meeting') {
      Object.keys(lists).forEach(function (key) {
        if (lists[key]) lists[key].hidden = false;
      });
    } else {
      showList(activeSection);
      return;
    }
    applyListFilters();
  }

  function listItemHtml(row, selected) {
    var classes = ['edesk-list__item', 'ticket--st-' + row.status_slug];
    if (row.notify) classes.push('edesk-list__item--notify');
    if (row.unseen_new) classes.push('edesk-list__item--new');
    if (row.has_reminder) classes.push('edesk-list__item--meeting');
    if (row.meeting_unread) classes.push('edesk-list__item--meeting-unread');
    if (selected) classes.push('edesk-list__item--active');
    var ackNew = row.ack_new ? ' data-ack-new="1"' : '';
    var ackEd = row.ack_editor ? ' data-ack-editor="1"' : '';
    var ackMeet = row.ack_meeting ? ' data-ack-meeting="1"' : '';
    var dot = row.notify ? '<span class="edesk-list__dot" aria-hidden="true"></span>' : '';
    var typeHtml = row.show_type && row.type_label
      ? '<span class="edesk-list__type">' + esc(row.type_label) + '</span>'
      : '';
    var displayName = row.title || row.type_label || '—';
    var newPill = row.unseen_new ? '<span class="ticket__pill ticket__pill--new">New</span>' : '';
    var waPill = row.from_whatsapp && row.section === 'pool'
      ? '<span class="edesk-list__pill edesk-list__pill--wa">WhatsApp</span>'
      : '';
    var statusBadge =
      row.section === 'mine'
        ? '<span class="task-badge task-badge--' + esc(row.status_slug) + '">' + esc(row.status_label) + '</span>'
        : '';
    var soon = row.has_reminder ? '<span class="edesk-list__pill edesk-list__pill--soon">Soon</span>' : '';
    var listAt = listAtForRow(row);
    var when = listAt
      ? '<span class="edesk-list__when" data-ts="' + esc(listAt) + '">' + esc(relativeTime(listAt)) + '</span>'
      : '';
  var msg = row.msg_count > 0 ? '<span class="edesk-list__msgs">' + row.msg_count + ' msg</span>' : '';

    return (
      '<button type="button" class="' +
      classes.join(' ') +
      '" id="ticket-' +
      esc(row.id) +
      '" data-task-id="' +
      esc(row.id) +
      '" data-section="' +
      esc(row.section) +
      '" data-updated-at="' +
      esc(row.updated_at) +
      '" data-list-at="' +
      esc(listAt) +
      '" data-notify="' +
      (row.notify ? '1' : '0') +
      '" data-meeting="' +
      (row.meeting_unread ? '1' : '0') +
      '" data-unread="' +
      (row.list_unread ? '1' : '0') +
      '"' +
      ackNew +
      ackEd +
      ackMeet +
      ' aria-current="' +
      (selected ? 'true' : 'false') +
      '">' +
      '<span class="edesk-list__row"><span class="edesk-list__id">' +
      esc(row.id) +
      '</span><span class="edesk-list__title">' +
      dot +
      typeHtml +
      '<span class="edesk-list__name">' +
      esc(displayName) +
      '</span></span></span>' +
      '<span class="edesk-list__meta">' +
      newPill +
      waPill +
      statusBadge +
      soon +
      msg +
      when +
      '<span class="edesk-list__client">' +
      esc(row.client) +
      '</span></span></button>'
    );
  }

  function renderList(section, rows, preserveSelection) {
    var listEl = lists[section];
    if (!listEl) return;
    if (!rows || rows.length === 0) {
      listEl.innerHTML =
        '<p class="edesk-list__empty">' +
        (section === 'pool' ? 'No unassigned tasks in the pool.' : 'No assigned tasks yet — claim one from the pool.') +
        '</p>';
      return;
    }
    var html = '';
    rows.forEach(function (row) {
      rowCache[row.id] = row;
      var sel = preserveSelection && normId(row.id) === normId(activeTaskId);
      html += listItemHtml(row, sel);
    });
    listEl.innerHTML = html;
    bindListClicks(listEl);
    applyListFilters();
  }

  function bindListClicks(container) {
    qsa('.edesk-list__item', container).forEach(function (item) {
      item.addEventListener('click', function () {
        selectTask(item.getAttribute('data-task-id') || '');
      });
    });
  }

  function applyListFilters() {
    var q = searchQuery.toLowerCase();
    var crossList = activeFilter === 'unread' || activeFilter === 'meeting';
    qsa('.edesk-list__item', root).forEach(function (item) {
      var list = item.closest('.edesk-list');
      var inSection = crossList ? !!list : list && !list.hidden;
      if (!inSection) return;
      var text = (item.textContent || '').toLowerCase();
      var matchSearch = q === '' || text.indexOf(q) !== -1;
      var matchFilter = true;
      if (activeFilter === 'unread') {
        matchFilter = item.getAttribute('data-unread') === '1';
      } else if (activeFilter === 'meeting') {
        matchFilter =
          item.getAttribute('data-meeting') === '1' || item.getAttribute('data-ack-meeting') === '1';
      }
      item.hidden = !(matchSearch && matchFilter);
    });
    if (crossList) {
      ['pool', 'mine'].forEach(function (section) {
        var listEl = lists[section];
        if (!listEl) return;
        var hasVisible = !!listEl.querySelector('.edesk-list__item:not([hidden])');
        var empty = listEl.querySelector('.edesk-list__empty');
        if (empty) empty.hidden = hasVisible;
      });
    }
  }

  function findListItem(taskId, preferSection) {
    var items = qsa('.edesk-list__item[data-task-id="' + CSS.escape(taskId) + '"]', root);
    if (items.length === 0) return null;
    if (items.length === 1) return items[0];
    preferSection = preferSection || activeSection || '';
    if (preferSection) {
      var preferred = items.find(function (el) {
        return el.getAttribute('data-section') === preferSection;
      });
      if (preferred) return preferred;
    }
    var mineItem = items.find(function (el) {
      return el.getAttribute('data-section') === 'mine';
    });
    return mineItem || items[items.length - 1];
  }

  function findPanels(taskId) {
    return qsa('.edesk-panel[data-task-id="' + CSS.escape(taskId) + '"]', root);
  }

  function findPanel(taskId, preferSection) {
    var panels = findPanels(taskId);
    if (panels.length === 0) return null;
    if (panels.length === 1) return panels[0];
    preferSection = preferSection || activeSection || '';
    if (preferSection) {
      var preferred = panels.find(function (p) {
        return p.getAttribute('data-section') === preferSection;
      });
      if (preferred) return preferred;
    }
    var minePanel = panels.find(function (p) {
      return p.getAttribute('data-section') === 'mine';
    });
    return minePanel || panels[panels.length - 1];
  }

  function removePanelsForTask(taskId) {
    findPanels(taskId).forEach(function (panel) {
      panel.remove();
    });
  }

  function updateUrl(taskId) {
    var url = new URL(window.location.href);
    if (taskId) {
      url.searchParams.set('ticket', taskId);
      url.hash = 'ticket-' + taskId;
    } else {
      url.searchParams.delete('ticket');
      url.hash = '';
    }
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function mountPanelHtml(taskId, html) {
    if (!panelsHost || !html) return false;
    removePanelsForTask(taskId);
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    var panel = wrap.firstElementChild;
    if (!panel) return false;
    panel.hidden = normId(taskId) !== normId(activeTaskId);
    if (!panel.hidden && emptyEl) emptyEl.hidden = true;
    panelsHost.appendChild(panel);
    bindPanelInteractions(panel);
    return true;
  }

  function finishClaimSuccess(data) {
    var taskId = normId(data.task_id);
    if (!taskId) return;
    activeTaskId = taskId;
    activeSection = 'mine';
    showList('mine');
    if (data.desk) {
      renderList('pool', data.desk.pool || [], true);
      renderList('mine', data.desk.mine || [], true);
      renderMeetingsBar(data.desk.meetings || []);
      updateTabBadges(data.desk.pool_count || (data.desk.pool || []).length, data.desk.mine_count || (data.desk.mine || []).length);
      refreshRelativeTimes();
    }
    if (typeof data.bell === 'number') setDeskBell(data.bell);
    var mounted = data.html ? mountPanelHtml(taskId, data.html) : false;
    if (!mounted) {
      fetchPanel(taskId, { show: true }).then(function () {
        selectTask(taskId, { skipFetch: true });
      });
      return;
    }
    selectTask(taskId, { skipFetch: true });
  }

  function fetchPanel(taskId, opts) {
    opts = opts || {};
    return postAjax('desk_panel', { task_id: taskId }).then(function (data) {
      if (data && data.ok && data.html) {
        mountPanelHtml(taskId, data.html);
        if (opts.show) selectTask(taskId, { skipFetch: true, noScroll: opts.noScroll });
      }
      return data;
    });
  }

  function selectTask(taskId, opts) {
    opts = opts || {};
    taskId = normId(taskId);
    if (!taskId) {
      activeTaskId = '';
      qsa('.edesk-list__item--active', root).forEach(function (el) {
        el.classList.remove('edesk-list__item--active');
        el.setAttribute('aria-current', 'false');
      });
      qsa('.edesk-panel', root).forEach(function (p) {
        p.hidden = true;
      });
      if (emptyEl) emptyEl.hidden = false;
      setMobileDetail(false);
      if (!opts.skipUrl) updateUrl('');
      return;
    }

    var item = findListItem(taskId);
    if (!item && rowCache[taskId]) {
      var cachedSection = rowCache[taskId].section;
      showList(cachedSection);
      item = findListItem(taskId);
    }
    if (!item) {
      if (!opts.skipFetch) {
        fetchPanel(taskId, { show: true, noScroll: opts.noScroll });
      }
      return;
    }

    var section = item.getAttribute('data-section') || 'mine';
    var panel = findPanel(taskId, section);
    if (section !== activeSection) showList(section);

    activeTaskId = taskId;
    qsa('.edesk-list__item--active', root).forEach(function (el) {
      el.classList.remove('edesk-list__item--active');
      el.setAttribute('aria-current', 'false');
    });
    item.classList.add('edesk-list__item--active');
    item.setAttribute('aria-current', 'true');

    if (!panel && !opts.skipFetch) {
      fetchPanel(taskId, { show: true, noScroll: opts.noScroll });
      ackOpenedItem(item);
      setMobileDetail(true);
      if (!opts.skipUrl) updateUrl(taskId);
      return;
    }

    qsa('.edesk-panel', root).forEach(function (p) {
      p.hidden = true;
    });
    if (panel) {
      panel.hidden = false;
      if (emptyEl) emptyEl.hidden = true;
    }

    ackOpenedItem(item);
    setMobileDetail(true);
    if (!opts.skipUrl) updateUrl(taskId);
    if (panelsHost && !opts.noScroll) panelsHost.scrollTop = 0;
  }

  function bindStatusForms(scope) {
    qsa('.edesk-status-form', scope || root).forEach(function (form) {
      if (form.getAttribute('data-bound') === '1') return;
      form.setAttribute('data-bound', '1');
      var select = form.querySelector('.js-task-status-select');
      var commentField = form.querySelector('.field--status-comment');
      var commentInput = commentField ? commentField.querySelector('textarea[name="status_comment"]') : null;
      var currentStatus = form.getAttribute('data-current-status') || '';
      function sync() {
        if (!select || !commentField || !commentInput) return;
        var needs = select.value !== currentStatus;
        commentField.hidden = !needs;
        commentInput.required = needs;
        if (!needs) commentInput.value = '';
      }
      if (select) {
        select.addEventListener('change', sync);
        sync();
      }
    });
  }

  function bindPanelInteractions(panel) {
    bindStatusForms(panel);
    qsa('.edesk-meeting-read', panel).forEach(function (btn) {
      if (btn.getAttribute('data-bound') === '1') return;
      btn.setAttribute('data-bound', '1');
      btn.addEventListener('click', function () {
        markMeetingRead(btn.getAttribute('data-task-id') || '');
        var alert = btn.closest('.edesk-update--alert');
        if (alert) {
          alert.classList.remove('edesk-update--meeting');
          btn.remove();
        }
      });
    });
    qsa('.edesk-pipeline__step', panel).forEach(function (step) {
      step.addEventListener('click', function () {
        var form = panel.querySelector('.edesk-status-form');
        var select = form ? form.querySelector('.js-task-status-select') : null;
        if (!select) return;
        select.value = step.getAttribute('data-status') || select.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        qsa('.edesk-pipeline__step', panel).forEach(function (s) {
          s.classList.toggle('edesk-pipeline__step--current', s === step);
        });
      });
    });
  }

  function bindAjaxForms() {
    root.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.getAttribute) return;

      if (form.classList.contains('edesk-ajax-claim')) {
        e.preventDefault();
        var claimId = (form.querySelector('[name="task_id"]') || {}).value || '';
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        postAjax('claim_task', { task_id: claimId })
          .then(function (data) {
            if (!data || !data.ok) {
              showToast((data && data.error) || 'Could not claim task.', 'err');
              return;
            }
            showToast('Task assigned to you.', 'ok');
            finishClaimSuccess(data);
          })
          .finally(function () {
            if (btn) btn.disabled = false;
          });
        return;
      }

      if (form.classList.contains('edesk-ajax-status')) {
        e.preventDefault();
        var taskId = (form.querySelector('[name="task_id"]') || {}).value || '';
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        postAjax('status_save', {
          task_id: taskId,
          status: (form.querySelector('[name="status"]') || {}).value || '',
          deliverable_output: (form.querySelector('[name="deliverable_output"]') || {}).value || '',
          status_comment: (form.querySelector('[name="status_comment"]') || {}).value || '',
        })
          .then(function (data) {
            if (!data || !data.ok) {
              showToast((data && data.error) || 'Could not save status.', 'err');
              return;
            }
            showToast('Status saved.', 'ok');
            if (data.desk) applyDeskLists(data.desk, true);
            if (typeof data.bell === 'number') setDeskBell(data.bell);
            if (data.html) mountPanelHtml(taskId, data.html);
            selectTask(taskId, { skipFetch: true, noScroll: true });
          })
          .finally(function () {
            if (btn) btn.disabled = false;
          });
        return;
      }

      if (form.classList.contains('ticket__thread-form')) {
        e.preventDefault();
        var tid = (form.querySelector('[name="task_id"]') || {}).value || '';
        var body = (form.querySelector('[name="thread_body"]') || {}).value || '';
        var tbtn = form.querySelector('button[type="submit"]');
        if (tbtn) tbtn.disabled = true;
        postAjax('thread_send', { task_id: tid, thread_body: body })
          .then(function (data) {
            if (!data || !data.ok) {
              showToast((data && data.error) || 'Could not send message.', 'err');
              return;
            }
            form.querySelector('[name="thread_body"]').value = '';
            showToast('Message sent.', 'ok');
            if (data.html) mountPanelHtml(tid, data.html);
            selectTask(tid, { skipFetch: true, noScroll: true });
            if (typeof data.bell === 'number') setDeskBell(data.bell);
          })
          .finally(function () {
            if (tbtn) tbtn.disabled = false;
          });
      }
    });
  }

  function renderMeetingsBar(meetings) {
    var bar = qs('#edesk-meetings', root);
    if (!bar) return;
    if (!meetings || meetings.length === 0) {
      bar.hidden = true;
      bar.innerHTML = '';
      return;
    }
    bar.hidden = false;
    var html = '<span class="edesk-meetings__label">Meetings</span>';
    meetings.forEach(function (m) {
      html +=
        '<span class="edesk-meetings__chip">' +
        '<button type="button" class="edesk-meetings__jump" data-task-id="' +
        esc(m.task_code) +
        '">' +
        esc(m.task_code) +
        '</button>' +
        '<span>' +
        esc(m.preview) +
        '</span>';
      if (m.meet_link) {
        html +=
          '<a class="text-link" href="' +
          esc(m.meet_link) +
          '" target="_blank" rel="noopener noreferrer">Join</a>';
      }
      html +=
        '<button type="button" class="btn btn--ghost btn--sm edesk-meeting-read" data-task-id="' +
        esc(m.task_code) +
        '">Mark read</button></span>';
    });
    bar.innerHTML = html;
  }

  function applyDeskLists(desk, preserveSelection, skipPanelRefresh) {
    if (!desk) return;
    renderList('pool', desk.pool || [], preserveSelection);
    renderList('mine', desk.mine || [], preserveSelection);
    renderMeetingsBar(desk.meetings || []);
    updateTabBadges(desk.pool_count || (desk.pool || []).length, desk.mine_count || (desk.mine || []).length);
    refreshRelativeTimes();
    if (skipPanelRefresh || !preserveSelection || !activeTaskId) return;
    var row = rowCache[activeTaskId];
    var cached = findListItem(activeTaskId);
    var prev = cached ? cached.getAttribute('data-updated-at') : '';
    if (row && row.updated_at && row.updated_at !== prev) {
      fetchPanel(activeTaskId, { noScroll: true });
    }
  }

  function handlePoll(data) {
    if (!data || !data.ok) return false;

    if (data.desk) {
      applyDeskLists(data.desk, true);
    }
    if (typeof data.bell === 'number') setDeskBell(data.bell);

    setLiveStatus('Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), false);

    if (activeTaskId) {
      var row = rowCache[activeTaskId];
      var cached = findListItem(activeTaskId);
      var prev = cached ? cached.getAttribute('data-updated-at') : '';
      if (row && row.updated_at && row.updated_at !== prev) {
        fetchPanel(activeTaskId, { noScroll: true });
      }
    }

    return true;
  }

  function initialTaskId() {
    var params = new URLSearchParams(window.location.search);
    var fromQuery = normId(params.get('ticket'));
    if (fromQuery) return fromQuery;
    var hash = window.location.hash.replace(/^#ticket-/, '');
    if (hash) return normId(hash);
    return '';
  }

  qsa('.edesk-tab', root).forEach(function (tab) {
    tab.addEventListener('click', function () {
      var section = tab.getAttribute('data-section') || 'mine';
      if (activeFilter !== 'all') {
        activeFilter = 'all';
        qsa('.edesk-filter', root).forEach(function (b) {
          b.classList.toggle('edesk-filter--active', b.getAttribute('data-filter') === 'all');
        });
      }
      showList(section);
      if (isMobile()) {
        selectTask('');
      } else {
        var first = qs('.edesk-list__item[data-section="' + section + '"]:not([hidden])', root);
        selectTask(first ? first.getAttribute('data-task-id') || '' : '');
      }
    });
  });

  qsa('.edesk-filter', root).forEach(function (btn) {
    btn.addEventListener('click', function () {
      setFilterMode(btn.getAttribute('data-filter') || 'all');
    });
  });

  var backBtn = qs('.edesk-detail__back', root);
  if (backBtn) {
    backBtn.addEventListener('click', function () {
      selectTask('');
      setMobileDetail(false);
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      searchQuery = searchInput.value;
      applyListFilters();
    });
  }

  qsa('.edesk-meetings__jump', root).forEach(function (btn) {
    btn.addEventListener('click', function () {
      selectTask(btn.getAttribute('data-task-id') || '');
    });
  });

  root.addEventListener('click', function (e) {
    var target = e.target;
    if (!target || !target.closest) return;
    var readBtn = target.closest('.edesk-meeting-read');
    if (readBtn) {
      e.preventDefault();
      var taskId = readBtn.getAttribute('data-task-id') || '';
      markMeetingRead(taskId).then(function () {
        var chip = readBtn.closest('.edesk-meetings__chip');
        if (chip) chip.remove();
        var bar = qs('#edesk-meetings', root);
        if (bar && !bar.querySelector('.edesk-meetings__chip')) {
          bar.hidden = true;
        }
      });
      return;
    }
    var jump = target.closest('.edesk-meetings__jump');
    if (jump) {
      e.preventDefault();
      selectTask(jump.getAttribute('data-task-id') || '');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
    var items = qsa('.edesk-list:not([hidden]) .edesk-list__item:not([hidden])', root);
    if (items.length === 0) return;
    var idx = items.findIndex(function (el) {
      return el.classList.contains('edesk-list__item--active');
    });
    if (e.key === '/' && searchInput) {
      e.preventDefault();
      searchInput.focus();
    } else if (e.key === 'j' || e.key === 'ArrowDown') {
      e.preventDefault();
      var next = items[Math.min(idx + 1, items.length - 1)];
      if (next) selectTask(next.getAttribute('data-task-id') || '');
    } else if (e.key === 'k' || e.key === 'ArrowUp') {
      e.preventDefault();
      var prev = items[Math.max(idx <= 0 ? 0 : idx - 1, 0)];
      if (prev) selectTask(prev.getAttribute('data-task-id') || '');
    }
  });

  document.addEventListener('click', function (e) {
    var link = e.target && e.target.closest ? e.target.closest('.desk-bell-dropdown__item') : null;
    if (!link || !link.closest('.desk-bell-wrap')) return;
    var idEl = link.querySelector('.desk-bell-dropdown__id');
    var tid = idEl ? normId(idEl.textContent) : '';
    if (tid) {
      e.preventDefault();
      selectTask(tid);
      var drop = qs('.desk-bell-dropdown');
      var btn = qs('.desk-bell-wrap .desk-bell');
      if (drop) drop.hidden = true;
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
  });

  bindListClicks(root);
  bindStatusForms(root);
  qsa('.edesk-panel', root).forEach(bindPanelInteractions);
  bindAjaxForms();

  var defaultTab = root.getAttribute('data-default-tab') || 'mine';
  showList(defaultTab);

  var initId = initialTaskId();
  if (initId) {
    selectTask(initId, { noScroll: true });
  } else if (!isMobile()) {
    var first = qs('.edesk-list__item--notify[data-section="mine"]', root)
      || qs('.edesk-list__item[data-section="' + defaultTab + '"]', root);
    if (first) selectTask(first.getAttribute('data-task-id') || '', { noScroll: true });
  }

  refreshRelativeTimes();
  setInterval(refreshRelativeTimes, 45000);
  setLiveStatus('Connected', false);

  var startBell = parseInt(root.getAttribute('data-bell') || '0', 10);
  var lastBell = parseInt(sessionStorage.getItem(BellKey) || '-1', 10);
  if (lastBell >= 0 && startBell > lastBell) playNotifyPing();
  sessionStorage.setItem(BellKey, String(startBell));

  window.AkhEditorDesk = {
    selectTask: selectTask,
    setDeskBell: setDeskBell,
    handlePoll: handlePoll,
    showToast: showToast,
    setLiveSyncing: function (on) {
      setLiveStatus(on ? 'Syncing…' : 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), on);
    },
  };
})();
