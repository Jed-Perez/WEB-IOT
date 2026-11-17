<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;
use App\Core\HttpException;

final class Greenhouse
{
    public static function transaction(callable $work): mixed
    {
        $db = DB::connection();
        $db->begin_transaction();
        try {
            $result = $work();
            $db->commit();
            return $result;
        } catch (\Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function device(int $id): array
    {
        $row = DB::query('SELECT id, name, type, last_seen_at, api_key_hash IS NOT NULL AS configured FROM devices WHERE id = ?', [$id])->get_result()->fetch_assoc();
        if (!$row) throw new HttpException(404, 'Device not found.');
        return $row;
    }

    public function authenticateDevice(string $key): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) throw new HttpException(401, 'Invalid device credentials.');
        $row = DB::query('SELECT id, name FROM devices WHERE api_key_hash = ?', [hash('sha256', $key)])->get_result()->fetch_assoc();
        if (!$row) throw new HttpException(401, 'Invalid device credentials.');
        return $row;
    }

    public function rotateKey(int $id, ?int $actor = null): string
    {
        return self::transaction(function () use ($id, $actor) {
            $before = $this->device($id);
            $key = bin2hex(random_bytes(32));
            DB::query('UPDATE devices SET api_key_hash = ? WHERE id = ?', [hash('sha256', $key), $id]);
            Activity::record($actor, 'device.key_replace', 'device', $id, ['configured' => (bool) $before['configured']], ['configured' => true, 'key_replaced' => true]);
            return $key;
        });
    }

    public function snapshot(int $deviceId): array
    {
        $device = $this->device($deviceId);
        $latest = DB::query('SELECT * FROM telemetry WHERE device_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1', [$deviceId])->get_result()->fetch_assoc();
        $stats = DB::query('SELECT MIN(temperature) temperature_min, MAX(temperature) temperature_max,
            MIN(humidity) humidity_min, MAX(humidity) humidity_max, MIN(ph) ph_min, MAX(ph) ph_max,
            MIN(tds) tds_min, MAX(tds) tds_max FROM telemetry WHERE device_id = ? AND recorded_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)', [$deviceId])->get_result()->fetch_assoc();
        $sensors = (new SensorThreshold())->all();
        foreach ($sensors as &$sensor) {
            $key = $sensor['key'];
            $sensor['value'] = $latest ? (float) $latest[$key] : null;
            $sensor['minDay'] = $stats[$key . '_min'] !== null ? (float) $stats[$key . '_min'] : null;
            $sensor['maxDay'] = $stats[$key . '_max'] !== null ? (float) $stats[$key . '_max'] : null;
        }
        unset($sensor);
        $alerts = DB::query('SELECT a.*, d.name AS device FROM alerts a JOIN devices d ON d.id = a.device_id WHERE a.device_id = ? ORDER BY a.id DESC LIMIT 200', [$deviceId])->get_result()->fetch_all(MYSQLI_ASSOC);
        $unread = DB::query("SELECT COUNT(*) total FROM alerts WHERE device_id = ? AND status = 'Active'", [$deviceId])->get_result()->fetch_assoc()['total'];
        $actuators = DB::query('SELECT a.*, c.status command_status, c.desired_on, c.mode requested_mode, c.expires_at command_expires_at
            FROM actuators a LEFT JOIN actuator_commands c ON c.id = (SELECT MAX(id) FROM actuator_commands WHERE actuator_id = a.id)
            WHERE a.device_id = ? ORDER BY a.id', [$deviceId])->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($actuators as &$actuator) {
            if ($actuator['command_status'] === 'pending' && strtotime($actuator['command_expires_at']) <= time()) $actuator['command_status'] = 'expired';
        }
        unset($actuator);
        return ['device' => $device, 'sensors' => $sensors, 'recorded_at' => $latest['recorded_at'] ?? null,
            'alerts' => $alerts, 'unread_count' => (int) $unread, 'actuators' => $actuators];
    }

