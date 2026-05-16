/**
 * Admin overview — Chart.js bar and doughnut charts.
 * Expects window._akhAdminCharts from admin/index.php.
 */
(function () {
  'use strict';

  var cfg = window._akhAdminCharts;
  if (!cfg || typeof Chart === 'undefined') {
    return;
  }

  var fontFamily = "'Source Sans 3', system-ui, sans-serif";
  Chart.defaults.font.family = fontFamily;
  Chart.defaults.color = '#5c5650';
  Chart.defaults.plugins.legend.labels.boxWidth = 12;
  Chart.defaults.plugins.legend.labels.padding = 14;

  function commonOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { font: { size: 12 } },
        },
      },
    };
  }

  function clickUrlHandler(urls) {
    return function (_evt, elements) {
      if (!elements.length || !urls[elements[0].index]) {
        return;
      }
      window.location.href = urls[elements[0].index];
    };
  }

  var statusEl = document.getElementById('akh-chart-status');
  if (statusEl && cfg.status && cfg.status.data.length) {
    new Chart(statusEl, {
      type: 'doughnut',
      data: {
        labels: cfg.status.labels,
        datasets: [
          {
            data: cfg.status.data,
            backgroundColor: cfg.status.colors,
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 6,
          },
        ],
      },
      options: Object.assign({}, commonOptions(), {
        cutout: '52%',
        plugins: {
          legend: { position: 'right', labels: { font: { size: 11 } } },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                var total = ctx.dataset.data.reduce(function (a, b) {
                  return a + b;
                }, 0);
                var pct = total ? Math.round((ctx.raw / total) * 100) : 0;
                return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
              },
            },
          },
        },
        onClick: clickUrlHandler(cfg.status.urls),
      }),
    });
  }

  var typesEl = document.getElementById('akh-chart-edit-types');
  if (typesEl && cfg.editTypes && cfg.editTypes.data.length) {
    new Chart(typesEl, {
      type: 'bar',
      data: {
        labels: cfg.editTypes.labels,
        datasets: [
          {
            label: 'Tasks',
            data: cfg.editTypes.data,
            backgroundColor: cfg.editTypes.colors,
            borderRadius: 4,
            maxBarThickness: 48,
          },
        ],
      },
      options: Object.assign({}, commonOptions(), {
        plugins: { legend: { display: false } },
        scales: {
          x: {
            grid: { display: false },
            ticks: { maxRotation: 45, minRotation: 0, font: { size: 11 } },
          },
          y: {
            beginAtZero: true,
            ticks: { stepSize: 1, precision: 0 },
            grid: { color: 'rgba(26, 23, 21, 0.06)' },
          },
        },
      }),
    });
  }

  function horizontalBar(el, block, barColor) {
    if (!el || !block || !block.data.length) {
      return;
    }
    var wrap = el.closest('.admin-chart-canvas-wrap--hbar');
    if (wrap) {
      var rows = block.data.length;
      wrap.style.height = Math.max(200, Math.min(420, 44 * rows + 48)) + 'px';
    }
    new Chart(el, {
      type: 'bar',
      data: {
        labels: block.labels,
        datasets: [
          {
            label: 'Tasks',
            data: block.data,
            backgroundColor: barColor || 'hsl(218, 38%, 42%)',
            borderRadius: 4,
            barThickness: 22,
          },
        ],
      },
      options: Object.assign({}, commonOptions(), {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { stepSize: 1, precision: 0 },
            grid: { color: 'rgba(26, 23, 21, 0.06)' },
          },
          y: {
            grid: { display: false },
            ticks: { font: { size: 12 } },
          },
        },
        onClick: block.urls ? clickUrlHandler(block.urls) : undefined,
      }),
    });
  }

  horizontalBar(
    document.getElementById('akh-chart-clients'),
    cfg.clients,
    'hsl(42, 55%, 42%)'
  );
  horizontalBar(
    document.getElementById('akh-chart-editors'),
    cfg.editors,
    'hsl(218, 38%, 42%)'
  );
})();
