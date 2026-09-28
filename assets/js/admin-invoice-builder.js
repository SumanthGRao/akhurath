/**
 * Multi-service invoice builder with live subtotal / tax / total.
 */
(function () {
  'use strict';

  var root = document.getElementById('inv-builder');
  if (!root) return;

  var catalog = {};
  try {
    catalog = JSON.parse(root.getAttribute('data-catalog') || '{}');
  } catch (e) {
    catalog = {};
  }

  var tbody = document.getElementById('inv-builder-rows');
  var tpl = document.getElementById('inv-builder-row-tpl');
  var taxInput = document.getElementById('inv-tax-percent');
  var subEl = document.getElementById('inv-live-subtotal');
  var taxEl = document.getElementById('inv-live-tax');
  var grandEl = document.getElementById('inv-live-total');
  var rowIndex = 0;

  function parseInr(val) {
    var s = String(val || '').replace(/[,\s₹]/g, '');
    var n = parseFloat(s);
    return isNaN(n) || n < 0 ? 0 : n;
  }

  function formatInr(n) {
    return 'Rs. ' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function serviceRate(key) {
    if (key && catalog[key]) {
      return Number(catalog[key].inr) || 0;
    }
    return 0;
  }

  function bindRow(tr) {
    var sel = tr.querySelector('.inv-builder__service');
    var customWrap = tr.querySelector('.inv-builder__custom-wrap');
    var customInput = tr.querySelector('.inv-builder__custom-label');
    var qtyInput = tr.querySelector('.inv-builder__qty');
    var rateInput = tr.querySelector('.inv-builder__rate');
    var lineEl = tr.querySelector('.inv-builder__line-total');
    var removeBtn = tr.querySelector('.inv-builder__remove');

    function syncCustom() {
      var isCustom = sel && sel.value === 'custom';
      if (customWrap) customWrap.hidden = !isCustom;
    }

    function recalcLine() {
      var qty = parseInt(qtyInput && qtyInput.value, 10);
      if (isNaN(qty) || qty < 1) qty = 1;
      var rate = parseInr(rateInput && rateInput.value);
      var line = qty * rate;
      if (lineEl) lineEl.textContent = formatInr(line);
      recalcGrand();
    }

    if (sel) {
      sel.addEventListener('change', function () {
        var rate = serviceRate(sel.value);
        if (rateInput && rate > 0) rateInput.value = rate.toFixed(2);
        syncCustom();
        recalcLine();
      });
    }
    if (qtyInput) qtyInput.addEventListener('input', recalcLine);
    if (rateInput) rateInput.addEventListener('input', recalcLine);
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        tr.remove();
        recalcGrand();
      });
    }
    syncCustom();
    if (sel && rateInput && rateInput.value === '') {
      var defRate = serviceRate(sel.value);
      if (defRate > 0) {
        rateInput.value = defRate.toFixed(2);
      }
    }
    recalcLine();
  }

  function recalcGrand() {
    var sub = 0;
    if (tbody) {
      tbody.querySelectorAll('.inv-builder__row').forEach(function (tr) {
        var qty = parseInt(tr.querySelector('.inv-builder__qty')?.value || '0', 10);
        var rate = parseInr(tr.querySelector('.inv-builder__rate')?.value);
        if (isNaN(qty) || qty < 1) return;
        sub += qty * rate;
      });
    }
    var taxPct = parseInr(taxInput && taxInput.value);
    var tax = sub * (taxPct / 100);
    var grand = sub + tax;
    if (subEl) subEl.textContent = formatInr(sub);
    if (taxEl) taxEl.textContent = formatInr(tax);
    if (grandEl) grandEl.textContent = formatInr(grand);
  }

  function addRow() {
    if (!tbody || !tpl) return;
    var html = tpl.innerHTML.replace(/__IDX__/g, String(rowIndex++));
    var wrap = document.createElement('tbody');
    wrap.innerHTML = html.trim();
    var tr = wrap.firstElementChild;
    if (!tr) return;
    tbody.appendChild(tr);
    bindRow(tr);
    recalcGrand();
  }

  var addBtn = document.getElementById('inv-builder-add');
  if (addBtn) addBtn.addEventListener('click', addRow);
  if (taxInput) taxInput.addEventListener('input', recalcGrand);

  addRow();
  addRow();
})();
