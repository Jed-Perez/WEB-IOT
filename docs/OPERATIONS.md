# Account approval, notifications, reports, and recovery

Run `C:\xampp\php\php.exe scripts/setup.php` when upgrading. Existing records are preserved. Existing accounts stay Active; new public registrations after the first administrator start Pending. The security upgrade invalidates old sessions once.

## Approval and security

In **Admin management**, use **Approve** for pending requests, or **Edit** to choose Active, Pending, Suspended, or Rejected. Only Active users can sign in or use the browser API. Suspending/rejecting an account, changing its role/password, and deleting it invalidate its access. The final active administrator cannot be removed or disabled.

The first account in a completely empty database remains an active administrator so the installation can be initialized. Administrator-created accounts default to Active. Staff cannot approve themselves or access management APIs.

**Profile → Sign out all devices** revokes all existing sessions and remembered tokens, including the current browser. Password resets and administrator password changes do the same. Session versions are checked on each application request; a revoked session cannot continue polling or controlling equipment.

User creation/editing/deletion/approval, threshold changes, device key replacement, and sign-out-all require current-password confirmation. A successful confirmation lasts five minutes in that session. Remembered sign-in does not count as password confirmation.

| Action | Limit |
| --- | --- |
| Login | 5 attempts per email per 15 minutes; 50 attempts per source IP per 15 minutes |
| Password reset email | 3 requests per email per hour; 20 per source IP per hour |
| Reset code verification | 5 attempts per email per 15 minutes; 30 per IP, plus the existing 5-attempt code limit |
| Registration | 10 attempts per source IP per hour |
| Password confirmation | 5 attempts per account per 15 minutes |

Successful login clears that email's login counter; successful confirmation clears its confirmation counter. Exceeding a limit returns HTTP 429 with `Retry-After`. Limits live in MySQL, so new cookies do not bypass them. Source IP uses the actual connection address, not client-supplied forwarding headers. Known and unknown password-reset emails receive the same public message, including when SMTP is unavailable.

## Email notifications

Copy `.env.example` to `.env` and supply your own `SMTP_*` settings. With `ALERT_EMAILS` empty, notifications go to Active Administrator accounts. To override recipients, set a comma-separated list of email addresses. No SMTP credentials are bundled.

The notification worker is independent of dashboard visits:

```powershell
C:\xampp\php\php.exe scripts/notifications.php
```

| Setting | Default | Meaning |
| --- | --- | --- |
| `NOTIFICATIONS_ENABLED` | `true` | Enables delivery when SMTP and recipients are configured |
| `ALERT_EMAILS` | empty | Active administrators, or an explicit comma-separated recipient list |
| `ALERT_DELAY_SECONDS` | 300 | Sustained abnormal-reading delay |
| `OFFLINE_DELAY_SECONDS` | 300 | Time without device contact before email |
| `COMMAND_ALERT_DELAY_SECONDS` | 60 | Additional delay after command expiry |
| `ALERT_REPEAT_SECONDS` | 3600 | Minimum interval between reminders for one incident |
| `ALERT_MAX_EMAILS` | 3 | Maximum email rounds per incident and recipient |
| `ALERT_HOURLY_LIMIT` | 10 | Maximum successfully sent notification emails per recipient per hour across incidents |

The worker tracks current threshold alerts, configured devices without contact, and commands that expired without acknowledgment in the last 24 hours. Sensors must keep reporting within two minutes to count as a sustained abnormal condition. Stale samples stop abnormal notifications and allow the device-offline check to take over. Recovery closes the incident and cancels unsent messages. A future recurrence starts a new episode and delay.

Acknowledging a threshold alert records that staff saw it; it does not stop notifications while the abnormal condition continues. Resolving it or receiving an in-range reading ends the current alert. If the next reading is still abnormal, a new alert starts with a fresh delay.

Delivery failures retry after 5 then 10 minutes, stopping after 3 attempts. A per-recipient delivery record prevents ordinary worker reruns from duplicating mail. As with most SMTP queues, a worker crash after SMTP acceptance but before saving success can cause a duplicate on retry. The hourly cap defers queued mail and applies separately from password-reset email limits.

When SMTP, recipients, or notification enablement is missing, the worker records `waiting` without using up retry attempts. Check **Email & backups** for worker status and delivery errors. Local SMTP settings are stored in the ignored `.env` file; real delivery has not been tested.

