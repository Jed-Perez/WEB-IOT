<section id="app">
    <script>
        window.greenPulse = <?= json_encode(['baseUrl' => url('api'), 'csrf' => $csrfToken, 'userId' => (int) $user['id'], 'isAdmin' => $isAdmin], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    </script>
    <aside id="sidebar">
        <div class="brand">
            <svg width="26" height="26" viewBox="0 0 30 30" aria-hidden="true">
                <path d="M15 27C6 24 4 14 6 5c9 0 18 4 18 12 0 5-4 8-9 10z" fill="#A8D672"/>
            </svg>
            GreenPulse
        </div>
        <button class="nav on" type="button" data-v="dash" aria-current="page">Dashboard</button>
        <button class="nav" type="button" data-v="act">Actuators</button>
        <button class="nav" type="button" data-v="alerts">Alert log</button>
        <button class="nav" type="button" data-v="reports">Reports / History</button>
        <button class="nav" type="button" data-v="devices">Devices</button>
        <?php if ($isAdmin): ?><button class="nav" id="admin-nav" type="button" data-v="admin">Admin management</button><?php endif; ?>
        <?php if ($isAdmin): ?><button class="nav" type="button" data-v="activity">Activity log</button><button class="nav" type="button" data-v="operations">Email &amp; backups</button><?php endif; ?>
        <button class="nav" type="button" data-v="profile">Profile</button>
        <button class="nav" type="button" data-v="settings">Settings</button>
        <div class="spacer"></div>
        <form method="post" action="<?= e(url('logout')) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><button class="nav" type="submit">Sign out</button></form>
    </aside>
    <main class="container-fluid">
        <div class="top">
            <div>
                <button class="mobile-menu" type="button" id="mobile-menu" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
                <h2 id="title">Greenhouse A</h2>
                <span class="live"><span class="dot"></span><span id="upd">Loading readings...</span></span>
            </div>
            <div class="user">
                <button class="notification-bell" type="button" id="notification-bell" aria-label="Unread alerts">♢ <span id="unread-count">0</span></button>
                <div class="user-copy">
                    <div class="user-name"><?= e($user['full_name']) ?></div>
                    <div class="account-role"><?= e($user['role'] ?? 'Farm Staff') ?></div>
                </div>
                <div class="av" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                <button class="btn btn-outline-success btn-sm theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode">Dark mode</button>
            </div>
        </div>
        <p class="demo-note dashboard-part" role="status">Readings are stored when your device sends telemetry. Commands remain pending until the device acknowledges them.</p>
        <p id="api-message" class="notice hide" role="status" aria-live="polite"></p>
        <?php if ($notice !== ''): ?><p class="notice dashboard-notice" role="status"><?= e($notice) ?></p><?php endif; ?>

        <section class="dashboard-part" aria-label="Environmental monitoring">
            <div class="section-heading">
                <div><h3>Environmental monitoring</h3><p class="sub">Lettuce growing ranges · Greenhouse A</p></div>
                <span class="tag warn" id="device-status">Waiting for device</span>
            </div>
            <div class="row g-3" id="gauges"></div>
        </section>

        <section class="row g-3 dashboard-part" aria-label="Telemetry and actuator control">
            <div class="col-12 col-xl-7" id="chart-column">
                <div class="card h-100">
                    <div class="ch">
                        <div><h3>Historical telemetry</h3><p class="sub" id="history-status">Stored readings by sensor</p></div>
                        <div class="chart-controls">
                            <div class="seg" id="sensor-select" aria-label="Select chart sensor">
                                <button class="on" type="button" data-sensor="Temperature">Temp</button><button type="button" data-sensor="Humidity">Humidity</button><button type="button" data-sensor="pH level">pH</button><button type="button" data-sensor="TDS">TDS</button>
                            </div>
                            <div class="seg" id="rng" aria-label="Chart time range"><button class="on" type="button" data-h="24">24 h</button><button type="button" data-h="168">7 d</button><button type="button" data-h="720">30 d</button></div>
                            <label class="date-range">From <input id="chart-date-from" type="date" aria-label="Start date"></label>
                            <label class="date-range">To <input id="chart-date-to" type="date" aria-label="End date"></label>
                            <button class="btn btn-outline-success btn-sm" id="export-chart" type="button">Export CSV</button>
                        </div>
                    </div>
                    <svg class="chart" id="chart" viewBox="0 0 640 260" role="img" aria-label="Sensor readings over time"></svg>
                    <div class="leg"><span id="chart-legend"><i style="background:#2F6B3A"></i>Temperature (&deg;C)</span></div>
                </div>
            </div>
            <div class="col-12 col-xl-5" id="actuator-column">
                <div class="card h-100" id="actuators">
                    <h3>Actuator control</h3>
                    <div class="act" id="act"></div>
                </div>
            </div>
        </section>

        <section class="card dashboard-part" id="alert-log">
            <div class="ch"><h3>Recent alerts</h3><button class="text-btn" type="button" data-v="alerts">View all</button></div>
            <label class="filter-label">Level <select id="alert-filter"><option value="all">All levels</option><option value="w">Warning</option><option value="b">Critical</option><option value="i">Info</option></select></label>
            <div class="wrap">
                <table>
                    <thead><tr><th>Date &amp; time</th><th>Level</th><th>Sensor / device</th><th>Event</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="alerts"></tbody>
                </table>
            </div>
        </section>

        <?php if ($isAdmin): ?>
        <section class="card admin-panel hide" id="admin-panel">
            <div class="ch">
                <div><h3>Admin management</h3><p class="sub">Manage farm accounts and access roles.</p></div>
                <button class="btn btn-success small-btn" id="add-user" type="button">Add user</button>
            </div>
            <p class="sub">Changes are saved to the database. At least one administrator must remain.</p>
            <div class="wrap">
                <table>
                    <thead><tr><th>Name</th><th>Email</th><th>Access</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="users"></tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="card hide view-panel" id="profile-panel" aria-labelledby="profile-heading">
            <h3 id="profile-heading">Profile</h3>
            <p class="sub">Your GreenPulse account details.</p>
            <button class="btn btn-outline-danger btn-sm" id="logout-all" type="button">Sign out all devices</button>
            <p class="sub">Ends all sessions and remembered logins, including this browser.</p>
            <div class="profile-details">
                <p><strong>Full name: </strong><span><?= e($user['full_name']) ?></span></p>
                <p><strong>Email: </strong><span><?= e($user['email']) ?></span></p>
                <p><strong>Role: </strong><span><?= e($user['role'] ?? 'Farm Staff') ?></span></p>
            </div>
        </section>

        <section class="card hide view-panel" id="settings-panel" aria-labelledby="settings-heading">
            <h3 id="settings-heading">Settings</h3>
            <p class="sub">Manage greenhouse sensor thresholds and appearance.</p>
            <?php if ($isAdmin): ?>
            <form class="threshold-form" method="post" action="<?= e(url('dashboard/thresholds')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <h4>Sensor thresholds</h4>
                <?php foreach ($thresholds as $sensor): ?>
                <div class="threshold-row"><strong><?= e($sensor['name']) ?><?= $sensor['unit'] !== '' ? ' (' . e($sensor['unit']) . ')' : '' ?></strong>
                    <label>Minimum<input name="<?= e($sensor['key']) ?>_min" type="number" step="any" min="<?= e($sensor['min']) ?>" max="<?= e($sensor['max']) ?>" value="<?= e($sensor['low']) ?>" required></label>
                    <label>Maximum<input name="<?= e($sensor['key']) ?>_max" type="number" step="any" min="<?= e($sensor['min']) ?>" max="<?= e($sensor['max']) ?>" value="<?= e($sensor['high']) ?>" required></label>
                </div>
                <?php endforeach; ?>
                <button class="btn btn-success small-btn" type="submit">Save thresholds</button>
            </form>
            <?php else: ?><p class="sub">Threshold editing is available to administrators only.</p><?php endif; ?>
            <div class="settings-option">
                <div><strong>Appearance</strong><p class="sub">Switch between light and dark mode.</p></div>
                <button class="btn btn-outline-success btn-sm theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode">Dark mode</button>
            </div>
        </section>

        <section class="card hide view-panel" id="devices-panel"><h3>Devices</h3><p class="sub">Registered greenhouse hardware and its last contact.</p>
    <div class="wrap"><table><thead><tr><th>Device</th><th>Type</th><th>Status</th><th>Last contact</th></tr></thead><tbody id="device-rows"></tbody></table></div>
    <?php if ($isAdmin): ?>
    <button class="btn btn-outline-success btn-sm" id="rotate-device-key" type="button">Generate / replace device key</button>
    <p class="sub">Replacing the key disconnects clients using the old key. Copy the new key into your ESP32 configuration; it is shown only once.</p>
    <output id="device-key" style="overflow-wrap:anywhere" aria-live="polite"></output>
    <?php endif; ?>
</section>
        <section class="card hide view-panel" id="reports-panel"><div class="ch"><div><h3>Reports / History</h3><p class="sub">Stored readings for the sensor and date range selected on the dashboard.</p></div><button class="btn btn-outline-success btn-sm" id="print-report" type="button">Print / Save PDF</button></div>
            <form id="summary-form" class="report-controls">
                <label>Summary from<input id="report-from" type="date" required></label>
                <label>Through<input id="report-to" type="date" required></label>
                <button class="btn btn-success btn-sm" type="submit">Load daily report</button>
                <button class="btn btn-outline-success btn-sm" id="export-summary" type="button">Export summary CSV</button>
            </form>
            <p class="sub" id="summary-method">Daily averages and estimated durations. Dates use Asia/Manila.</p>
            <div class="wrap"><table><thead><tr><th>Day</th><th>Sensor</th><th>Samples</th><th>Average</th><th>Min / max</th><th>Outside ideal</th><th>Unknown time</th></tr></thead><tbody id="daily-sensors"></tbody></table></div>
            <h4>Actuator runtime</h4><div class="wrap"><table><thead><tr><th>Day</th><th>Actuator</th><th>Estimated ON time</th><th>Unknown time</th></tr></thead><tbody id="daily-runtime"></tbody></table></div>
            <h4>Recent raw readings</h4>
            <div class="wrap"><table><thead><tr><th>Date &amp; time</th><th>Sensor</th><th>Reading</th><th>Range</th></tr></thead><tbody id="history-rows"></tbody></table></div>
            <button class="btn btn-outline-success btn-sm" id="export-history" type="button">Export CSV</button>
        </section>
        <?php if ($isAdmin): ?>
        <section class="card hide view-panel" id="activity-panel"><h3>Activity log</h3><p class="sub">Account, threshold, device key, and actuator changes. Passwords and keys are never recorded here.</p>
            <div class="wrap"><table><thead><tr><th>Date &amp; time</th><th>Who</th><th>Action</th><th>Record</th><th>Changes</th></tr></thead><tbody id="activity-rows"></tbody></table></div>
            <button id="activity-more" class="btn btn-outline-success btn-sm" type="button">Load older activity</button>
        </section>
        <section class="card hide view-panel" id="operations-panel"><h3>Email &amp; backups</h3><p class="sub">Status of scheduled notification processing and verified database backups.</p>
            <button id="refresh-operations" class="btn btn-outline-success btn-sm" type="button">Refresh status</button>
            <div id="maintenance-status" aria-live="polite"></div>
            <h4>Recent email deliveries</h4><div class="wrap"><table><thead><tr><th>Recipient</th><th>Event</th><th>Status</th><th>Attempts</th><th>Sent</th></tr></thead><tbody id="delivery-rows"></tbody></table></div>
        </section>
        <?php endif; ?>
    </main>
</section>
<div class="notification-popover hide" id="notification-popover" role="status"></div>
<dialog id="user-dialog">
    <form id="user-form" method="dialog">
        <h2 id="user-dialog-title">Add user</h2>
        <label for="user-name">Full name<input class="form-control" id="user-name" name="name" required></label>
        <label for="user-email">Email<input class="form-control" id="user-email" name="email" type="email" required></label>
        <label for="user-role">Role<select class="form-select" id="user-role"><option>Farm Staff</option><option>Administrator</option></select></label>
        <label for="user-status">Account status<select class="form-select" id="user-status"><option>Active</option><option>Pending</option><option>Suspended</option><option>Rejected</option></select></label>
        <label for="user-password">Password (leave blank to keep existing)<input class="form-control" id="user-password" type="password" minlength="8" maxlength="72" autocomplete="new-password"></label>
        <p id="user-error" role="alert"></p>
        <div class="dialog-actions">
            <button class="btn btn-outline-secondary" id="cancel-user" type="button">Cancel</button>
            <button class="btn btn-success small-btn" type="submit">Save user</button>
        </div>
    </form>
</dialog>
<dialog id="confirm-password-dialog">
    <form id="confirm-password-form" method="dialog">
        <h2>Confirm your password</h2><p class="sub">Required for account security and administrator changes. Confirmation lasts five minutes.</p>
        <label for="confirm-password-input">Your current password<input id="confirm-password-input" type="password" class="form-control" autocomplete="current-password" required></label>
        <p id="confirm-password-error" role="alert"></p>
        <div class="dialog-actions"><button class="btn btn-outline-secondary" id="cancel-confirm-password" type="button">Cancel</button><button class="btn btn-success" type="submit">Confirm</button></div>
    </form>
</dialog>
