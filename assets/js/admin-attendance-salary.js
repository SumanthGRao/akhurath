(function () {
  'use strict';

  var root = document.getElementById('admin-salary-calc');
  if (!root) {
    return;
  }

  var monthlyInput = document.getElementById('admin-salary-monthly');
  var allowedInput = document.getElementById('admin-salary-allowed-leaves');
  var resultsEl = document.getElementById('admin-salary-results');
  var emptyEl = document.getElementById('admin-salary-empty');
  if (!monthlyInput || !allowedInput || !resultsEl || !emptyEl) {
    return;
  }

  var workingDays = Math.max(1, parseInt(root.getAttribute('data-working-days') || '1', 10));
  var excusedLeave = parseFloat(root.getAttribute('data-excused-leave') || '0') || 0;
  var unapprovedLeave = Math.max(0, parseInt(root.getAttribute('data-unapproved-leave') || '0', 10));
  var storageKey = root.getAttribute('data-storage-key') || '';

  function formatInr(amount) {
    var n = Number(amount);
    if (!isFinite(n)) {
      return '—';
    }
    var rounded = Math.round(n * 100) / 100;
    var opts = Math.abs(rounded - Math.round(rounded)) < 0.001
      ? { maximumFractionDigits: 0 }
      : { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    return '₹' + rounded.toLocaleString('en-IN', opts);
  }

  function formatDays(days) {
    var n = Math.round(days * 10) / 10;
    if (Math.abs(n - Math.round(n)) < 0.001) {
      return String(Math.round(n));
    }
    return n.toFixed(1);
  }

  function parseInput(el) {
    var raw = String(el.value || '').trim();
    if (raw === '') {
      return null;
    }
    var n = parseFloat(raw);
    return isFinite(n) && n >= 0 ? n : null;
  }

  function calc(monthlySalary, allowedLeaves) {
    var perDay = monthlySalary / workingDays;
    var paidLeave = Math.min(excusedLeave, allowedLeaves);
    var lopApproved = Math.max(0, excusedLeave - allowedLeaves);
    var lopAbsent = unapprovedLeave;
    var lopDays = lopApproved + lopAbsent;
    var deduction = Math.round(lopDays * perDay * 100) / 100;
    var net = Math.max(0, Math.round((monthlySalary - deduction) * 100) / 100);

    return {
      perDay: perDay,
      paidLeave: paidLeave,
      lopDays: lopDays,
      lopApproved: lopApproved,
      lopAbsent: lopAbsent,
      deduction: deduction,
      net: net,
    };
  }

  function setOut(name, text) {
    var node = root.querySelector('[data-salary-out="' + name + '"]');
    if (node) {
      node.textContent = text;
    }
  }

  function render() {
    var monthly = parseInput(monthlyInput);
    var allowed = parseInput(allowedInput);
    if (allowed === null) {
      allowed = 0;
    }

    if (monthly === null || monthly <= 0) {
      resultsEl.hidden = true;
      emptyEl.hidden = false;
      return;
    }

    var r = calc(monthly, allowed);
    resultsEl.hidden = false;
    emptyEl.hidden = true;

    setOut('per_day', formatInr(r.perDay));
    setOut('paid_leave', formatDays(r.paidLeave) + ' day' + (Math.abs(r.paidLeave - 1) < 0.001 ? '' : 's'));
    setOut('lop_days', formatDays(r.lopDays) + ' day' + (Math.abs(r.lopDays - 1) < 0.001 ? '' : 's'));
    setOut('lop_deduction', '− ' + formatInr(r.deduction));
    setOut('net_salary', formatInr(r.net));

    var parts = [];
    if (r.lopAbsent > 0) {
      parts.push(formatDays(r.lopAbsent) + ' unapproved');
    }
    if (r.lopApproved > 0) {
      parts.push(formatDays(r.lopApproved) + ' excess approved leave');
    }
    setOut('lop_detail', parts.length ? 'LOP includes: ' + parts.join(' + ') + '.' : 'No LOP this month.');
  }

  function saveDraft() {
    if (!storageKey || !window.localStorage) {
      return;
    }
    try {
      localStorage.setItem(
        storageKey,
        JSON.stringify({
          monthly: monthlyInput.value,
          allowed: allowedInput.value,
        })
      );
    } catch (e) {
      /* ignore quota / private mode */
    }
  }

  function loadDraft() {
    if (!storageKey || !window.localStorage) {
      return;
    }
    try {
      var raw = localStorage.getItem(storageKey);
      if (!raw) {
        return;
      }
      var data = JSON.parse(raw);
      if (data && typeof data === 'object') {
        if (data.monthly != null) {
          monthlyInput.value = String(data.monthly);
        }
        if (data.allowed != null) {
          allowedInput.value = String(data.allowed);
        }
      }
    } catch (e) {
      /* ignore corrupt storage */
    }
  }

  function onInput() {
    saveDraft();
    render();
  }

  loadDraft();
  monthlyInput.addEventListener('input', onInput);
  allowedInput.addEventListener('input', onInput);
  render();
})();