## Scheduled tasks on this Windows computer

Install the tasks with:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/install-schedules.ps1
```

The installer creates:

- `GreenPulse-SEMPROJ-WEBDEV-IOT-Notifications`: every minute.
- `GreenPulse-SEMPROJ-WEBDEV-IOT-Backup`: daily at **02:00 Asia/Manila (UTC+08:00)**.

These tasks have been installed for the current Windows account. They run in hidden windows while that account is signed in, with MySQL running. They do not wake a powered-off computer. Missed runs can start when the task becomes available. For unattended operation after sign-out, an operator must configure a suitable service account in Task Scheduler; no Windows account password is stored by the project.

Verification on October 30, 2026 found the backup task enabled, with its next run at 02:00 Manila time on October 31. The notification task is currently disabled; its last recorded failure was caused by MySQL being stopped. MySQL has been restarted. Enable the notification task in Task Scheduler when ready to send live alert emails. The installer preserves the enabled/disabled state of existing tasks.

The installer is repeatable and refuses to replace a task whose action differs. Worker overlap is blocked by database locks and Task Scheduler's IgnoreNew policy. Logs are stored in `storage/logs/`, with a previous log retained after 1 MiB. The dashboard shows the latest database-recorded maintenance result.

## Activity log

Administrators can view timestamped user changes/approval/deletion, threshold changes, device key replacement, actuator requests and device-confirmed changes, alert status updates, password resets, and sign-out-all events. Older records load in pages of 50.

The log stores actor name/ID and previous/new values in the same transaction as the change. It stores boolean indications of password/key replacement, never the credentials or their hashes. Actor names remain after account deletion. There is no UI/API for editing the log; a database operator with sufficient privileges can still modify it.

## Report interpretation

**Reports / History** has a separate date range for daily summaries, limited to 31 days. Days are split using **Asia/Manila** calendar boundaries. It shows sample averages/minima/maxima, time outside ideal ranges, estimated relay ON time, and unknown coverage. CSV and browser print/PDF are available.

Each new sample records the ideal range in effect when it arrived. Later threshold changes do not rewrite historical classifications. Older readings from before this upgrade still contribute to sample averages, but their threshold coverage is unknown. Relay history begins with device reports received after the upgrade.

Durations are estimates: a sample or relay state is held until the next report, up to **120 seconds**. Longer gaps are unknown, not automatically normal or OFF. This prevents an offline device from appearing to run indefinitely. Averages are sample-weighted, not time-weighted. Physical runtime accuracy depends on frequent, honest firmware reports.

## Backups and recovery

Manual backup:

```powershell
C:\xampp\php\php.exe scripts/backup.php
```

Archives are compressed JSON-lines files in `storage/backups/`, with separate SHA-256 checksums. They include table definitions and data, including sensitive account/token hashes. The folder is ignored by Git and denied by Apache. Keep any copied archives private.

Each backup reads a consistent InnoDB snapshot, restores into a randomly named `greenpulse_restore_*` database, verifies row counts and deterministic row hashes for every table, then deletes only that temporary database. Only a successful verification marks the backup job successful and triggers retention. `BACKUP_KEEP=14` retains the most recent 14 archives; manual successful backups also count toward retention. Avoid running schema migrations during a backup.

Retest a trusted archive without changing the application database:

```powershell
C:\xampp\php\php.exe scripts/restore-test.php storage/backups/greenpulse-YYYYMMDD-HHMMSS-ID.jsonl.gz
```

Recover into a new database:

```powershell
C:\xampp\php\php.exe scripts/restore.php storage/backups/greenpulse-YYYYMMDD-HHMMSS-ID.jsonl.gz greenpulse_recovered_october
```

The target must start with `greenpulse_recovered_` and must not already exist. Recovery verifies the data, invalidates old browser sessions, remembered tokens, and reset codes, and retains the recovered copy. It does **not** overwrite or switch the application's current database. After inspecting the copy, set `DB_DATABASE=greenpulse_recovered_october` in `.env` to use it. The scheduled workers use the same database configuration.

Restoration requires a database account that can create and drop temporary databases. Archives are trusted local operator inputs, not uploads; checksums detect corruption, not malicious replacement of both files. Local backups protect against bad changes but do not survive loss of the same disk—copy verified archives to separate storage for that case.
