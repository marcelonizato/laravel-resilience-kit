<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $csrfToken }}">
    <title>Resilience Kit · Circuit Breakers</title>
    <style>
        :root {
            --bg: #f5f6f8; --card: #fff; --text: #1d2433; --muted: #6b7385; --border: #e3e6ec;
            --closed: #15803d; --closed-bg: #dcfce7;
            --open: #b91c1c; --open-bg: #fee2e2;
            --half: #b45309; --half-bg: #fef3c7;
            --primary: #2563eb;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1420; --card: #171d2c; --text: #e6e9f0; --muted: #98a1b5; --border: #263049;
                --closed: #4ade80; --closed-bg: #14321f;
                --open: #f87171; --open-bg: #3b1818;
                --half: #fbbf24; --half-bg: #3a2c0d;
                --primary: #60a5fa;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: var(--muted); }
        .toolbar { display: flex; align-items: center; gap: 12px; font-size: 13px; }
        button { font: inherit; cursor: pointer; border-radius: 6px; border: 1px solid var(--border); background: var(--card); color: var(--text); padding: 5px 12px; }
        button:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        button:disabled { opacity: .5; cursor: not-allowed; }
        button.danger:hover:not(:disabled) { border-color: var(--open); color: var(--open); }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; }
        .stat b { display: block; font-size: 24px; line-height: 1.2; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
        .name { font-weight: 600; word-break: break-all; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.closed { color: var(--closed); background: var(--closed-bg); }
        .badge.open { color: var(--open); background: var(--open-bg); }
        .badge.half_open { color: var(--half); background: var(--half-bg); }
        .reason { max-width: 320px; word-break: break-word; }
        .empty, .error { padding: 28px; text-align: center; }
        .error { color: var(--open); background: var(--open-bg); border-radius: 8px; margin-bottom: 16px; padding: 10px 14px; text-align: left; }
        [hidden] { display: none !important; }
        footer { margin-top: 16px; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>Circuit Breakers</h1>
            <div class="muted">Laravel Resilience Kit</div>
        </div>
        <div class="toolbar">
            <span id="updated" class="muted">Loading…</span>
            <label><input type="checkbox" id="auto" checked> Auto-refresh (5s)</label>
            <button type="button" id="refresh">Refresh</button>
        </div>
    </header>

    <div id="error" class="error" role="alert" hidden></div>

    <section class="summary" aria-label="Summary">
        <div class="stat"><span class="muted">Total</span><b id="count-total">0</b></div>
        <div class="stat"><span class="muted">Closed</span><b id="count-closed">0</b></div>
        <div class="stat"><span class="muted">Half-open</span><b id="count-half_open">0</b></div>
        <div class="stat"><span class="muted">Open</span><b id="count-open">0</b></div>
    </section>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>Circuit</th>
                <th>State</th>
                <th>Failures</th>
                <th>Recovery timeout</th>
                <th>Last tripped</th>
                <th>Last failure</th>
                <th></th>
            </tr>
            </thead>
            <tbody id="rows"></tbody>
        </table>
        <div id="empty" class="empty muted" hidden>No circuits registered yet.</div>
    </div>

    <footer class="muted">
        Thresholds and timeouts follow store overrides, then <code>circuits.{name}</code>, then the global defaults in <code>config/resilience-kit.php</code>.
    </footer>
</div>

<script>
    (function () {
        'use strict';

        var CIRCUITS_URL = @json($circuitsUrl);
        var RESET_URL = @json($resetUrlTemplate);
        var CSRF = document.querySelector('meta[name="csrf-token"]').content;
        var REFRESH_MS = 5000;

        var rows = document.getElementById('rows');
        var empty = document.getElementById('empty');
        var errorBox = document.getElementById('error');
        var updated = document.getElementById('updated');
        var auto = document.getElementById('auto');
        var timer = null;

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined && text !== null) node.textContent = text;
            return node;
        }

        function showError(message) {
            errorBox.textContent = message;
            errorBox.hidden = !message;
        }

        function request(url, options) {
            options = options || {};
            options.headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF
            }, options.headers || {});
            options.credentials = 'same-origin';

            return fetch(url, options).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (!response.ok) {
                        throw new Error(body.message || ('Request failed with status ' + response.status));
                    }
                    return body;
                });
            });
        }

        function formatDate(iso) {
            return iso ? new Date(iso).toLocaleString() : '—';
        }

        function render(circuits) {
            var counts = { closed: 0, half_open: 0, open: 0 };

            rows.replaceChildren();

            circuits.forEach(function (circuit) {
                counts[circuit.state] = (counts[circuit.state] || 0) + 1;

                var tr = el('tr');
                tr.appendChild(el('td', 'name', circuit.name));

                var state = el('td');
                state.appendChild(el('span', 'badge ' + circuit.state, circuit.state_label));
                if (circuit.state === 'open' && circuit.retry_after !== null) {
                    state.appendChild(el('div', 'muted', 'retry in ' + circuit.retry_after + 's'));
                }
                tr.appendChild(state);

                tr.appendChild(el('td', null, circuit.failures_count + ' / ' + circuit.failure_threshold));
                tr.appendChild(el('td', null, circuit.recovery_timeout + 's'));
                tr.appendChild(el('td', null, formatDate(circuit.last_tripped_at)));
                tr.appendChild(el('td', 'reason muted', circuit.last_failure_reason || '—'));

                var action = el('td');
                var button = el('button', 'danger', 'Reset');
                button.type = 'button';
                button.addEventListener('click', function () { reset(circuit.name, button); });
                action.appendChild(button);
                tr.appendChild(action);

                rows.appendChild(tr);
            });

            document.getElementById('count-total').textContent = circuits.length;
            ['closed', 'half_open', 'open'].forEach(function (key) {
                document.getElementById('count-' + key).textContent = counts[key] || 0;
            });
            empty.hidden = circuits.length > 0;
        }

        function load() {
            return request(CIRCUITS_URL)
                .then(function (body) {
                    render(body.circuits || []);
                    updated.textContent = 'Updated ' + new Date().toLocaleTimeString();
                    showError('');
                })
                .catch(function (error) { showError('Could not load circuits: ' + error.message); });
        }

        function reset(name, button) {
            if (!window.confirm('Reset circuit "' + name + '"? It will be forced to CLOSED and its counters cleared.')) {
                return;
            }

            button.disabled = true;

            request(RESET_URL.replace('__NAME__', encodeURIComponent(name)), { method: 'POST' })
                .then(load)
                .catch(function (error) {
                    button.disabled = false;
                    showError('Could not reset "' + name + '": ' + error.message);
                });
        }

        function schedule() {
            clearInterval(timer);
            if (auto.checked) timer = setInterval(load, REFRESH_MS);
        }

        document.getElementById('refresh').addEventListener('click', load);
        auto.addEventListener('change', schedule);

        load();
        schedule();
    })();
</script>
</body>
</html>
