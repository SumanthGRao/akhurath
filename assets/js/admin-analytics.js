/**
 * Admin task analytics — Chart.js charts for monthly reports.
 */
(function () {
  'use strict';

  var cfg = window._akhAdminAnalytics;
  if (!cfg || typeof Chart === 'undefined') {
    return;
  }

  var fontFamily = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
  Chart.defaults.font.family = fontFamily;
  Chart.defaults.color = '#5c5650';

  function barOptions(stacked) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: stacked ? { stacked: true } : {},
        y: { beginAtZero: true, ticks: { precision: 0 } },
      },
      plugins: {
        legend: { position: 'bottom' },
      },
    };
  }

  var trendEl = document.getElementById('akh-analytics-trend');
  if (trendEl && cfg.trend) {
    new Chart(trendEl, {
      type: 'line',
      data: {
        labels: cfg.trend.labels || [],
        datasets: [
          {
            label: 'Incoming',
            data: cfg.trend.incoming || [],
            borderColor: 'hsl(218, 38%, 42%)',
            backgroundColor: 'hsla(218, 38%, 42%, 0.12)',
            tension: 0.25,
            fill: true,
          },
          {
            label: 'Delivered',
            data: cfg.trend.delivered || [],
            borderColor: 'hsl(132, 40%, 36%)',
            backgroundColor: 'hsla(132, 40%, 36%, 0.12)',
            tension: 0.25,
            fill: true,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  var monthEl = document.getElementById('akh-analytics-month');
  if (monthEl && cfg.monthCompare) {
    new Chart(monthEl, {
      type: 'doughnut',
      data: {
        labels: cfg.monthCompare.labels || [],
        datasets: [
          {
            data: cfg.monthCompare.data || [],
            backgroundColor: cfg.monthCompare.colors || [],
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
      },
    });
  }

  var clientsEl = document.getElementById('akh-analytics-clients');
  if (clientsEl && cfg.clients && (cfg.clients.labels || []).length) {
    new Chart(clientsEl, {
      type: 'bar',
      data: {
        labels: cfg.clients.labels,
        datasets: [
          {
            label: 'Incoming',
            data: cfg.clients.incoming,
            backgroundColor: 'hsl(218, 38%, 42%)',
          },
          {
            label: 'Delivered',
            data: cfg.clients.delivered,
            backgroundColor: 'hsl(132, 40%, 36%)',
          },
        ],
      },
      options: barOptions(false),
    });
  }

  var editorDelEl = document.getElementById('akh-analytics-editor-delivery');
  if (editorDelEl && cfg.editorDelivery && (cfg.editorDelivery.labels || []).length) {
    var delHours = (cfg.editorDelivery.hours || []).map(function (h) {
      return h === null || h === undefined ? 0 : h;
    });
    new Chart(editorDelEl, {
      type: 'bar',
      data: {
        labels: cfg.editorDelivery.labels,
        datasets: [
          {
            label: 'Avg New → Delivered (hours)',
            data: delHours,
            backgroundColor: 'hsl(132, 40%, 36%)',
          },
        ],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } },
      },
    });
  }

  var pipelineEl = document.getElementById('akh-analytics-pipeline');
  if (pipelineEl && cfg.pipeline && (cfg.pipeline.labels || []).length) {
    new Chart(pipelineEl, {
      type: 'bar',
      data: {
        labels: cfg.pipeline.labels,
        datasets: [
          {
            label: 'Transitions',
            data: cfg.pipeline.counts,
            backgroundColor: 'hsl(218, 38%, 42%)',
          },
        ],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  var editorsEl = document.getElementById('akh-analytics-editors');
  if (editorsEl && cfg.editors && (cfg.editors.labels || []).length) {
    new Chart(editorsEl, {
      type: 'bar',
      data: {
        labels: cfg.editors.labels,
        datasets: [
          {
            label: 'Handled',
            data: cfg.editors.handled,
            backgroundColor: 'hsl(205, 42%, 40%)',
          },
          {
            label: 'Delivered',
            data: cfg.editors.delivered,
            backgroundColor: 'hsl(132, 40%, 36%)',
          },
        ],
      },
      options: barOptions(false),
    });
  }

  var refreshMs = 90000;
  setInterval(function () {
    if (document.hidden) {
      return;
    }
    window.location.reload();
  }, refreshMs);
})();
