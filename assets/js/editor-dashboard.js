/**
 * Editor dashboard: tabs, master/detail selection, acks, status form.
 */
(function () {
  'use strict';

  var root = document.getElementById('editor-desk');
  if (!root) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var BellKey = 'akh_editor_bell_last';

  function qs(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function qsa(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  }

  function normId(id) {
    return String(id || '').trim();
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
    if (typeof n === 'number' && n > 0) {
      b.classList.add('desk-bell--wiggle', 'desk-bell--pop');
    } else {
      b.classList.remove('desk-bell--wiggle', 'desk-bell--pop');
    }
  }

  function postAck(kind, taskId) {
    var fd = new URLSearchParams();
    fd.set('ajax_action', 'view_ack');
    fd.set('ack_kind', kind);
    fd.set('task_id', taskId);
    fd.set('csrf_token', csrf);
    return fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    }).then(function (r) { return r.json(); });
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
      postAck('new', tid).then(function (j) {
        if (j && j.ok && typeof j.bell === 'number') setDeskBell(j.bell);
      }).catch(function () {});
    }
    if (btn.getAttribute('data-ack-editor') === '1') {
      btn.removeAttribute('data-ack-editor');
      btn.classList.remove('edesk-list__item--notify');
      var dot = btn.querySelector('.edesk-list__dot');
      if (dot) dot.remove();
      postAck('editor_task', tid).then(function (j) {
        if (j && j.ok && typeof j.bell === 'number') setDeskBell(j.bell);
      }).catch(function () {});
    }
  }

  var tabs = qsa('.edesk-tab', root);
  var lists = {
    pool: qs('#edesk-list-pool', root),
    mine: qs('#edesk-list-mine', root),
  };
  var panels = qsa('.edesk-panel', root);
  var emptyEl = qs('#edesk-empty', root);
  var detailScroll = qs('.edesk-detail__scroll', root);
  var searchInput = qs('#edesk-search', root);
  var activeSection = 'mine';
  var activeTaskId = '';

  function isMobile() {
    return window.matchMedia('(max-width: 820px)').matches;
  }

  function setMobileDetail(on) {
    document.body.classList.toggle('edesk--mobile-detail', on && isMobile());
  }

  function showList(section) {
    activeSection = section;
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-section') === section;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    Object.keys(lists).forEach(function (key) {
      if (!lists[key]) return;
      lists[key].hidden = key !== section;
    });
    var hint = qs('#edesk-sidebar-hint', root);
    if (hint) {
      hint.textContent =
        section === 'pool'
          ? 'Open a row once to clear it from your new-task bell count.'
          : 'Select a task — updates and messages appear on the right.';
    }
  }

  function findListItem(taskId) {
    return qs('.edesk-list__item[data-task-id="' + CSS.escape(taskId) + '"]', root);
  }

  function findPanel(taskId) {
    return qs('.edesk-panel[data-task-id="' + CSS.escape(taskId) + '"]', root);
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

  function selectTask(taskId, opts) {
    opts = opts || {};
    taskId = normId(taskId);
    if (!taskId) {
      activeTaskId = '';
      qsa('.edesk-list__item--active', root).forEach(function (el) {
        el.classList.remove('edesk-list__item--active');
        el.setAttribute('aria-current', 'false');
      });
      panels.forEach(function (p) { p.hidden = true; });
      if (emptyEl) emptyEl.hidden = false;
      setMobileDetail(false);
      if (!opts.skipUrl) updateUrl('');
      return;
    }

    var item = findListItem(taskId);
    var panel = findPanel(taskId);
    if (!item || !panel) {
      return;
    }

    var section = item.getAttribute('data-section') || 'mine';
    if (section !== activeSection) {
      showList(section);
    }

    activeTaskId = taskId;
    qsa('.edesk-list__item--active', root).forEach(function (el) {
      el.classList.remove('edesk-list__item--active');
      el.setAttribute('aria-current', 'false');
    });
    item.classList.add('edesk-list__item--active');
    item.setAttribute('aria-current', 'true');

    panels.forEach(function (p) { p.hidden = true; });
    panel.hidden = false;
    if (emptyEl) emptyEl.hidden = true;

    ackOpenedItem(item);
    setMobileDetail(true);
    if (!opts.skipUrl) updateUrl(taskId);

    if (detailScroll && !opts.noScroll) {
      detailScroll.scrollTop = 0;
    }
  }

  function initialTaskId() {
    var params = new URLSearchParams(window.location.search);
    var fromQuery = normId(params.get('ticket'));
    if (fromQuery && (findListItem(fromQuery) || findPanel(fromQuery))) {
      return fromQuery;
    }
    var hash = window.location.hash.replace(/^#ticket-/, '');
    if (hash && (findListItem(hash) || findPanel(hash))) {
      return normId(hash);
    }
    var prefer = qs('.edesk-list__item--notify[data-section="mine"]', root)
      || qs('.edesk-list__item--new[data-section="pool"]', root)
      || qs('.edesk-list__item[data-section="mine"]', root)
      || qs('.edesk-list__item[data-section="pool"]', root);
    return prefer ? normId(prefer.getAttribute('data-task-id')) : '';
  }

  function filterLists(query) {
    query = String(query || '').trim().toLowerCase();
    qsa('.edesk-list__item', root).forEach(function (item) {
      if (query === '') {
        item.hidden = false;
        return;
      }
      var text = (item.textContent || '').toLowerCase();
      item.hidden = text.indexOf(query) === -1;
    });
  }

  function bindStatusForms() {
    qsa('.edesk-status-form', root).forEach(function (form) {
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

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var section = tab.getAttribute('data-section') || 'mine';
      showList(section);
      if (isMobile()) {
        selectTask('', { skipUrl: true });
        setMobileDetail(false);
      } else {
        var first = qs('.edesk-list__item[data-section="' + section + '"]:not([hidden])', root);
        if (first) {
          selectTask(first.getAttribute('data-task-id') || '');
        } else {
          selectTask('');
        }
      }
    });
  });

  qsa('.edesk-list__item', root).forEach(function (item) {
    item.addEventListener('click', function () {
      selectTask(item.getAttribute('data-task-id') || '');
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
      filterLists(searchInput.value);
    });
  }

  qsa('.edesk-meetings__jump', root).forEach(function (btn) {
    btn.addEventListener('click', function () {
      selectTask(btn.getAttribute('data-task-id') || '');
    });
  });

  var defaultTab = root.getAttribute('data-default-tab') || 'mine';
  showList(defaultTab);
  bindStatusForms();
  selectTask(initialTaskId(), { noScroll: true });

  var startBell = parseInt(root.getAttribute('data-bell') || '0', 10);
  var lastBell = parseInt(sessionStorage.getItem(BellKey) || '-1', 10);
  if (lastBell >= 0 && startBell > lastBell) {
    playNotifyPing();
  }
  sessionStorage.setItem(BellKey, String(startBell));

  window.AkhEditorDesk = {
    selectTask: selectTask,
    setDeskBell: setDeskBell,
  };
})();