    public function record(int $deviceId, array $values): int
    {
        return self::transaction(function () use ($deviceId, $values) {
            // Serialize ingestion per device so repeated out-of-range samples produce one active alert.
            DB::query('SELECT id FROM devices WHERE id = ? FOR UPDATE', [$deviceId]);
            DB::query('INSERT INTO telemetry (device_id, temperature, humidity, ph, tds, recorded_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$deviceId, $values['temperature'], $values['humidity'], $values['ph'], $values['tds']]);
            $id = DB::connection()->insert_id;
            DB::query('UPDATE devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$deviceId]);
            foreach ((new SensorThreshold())->all() as $sensor) {
                $value = $values[$sensor['key']];
                DB::query('INSERT INTO telemetry_ranges (telemetry_id, sensor_key, ideal_min, ideal_max, outside_range) VALUES (?, ?, ?, ?, ?)',
                    [$id, $sensor['key'], $sensor['low'], $sensor['high'], (int) ($value < $sensor['low'] || $value > $sensor['high'])]);
                if ($value >= $sensor['low'] && $value <= $sensor['high']) {
                    DB::query("UPDATE alerts SET status = 'Resolved', updated_at = UTC_TIMESTAMP() WHERE device_id = ? AND sensor_key = ? AND status <> 'Resolved'", [$deviceId, $sensor['key']]);
                    continue;
                }
                $open = DB::query("SELECT id, updated_at FROM alerts WHERE device_id = ? AND sensor_key = ? AND status <> 'Resolved' LIMIT 1", [$deviceId, $sensor['key']])->get_result()->fetch_assoc();
                if ($open && time() - strtotime($open['updated_at']) > 120) {
                    DB::query("UPDATE alerts SET status = 'Resolved', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$open['id']]);
                    $open = null;
                }
                $message = $sensor['name'] . ': ' . $value . ' ' . $sensor['unit'] . '; ideal range ' . $sensor['low'] . ' - ' . $sensor['high'] . '.';
                if ($open) {
                    DB::query('UPDATE alerts SET message = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$message, $open['id']]);
                } else {
                    DB::query('INSERT INTO alerts (device_id, sensor_key, message, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$deviceId, $sensor['key'], $message]);
                }
            }
            return $id;
        });
    }

    public function history(int $deviceId, string $from, string $to, int $limit = 1001): array
    {
        $this->device($deviceId);
        return DB::query('SELECT id, recorded_at, temperature, humidity, ph, tds FROM telemetry WHERE device_id = ? AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at DESC, id DESC LIMIT ?', [$deviceId, $from, $to, $limit])->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function updateAlert(int $id, string $status, int $userId): void
    {
        self::transaction(function () use ($id, $status, $userId) {
            $alert = DB::query('SELECT status FROM alerts WHERE id = ? FOR UPDATE', [$id])->get_result()->fetch_assoc();
            if (!$alert) throw new HttpException(404, 'Alert not found.');
            if ($alert['status'] === 'Resolved' && $status !== 'Resolved') throw new HttpException(409, 'This alert is already resolved.');
            DB::query('UPDATE alerts SET status = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $userId, $id]);
            Activity::record($userId, 'alert.update', 'alert', $id, $alert, ['status' => $status]);
        });
    }

    public function command(int $id, string $mode, bool $on, int $userId): int
    {
        return self::transaction(function () use ($id, $mode, $on, $userId) {
            $actuator = DB::query('SELECT * FROM actuators WHERE id = ? FOR UPDATE', [$id])->get_result()->fetch_assoc();
            if (!$actuator) throw new HttpException(404, 'Actuator not found.');
            $pending = DB::query("SELECT id FROM actuator_commands WHERE actuator_id = ? AND status = 'pending' AND expires_at > UTC_TIMESTAMP() LIMIT 1", [$id])->get_result()->fetch_assoc();
            if ($pending) throw new HttpException(409, 'Wait for the pending command to be acknowledged or expire.');
            if ($on && $actuator['cooldown_until'] && strtotime($actuator['cooldown_until']) > time()) throw new HttpException(409, 'The actuator is in its cooldown period.');
            DB::query("UPDATE actuator_commands SET status = 'expired' WHERE actuator_id = ? AND status = 'pending' AND expires_at <= UTC_TIMESTAMP()", [$id]);
            DB::query('INSERT INTO actuator_commands (actuator_id, requested_by, mode, desired_on, duration_seconds, created_at, expires_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND))',
                [$id, $userId, $mode, (int) $on, $on ? $actuator['max_seconds'] : 0]);
            $commandId = DB::connection()->insert_id;
            Activity::record($userId, 'actuator.command', 'actuator', $id, ['mode' => $actuator['mode'], 'reported_on' => $actuator['reported_on']], ['command_id' => $commandId, 'mode' => $mode, 'requested_on' => $on]);
            return $commandId;
        });
    }

    public function pending(int $deviceId): array
    {
        DB::query('UPDATE devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$deviceId]);
        return DB::query("SELECT c.id, c.actuator_id, a.name, c.mode, c.desired_on, c.duration_seconds, a.cooldown_seconds, c.expires_at
            FROM actuator_commands c JOIN actuators a ON a.id = c.actuator_id
            WHERE a.device_id = ? AND c.status = 'pending' AND c.expires_at > UTC_TIMESTAMP() ORDER BY c.id", [$deviceId])->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function acknowledge(int $deviceId, int $commandId, bool $on): void
    {
        self::transaction(function () use ($deviceId, $commandId, $on) {
            // Lock actuator before command, matching the queue operation's lock order.
            $command = DB::query('SELECT c.* FROM actuator_commands c JOIN actuators a ON a.id = c.actuator_id WHERE c.id = ? AND a.device_id = ?', [$commandId, $deviceId])->get_result()->fetch_assoc();
            if (!$command) throw new HttpException(404, 'Command not found for this device.');
            DB::query('SELECT id FROM actuators WHERE id = ? FOR UPDATE', [$command['actuator_id']]);
            $command = DB::query('SELECT * FROM actuator_commands WHERE id = ? FOR UPDATE', [$commandId])->get_result()->fetch_assoc();
            if ($command['status'] === 'acknowledged') return; // Retry-safe acknowledgment.
            if ($command['status'] !== 'pending' || strtotime($command['expires_at']) <= time()) throw new HttpException(409, 'Command has expired.');
            if ($command['mode'] === 'manual' && (bool) $command['desired_on'] !== $on) throw new HttpException(422, 'Reported state must match the manual command.');
            DB::query("UPDATE actuator_commands SET status = 'acknowledged', acknowledged_at = UTC_TIMESTAMP() WHERE id = ?", [$commandId]);
            DB::query('UPDATE actuators SET mode = ?, reported_on = ?, reported_at = UTC_TIMESTAMP(), cooldown_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL (? + cooldown_seconds) SECOND) WHERE id = ?',
                [$command['mode'], (int) $on, $on ? $command['duration_seconds'] : 0, $command['actuator_id']]);
            DB::query('INSERT INTO actuator_state_history (actuator_id, mode, reported_on, recorded_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$command['actuator_id'], $command['mode'], (int) $on]);
            Activity::record(null, 'actuator.acknowledge', 'command', $commandId, ['status' => 'pending'], ['status' => 'acknowledged', 'reported_on' => $on]);
            DB::query('UPDATE devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$deviceId]);
        });
    }

    public function reportState(int $deviceId, int $id, string $mode, bool $on): void
    {
        self::transaction(function () use ($deviceId, $id, $mode, $on) {
            $actuator = DB::query('SELECT * FROM actuators WHERE id = ? AND device_id = ? FOR UPDATE', [$id, $deviceId])->get_result()->fetch_assoc();
            if (!$actuator) throw new HttpException(404, 'Actuator not found for this device.');
            DB::query('UPDATE actuators SET mode = ?, reported_on = ?, reported_at = UTC_TIMESTAMP() WHERE id = ?', [$mode, (int) $on, $id]);
            DB::query('INSERT INTO actuator_state_history (actuator_id, mode, reported_on, recorded_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$id, $mode, (int) $on]);
            if ($actuator['reported_on'] === null || (bool) $actuator['reported_on'] !== $on || $actuator['mode'] !== $mode) {
                Activity::record(null, 'actuator.state', 'actuator', $id, ['mode' => $actuator['mode'], 'on' => $actuator['reported_on']], ['mode' => $mode, 'on' => $on]);
            }
            if (!$on && (bool) $actuator['reported_on']) {
                DB::query('UPDATE actuators SET cooldown_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL cooldown_seconds SECOND) WHERE id = ?', [$id]);
            }
            DB::query('UPDATE devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?', [$deviceId]);
        });
    }
}
