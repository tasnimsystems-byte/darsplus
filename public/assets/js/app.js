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
    var ready = function (canvas) {
        if (canvas.parentElement) {
            canvas.parentElement.classList.add('is-ready');
        }
    };
    if (!window.Chart) {
        // No chart library: drop the placeholders; the figures are in the table next to each chart.
        document.querySelectorAll('canvas.js-chart').forEach(ready);
        return;
    }
    // Colours come from the design tokens, so the charts follow the theme (light / dark).
    var styles = getComputedStyle(document.documentElement);
    var token = function (name, fallback) {
        var value = styles.getPropertyValue(name).trim();
        return value || fallback;
    };
    var palette = [1, 2, 3, 4, 5, 6, 7, 8].map(function (n) { return token('--chart-' + n, '#1f5a4c'); });
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    window.Chart.defaults.color = token('--c-text-muted', '#4a5a5c');
    window.Chart.defaults.borderColor = token('--c-row-line', '#ebe2d0');

    document.querySelectorAll('canvas.js-chart').forEach(function (canvas) {
        var spec;
        try {
            spec = JSON.parse(canvas.dataset.chart);
        } catch (error) {
            ready(canvas);
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
                    borderColor: spec.type === 'line' ? palette[0] : token('--c-surface', '#ffffff'),
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
        ready(canvas);
    });
})();

// Attendance: circles that cycle through the states of the current lesson.
(function () {
    'use strict';
    // Attendance: one click on the current lesson's circle moves it to the next state
    // (present → absent → late → excused in school → present) and keeps the counters current.
    var attendanceOrder = ['present', 'absent', 'late', 'justified'];
    document.querySelectorAll('.attendance-table').forEach(function (table) {
        var labels = {};
        try {
            labels = JSON.parse(table.dataset.attLabels || '{}');
        } catch (error) {
            labels = {};
        }
        var hint = table.dataset.attHint || '';
        table.addEventListener('click', function (event) {
            var button = event.target.closest('[data-att-cycle]');
            if (!button) {
                return;
            }
            var input = button.parentElement.querySelector('[data-att-input]');
            var from = input.value;
            var to = attendanceOrder[(attendanceOrder.indexOf(from) + 1) % attendanceOrder.length];
            input.value = to;
            button.classList.remove('att-' + from);
            button.classList.add('att-' + to);
            var text = (labels[to] || to);
            button.title = text;
            button.setAttribute('aria-label', button.dataset.attPeriod + ': ' + text + ' – ' + hint);
            [from, to].forEach(function (state, index) {
                var counter = document.querySelector('[data-att-count="' + state + '"]');
                if (counter) {
                    counter.textContent = String(Math.max(0, parseInt(counter.textContent, 10) + (index === 0 ? -1 : 1)));
                }
            });
        });
    });
})();

/* Show a block only while its checkbox is checked: <div data-revealed-by="checkbox-id">;
   "select all" checkboxes: <input data-check-all="container-id">. Without JavaScript all blocks stay visible. */
(function () {
    'use strict';

    document.querySelectorAll('[data-revealed-by]').forEach(function (block) {
        var toggle = document.getElementById(block.dataset.revealedBy);
        if (!toggle) {
            return;
        }
        var sync = function () {
            block.classList.toggle('d-none', !toggle.checked);
        };
        toggle.addEventListener('change', sync);
        sync();
    });

    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        var container = document.getElementById(master.dataset.checkAll);
        if (!container) {
            return;
        }
        master.addEventListener('change', function () {
            container.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                if (box !== master) {
                    box.checked = master.checked;
                }
            });
        });
    });
})();

/* School settings: live total of the component weights (the server checks it again). */
(function () {
    'use strict';
    document.querySelectorAll('[data-weight-table]').forEach(function (table) {
        var output = table.querySelector('[data-weight-total]');
        var update = function () {
            var total = 0;
            table.querySelectorAll('[data-weight]').forEach(function (input) {
                var value = parseFloat(String(input.value).replace(',', '.'));
                if (!isNaN(value)) {
                    total += value;
                }
            });
            total = Math.round(total * 100) / 100;
            output.textContent = total + '%';
            output.classList.toggle('text-success', total === 100);
            output.classList.toggle('text-danger', total !== 100 && total !== 0);
        };
        table.addEventListener('input', update);
        update();
    });
})();

/* Toasts: success messages leave by themselves, errors stay until closed. Without JavaScript they are plain, visible messages. */
(function () {
    'use strict';
    document.querySelectorAll('.toast-stack .toast').forEach(function (toast) {
        if (window.bootstrap && window.bootstrap.Toast) {
            var instance = window.bootstrap.Toast.getOrCreateInstance(toast);
            instance.show();
        }
    });
})();

/* Homeroom sheet: choosing a ready-made remark puts its text in the remark box, where it can still be edited. */
(function () {
    'use strict';
    document.addEventListener('change', function (event) {
        var select = event.target.closest && event.target.closest('select[data-fill-target]');
        if (!select || select.value === '') {
            return;
        }
        var box = document.getElementById(select.dataset.fillTarget);
        if (box) {
            box.value = select.options[select.selectedIndex].text;
            box.focus();
        }
    });
})();

/* Lesson page: after an online lesson, mark the students who did not join as absent – only in the
   table (those still "present"); the teacher reviews and saves. */
(function () {
    'use strict';

    document.querySelectorAll('[data-online-mark-absent]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('tr[data-online-joined="0"]').forEach(function (row) {
                var input = row.querySelector('[data-att-input]');
                var cycle = row.querySelector('[data-att-cycle]');
                if (input && cycle && input.value === 'present') {
                    cycle.click();
                }
            });
        });
    });

    // The portal's list of live lessons looks again every half minute.
    var live = document.querySelector('[data-live-refresh]');
    if (live) {
        setInterval(function () {
            if (!document.hidden) {
                window.location.reload();
            }
        }, 30000);
    }
})();