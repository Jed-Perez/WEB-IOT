# GreenPulse

PHP MVC greenhouse monitoring for XAMPP, PHP 8.1+, and MariaDB 10.4+. The browser dashboard now reads and writes MySQL data instead of generating sample values.

## Run locally

1. Start Apache and MySQL in XAMPP.
2. Optionally copy `.env.example` to `.env` and change database settings. Defaults are `127.0.0.1:3306`, user `root`, an empty password, and database `login_system`. Environment variables take precedence over `.env`.
3. Install dependencies and create the database from the project directory:

   ```powershell
   composer install
   C:\xampp\php\php.exe scripts/setup.php
   ```

   If Composer reports a missing ZIP extension, enable it in your PHP configuration or invoke Composer with PHP's `-d extension=zip` option.
4. Open **http://localhost/SEMPROJ-WEBDEV-IOT/register**. The first registered account becomes an active Administrator. Subsequent public registrations become Pending Farm Staff and cannot sign in until approved under **Admin management**. No default password or demo accounts are created. Existing accounts retain access when upgrading.
5. Sign in. In **Devices**, generate a device key and copy it into your device's configuration. Use the API described in [docs/API.md](docs/API.md) to send readings.

The local database and installed dependencies have been prepared in this workspace. The dashboard starts empty until a device sends telemetry.

`setup.php` creates the database and applies `login_system.sql`, `database/greenhouse.sql`, and `database/operations.sql`. Rerunning it preserves accounts, readings, and customized thresholds. For a manual phpMyAdmin setup, import these files in that order, selecting `login_system` after the first import. Existing schemas without a role column receive Farm Staff roles; an operator must explicitly assign an administrator to such an older database. The security upgrade requires previously signed-in users to sign in again.

## Implemented features

- Registration, password-hashed login, sessions, CSRF protection, and logout via POST.
- Optional **Remember me for 30 days** on sign-in. A random, HttpOnly cookie restores the session after the browser closes; only its hash is stored in the database. Tokens rotate on restoration and expire 30 days after sign-in. Signing out revokes this browser's token; password changes/resets revoke all remembered tokens for the account.
- Administrator approval, rejection, suspension, creation, editing, role changes, and deletion. Self-deletion and removing the last active administrator are rejected.
- Login/reset rate limits, password confirmation for sensitive actions, and **Sign out all devices** under Profile. Password changes and suspension invalidate existing sessions as well as remembered logins.
- Temperature, humidity, pH, and TDS ingestion authenticated by a per-device bearer key. Only its SHA-256 hash is stored; key replacement revokes the previous key.
- Latest readings, minimum/maximum values over the last 24 hours, timestamped history, date filtering, and CSV exports. Times are stored in UTC and displayed in the browser's local timezone.
- Threshold alerts with persistent acknowledgment/resolution. Repeated out-of-range samples update the existing open alert; a return to the ideal range resolves it.
- Persistent actuator command queue with expiry, device acknowledgment, reported state, and cooldown checks. The UI distinguishes queued commands from confirmed relay states.
- Device last-contact status and dashboard refresh every 15 seconds. A device or actuator report older than two minutes is considered offline or unknown.
- Delayed email alerts for prolonged abnormal readings, offline devices, and expired/unacknowledged commands, with bounded reminders, retries, and an hourly recipient cap.
- Administrator **Activity log** with actor, timestamp, and before/after values; **Email & backups** shows maintenance and delivery status.
- Daily averages, time outside the historical ideal range, estimated actuator runtime, and unknown data coverage, with Manila calendar dates and CSV export.
- Scheduled daily database backups, automatic restoration and row-hash verification, retention, and recovery into a new database. See [operations and recovery](docs/OPERATIONS.md).

This is a shared farm: approved, active staff can see its data, acknowledge alerts, and queue actuator commands. Administrators also manage users, thresholds, and device keys. Sensitive administrator changes require the current password, with confirmation valid for five minutes. The dashboard currently displays the seeded Greenhouse A controller with a cooling fan and water pump.

History displays the latest 1,000 readings in the selected range; the report table displays the latest 100 of those. CSV exports include all readings in that range. Ranges are limited to 31 days. The alert log shows the latest 200 alerts; its badge counts all active alerts.

## Database

| Table | Purpose |
| --- | --- |
| `users` | Accounts, password hashes, and roles |
| `remember_tokens` | Hashed persistent sign-in tokens with a 30-day expiry |
| `password_resets` | Hashed reset codes, expiry, attempts, and verification |
| `sensor_thresholds` | Configurable ideal ranges and gauge scales |
| `devices` | Registered controller, key hash, and last contact |
| `telemetry` | Timestamped sensor samples with a device/time index |
| `alerts` | Sensor threshold events, status, and acting user |
| `actuators` | Reported relay states, modes, and timing limits |
| `actuator_commands` | Requested actions, requester, expiry, and acknowledgments |
| `rate_limits` | Persistent attempt counters and reset times |
| `activity_log` | Actor and before/after change history, without credentials |
| `telemetry_ranges` | Ideal range and out-of-range status when each sample arrived |
| `actuator_state_history` | Device-reported relay states for runtime estimates |
| `notification_incidents` | Alert episodes, delays, and reminder counts |
| `notification_deliveries` | Recipient delivery state, retries, and errors |
| `maintenance_runs` | Most recent notification/backup worker results |

Relationships use foreign keys. Device identifiers come from the authenticated key for device APIs, preventing one device from reporting another device's state. Application queries use prepared statements.

## Email and hardware setup

Configure the `SMTP_*` values in `.env` to enable password reset delivery. PHPMailer is installed by Composer. Reset codes expire after ten minutes, allow five attempts, and cannot be reused. SMTP delivery requires your own working mail credentials; it is not exercised by the integration tests. A previously committed example included an SMTP password; it has been removed from the template, and that credential should be revoked with the mail provider.

ESP32 firmware and physical relay wiring are not included. The HTTP API is ready to integrate; there is no MQTT broker or server-side automation daemon. Firmware must poll commands, apply the thresholds in auto mode, enforce maximum manual run time and cooldown locally, stop safely on connection loss, and report the resulting state. A queued command alone never proves that hardware moved. See [the device contract](docs/API.md#device-api).

For access beyond local development, use HTTPS and a dedicated database account. The app limits login, registration, reset, and password-confirmation attempts; configure web-server limits for telemetry and overall traffic as needed. Register the initial administrator before exposing the site to other users. `.htaccess` denies access to configuration, source directories, SQL files, backups, dependencies, and tests; Apache needs `mod_rewrite` and `AllowOverride All`. Bootstrap and fonts require internet access.

## Development server

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8081 -t public app/tunnel-router.php
```

Open `http://127.0.0.1:8081/SEMPROJ-WEBDEV-IOT/`. The router derives the folder name automatically and serves only allowed public assets and application routes.

## Verification

```powershell
C:\xampp\php\php.exe tests/integration.php
node --check public/js/dashboard.js
node --check public/js/operations.js
```

The PHP integration suite starts a temporary HTTP server and creates a randomly named `greenpulse_test_*` database, then deletes that test database and stops its server. It requires database creation/deletion privileges and access to PHP's session directory. It covers authentication, approval, session revocation, rate limits, password confirmation, CSRF, roles, user CRUD, last-admin protection, schema reapplication, telemetry, alerts, history/CSV, reports and gaps, auditing, device commands, remembered logins, and reset codes. Notifications use an injected test transport and send no external email. Backups are restored into disposable databases and compared by row hashes; recovery tests also verify that an existing database cannot be overwritten. Application records are not used for testing.
