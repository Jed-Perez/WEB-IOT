<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Report
{
    private const SENSORS = ['temperature', 'humidity', 'ph', 'tds'];
    private const HOLD_SECONDS = 120;

    private static function day(int $time): string
    {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d');
    }

    private static function segments(int $start, int $end, callable $visit): void
    {
        while ($start < $end) {
            $day = self::day($start);
            $next = (new \DateTimeImmutable($day, new \DateTimeZone('Asia/Manila')))->modify('+1 day')->getTimestamp();
            $stop = min($end, $next);
            $visit($day, $stop - $start);
            $start = $stop;
        }
    }

    public function summary(int $deviceId, string $from, string $to): array
    {
        (new Greenhouse())->device($deviceId);
        $start = strtotime($from); $end = min(time(), strtotime($to));
        $actuators = DB::query('SELECT id, name FROM actuators WHERE device_id = ? ORDER BY id', [$deviceId])->get_result()->fetch_all(MYSQLI_ASSOC);
        $days = [];
        self::segments($start, $end, function ($day, $seconds) use (&$days, $actuators) {
            $sensors = [];
            foreach (self::SENSORS as $key) $sensors[$key] = ['count' => 0, 'sum' => 0.0, 'average' => null, 'min' => null, 'max' => null, 'outside_seconds' => 0, 'observed_seconds' => 0];
            $runtime = [];
            foreach ($actuators as $item) $runtime[$item['id']] = ['name' => $item['name'], 'on_seconds' => 0, 'observed_seconds' => 0];
            $days[$day] = ['date' => $day, 'period_seconds' => $seconds, 'sensors' => $sensors, 'actuators' => $runtime];
        });
        foreach (self::SENSORS as $key) {
            // Column name comes only from the fixed allowlist above.
            $statement = DB::query("SELECT t.recorded_at, t.next_at, t.reading, r.outside_range FROM
                (SELECT id, recorded_at, $key AS reading, LEAD(recorded_at) OVER (ORDER BY recorded_at, id) next_at
                 FROM telemetry WHERE device_id = ? AND recorded_at >= ? AND recorded_at < ?) t
                LEFT JOIN telemetry_ranges r ON r.telemetry_id = t.id AND r.sensor_key = ? ORDER BY t.recorded_at, t.id",
                [$deviceId, gmdate('Y-m-d H:i:s', $start - self::HOLD_SECONDS), gmdate('Y-m-d H:i:s', $end), $key]);
            $statement->bind_result($at, $next, $reading, $outside);
            while ($statement->fetch()) {
                $time = strtotime($at);
                $day = self::day($time);
                if ($time >= $start && isset($days[$day])) {
                    $sensor = &$days[$day]['sensors'][$key];
                    $sensor['count']++; $sensor['sum'] += (float) $reading;
                    $sensor['min'] = $sensor['min'] === null ? (float) $reading : min($sensor['min'], (float) $reading);
                    $sensor['max'] = $sensor['max'] === null ? (float) $reading : max($sensor['max'], (float) $reading);
                    unset($sensor);
                }
                if ($outside === null) continue; // Legacy samples have no historical threshold snapshot.
                $stop = min($end, $time + self::HOLD_SECONDS, $next ? strtotime($next) : $end);
                self::segments(max($time, $start), $stop, function ($day, $seconds) use (&$days, $key, $outside) {
                    $days[$day]['sensors'][$key]['observed_seconds'] += $seconds;
                    if ((int) $outside) $days[$day]['sensors'][$key]['outside_seconds'] += $seconds;
                });
            }
            $statement->close();
        }
        foreach ($actuators as $actuator) {
            $statement = DB::query('SELECT recorded_at, reported_on, LEAD(recorded_at) OVER (ORDER BY recorded_at, id) next_at FROM actuator_state_history WHERE actuator_id = ? AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at, id',
                [$actuator['id'], gmdate('Y-m-d H:i:s', $start - self::HOLD_SECONDS), gmdate('Y-m-d H:i:s', $end)]);
            $statement->bind_result($at, $on, $next);
            while ($statement->fetch()) {
                $time = strtotime($at);
                $stop = min($end, $time + self::HOLD_SECONDS, $next ? strtotime($next) : $end);
                self::segments(max($time, $start), $stop, function ($day, $seconds) use (&$days, $actuator, $on) {
                    $days[$day]['actuators'][$actuator['id']]['observed_seconds'] += $seconds;
                    if ((int) $on) $days[$day]['actuators'][$actuator['id']]['on_seconds'] += $seconds;
                });
            }
            $statement->close();
        }
        foreach ($days as &$day) {
            foreach ($day['sensors'] as &$sensor) {
                $sensor['average'] = $sensor['count'] ? round($sensor['sum'] / $sensor['count'], 2) : null;
                $sensor['unknown_seconds'] = max(0, $day['period_seconds'] - $sensor['observed_seconds']);
                unset($sensor['sum']);
            }
            unset($sensor);
            foreach ($day['actuators'] as &$actuator) $actuator['unknown_seconds'] = max(0, $day['period_seconds'] - $actuator['observed_seconds']);
            unset($actuator);
        }
        unset($day);
        return ['timezone' => 'Asia/Manila', 'days' => array_values($days),
            'method' => 'Daily sample averages. Duration estimates hold each reported value for at most 120 seconds until the next report; gaps and readings without saved thresholds are unknown.'];
    }
}
