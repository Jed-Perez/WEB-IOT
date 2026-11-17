<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiController;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\Greenhouse;
use App\Models\SensorThreshold;

final class GreenhouseController extends ApiController
{
    public function snapshot(): void
    {
        $this->user();
        $this->json((new Greenhouse())->snapshot($this->id($_GET['device_id'] ?? 1)));
    }

    private function range(): array
    {
        $from = $_GET['from'] ?? gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
        $to = $_GET['to'] ?? gmdate('Y-m-d\TH:i:s\Z', time() + 1);
        foreach ([$from, $to] as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)) throw new HttpException(422, 'Dates must use UTC ISO format, for example 2026-10-30T00:00:00Z.');
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date);
            if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $date) throw new HttpException(422, 'Invalid date.');
        }
        $start = strtotime($from); $end = strtotime($to);
        if ($end <= $start || $end - $start > 31 * 86400) throw new HttpException(422, 'Choose a date range of up to 31 days, with the end after the start.');
        return [gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end)];
    }

    public function history(): void
    {
        $this->user();
        [$from, $to] = $this->range();
        $rows = (new Greenhouse())->history($this->id($_GET['device_id'] ?? 1), $from, $to);
        $this->json(['rows' => array_reverse(array_slice($rows, 0, 1000)), 'truncated' => count($rows) > 1000]);
    }

    public function report(): void
    {
        $this->user();
        [$from, $to] = $this->range();
        $report = (new \App\Models\Report())->summary($this->id($_GET['device_id'] ?? 1), $from, $to);
        if (($_GET['format'] ?? '') !== 'csv') { $this->json($report); return; }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="greenpulse-daily-report.csv"');
        header('Cache-Control: no-store');
        $output = fopen('php://output', 'wb');
        fputcsv($output, ['Day (Asia/Manila)', 'Type', 'Name', 'Samples', 'Average', 'Minimum', 'Maximum', 'Outside range seconds', 'ON seconds', 'Observed seconds', 'Unknown seconds']);
        foreach ($report['days'] as $day) {
            foreach ($day['sensors'] as $key => $sensor) fputcsv($output, [$day['date'], 'sensor', $key, $sensor['count'], $sensor['average'], $sensor['min'], $sensor['max'], $sensor['outside_seconds'], '', $sensor['observed_seconds'], $sensor['unknown_seconds']]);
            foreach ($day['actuators'] as $actuator) {
                $name = preg_match('/^[=+@\-\t\r]/', $actuator['name']) ? "'" . $actuator['name'] : $actuator['name'];
                fputcsv($output, [$day['date'], 'actuator', $name, '', '', '', '', '', $actuator['on_seconds'], $actuator['observed_seconds'], $actuator['unknown_seconds']]);
            }
        }
        fclose($output);
    }

    public function export(): void
    {
        $this->user();
        [$from, $to] = $this->range();
        $id = $this->id($_GET['device_id'] ?? 1);
        (new Greenhouse())->device($id);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="greenpulse-history.csv"');
        header('Cache-Control: no-store');
        $output = fopen('php://output', 'wb');
        fputcsv($output, ['Recorded at (UTC)', 'Temperature (C)', 'Humidity (%)', 'pH', 'TDS (ppm)']);
        $statement = Database::query('SELECT recorded_at, temperature, humidity, ph, tds FROM telemetry WHERE device_id = ? AND recorded_at >= ? AND recorded_at < ? ORDER BY recorded_at, id', [$id, $from, $to]);
        $statement->bind_result($at, $temperature, $humidity, $ph, $tds);
        while ($statement->fetch()) fputcsv($output, [$at, $temperature, $humidity, $ph, $tds]);
        fclose($output);
    }

    public function alert(): void
    {
        $user = $this->user(write: true);
        $input = $this->input();
        if (!in_array($input['status'] ?? null, ['Acknowledged', 'Resolved'], true)) throw new HttpException(422, 'Invalid alert status.');
        (new Greenhouse())->updateAlert($this->id($input['id'] ?? null), $input['status'], (int) $user['id']);
        $this->json(['message' => 'Alert updated.']);
    }

    public function command(): void
    {
        $user = $this->user(write: true);
        $input = $this->input();
        $mode = $this->mode($input['mode'] ?? null);
        $on = $this->boolean($input['on'] ?? null);
        if ($mode === 'auto' && $on) throw new HttpException(422, 'Auto mode lets the device choose the relay state. Send on=false.');
        $id = (new Greenhouse())->command($this->id($input['id'] ?? null), $mode, $on, (int) $user['id']);
        $this->json(['id' => $id, 'message' => 'Command queued; waiting for the device.'], 201);
    }

    public function rotateKey(): void
    {
        $user = $this->user(admin: true, write: true);
        \App\Core\AccountSecurity::requireConfirmation();
        $input = $this->input();
        $this->json(['api_key' => (new Greenhouse())->rotateKey($this->id($input['id'] ?? null), (int) $user['id'])]);
    }

    public function ingest(): void
    {
        $device = $this->device();
        $input = $this->input();
        $ranges = ['temperature' => [-40, 85], 'humidity' => [0, 100], 'ph' => [0, 14], 'tds' => [0, 10000]];
        foreach ($ranges as $key => [$min, $max]) {
            $value = $input[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) throw new HttpException(422, "$key must be a number between $min and $max.");
        }
        $this->json(['id' => (new Greenhouse())->record((int) $device['id'], $input)], 201);
    }

    public function pending(): void
    {
        $device = $this->device();
        $this->json(['commands' => (new Greenhouse())->pending((int) $device['id']), 'thresholds' => (new SensorThreshold())->all(), 'server_time' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    public function acknowledge(): void
    {
        $device = $this->device();
        $input = $this->input();
        (new Greenhouse())->acknowledge((int) $device['id'], $this->id($input['id'] ?? null), $this->boolean($input['on'] ?? null));
        $this->json(['message' => 'Command acknowledged.']);
    }

    public function reportState(): void
    {
        $device = $this->device();
        $input = $this->input();
        (new Greenhouse())->reportState((int) $device['id'], $this->id($input['id'] ?? null), $this->mode($input['mode'] ?? null), $this->boolean($input['on'] ?? null));
        $this->json(['message' => 'Actuator state saved.']);
    }
}
