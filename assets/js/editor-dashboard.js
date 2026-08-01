/**
 * Editor desk — live sync (ClickUp / ServiceNow style), AJAX actions, filters.
 */
(function () {
  'use strict';

  var root = document.getElementById('editor-desk');
  if (!root) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var siteTimezone = root.getAttribute('data-timezone') || 'Asia/Kolkata';
  var BellKey = 'akh_editor_bell_last';
  var rowCache = {};
  var lastDeskBell = parseInt(root.getAttribute('data-bell') || '0', 10);
  var lastNotifySig =
    typeof window._akhPortalPush !== 'undefined' && window._akhPortalPush.notify_sig
      ? String(window._akhPortalPush.notify_sig)
      : '';
  var deskAlertReady = false;
  var seenNoticeKeys = {};
  var lastPoolCount =
    typeof window._akhPortalPush !== 'undefined' && typeof window._akhPortalPush.pool === 'number'
      ? window._akhPortalPush.pool
      : 0;
  var lastThreadSigByTask = {};
  var threadPollInterval = null;
  var deskPollInterval = null;
  var liveClockInterval = null;
  var lastSyncAt = Date.now();
  var THREAD_POLL_MS = 1200;
  var DESK_POLL_MS = 2500;
  var activeTaskId = '';
  var activeSection = 'mine';
  var activeStatusFilter = 'all';
  var searchQuery = '';

  var POOL_STATUS_OPTS = [
    { value: 'all', label: 'All pool tasks' },
    { value: 'new', label: 'New' },
    { value: 'whatsapp', label: 'WhatsApp' },
  ];

  var MINE_STATUS_OPTS = [
    { value: 'all', label: 'All statuses' },
    { value: 'assigned', label: 'Assigned' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'review', label: 'Internal review' },
    { value: 'preview_sent', label: 'Preview sent' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'reverted', label: 'Returned for revision' },
    { value: 'closed', label: 'Closed' },
  ];

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
    playLoudAlert(1);
  }

  function playLoudAlert(times) {
    if (window.DeskAlert && typeof window.DeskAlert.play === 'function') {
      window.DeskAlert.play(times);
      return;
    }
    if (window.DeskAlert && typeof window.DeskAlert.unlock === 'function') {
      window.DeskAlert.unlock();
    }
  }

  function showDeskAlert(opts) {
    opts = opts || {};
    var host = qs('#edesk-toasts', root);
    if (!host) return;
    var taskId = normId(opts.taskId || '');
    var title = String(opts.title || opts.taskId || 'Update').trim();
    var body = String(opts.body || 'New activity on your board.').trim();
    var label = String(opts.label || 'Notification').trim();
    var el = document.createElement('button');
    el.type = 'button';
    el.className = 'edesk-chat-alert';
    el.innerHTML =
      '<span class="edesk-chat-alert__icon" aria-hidden="true">' +
      esc(opts.icon || '💬') +
      '</span>' +
      '<span class="edesk-chat-alert__content">' +
      '<span class="edesk-chat-alert__label">' +
      esc(label) +
      '</span>' +
      '<strong class="edesk-chat-alert__title">' +
      esc(title) +
      '</strong>' +
      '<span class="edesk-chat-alert__body">' +
      esc(body) +
      '</span>' +
      '</span>' +
      '<span class="edesk-chat-alert__close" aria-hidden="true">×</span>';
    el.addEventListener('click', function () {
      if (taskId) selectTask(taskId);
      el.classList.remove('edesk-chat-alert--in');
      setTimeout(function () {
        el.remove();
      }, 220);
    });
    host.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add('edesk-chat-alert--in');
    });
    setTimeout(function () {
      if (!el.parentNode) return;
      el.classList.remove('edesk-chat-alert--in');
      setTimeout(function () {
        el.remove();
      }, 280);
    }, 12000);
  }

  function noticePopupTitle(notice, fallback) {
    return String(
      (notice && (notice.customer_name || notice.client)) ||
        (notice && notice.title) ||
        fallback ||
        'Update'
    ).trim();
  }

  function notifyActivity(opts) {
    opts = opts || {};
    var taskId = normId(opts.taskId || '');
    var title = String(opts.title || opts.taskId || 'Update').trim();
    var body = String(opts.body || 'New activity on your board.').trim();
    var label = String(opts.label || 'Notification').trim();
    var tag = String(opts.tag || 'akh-editor-' + (taskId || label));
    var onClick = function () {
      if (taskId) selectTask(taskId);
    };

    if (window.DeskAlert && typeof window.DeskAlert.notify === 'function') {
      var ticketUrl = taskId
        ? window.location.pathname +
          (window.location.pathname.indexOf('?') === -1 ? '?' : '&') +
          'ticket=' +
          encodeURIComponent(taskId) +
          '#ticket-' +
          taskId
        : window.location.href;
      window.DeskAlert.notify({
        host: qs('#edesk-toasts', root),
        taskId: taskId,
        title: title,
        label: label,
        body: body,
        icon: opts.icon || '🔔',
        beep: typeof opts.beep === 'number' ? opts.beep : 1,
        tag: tag,
        url: ticketUrl,
        onClick: onClick,
      });
      return;
    }

    playLoudAlert(typeof opts.beep === 'number' ? opts.beep : 2);
    showDeskAlert(opts);
    if (document.hidden && window.AkhPortalPush && typeof window.AkhPortalPush.tryOsNotify === 'function') {
      window.AkhPortalPush.tryOsNotify(title, body, taskId, tag);
    }
  }

  function noticeFingerprint(n) {
    if (!n) return '';
    return [
      n.task_id || n.anchor_id || '',
      n.label || '',
      n.detail || n.preview || '',
      n.kind || '',
      n.event_id || n.max_id || n.created_at || '',
    ].join('|');
  }

  function seedNoticeBaseline(notices) {
    (notices || []).forEach(function (n) {
      var fp = noticeFingerprint(n);
      if (fp) seenNoticeKeys[fp] = true;
    });
    deskAlertReady = true;
  }

  function alertFreshNotices(notices) {
    if (!deskAlertReady) return;
    (notices || []).forEach(function (n) {
      var fp = noticeFingerprint(n);
      if (!fp || seenNoticeKeys[fp]) return;
      seenNoticeKeys[fp] = true;
      var taskId = normId(n.task_id || n.anchor_id || '');
      notifyActivity({
        taskId: taskId,
        title: noticePopupTitle(n, taskId || 'Editor desk'),
        label: n.label || 'Update',
        body: n.detail || n.preview || n.label || 'New activity on your task board.',
        icon: String(n.label || '').toLowerCase().indexOf('message') !== -1 ? '💬' : '🔔',
        beep: 1,
        tag: 'akh-editor-notice-' + fp,
      });
    });
  }

  function noticeFromPoll(data) {
    if (!data || !Array.isArray(data.notices) || data.notices.length === 0) return null;
    return data.notices[0];
  }

  function maybeAlertFromPoll(data, meta) {
    if (!data || !data.ok) return;
    meta = meta || {};

    alertFreshNotices(data.notices || []);

    var poolCount = typeof data.pool === 'number' ? data.pool : lastPoolCount;
    if (typeof poolCount === 'number') {
      lastPoolCount = poolCount;
    }

    var notifySig = typeof data.notify_sig === 'string' ? data.notify_sig : '';
    if (notifySig !== '') lastNotifySig = notifySig;
  }

  function scanDeskMessageAlerts(desk, meta) {
    meta = meta || {};
    if (!desk || !deskAlertReady) return;
    var rows = (desk.mine || []).concat(desk.pool || []);
    rows.forEach(function (row) {
      var id = normId(row.id);
      if (!id) return;
      var prev = rowCache[id];
      var unread = typeof row.unread_msg_count === 'number' ? row.unread_msg_count : 0;
      var prevUnread = prev && typeof prev.unread_msg_count === 'number' ? prev.unread_msg_count : 0;
      if (unread > prevUnread && unread > 0) {
        notifyActivity({
          taskId: id,
          title: row.client || row.customer_name || row.title || id,
          label: 'New message',
          body: unread === 1 ? '1 unread WhatsApp message' : unread + ' unread WhatsApp messages',
          icon: '💬',
          beep: 1,
          tag: 'akh-editor-msg-' + id + '-' + unread,
        });
      }
    });
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

  function setLiveStatus(text, syncing, state) {
    var live = qs('#edesk-live', root);
    var timeEl = qs('#edesk-live-time', root);
    if (timeEl) timeEl.textContent = text;
    if (live) {
      live.classList.toggle('edesk-live--sync', !!syncing);
      if (state === 'new') {
        live.classList.add('edesk-live--new');
        setTimeout(function () {
          live.classList.remove('edesk-live--new');
        }, 2200);
      }
    }
  }

  function markSyncSuccess(flashNew) {
    lastSyncAt = Date.now();
    updateLiveClock();
    if (flashNew) {
      setLiveStatus('New message', false, 'new');
      setTimeout(updateLiveClock, 2200);
    }
  }

  function updateLiveClock() {
    var sec = Math.max(0, Math.floor((Date.now() - lastSyncAt) / 1000));
    var label = sec < 2 ? 'just now' : sec + 's ago';
    setLiveStatus('Synced ' + label, false);
  }

  function startLiveClock() {
    if (liveClockInterval) return;
    updateLiveClock();
    liveClockInterval = setInterval(updateLiveClock, 1000);
  }

  function stopLiveClock() {
    if (!liveClockInterval) return;
    clearInterval(liveClockInterval);
    liveClockInterval = null;
  }

  function formatSiteDateTime(iso) {
    if (!iso) return '';
    var t = parseTs(iso);
    if (isNaN(t)) return String(iso);
    try {
      return new Intl.DateTimeFormat('en-IN', {
        timeZone: siteTimezone,
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
      }).format(new Date(t));
    } catch (e) {
      return String(iso);
    }
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

  function unreadMsgCount(row) {
    if (!row) return 0;
    if (typeof row.unread_msg_count === 'number') return row.unread_msg_count;
    return 0;
  }

  function clearMsgBadge(btn) {
    if (!btn) return;
    btn.setAttribute('data-msg-count', '0');
    var msgs = btn.querySelector('.edesk-list__msgs');
    if (msgs) msgs.remove();
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
      clearMsgBadge(btn);
      if (rowCache[tid]) rowCache[tid].unread_msg_count = 0;
    } else if (btn.getAttribute('data-ack-meeting') === '1') {
      clearMeetingBadge(btn);
      postAck('meeting', tid);
    }
    var unreadMsgs = parseInt(btn.getAttribute('data-msg-count') || '0', 10);
    if (unreadMsgs > 0) {
      clearMsgBadge(btn);
      if (rowCache[tid]) rowCache[tid].unread_msg_count = 0;
      if (btn.getAttribute('data-ack-editor') !== '1') {
        postAck('message', tid);
      }
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
  var statusFilter = qs('#edesk-status-filter', root);

  function normalizeSearch(s) {
    return String(s || '').toLowerCase().trim();
  }

  function syncStatusFilterOptions(section) {
    if (!statusFilter) return;
    var opts = section === 'pool' ? POOL_STATUS_OPTS : MINE_STATUS_OPTS;
    var current = activeStatusFilter;
    var valid = opts.some(function (o) {
      return o.value === current;
    });
    statusFilter.innerHTML = opts
      .map(function (o) {
        return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>';
      })
      .join('');
    if (!valid) {
      current = 'all';
      activeStatusFilter = 'all';
    }
    statusFilter.value = current;
  }

  function isMobile() {
    return window.matchMedia('(max-width: 820px)').matches;
  }

  function setMobileDetail(on) {
    document.body.classList.toggle('edesk--mobile-detail', on && isMobile());
  }

  function showList(section) {
    activeSection = section;
    qsa('.edesk-tab', root).forEach(function (tab) {
      var on = tab.getAttribute('data-section') === section;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    Object.keys(lists).forEach(function (key) {
      if (lists[key]) lists[key].hidden = key !== section;
    });
    syncStatusFilterOptions(section);
    var hint = qs('#edesk-sidebar-hint', root);
    if (hint) {
      hint.textContent =
        section === 'pool'
          ? 'New jobs appear here in real time — claim to move to My tasks.'
          : 'Live updates for messages, feedback, and status changes.';
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
    var customerTone = row.customer_tone || (row.from_whatsapp ? 'wa' : 'portal');
    var customerHtml =
      row.client && row.client !== '—'
        ? '<span class="edesk-list__customer edesk-list__customer--' +
          esc(customerTone) +
          '"><span class="edesk-list__customer-kicker">Client</span><span class="edesk-list__customer-name">' +
          esc(row.client) +
          '</span></span>'
        : '';
    var searchBlob =
      row.search ||
      normalizeSearch(
        [row.id, row.title, row.type_label, row.client, row.status_label].join(' ')
      );
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
    var unreadMsgs = unreadMsgCount(row);
    var msg = unreadMsgs > 0 ? '<span class="edesk-list__msgs">' + unreadMsgs + ' msg</span>' : '';

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
      '" data-msg-count="' +
      esc(String(unreadMsgs)) +
      '" data-list-at="' +
      esc(listAt) +
      '" data-status="' +
      esc(row.status_slug) +
      '" data-whatsapp="' +
      (row.from_whatsapp ? '1' : '0') +
      '" data-search="' +
      esc(searchBlob) +
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
      '</span><span class="edesk-list__body"><span class="edesk-list__title">' +
      dot +
      typeHtml +
      '<span class="edesk-list__name">' +
      esc(displayName) +
      '</span></span>' +
      customerHtml +
      '</span></span>' +
      '<span class="edesk-list__meta">' +
      newPill +
      waPill +
      statusBadge +
      soon +
      msg +
      when +
      '</span></button>'
    );
  }

  function sortDeskRows(rows) {
    return (rows || []).slice().sort(function (a, b) {
      var pa = typeof a.priority === 'number' ? a.priority : 0;
      var pb = typeof b.priority === 'number' ? b.priority : 0;
      if (pa !== pb) return pb - pa;
      var aa = pa > 0 ? 1 : 0;
      var ab = pb > 0 ? 1 : 0;
      if (aa !== ab) return ab - aa;
      var ta = listAtForRow(a);
      var tb = listAtForRow(b);
      return String(tb).localeCompare(String(ta));
    });
  }

  function renderList(section, rows, preserveSelection) {
    var listEl = lists[section];
    if (!listEl) return;
    rows = sortDeskRows(rows);
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
    var q = normalizeSearch(searchQuery);
    ['pool', 'mine'].forEach(function (section) {
      var listEl = lists[section];
      if (!listEl) return;
      var isActiveList = section === activeSection;
      var items = qsa('.edesk-list__item', listEl);
      var visibleCount = 0;
      items.forEach(function (item) {
        if (!isActiveList) {
          item.hidden = true;
          return;
        }
        var haystack = normalizeSearch(item.getAttribute('data-search') || item.textContent || '');
        var matchSearch = q === '' || haystack.indexOf(q) !== -1;
        var matchStatus = true;
        if (activeStatusFilter !== 'all') {
          if (activeStatusFilter === 'whatsapp') {
            matchStatus = item.getAttribute('data-whatsapp') === '1';
          } else {
            matchStatus = item.getAttribute('data-status') === activeStatusFilter;
          }
        }
        var show = matchSearch && matchStatus;
        item.hidden = !show;
        item.setAttribute('aria-hidden', show ? 'false' : 'true');
        if (show) visibleCount += 1;
      });

      var staticEmpty = listEl.querySelector('.edesk-list__empty:not(.edesk-list__empty--filter)');
      var filterEmpty = listEl.querySelector('.edesk-list__empty--filter');
      if (items.length === 0) {
        if (staticEmpty) staticEmpty.hidden = false;
        if (filterEmpty) filterEmpty.hidden = true;
        return;
      }
      if (staticEmpty) staticEmpty.hidden = true;
      if (visibleCount === 0) {
        if (!filterEmpty) {
          filterEmpty = document.createElement('p');
          filterEmpty.className = 'edesk-list__empty edesk-list__empty--filter';
          listEl.appendChild(filterEmpty);
        }
        filterEmpty.textContent =
          q !== '' && activeStatusFilter !== 'all'
            ? 'No tasks match your search and status filter.'
            : q !== ''
              ? 'No tasks match your search.'
              : 'No tasks match this status filter.';
        filterEmpty.hidden = false;
      } else if (filterEmpty) {
        filterEmpty.hidden = true;
      }
    });
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

  function snapshotTaskRow(taskId) {
    var row = rowCache[taskId];
    if (!row) return null;
    return {
      updated_at: row.updated_at || '',
      msg_count: row.msg_count || 0,
      msg_sig: row.msg_sig || '',
    };
  }

  function rowNeedsPanelRefresh(row, prev) {
    if (!row || !prev) return false;
    if (row.updated_at && row.updated_at !== prev.updated_at) return true;
    return (row.msg_sig || '') !== (prev.msg_sig || '');
  }

  function rowNeedsThreadOnlyRefresh(row, prev) {
    if (!row || !prev) return false;
    if (row.updated_at && row.updated_at !== prev.updated_at) return false;
    return (row.msg_sig || '') !== (prev.msg_sig || '');
  }

  function rememberThreadSig(taskId, sig) {
    if (!taskId || !sig) return;
    lastThreadSigByTask[taskId] = sig;
  }

  function updateThreadScroll(panel, html, opts) {
    opts = opts || {};
    var scroll = panel.querySelector('.ticket__thread-scroll');
    if (!scroll || typeof html !== 'string') return false;
    var nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 96;
    scroll.innerHTML = html;
    if (opts.forceBottom || nearBottom) {
      requestAnimationFrame(function () {
        scroll.scrollTop = scroll.scrollHeight;
      });
    }
    return true;
  }

  function pollThread(taskId, force) {
    taskId = normId(taskId);
    if (!taskId) return Promise.resolve();
    var panel = findPanel(taskId);
    if (!panel || !panel.querySelector('.ticket__thread')) return Promise.resolve();
    return postAjax('thread_poll', { task_id: taskId }).then(function (data) {
      if (!data || !data.ok || typeof data.html !== 'string') return data;
      var sig = data.msg_sig || '';
      var prevSig = lastThreadSigByTask[taskId] || '';
      if (!force && sig !== '' && sig === prevSig) return data;
      var isNew = sig !== '' && sig !== prevSig && prevSig !== '';
      if (sig !== '') rememberThreadSig(taskId, sig);
      updateThreadScroll(panel, data.html, { forceBottom: force || prevSig === '' || isNew });
      markSyncSuccess(isNew);
      return data;
    });
  }

  function stopThreadPoll() {
    if (threadPollInterval) {
      clearInterval(threadPollInterval);
      threadPollInterval = null;
    }
    var live = qs('#edesk-live', root);
    if (live) live.classList.remove('edesk-live--active');
  }

  function startThreadPoll() {
    stopThreadPoll();
    var live = qs('#edesk-live', root);
    if (live) live.classList.add('edesk-live--active');
    threadPollInterval = setInterval(function () {
      if (!activeTaskId) return;
      pollThread(activeTaskId, false);
    }, THREAD_POLL_MS);
  }

  function startDeskPoll() {
    if (window._akhPortalPush && window._akhPortalPush.mode) {
      return;
    }
    if (deskPollInterval) return;
    deskPollInterval = setInterval(function () {
      setLiveStatus('Syncing…', true);
      postAjax('poll', {})
        .then(function (data) {
          handlePoll(data);
          markSyncSuccess(false);
        })
        .catch(function () {
          setLiveStatus('Reconnecting…', false);
        });
    }, DESK_POLL_MS);
  }

  function syncActiveThreadPoll(taskId) {
    taskId = normId(taskId);
    if (!taskId) {
      stopThreadPoll();
      return;
    }
    var panel = findPanel(taskId);
    if (!panel || !panel.querySelector('.ticket__thread')) {
      stopThreadPoll();
      return;
    }
    var row = rowCache[taskId];
    if (row && row.msg_sig) rememberThreadSig(taskId, row.msg_sig);
    startThreadPoll();
    pollThread(taskId, true);
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
    if (normId(taskId) === normId(activeTaskId)) {
      syncActiveThreadPoll(taskId);
    }
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

  function itemNeedsNotificationPanel(item) {
    if (!item) return false;
    return (
      item.getAttribute('data-ack-editor') === '1' ||
      item.getAttribute('data-ack-meeting') === '1' ||
      item.getAttribute('data-notify') === '1'
    );
  }

  function revealSelectedPanel(taskId, section, panel) {
    qsa('.edesk-panel', root).forEach(function (p) {
      p.hidden = true;
    });
    panel = panel || findPanel(taskId, section);
    if (panel) {
      panel.hidden = false;
      if (emptyEl) emptyEl.hidden = true;
    }
    return panel;
  }

  function finalizeTaskSelection(item, taskId, section, panel, opts) {
    revealSelectedPanel(taskId, section, panel);
    ackOpenedItem(item);
    setMobileDetail(true);
    if (!opts.skipUrl) updateUrl(taskId);
    if (panelsHost && !opts.noScroll) panelsHost.scrollTop = 0;
    syncActiveThreadPoll(taskId);
  }

  function selectTask(taskId, opts) {
    opts = opts || {};
    taskId = normId(taskId);
    if (!taskId) {
      activeTaskId = '';
      stopThreadPoll();
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

    var mustRefreshForNotify = itemNeedsNotificationPanel(item);
    if ((mustRefreshForNotify || !panel) && !opts.skipFetch) {
      fetchPanel(taskId, { noScroll: opts.noScroll }).then(function () {
        finalizeTaskSelection(item, taskId, section, findPanel(taskId, section), opts);
      });
      return;
    }

    finalizeTaskSelection(item, taskId, section, panel, opts);
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
    qsa('.edesk-end-chat', panel).forEach(function (btn) {
      if (btn.getAttribute('data-bound') === '1') return;
      btn.setAttribute('data-bound', '1');
      btn.addEventListener('click', function () {
        var taskId = btn.getAttribute('data-task-id') || '';
        if (!taskId) return;
        if (
          !window.confirm(
            'Close this WhatsApp chat and return the client to the bot menu? A goodbye message will be sent.'
          )
        ) {
          return;
        }
        btn.disabled = true;
        postAjax('end_chat', { task_id: taskId })
          .then(function (data) {
            if (!data || !data.ok) {
              showToast((data && data.error) || 'Could not end chat.', 'err');
              return;
            }
            showToast('Chat closed. Goodbye message sent.', 'ok');
            pollThread(taskId, true);
          })
          .finally(function () {
            btn.disabled = false;
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
            if (data.msg_sig) rememberThreadSig(tid, data.msg_sig);
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
      var who = [m.customer_name, m.project_name].filter(Boolean).join(' — ');
      var when = m.when_label || m.start_time || '';
      var requested =
        m.requested_time_text && m.requested_time_text !== when
          ? '<p class="edesk-meetings__requested"><strong>Requested:</strong> ' + esc(m.requested_time_text) + '</p>'
          : '';
      var endTime = m.end_time ? '<p class="edesk-meetings__end"><strong>Ends:</strong> ' + esc(m.end_time) + '</p>' : '';
      html +=
        '<article class="edesk-meetings__card">' +
        '<button type="button" class="edesk-meetings__jump" data-task-id="' +
        esc(m.task_code) +
        '">' +
        esc(m.task_code) +
        '</button>' +
        '<div class="edesk-meetings__meta">' +
        (when ? '<p class="edesk-meetings__when"><strong>When:</strong> ' + esc(when) + '</p>' : '') +
        (who ? '<p class="edesk-meetings__who">' + esc(who) + '</p>' : '') +
        requested +
        endTime +
        '</div>' +
        '<div class="edesk-meetings__actions">';
      if (m.meet_link) {
        html +=
          '<a class="text-link" href="' +
          esc(m.meet_link) +
          '" target="_blank" rel="noopener noreferrer">Join</a>';
      }
      html +=
        '<button type="button" class="btn btn--ghost btn--sm edesk-meeting-read" data-task-id="' +
        esc(m.task_code) +
        '">Mark read</button></div></article>';
    });
    bar.innerHTML = html;
  }

  function applyDeskLists(desk, preserveSelection, skipPanelRefresh) {
    if (!desk) return;
    var prevActive = preserveSelection && activeTaskId ? snapshotTaskRow(activeTaskId) : null;
    renderList('pool', desk.pool || [], preserveSelection);
    renderList('mine', desk.mine || [], preserveSelection);
    renderMeetingsBar(desk.meetings || []);
    updateTabBadges(desk.pool_count || (desk.pool || []).length, desk.mine_count || (desk.mine || []).length);
    refreshRelativeTimes();
    if (skipPanelRefresh || !preserveSelection || !activeTaskId) return;
    var row = rowCache[activeTaskId];
    if (!rowNeedsPanelRefresh(row, prevActive)) return;
    if (rowNeedsThreadOnlyRefresh(row, prevActive)) {
      pollThread(activeTaskId, true);
      return;
    }
    fetchPanel(activeTaskId, { noScroll: true });
  }

  function handlePoll(data, meta) {
    if (!data || !data.ok) return false;

    if (data.desk) {
      scanDeskMessageAlerts(data.desk, meta);
      applyDeskLists(data.desk, true);
    }
    maybeAlertFromPoll(data, meta);
    if (typeof data.bell === 'number') {
      lastDeskBell = data.bell;
      setDeskBell(data.bell);
    }
    if (typeof data.pool === 'number' && window._akhPortalPush) {
      window._akhPortalPush.pool = data.pool;
    }

    markSyncSuccess(false);

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
      activeStatusFilter = 'all';
      if (statusFilter) statusFilter.value = 'all';
      showList(section);
      if (isMobile()) {
        selectTask('');
      } else {
        var first = qs('.edesk-list__item[data-section="' + section + '"]:not([hidden])', root);
        selectTask(first ? first.getAttribute('data-task-id') || '' : '');
      }
    });
  });

  if (statusFilter) {
    statusFilter.addEventListener('change', function () {
      activeStatusFilter = statusFilter.value || 'all';
      applyListFilters();
    });
  }

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
    searchInput.addEventListener('search', function () {
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
        var chip = readBtn.closest('.edesk-meetings__card');
        if (chip) chip.remove();
        var bar = qs('#edesk-meetings', root);
        if (bar && !bar.querySelector('.edesk-meetings__card')) {
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
  } else {
    stopThreadPoll();
  }

  refreshRelativeTimes();
  startLiveClock();
  startDeskPoll();
  markSyncSuccess(false);

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (window.AkhPortalPush && typeof window.AkhPortalPush.startKeepalive === 'function') {
        window.AkhPortalPush.startKeepalive();
      }
      return;
    }
    if (activeTaskId) pollThread(activeTaskId, false);
    if (window.AkhPortalPush && typeof window.AkhPortalPush.forcePoll === 'function') {
      window.AkhPortalPush.forcePoll();
      return;
    }
    if (!window._akhPortalPush || !window._akhPortalPush.mode) {
      postAjax('poll', {}).then(handlePoll).catch(function () {});
    }
  });

  setInterval(refreshRelativeTimes, 45000);

  seedNoticeBaseline((window._akhPortalPush && window._akhPortalPush.notices) || []);
  root.addEventListener('pointerdown', function () {
    if (window.DeskAlert) window.DeskAlert.unlock();
  }, { once: true, capture: true });

  var startBell = parseInt(root.getAttribute('data-bell') || '0', 10);
  var lastBell = parseInt(sessionStorage.getItem(BellKey) || '-1', 10);
  if (lastBell >= 0 && startBell > lastBell) playNotifyPing();
  sessionStorage.setItem(BellKey, String(startBell));

  window.AkhEditorDesk = {
    selectTask: selectTask,
    setDeskBell: setDeskBell,
    handlePoll: handlePoll,
    showToast: showToast,
    showDeskAlert: showDeskAlert,
    playLoudAlert: playLoudAlert,
    setLiveSyncing: function (on) {
      if (on) {
        setLiveStatus('Syncing…', true);
      } else {
        markSyncSuccess(false);
      }
    },
  };
})();
