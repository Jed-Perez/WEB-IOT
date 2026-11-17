(() => {
    "use strict";
    const config = window.greenPulse;
    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => [...document.querySelectorAll(selector)];
    const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
    const date = (value) => value ? new Date(value.replace(" ", "T") + "Z") : null;
    const dateLabel = (value) => date(value)?.toLocaleString() || "Never";
    const number = (value, decimals = 1) => value === null || value === undefined ? "--" : Number(value).toFixed(decimals);
    let snapshot = null, rows = [], users = [], editingUser = null;
    let selectedSensor = "temperature", selectedRange = 24, activeView = "dash";
    let historyRequest = 0, refreshing = false;

    function message(text, error = false) {
        const box = $("#api-message");
        box.textContent = text;
        box.classList.toggle("hide", !text);
        box.classList.toggle("err", error);
    }
    async function api(path, body, retried = false) {
        const response = await fetch(`${config.baseUrl}/${path}`, {
            method: body === undefined ? "GET" : "POST",
            credentials: "same-origin", cache: "no-store",
            headers: body === undefined ? {} : { "Content-Type": "application/json", "X-CSRF-Token": config.csrf },
            body: body === undefined ? undefined : JSON.stringify(body)
        });
        const data = await response.json().catch(() => ({ error: "The server returned an invalid response." }));
        if (response.status === 428 && !retried && window.greenPulseConfirm) {
            await window.greenPulseConfirm();
            return api(path, body, true);
        }
        if (!response.ok) throw new Error(data.error || "Request failed.");
        return data;
    }
    async function action(button, work) {
        if (button) button.disabled = true;
        try { await work(); } catch (error) { message(error.message, true); }
        finally { if (button?.isConnected) button.disabled = false; }
    }
    function renderGauges() {
        $("#gauges").innerHTML = snapshot.sensors.map((sensor) => {
            const hasValue = sensor.value !== null;
            const inRange = hasValue && sensor.value >= sensor.low && sensor.value <= sensor.high;
            const tone = !hasValue ? "warn" : inRange ? "" : "critical";
            const label = !hasValue ? "No readings" : inRange ? "In range" : sensor.value < sensor.low ? "Too low" : "Too high";
            const progress = hasValue ? Math.max(0, Math.min(100, (sensor.value - sensor.min) / (sensor.max - sensor.min) * 100)) : 0;
            return `<div class="col-12 col-sm-6 col-xxl-3"><article class="card gauge h-100">
                <div class="gn">${escapeHtml(sensor.name)}</div>
                <svg viewBox="0 0 100 60" aria-hidden="true"><path d="M10 55A40 40 0 0 1 90 55" pathLength="100" fill="none" stroke="var(--line)" stroke-width="9" stroke-linecap="round"/><path d="M10 55A40 40 0 0 1 90 55" pathLength="100" fill="none" stroke="${tone === "critical" ? "#C8452F" : "#2F6B3A"}" stroke-width="5" stroke-dasharray="${progress} 100"/></svg>
                <div class="gv">${number(sensor.value, sensor.decimals)}<small> ${escapeHtml(sensor.unit)}</small></div>
                <span class="tag ${tone}">${label}</span><div class="sub">Ideal ${sensor.low}–${sensor.high} ${escapeHtml(sensor.unit)}</div>
                <div class="range-meta">24h min <b>${number(sensor.minDay, sensor.decimals)}</b> · max <b>${number(sensor.maxDay, sensor.decimals)}</b></div>
            </article></div>`;
        }).join("");
    }
    function renderDevices() {
        const device = snapshot.device;
        const online = device.last_seen_at && Date.now() - date(device.last_seen_at).getTime() < 120000;
        $("#device-status").textContent = online ? "Device online" : "Device offline";
        $("#device-status").classList.toggle("warn", !online);
        $("#upd").textContent = snapshot.recorded_at ? `Last reading: ${dateLabel(snapshot.recorded_at)}` : "Waiting for first reading";
        $("#device-rows").innerHTML = `<tr><td>${escapeHtml(device.name)}</td><td>${escapeHtml(device.type)}</td><td>${online ? "Online" : "Offline"}${Number(device.configured) ? "" : " · key not configured"}</td><td>${escapeHtml(dateLabel(device.last_seen_at))}</td></tr>`;
    }
    function renderAlerts() {
        if (!snapshot) return;
        const filter = $("#alert-filter").value;
        const levels = { w: "Warning", b: "Critical", i: "Info" };
        const alerts = snapshot.alerts.filter((item) => filter === "all" || item.level === filter);
        $("#alerts").innerHTML = alerts.map((item) => `<tr><td>${escapeHtml(dateLabel(item.created_at))}</td><td class="sev ${escapeHtml(item.level)}">${levels[item.level] || "Info"}</td><td>${escapeHtml(item.device)}</td><td>${escapeHtml(item.message)}</td><td>${escapeHtml(item.status)}</td><td>${item.status === "Resolved" ? "Resolved" : `${item.status === "Active" ? `<button class="text-btn" data-alert="${item.id}" data-status="Acknowledged">Acknowledge</button>` : ""}<button class="text-btn" data-alert="${item.id}" data-status="Resolved">Resolve</button>`}</td></tr>`).join("") || '<tr><td colspan="6" class="empty-state">No alerts match this filter.</td></tr>';
        $("#unread-count").textContent = snapshot.unread_count;
        $("#notification-bell").setAttribute("aria-label", `${snapshot.unread_count} active alerts`);
    }
    function renderActuators() {
        $("#act").innerHTML = snapshot.actuators.map((item) => {
            const pending = item.command_status === "pending";
            const fresh = item.reported_at && Date.now() - date(item.reported_at).getTime() < 120000;
            const known = item.reported_on !== null && fresh;
            const on = Number(item.reported_on) === 1;
            const cooldown = item.cooldown_until ? Math.max(0, Math.ceil((date(item.cooldown_until).getTime() - Date.now()) / 1000)) : 0;
            const state = known ? (on ? "ON" : "OFF") : "UNKNOWN";
            const feedback = pending ? `Pending: ${item.requested_mode} ${Number(item.desired_on) ? "ON" : "OFF / automatic"}; waiting for device` : item.command_status ? `Last command: ${item.command_status}` : "No commands sent";
            return `<div class="arow"><div class="actuator-heading"><b>${escapeHtml(item.name)}</b><span class="act-state ${known && on ? "is-on" : "is-off"}">${state}</span>
                <button class="sw" type="button" role="switch" aria-label="${escapeHtml(item.name)}" aria-checked="${known && on}" data-toggle="${item.id}" ${pending || item.mode !== "manual" || !known || (!on && cooldown) ? "disabled" : ""}></button></div>
                <div class="actuator-details"><span class="sub">Reported mode: ${escapeHtml(item.mode)}</span><div class="seg"><button type="button" data-mode="auto" data-actuator="${item.id}" class="${item.mode === "auto" ? "on" : ""}" ${pending ? "disabled" : ""}>Auto</button><button type="button" data-mode="manual" data-actuator="${item.id}" class="${item.mode === "manual" ? "on" : ""}" ${pending ? "disabled" : ""}>Manual</button></div></div>
                <small class="command-feedback">${escapeHtml(feedback)} · max manual run ${item.max_seconds}s${cooldown ? ` · cooldown ${cooldown}s` : ""}<br>Last reported: ${escapeHtml(dateLabel(item.reported_at))}</small></div>`;
        }).join("");
    }
    function rangeQuery() {
        const fromValue = $("#chart-date-from").value, toValue = $("#chart-date-to").value;
        if (Boolean(fromValue) !== Boolean(toValue)) throw new Error("Select both start and end dates.");
        const end = toValue ? new Date(`${toValue}T00:00:00`) : new Date(Date.now() + 1000);
        if (toValue) end.setDate(end.getDate() + 1);
        const start = fromValue ? new Date(`${fromValue}T00:00:00`) : new Date(end.getTime() - selectedRange * 3600000);
        if (end <= start || end - start > 31 * 86400000) throw new Error("Choose a date range of up to 31 days.");
        const iso = (value) => value.toISOString().replace(/\.\d{3}Z$/, "Z");
        return new URLSearchParams({ from: iso(start), to: iso(end) }).toString();
    }
    async function loadHistory() {
        const request = ++historyRequest;
        try {
            const result = await api(`history?${rangeQuery()}`);
            if (request !== historyRequest) return;
            rows = result.rows;
            $("#history-status").textContent = result.truncated ? "Showing latest 1,000 readings. CSV includes the full selected range." : `${rows.length} stored readings in selected range`;
            renderChart();
        } catch (error) {
            if (request !== historyRequest) return;
            rows = []; renderChart();
            $("#history-status").textContent = "History unavailable for the selected range.";
            throw error;
        }
    }
    function renderChart() {
        const sensor = snapshot?.sensors.find((item) => item.key === selectedSensor);
        if (!sensor) return;
        $("#chart-legend").textContent = `${sensor.name} (${sensor.unit})`;
        $("#history-rows").innerHTML = rows.slice(-100).reverse().map((row) => `<tr><td>${escapeHtml(dateLabel(row.recorded_at))}</td><td>${escapeHtml(sensor.name)}</td><td>${number(row[sensor.key], sensor.decimals)} ${escapeHtml(sensor.unit)}</td><td>${sensor.low}–${sensor.high}</td></tr>`).join("") || '<tr><td colspan="4" class="empty-state">No readings in this date range.</td></tr>';
        if (!rows.length) {
            $("#chart").innerHTML = '<text x="320" y="130" text-anchor="middle" fill="var(--mute)">No readings in this date range</text>';
            return;
        }
        const values = rows.map((row) => Number(row[sensor.key]));
        const min = Math.min(sensor.min, ...values), max = Math.max(sensor.max, ...values);
        const times = rows.map((row) => date(row.recorded_at).getTime());
        const first = times[0], last = times.at(-1);
        const x = (i) => last === first ? 340 : 68 + (times[i] - first) / (last - first) * 554;
        const y = (value) => 226 - (value - min) / (max - min || 1) * 206;
        let grid = "";
        for (let i = 0; i < 5; i++) {
            const value = max - (max - min) * i / 4;
            grid += `<line x1="68" x2="622" y1="${y(value)}" y2="${y(value)}" stroke="var(--line)"/><text x="60" y="${y(value) + 4}" text-anchor="end" font-size="11" fill="var(--mute)">${value.toFixed(sensor.decimals)}</text>`;
        }
        const path = values.map((value, i) => `${i ? "L" : "M"}${x(i).toFixed(1)} ${y(value).toFixed(1)}`).join(" ");
        const points = values.map((value, i) => `<circle cx="${x(i)}" cy="${y(value)}" r="2" fill="#2F6B3A"><title>${escapeHtml(dateLabel(rows[i].recorded_at))}: ${value}</title></circle>`).join("");
        $("#chart").innerHTML = `${grid}<path d="${path}" fill="none" stroke="#2F6B3A" stroke-width="2"/>${points}<text x="68" y="252" font-size="10" fill="var(--mute)">${escapeHtml(dateLabel(rows[0].recorded_at))}</text><text x="622" y="252" text-anchor="end" font-size="10" fill="var(--mute)">${escapeHtml(dateLabel(rows.at(-1).recorded_at))}</text>`;
    }
    async function refresh() {
        if (refreshing) return;
        refreshing = true;
        try {
            snapshot = await api("dashboard");
            renderGauges(); renderDevices(); renderAlerts(); renderActuators();
            await loadHistory();
        } catch (error) {
            $("#upd").textContent = "Connection failed · displayed data may be stale";
            $("#device-status").textContent = "Connection unavailable";
            $("#device-status").classList.add("warn");
            $$("#act button").forEach((button) => { button.disabled = true; });
            message(error.message, true);
        } finally { refreshing = false; }
    }
    async function loadUsers() {
        if (!config.isAdmin) return;
        users = (await api("users")).users;
        $("#users").innerHTML = users.map((user) => `<tr><td>${escapeHtml(user.full_name)}</td><td>${escapeHtml(user.email)}</td><td>${escapeHtml(user.role)}</td><td>${escapeHtml(user.status)}</td><td class="table-actions">${user.status === "Pending" ? `<button class="text-btn" data-approve-user="${user.id}">Approve</button>` : ""}<button class="text-btn" data-edit-user="${user.id}">Edit</button>${Number(user.id) !== config.userId ? `<button class="text-btn danger-btn" data-delete-user="${user.id}">Delete</button>` : ""}</td></tr>`).join("");
    }
    function openUserDialog(id = null) {
        editingUser = id;
        const user = users.find((item) => Number(item.id) === id);
        $("#user-dialog-title").textContent = id === null ? "Add user" : "Edit user";
        $("#user-name").value = user?.full_name || "";
        $("#user-email").value = user?.email || "";
        $("#user-role").value = user?.role || "Farm Staff";
        $("#user-status").value = user?.status || "Active";
        $("#user-password").value = "";
        $("#user-password").required = id === null;
        $("#user-error").textContent = "";
        $("#user-dialog").showModal();
    }
    function showView(view) {
        activeView = view;
        const titles = { dash: "Greenhouse A", act: "Actuator control", alerts: "Alert log", admin: "Admin management", profile: "Profile", settings: "Settings", devices: "Devices", reports: "Reports / History", activity: "Activity log", operations: "Email & backups" };
        $("#title").textContent = titles[view];
        $$(".nav[data-v]").forEach((button) => {
            button.classList.toggle("on", button.dataset.v === view);
            if (button.dataset.v === view) button.setAttribute("aria-current", "page"); else button.removeAttribute("aria-current");
        });
        const secondary = { admin: "admin-panel", profile: "profile-panel", settings: "settings-panel", devices: "devices-panel", reports: "reports-panel", activity: "activity-panel", operations: "operations-panel" };
        $$(".dashboard-part").forEach((part) => part.classList.toggle("hide", Boolean(secondary[view])));
        Object.entries(secondary).forEach(([key, id]) => $(`#${id}`)?.classList.toggle("hide", view !== key));
        $(".demo-note").classList.toggle("hide", view !== "dash");
        if (!secondary[view]) {
            $("#gauges").closest("section").classList.toggle("hide", view !== "dash");
            const telemetry = $("#actuators").closest("section[aria-label]");
            telemetry.classList.toggle("hide", view === "alerts");
            telemetry.classList.toggle("actuators-only", view === "act");
            $("#chart-column").classList.toggle("hide", view === "act");
            $("#alert-log").classList.toggle("hide", view === "act");
        }
        if (view === "admin") action(null, loadUsers);
        if (view === "activity") window.greenPulseActivity?.();
        if (view === "operations") window.greenPulseOperations?.();
        if (view === "reports") window.greenPulseReport?.();
        $("#sidebar").classList.remove("open"); $("#mobile-menu").setAttribute("aria-expanded", "false");
    }

    $$(".nav[data-v], .text-btn[data-v]").forEach((button) => button.addEventListener("click", () => showView(button.dataset.v)));
    $("#mobile-menu").addEventListener("click", () => $("#mobile-menu").setAttribute("aria-expanded", String($("#sidebar").classList.toggle("open"))));
    $("#alert-filter").addEventListener("change", renderAlerts);
    $("#notification-bell").addEventListener("click", () => {
        $("#notification-popover").textContent = snapshot ? `${snapshot.unread_count} active alerts. Open the alert log to review them.` : "Loading alerts...";
        $("#notification-popover").classList.toggle("hide");
    });
    $("#alerts").addEventListener("click", (event) => {
        const button = event.target.closest("[data-alert]");
        if (button) action(button, async () => { await api("alerts/update", { id: Number(button.dataset.alert), status: button.dataset.status }); await refresh(); });
    });
    $("#act").addEventListener("click", (event) => {
        const button = event.target.closest("[data-toggle], [data-mode]");
        if (!button || !snapshot) return;
        const id = Number(button.dataset.toggle || button.dataset.actuator);
        const item = snapshot.actuators.find((actuator) => Number(actuator.id) === id);
        action(button, async () => {
            const result = await api("actuators/command", { id, mode: button.dataset.mode || "manual", on: button.dataset.toggle ? !Number(item.reported_on) : false });
            message(result.message); await refresh();
        });
    });
    $("#sensor-select").addEventListener("click", (event) => {
        const button = event.target.closest("[data-sensor]");
        if (!button) return;
        selectedSensor = { Temperature: "temperature", Humidity: "humidity", "pH level": "ph", TDS: "tds" }[button.dataset.sensor];
        $$("#sensor-select button").forEach((item) => item.classList.toggle("on", item === button)); renderChart();
    });
    $("#rng").addEventListener("click", (event) => {
        const button = event.target.closest("[data-h]");
        if (!button) return;
        selectedRange = Number(button.dataset.h);
        $("#chart-date-from").value = ""; $("#chart-date-to").value = "";
        $$("#rng button").forEach((item) => item.classList.toggle("on", item === button));
        action(null, loadHistory);
    });
    ["#chart-date-from", "#chart-date-to"].forEach((selector) => $(selector).addEventListener("change", () => {
        if ($("#chart-date-from").value && $("#chart-date-to").value) {
            $$("#rng button").forEach((item) => item.classList.remove("on")); action(null, loadHistory);
        }
    }));
    ["#export-chart", "#export-history"].forEach((selector) => $(selector).addEventListener("click", () => action(null, async () => {
        window.location.assign(`${config.baseUrl}/history/export?${rangeQuery()}`);
    })));
    $("#print-report").addEventListener("click", () => window.print());
    $("#add-user")?.addEventListener("click", () => openUserDialog());
    $("#cancel-user").addEventListener("click", () => $("#user-dialog").close());
    $("#user-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = event.submitter; button.disabled = true;
        try {
            await api("users/save", { ...(editingUser === null ? {} : { id: editingUser }), full_name: $("#user-name").value, email: $("#user-email").value, role: $("#user-role").value, status: $("#user-status").value, password: $("#user-password").value });
            $("#user-dialog").close();
            if (editingUser === config.userId) { window.location.reload(); return; }
            await loadUsers(); message("Account saved.");
        } catch (error) { $("#user-error").textContent = error.message; }
        finally { button.disabled = false; }
    });
    $("#users")?.addEventListener("click", (event) => {
        const approve = event.target.closest("[data-approve-user]");
        if (approve) action(approve, async () => {
            const user = users.find((item) => Number(item.id) === Number(approve.dataset.approveUser));
            await api("users/save", { id: Number(user.id), full_name: user.full_name, email: user.email, role: user.role, status: "Active" });
            await loadUsers(); message("Account approved. The user can now sign in.");
        });
        const edit = event.target.closest("[data-edit-user]"), remove = event.target.closest("[data-delete-user]");
        if (edit) openUserDialog(Number(edit.dataset.editUser));
        if (remove && window.confirm("Delete this account? This cannot be undone.")) action(remove, async () => { await api("users/delete", { id: Number(remove.dataset.deleteUser) }); await loadUsers(); });
    });
    $("#rotate-device-key")?.addEventListener("click", (event) => {
        if (!window.confirm("Generate a new device key? Any previous key will stop working.")) return;
        action(event.currentTarget, async () => {
            const result = await api("devices/key", { id: 1 });
            $("#device-key").textContent = result.api_key;
            await refresh();
        });
    });
    window.greenPulseApi = api;
    window.greenPulseMessage = message;
    showView("dash"); refresh();
    setInterval(() => { if (!document.hidden) refresh(); }, 15000);
})();
