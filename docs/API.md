# GreenPulse API

Base URL on this XAMPP installation: `http://localhost/SEMPROJ-WEBDEV-IOT/api`.

Requests with a body use `Content-Type: application/json`. Errors return an HTTP status and `{"error":"message"}`. IDs are positive integers. Successful writes return JSON; a new resource/command uses status 201. API responses are not cached.

## Browser API

Sign in through `/login`. Browser requests use the PHP session cookie; POST requests also require `X-CSRF-Token`, available to the dashboard as `window.greenPulse.csrf`. Device tokens cannot access these endpoints.

| Method / path | Access | Input / result |
| --- | --- | --- |
| `GET /dashboard` | Staff | Snapshot: sensors, latest timestamp, device, actuators, latest 200 alerts, total active alerts. Optional `device_id`, default 1. |
| `GET /history` | Staff | Optional `device_id`, `from`, `to`; chronological `rows` and a `truncated` flag. Latest 1,000 samples. |
| `GET /history/export` | Staff | Same filters; downloads all matching samples as CSV. |
| `POST /alerts/update` | Staff | `{"id":1,"status":"Acknowledged"}` or `Resolved`. Resolved alerts cannot be reopened. |
| `POST /actuators/command` | Staff | `{"id":1,"mode":"manual","on":true}`; queues a command and returns its `id`. |
| `GET /users` | Administrator | Returns `users` with id, full_name, email, role. Never returns passwords. |
| `POST /users/save` | Administrator | `full_name`, `email`, `role`, `password`; include `id` to edit. Password required for creation; omit/leave blank to retain an existing password. |
| `POST /users/delete` | Administrator | `{"id":2}`. Own-account deletion is rejected. |
| `POST /devices/key` | Administrator | `{"id":1}`; returns a new `api_key` once and invalidates the previous key. |

Roles are exactly `Administrator` and `Farm Staff`. Names are limited to 100 characters, emails to 150 characters, and passwords to 8–72 bytes. Duplicate emails and removing the final administrator are rejected. A changed role or deleted account takes effect on its next API request.

History dates use UTC ISO 8601, such as `2026-10-30T00:00:00Z`. The start is inclusive, the end exclusive. The maximum interval is 31 days; defaults cover the preceding 24 hours. The browser converts local date selections to UTC. Sensor ideal ranges in the UI are the current configured ranges, not a historical threshold audit.

Threshold changes remain a form POST to `/dashboard/thresholds`, with `csrf_token` and `{temperature,humidity,ph,tds}_{min,max}` fields, restricted to administrators. Each minimum must be below the maximum and inside the gauge scale. Changes apply to subsequent telemetry and are returned to the device on its next poll.

## Device API

Generate a key through the administrator's **Devices** panel. Send it on every device request:

```http
Authorization: Bearer YOUR_64_CHARACTER_DEVICE_KEY
```

The key identifies the device; no client-supplied device ID is accepted for telemetry. Device endpoints do not need a browser session or CSRF token. Use HTTPS whenever the server is accessed beyond local development.

### 1. Send readings

`POST /device/telemetry`

```json
{"temperature":23.4,"humidity":64,"ph":6.1,"tds":720}
```

All four fields are required JSON numbers. Accepted physical ranges are temperature -40–85 C, humidity 0–100%, pH 0–14, and TDS 0–10000 ppm. These validation ranges are separate from configured ideal ranges. Invalid samples are rejected without inserting telemetry or alerts.

The server assigns the UTC receipt timestamp and returns `{"id":123}` with HTTP 201. Each accepted request creates a sample; telemetry retries are not deduplicated. Start with one sample every 15–30 seconds. Valid samples update last-contact time and evaluate threshold alerts in the same database transaction.

Example in PowerShell (replace the key with the one shown in Devices):

```powershell
$deviceHeaders = @{ Authorization = 'Bearer YOUR_64_CHARACTER_DEVICE_KEY' }
$sample = @{ temperature = 23.4; humidity = 64; ph = 6.1; tds = 720 } | ConvertTo-Json
Invoke-RestMethod -Method Post `
  -Uri 'http://localhost/SEMPROJ-WEBDEV-IOT/api/device/telemetry' `
  -Headers $deviceHeaders -ContentType 'application/json' -Body $sample
```

An ESP32 must use the computer's LAN address instead of `localhost`.

### 2. Poll commands

`GET /device/commands`

Returns `commands`, current `thresholds`, and `server_time` (UTC). Poll every few seconds. The default cooling fan has actuator ID 1, a 120-second maximum manual run, and a 15-second cooldown; the water pump has ID 2, a 60-second maximum run, and a 30-second cooldown.

```json
{
  "commands": [{
    "id": 42,
    "actuator_id": 1,
    "name": "Cooling fan",
    "mode": "manual",
    "desired_on": 1,
    "duration_seconds": 120,
    "cooldown_seconds": 15,
    "expires_at": "2026-10-30 04:01:00"
  }],
  "thresholds": [],
  "server_time": "2026-10-30T04:00:01Z"
}
```

The example omits threshold entries for brevity. Database integer fields can be returned as numbers or numeric strings; parse them numerically. SQL-style timestamps such as `expires_at` are UTC.

Commands expire 60 seconds after creation. Only one unexpired pending command per actuator is allowed; conflicts return 409. Pending commands are returned repeatedly until acknowledged or expired. Firmware must remember processed command IDs so a retry cannot restart a relay timer. An `auto` command has `desired_on=0` and `duration_seconds=0`: it switches to device-side automation, not necessarily an OFF relay state.

### 3. Apply and acknowledge

`POST /device/commands/ack`

```json
{"id":42,"on":true}
```

`id` is the **command ID**, not the actuator ID. `on` must be a JSON boolean. A manual acknowledgment must match its requested state; in auto mode it reports the resulting automatic state. Commands for a different device return 404. Expired commands return 409 and must not be executed. Repeating a successful acknowledgment is safe and does not reset timestamps or cooldowns.

The API only records acknowledgment after the device sends it; creating or polling a command does not alter the displayed reported relay state.

### 4. Report state changes and heartbeat

`POST /device/state`

```json
{"id":1,"mode":"auto","on":false}
```

Here `id` is the **actuator ID**. Report each relay's state periodically (for example every 30 seconds), after automatic changes, and after the manual timer expires. This updates actual state without creating or acknowledging a command. A transition to OFF starts the cooldown. A manual ON acknowledgment reserves its maximum run plus cooldown, rejecting further ON commands during that window; OFF commands remain possible when there is no pending command.

Firmware is responsible for the physical safety timer, cooldown, auto-mode behavior, and stopping on loss of connectivity. The web server cannot stop a disconnected relay. After the maximum manual duration, firmware must stop, return to auto mode, respect cooldown, and report the result. There is no background server task that runs hardware rules. Device online status means recent API contact, while each relay has its own reported-state freshness.

## Status codes

| Status | Meaning |
| --- | --- |
| 400 | Invalid JSON |
| 401 | Missing/invalid device key or signed-out browser |
| 403 | Insufficient role or invalid CSRF token |
| 404 | Unknown route/resource or resource belonging to another device |
| 405 | Unsupported HTTP method |
| 409 | Duplicate email, protected account action, cooldown, pending/expired command, or resolved alert |
| 413 | JSON body exceeds 16 KiB |
| 415 | Body is not application/json |
| 422 | Invalid field or date range |
| 500 | Server/database error; details go to server logs |
