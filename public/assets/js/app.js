/* SPMS – small progressive enhancements. Every feature works without JavaScript
   except the confirmation dialog, which falls back to the browser's own confirm(). */
(function () {
    'use strict';

    // Language selector: submit on change.
    document.querySelectorAll('.js-autosubmit').forEach(function (element) {
        element.addEventListener('change', function () {
            element.form.submit();
        });
    });

    // Confirmation before destructive actions: <form data-confirm="message">.
    var modalElement = document.getElementById('confirm-modal');
    var pendingForm = null;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') {
            return;
        }
        event.preventDefault();

        if (!modalElement || !window.bootstrap) {
            if (window.confirm(form.dataset.confirm)) {
                form.dataset.confirmed = '1';
                form.submit();
            }
            return;
        }
        pendingForm = form;
        document.getElementById('confirm-modal-body').textContent = form.dataset.confirm;
        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    });

    if (modalElement) {
        document.getElementById('confirm-modal-ok').addEventListener('click', function () {
            if (pendingForm) {
                pendingForm.dataset.confirmed = '1';
                pendingForm.submit();
            }
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            pendingForm = null;
        });
    }

    // Move focus to the first invalid field after a failed submission.
    var firstInvalid = document.querySelector('[aria-invalid="true"]');
    if (firstInvalid) {
        firstInvalid.focus();
    }
})();

// Print layout: a print button and "back" to the previous page.
(function () {
    'use strict';
    document.querySelectorAll('.js-print').forEach(function (button) {
        button.addEventListener('click', function () { window.print(); });
    });
    document.querySelectorAll('.js-back').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (window.history.length > 1) {
                event.preventDefault();
                window.history.back();
            }
        });
    });
})();

// Dashboard charts: <canvas class="js-chart" data-chart='{"type","labels","values","max","label"}'>.
(function () {
    'use strict';
    if (!window.Chart) {
        return;
    }
    var palette = ['#26667a', '#7aa7b3', '#c98a3a', '#5b8a5a', '#a35d6a', '#8b7fb0', '#4f7fa3', '#b0a05a'];
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    window.Chart.defaults.color = '#3b4847';

    document.querySelectorAll('canvas.js-chart').forEach(function (canvas) {
        var spec;
        try {
            spec = JSON.parse(canvas.dataset.chart);
        } catch (error) {
            return;
        }
        var round = spec.type === 'doughnut';
        new window.Chart(canvas, {
            type: spec.type,
            data: {
                labels: spec.labels,
                datasets: [{
                    label: spec.label,
                    data: spec.values,
                    backgroundColor: round || spec.type === 'bar' && spec.max === null ? palette : palette[0],
                    borderColor: spec.type === 'line' ? palette[0] : '#ffffff',
                    borderWidth: spec.type === 'line' ? 2 : 1,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduceMotion ? false : { duration: 400 },
                plugins: {
                    legend: { display: round, position: 'bottom', rtl: true },
                    tooltip: { rtl: true }
                },
                scales: round ? {} : {
                    x: { reverse: true },
                    y: { beginAtZero: true, max: spec.max === null ? undefined : spec.max, position: 'right', ticks: { precision: 0 } }
                }
            }
        });
    });
})();
