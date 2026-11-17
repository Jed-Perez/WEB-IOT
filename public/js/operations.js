(() => {
    "use strict";
    const $ = (selector) => document.querySelector(selector);
    const api = window.greenPulseApi;
    const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
    const date = (value) => value ? new Date(value.replace(" ", "T") + "Z").toLocaleString() : "--";
    const run = async (work) => { try { await work(); } catch (error) { window.greenPulseMessage(error.message, true); } };
    let confirmation = null, resolveConfirmation = null, rejectConfirmation = null;
    window.greenPulseConfirm = () => {
        if (confirmation) return confirmation;
        $("#confirm-password-input").value = "";
        $("#confirm-password-error").textContent = "";
        confirmation = new Promise((resolve, reject) => { resolveConfirmation = resolve; rejectConfirmation = reject; });
        $("#confirm-password-dialog").showModal();
        $("#confirm-password-input").focus();
        return confirmation;
    };
    function finishConfirmation(ok) {
        if (!confirmation) return;
        const resolve = resolveConfirmation, reject = rejectConfirmation;
        confirmation = null; resolveConfirmation = null; rejectConfirmation = null;
        $("#confirm-password-input").value = "";
        $("#confirm-password-dialog").close();
        if (ok) resolve(); else reject(new Error("Password confirmation cancelled."));
    }
    $("#cancel-confirm-password").addEventListener("click", () => finishConfirmation(false));
    $("#confirm-password-dialog").addEventListener("cancel", (event) => { event.preventDefault(); finishConfirmation(false); });
    $("#confirm-password-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = event.submitter; button.disabled = true;
        try {
            await api("security/confirm", { password: $("#confirm-password-input").value });
            finishConfirmation(true);
        } catch (error) { $("#confirm-password-error").textContent = error.message; }
        finally { button.disabled = false; }
    });
    $(".threshold-form")?.addEventListener("submit", (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        run(async () => { await window.greenPulseConfirm(); form.submit(); });
    });
    $("#logout-all").addEventListener("click", () => run(async () => {
        const result = await api("security/logout-all", {});
        window.location.assign(result.redirect);
    }));

    let activityNext = null;
    const activityRows = (rows) => rows.map((item) => `<tr><td>${escape(date(item.created_at))}</td><td>${escape(item.actor_name)}</td><td>${escape(item.action)}</td><td>${escape(item.entity_type)} #${escape(item.entity_id)}</td><td><details><summary>View changes</summary><strong>Before</strong><pre>${escape(JSON.stringify(JSON.parse(item.before_json || "null"), null, 2))}</pre><strong>After</strong><pre>${escape(JSON.stringify(JSON.parse(item.after_json || "null"), null, 2))}</pre></details></td></tr>`).join("");
    async function loadActivity(append = false) {
        const result = await api(`activity${append && activityNext ? `?before=${activityNext}` : ""}`);
        if (append) $("#activity-rows").insertAdjacentHTML("beforeend", activityRows(result.rows));
        else $("#activity-rows").innerHTML = activityRows(result.rows) || '<tr><td colspan="5">No activity recorded yet.</td></tr>';
        activityNext = result.next;
        $("#activity-more").disabled = !activityNext;
    }
    window.greenPulseActivity = () => run(() => loadActivity());
    $("#activity-more")?.addEventListener("click", (event) => run(async () => {
        event.target.disabled = true;
        try { await loadActivity(true); } finally { event.target.disabled = !activityNext; }
    }));
    window.greenPulseOperations = () => run(async () => {
        const result = await api("operations");
        $("#maintenance-status").innerHTML = result.runs.map((job) => `<p><strong>${escape(job.job)}: ${escape(job.status)}</strong><br>${escape(date(job.last_run_at))} · ${escape(job.detail)}</p>`).join("") || '<p>No maintenance runs recorded yet.</p>';
        $("#delivery-rows").innerHTML = result.deliveries.map((item) => `<tr><td>${escape(item.recipient)}</td><td>${escape(item.kind)}: ${escape(item.message)}</td><td>${escape(item.status)}${item.last_error ? `<br>${escape(item.last_error)}` : ""}</td><td>${Number(item.attempts)}</td><td>${escape(date(item.sent_at))}</td></tr>`).join("") || '<tr><td colspan="5">No email deliveries queued.</td></tr>';
    });
    $("#refresh-operations")?.addEventListener("click", window.greenPulseOperations);

    const manilaDate = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit" });
    $("#report-to").value = manilaDate.format(new Date());
    $("#report-from").value = manilaDate.format(new Date(Date.now() - 6 * 86400000));
    function reportQuery() {
        const from = $("#report-from").value, to = $("#report-to").value;
        if (!from || !to) throw new Error("Choose both report dates.");
        const start = new Date(`${from}T00:00:00+08:00`);
        const end = new Date(new Date(`${to}T00:00:00+08:00`).getTime() + 86400000);
        if (end <= start || end - start > 31 * 86400000) throw new Error("Choose up to 31 days, with the end on or after the start.");
        const iso = (value) => value.toISOString().replace(/\.\d{3}Z$/, "Z");
        return new URLSearchParams({ from: iso(start), to: iso(end) }).toString();
    }
    const duration = (seconds) => `${(Number(seconds) / 60).toFixed(1)} min`;
    const numeric = (value) => value === null ? "--" : Number(value).toFixed(2);
    const sensorNames = { temperature: "Temperature (°C)", humidity: "Humidity (%)", ph: "pH", tds: "TDS (ppm)" };
    let reportRequest = 0;
    async function loadReport() {
        const request = ++reportRequest;
        $("#daily-sensors").innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
        $("#daily-runtime").innerHTML = "";
        try {
            const result = await api(`reports?${reportQuery()}`);
            if (request !== reportRequest) return;
            $("#summary-method").textContent = `${result.method} Dates use ${result.timezone}.`;
            $("#daily-sensors").innerHTML = result.days.flatMap((day) => Object.entries(day.sensors).map(([key, item]) => `<tr><td>${escape(day.date)}</td><td>${escape(sensorNames[key] || key)}</td><td>${item.count}</td><td>${numeric(item.average)}</td><td>${numeric(item.min)} / ${numeric(item.max)}</td><td>${duration(item.outside_seconds)}</td><td>${duration(item.unknown_seconds)}</td></tr>`)).join("") || '<tr><td colspan="7">No elapsed time in the selected date range.</td></tr>';
            $("#daily-runtime").innerHTML = result.days.flatMap((day) => Object.values(day.actuators).map((item) => `<tr><td>${escape(day.date)}</td><td>${escape(item.name)}</td><td>${duration(item.on_seconds)}</td><td>${duration(item.unknown_seconds)}</td></tr>`)).join("");
        } catch (error) {
            if (request === reportRequest) $("#daily-sensors").innerHTML = '<tr><td colspan="7">Report unavailable for this range.</td></tr>';
            throw error;
        }
    }
    window.greenPulseReport = () => run(loadReport);
    $("#summary-form").addEventListener("submit", (event) => { event.preventDefault(); window.greenPulseReport(); });
    $("#export-summary").addEventListener("click", () => run(async () => window.location.assign(`${window.greenPulse.baseUrl}/reports?${reportQuery()}&format=csv`)));
})();
