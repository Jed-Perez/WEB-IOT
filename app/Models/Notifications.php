<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\{Database as DB, Mailer};

final class Notifications
{
    public function run(?callable $send = null): array
    {
        $config = require dirname(__DIR__, 2) . '/config/operations.php';
        $lock = 'gp-notifications-' . DB::query('SELECT DATABASE() db')->get_result()->fetch_assoc()['db'];
        if (!(int) DB::query('SELECT GET_LOCK(?, 0) acquired', [$lock])->get_result()->fetch_assoc()['acquired']) return ['status' => 'busy', 'sent' => 0];
        try {
            $this->detect($config);
            DB::query('DELETE FROM rate_limits WHERE resets_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
            DB::query('DELETE FROM remember_tokens WHERE expires_at <= UTC_TIMESTAMP()');
            $recipients = $config['recipients'] ?: array_column(DB::query("SELECT email FROM users WHERE role = 'Administrator' AND status = 'Active'")->get_result()->fetch_all(MYSQLI_ASSOC), 'email');
            $recipients = array_values(array_unique(array_filter($recipients, fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
            if (!$config['notifications_enabled'] || (!$send && !Mailer::configured()) || !$recipients) {
                return $this->result('waiting', 0, 'Email delivery is waiting for SMTP configuration, enabled notifications, and an active recipient.');
            }
            foreach (DB::query('SELECT * FROM notification_incidents WHERE resolved_at IS NULL')->get_result()->fetch_all(MYSQLI_ASSOC) as $incident) {
                $delay = match ($incident['kind']) { 'abnormal' => $config['abnormal_delay'], 'offline' => $config['offline_delay'], default => $config['command_delay'] };
                if (time() - strtotime($incident['started_at']) < $delay || (int) $incident['send_count'] >= $config['max_emails']) continue;
                if ($incident['last_queued_at'] && time() - strtotime($incident['last_queued_at']) < $config['repeat_seconds']) continue;
                Greenhouse::transaction(function () use ($incident, $recipients) {
                    $repeat = (int) $incident['send_count'] + 1;
                    foreach ($recipients as $email) DB::query('INSERT IGNORE INTO notification_deliveries (incident_id, recipient, repeat_number, generation, next_attempt_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$incident['id'], $email, $repeat, $incident['generation']]);
                    DB::query('UPDATE notification_incidents SET send_count = ?, last_queued_at = UTC_TIMESTAMP() WHERE id = ?', [$repeat, $incident['id']]);
                });
            }
            $sent = 0; $failed = 0;
            // A process dying after SMTP acceptance can cause one duplicate on retry.
            // Persist per-recipient delivery and bound both retries and repeats.
            $pending = DB::query("SELECT d.*, i.message, i.kind FROM notification_deliveries d JOIN notification_incidents i ON i.id = d.incident_id WHERE d.status = 'pending' AND d.next_attempt_at <= UTC_TIMESTAMP() AND i.resolved_at IS NULL AND d.generation = i.generation ORDER BY d.id LIMIT 25")->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($pending as $delivery) {
                if (!in_array($delivery['recipient'], $recipients, true)) {
                    DB::query("UPDATE notification_deliveries SET status = 'cancelled' WHERE id = ?", [$delivery['id']]);
                    continue;
                }
                $recent = (int) DB::query("SELECT COUNT(*) total FROM notification_deliveries WHERE recipient = ? AND sent_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)", [$delivery['recipient']])->get_result()->fetch_assoc()['total'];
                if ($recent >= $config['hourly_limit']) {
                    DB::query('UPDATE notification_deliveries SET next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = ?', [$delivery['id']]);
                    continue;
                }
                try {
                    ($send ?? [new Mailer(), 'sendNotification'])($delivery['recipient'], 'GreenPulse: ' . $delivery['kind'], $delivery['message']);
                    DB::query("UPDATE notification_deliveries SET status = 'sent', attempts = attempts + 1, sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?", [$delivery['id']]);
                    $sent++;
                } catch (\Throwable $error) {
                    error_log('GreenPulse notification delivery failed: ' . $error->getMessage());
                    $attempts = (int) $delivery['attempts'] + 1;
                    DB::query('UPDATE notification_deliveries SET status = ?, attempts = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), last_error = ? WHERE id = ?',
                        [$attempts >= 3 ? 'failed' : 'pending', $attempts, 300 * $attempts, 'SMTP delivery failed. Check mail configuration and server logs.', $delivery['id']]);
                    $failed++;
                }
            }
            return $this->result($failed ? 'warning' : 'ok', $sent, "$sent emails sent; $failed delivery attempts failed.");
        } finally { DB::query('SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    private function detect(array $config): void
    {
        $conditions = [];
        $alerts = DB::query("SELECT a.*, d.name FROM alerts a JOIN devices d ON d.id = a.device_id WHERE a.status <> 'Resolved' AND a.updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)", [$config['fresh_seconds']])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($alerts as $alert) $conditions['alert:' . $alert['id']] = ['abnormal', $alert['device_id'], $alert['name'] . ': ' . $alert['message'], $alert['created_at']];
        $devices = DB::query('SELECT * FROM devices WHERE api_key_hash IS NOT NULL')->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($devices as $device) {
            $last = $device['last_seen_at'] ?? $device['created_at'];
            if (time() - strtotime($last) >= $config['fresh_seconds']) $conditions['offline:' . $device['id']] = ['offline', $device['id'], $device['name'] . ' has not contacted GreenPulse since ' . $last . ' UTC.', $last];
        }
        DB::query("UPDATE actuator_commands SET status = 'expired' WHERE status = 'pending' AND expires_at <= UTC_TIMESTAMP()");
        $commands = DB::query("SELECT c.*, a.device_id, a.name FROM actuator_commands c JOIN actuators a ON a.id = c.actuator_id WHERE c.status = 'expired' AND c.expires_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)")->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($commands as $command) $conditions['command:' . $command['id']] = ['command', $command['device_id'], 'Command #' . $command['id'] . ' for ' . $command['name'] . ' expired without a device acknowledgment.', $command['expires_at']];
        Greenhouse::transaction(function () use ($conditions) {
            $existing = DB::query('SELECT * FROM notification_incidents FOR UPDATE')->get_result()->fetch_all(MYSQLI_ASSOC);
            $byKey = array_column($existing, null, 'incident_key');
            foreach ($conditions as $key => [$kind, $device, $message, $start]) {
                $old = $byKey[$key] ?? null;
                if (!$old) DB::query('INSERT INTO notification_incidents (incident_key, kind, device_id, message, started_at) VALUES (?, ?, ?, ?, ?)', [$key, $kind, $device, $message, $start]);
                elseif ($old['resolved_at']) DB::query('UPDATE notification_incidents SET message = ?, started_at = UTC_TIMESTAMP(), resolved_at = NULL, send_count = 0, last_queued_at = NULL, generation = generation + 1 WHERE id = ?', [$message, $old['id']]);
                else DB::query('UPDATE notification_incidents SET message = ? WHERE id = ?', [$message, $old['id']]);
            }
            foreach ($existing as $old) {
                if (!$old['resolved_at'] && !isset($conditions[$old['incident_key']])) {
                    DB::query('UPDATE notification_incidents SET resolved_at = UTC_TIMESTAMP() WHERE id = ?', [$old['id']]);
                    DB::query("UPDATE notification_deliveries SET status = 'cancelled' WHERE incident_id = ? AND status = 'pending'", [$old['id']]);
                }
            }
        });
    }

    private function result(string $status, int $sent, string $detail): array
    {
        DB::query("INSERT INTO maintenance_runs (job, last_run_at, status, detail) VALUES ('notifications', UTC_TIMESTAMP(), ?, ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), status = VALUES(status), detail = VALUES(detail)", [$status, $detail]);
        return ['status' => $status, 'sent' => $sent, 'detail' => $detail];
    }
}
