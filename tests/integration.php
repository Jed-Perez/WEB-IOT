<?php
declare(strict_types=1);

// Uses a unique, disposable database; never runs tests against the application database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDatabase = 'greenpulse_test_' . bin2hex(random_bytes(6));
putenv('DB_DATABASE=' . $testDatabase);
require dirname(__DIR__) . '/scripts/setup.php';

use App\Core\Database as DB;
use App\Models\PasswordReset;

$server = null;
$clients = [];
$log = tempnam(sys_get_temp_dir(), 'greenpulse-test-');
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function request(string $client, string $method, string $path, mixed $body = null, array $headers = [], int $expected = 200): array
{
    global $clients, $base;
    $curl = $clients[$client] ??= curl_init();
    curl_setopt_array($curl, [CURLOPT_URL => $base . $path, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => $body === null ? null : (is_array($body) ? json_encode($body) : $body),
        CURLOPT_HTTPHEADER => array_merge(is_array($body) ? ['Content-Type: application/json'] : [], $headers)]);
    $text = curl_exec($curl);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    check($status === $expected, "$method $path expected $expected; received $status: " . substr($text, 0, 300));
    return [json_decode($text, true), $text];
}
function csrf(string $client): string
{
    [, $html] = request($client, 'GET', '/login');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $match);
    check(isset($match[1]), 'Login form has CSRF token');
    return $match[1];
}
function form(string $client, string $path, array $values, int $expected = 302): array
{
    return request($client, 'POST', $path, http_build_query($values), ['Content-Type: application/x-www-form-urlencoded'], $expected);
}

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address . '/' . basename(dirname(__DIR__));
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', 'public', 'app/tunnel-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__));
    if (!is_resource($server)) throw new RuntimeException('Cannot start the test HTTP server.');
    fclose($pipes[0]);
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    request('guest', 'GET', '/api/dashboard', expected: 401);
    request('guest', 'GET', '/api/missing', expected: 404);
    request('guest', 'GET', '/api/device/telemetry', expected: 405);
    request('guest', 'GET', '/config/database.php', expected: 404);
    form('guest', '/register', ['full_name' => 'Blocked', 'email' => 'blocked@example.test', 'password' => 'Password123'], 403);
    $adminToken = csrf('admin');
    form('admin', '/register', ['csrf_token' => $adminToken, 'full_name' => 'Admin Test', 'email' => 'admin@example.test', 'password' => 'Password123']);
    $staffToken = csrf('staff');
    form('staff', '/register', ['csrf_token' => $staffToken, 'full_name' => 'Staff Test', 'email' => 'staff@example.test', 'password' => 'Password123']);
    form('admin', '/login', ['csrf_token' => $adminToken, 'email' => 'admin@example.test', 'password' => 'wrong'], 200);
    form('admin', '/login', ['csrf_token' => $adminToken, 'email' => 'admin@example.test', 'password' => 'Password123']);
    $adminHeaders = ['X-CSRF-Token: ' . $adminToken];
    form('staff', '/login', ['csrf_token' => $staffToken, 'email' => 'staff@example.test', 'password' => 'Password123'], 200);
    request('staff', 'GET', '/api/dashboard', expected: 401);
    request('admin', 'POST', '/api/devices/key', ['id' => 1], $adminHeaders, 428);
    request('admin', 'POST', '/api/security/confirm', ['password' => 'wrong'], $adminHeaders, 422);
    request('admin', 'POST', '/api/security/confirm', ['password' => 'Password123'], $adminHeaders);
    $staffId = (int) DB::query("SELECT id FROM users WHERE email = 'staff@example.test'")->get_result()->fetch_assoc()['id'];
    request('admin', 'POST', '/api/users/save', ['id' => $staffId, 'full_name' => 'Staff Test', 'email' => 'staff@example.test', 'role' => 'Farm Staff', 'status' => 'Active'], $adminHeaders);
    form('staff', '/login', ['csrf_token' => $staffToken, 'email' => 'staff@example.test', 'password' => 'Password123']);
    [, $dashboard] = request('admin', 'GET', '/dashboard');
    check(str_contains($dashboard, 'window.greenPulse ='), 'Dashboard renders API configuration');
    $adminHeaders = ['X-CSRF-Token: ' . $adminToken];
    $staffHeaders = ['X-CSRF-Token: ' . $staffToken];
    [$data] = request('admin', 'GET', '/api/users');
    check($data['users'][0]['role'] === 'Administrator' && $data['users'][1]['role'] === 'Farm Staff', 'First account is admin; subsequent accounts are staff');
    $adminId = (int) $data['users'][0]['id'];
    request('staff', 'GET', '/api/users', expected: 403);
    request('staff', 'POST', '/api/users/save', [], $staffHeaders, 403);
    request('admin', 'POST', '/api/devices/key', ['id' => 1], expected: 403);
    request('staff', 'POST', '/api/devices/key', ['id' => 1], $staffHeaders, 403);
    [$data] = request('admin', 'POST', '/api/devices/key', ['id' => 1], $adminHeaders);
    $deviceHeaders = ['Authorization: Bearer ' . $data['api_key']];
    check(DB::query('SELECT api_key_hash FROM devices WHERE id = 1')->get_result()->fetch_assoc()['api_key_hash'] !== $data['api_key'], 'Device key stored as hash');
    [$data] = request('admin', 'GET', '/api/dashboard');
    check($data['sensors'][0]['value'] === null && $data['alerts'] === [], 'New database has no simulated readings');
    $reading = ['temperature' => 28.5, 'humidity' => 60, 'ph' => 6.1, 'tds' => 720];
    request('device', 'POST', '/api/device/telemetry', $reading, expected: 401);
    request('device', 'POST', '/api/device/telemetry', array_replace($reading, ['humidity' => 101]), $deviceHeaders, 422);
    request('device', 'POST', '/api/device/telemetry', array_replace($reading, ['ph' => '6.1']), $deviceHeaders, 422);
    request('device', 'POST', '/api/device/telemetry', '{broken', array_merge($deviceHeaders, ['Content-Type: application/json']), 400);
    request('device', 'POST', '/api/device/telemetry', $reading, $deviceHeaders, 201);
    request('device', 'POST', '/api/device/telemetry', $reading, $deviceHeaders, 201);
    [$data] = request('staff', 'GET', '/api/dashboard');
    check($data['sensors'][0]['value'] === 28.5 && count($data['alerts']) === 1, 'Readings persist; duplicate threshold alerts are suppressed');
    $alertId = (int) $data['alerts'][0]['id'];
    request('staff', 'POST', '/api/alerts/update', ['id' => $alertId, 'status' => 'Acknowledged'], $staffHeaders);
    [$data] = request('admin', 'GET', '/api/dashboard');
    check($data['alerts'][0]['status'] === 'Acknowledged', 'Acknowledgment is shared across sessions');
    request('device', 'POST', '/api/device/telemetry', array_replace($reading, ['temperature' => 22]), $deviceHeaders, 201);
    [$data] = request('admin', 'GET', '/api/dashboard');
    check($data['alerts'][0]['status'] === 'Resolved', 'Normal readings resolve threshold alerts');
    request('staff', 'POST', '/api/alerts/update', ['id' => $alertId, 'status' => 'Acknowledged'], $staffHeaders, 409);
    [$data] = request('staff', 'GET', '/api/history');
    check(count($data['rows']) === 3, 'History returns persisted readings');
    [, $csv] = request('staff', 'GET', '/api/history/export');
    check(str_contains($csv, 'Temperature (C)') && str_contains($csv, '28.50'), 'CSV exports stored values');
    request('staff', 'GET', '/api/history?from=invalid', expected: 422);
    request('staff', 'GET', '/api/history?from=2026-02-30T00:00:00Z&to=2026-03-01T00:00:00Z', expected: 422);
    request('staff', 'GET', '/api/history?from=2026-01-01T00:00:00Z&to=2026-03-01T00:00:00Z', expected: 422);
    $thresholds = ['csrf_token' => $adminToken, 'temperature_min' => 19, 'temperature_max' => 25, 'humidity_min' => 50, 'humidity_max' => 70, 'ph_min' => 5.5, 'ph_max' => 6.5, 'tds_min' => 560, 'tds_max' => 840];
    form('staff', '/dashboard/thresholds', array_replace($thresholds, ['csrf_token' => $staffToken]));
    check((float) DB::query("SELECT ideal_min FROM sensor_thresholds WHERE sensor_key = 'temperature'")->get_result()->fetch_assoc()['ideal_min'] === 18.0, 'Staff cannot update thresholds');
    form('admin', '/dashboard/thresholds', $thresholds);
    check((float) DB::query("SELECT ideal_min FROM sensor_thresholds WHERE sensor_key = 'temperature'")->get_result()->fetch_assoc()['ideal_min'] === 19.0, 'Admin threshold changes persist');
    require dirname(__DIR__) . '/scripts/setup.php';
    check((float) DB::query("SELECT ideal_min FROM sensor_thresholds WHERE sensor_key = 'temperature'")->get_result()->fetch_assoc()['ideal_min'] === 19.0, 'Rerunning setup preserves customized thresholds');
    $account = ['full_name' => 'New Staff', 'email' => 'new@example.test', 'role' => 'Farm Staff', 'password' => 'Password456'];
    request('admin', 'POST', '/api/users/save', $account, $adminHeaders, 201);
    request('admin', 'POST', '/api/users/save', $account, $adminHeaders, 409);
    $newId = (int) DB::query('SELECT id FROM users WHERE email = ?', [$account['email']])->get_result()->fetch_assoc()['id'];
    request('admin', 'POST', '/api/users/save', array_replace($account, ['id' => $newId, 'full_name' => 'Updated Staff', 'password' => '']), $adminHeaders);
    request('admin', 'POST', '/api/users/save', array_replace($account, ['id' => $adminId, 'email' => 'admin@example.test']), $adminHeaders, 409);
    request('admin', 'POST', '/api/users/delete', ['id' => $adminId], $adminHeaders, 409);
    request('admin', 'POST', '/api/users/delete', ['id' => $newId], $adminHeaders);
    check(DB::query('SELECT id FROM users WHERE id = ?', [$newId])->get_result()->num_rows === 0, 'User deletion persists');
    [$data] = request('staff', 'POST', '/api/actuators/command', ['id' => 1, 'mode' => 'manual', 'on' => true], $staffHeaders, 201);
    $commandId = (int) $data['id'];
    request('staff', 'POST', '/api/actuators/command', ['id' => 1, 'mode' => 'manual', 'on' => true], $staffHeaders, 409);
    [$data] = request('device', 'GET', '/api/device/commands', headers: $deviceHeaders);
    check(count($data['commands']) === 1 && (int) $data['commands'][0]['duration_seconds'] === 120, 'Device polls bounded manual command');
    [$data] = request('admin', 'GET', '/api/dashboard');
    check($data['actuators'][0]['reported_on'] === null, 'Queued command does not claim relay acknowledgment');
    request('device', 'POST', '/api/device/commands/ack', ['id' => $commandId, 'on' => false], $deviceHeaders, 422);
    request('device', 'POST', '/api/device/commands/ack', ['id' => $commandId, 'on' => true], $deviceHeaders);
    request('device', 'POST', '/api/device/commands/ack', ['id' => $commandId, 'on' => true], $deviceHeaders);
    request('staff', 'POST', '/api/actuators/command', ['id' => 1, 'mode' => 'manual', 'on' => true], $staffHeaders, 409);
    request('device', 'POST', '/api/device/state', ['id' => 1, 'mode' => 'auto', 'on' => false], $deviceHeaders);
    [$data] = request('admin', 'GET', '/api/dashboard');
    check((int) $data['actuators'][0]['reported_on'] === 0 && $data['actuators'][0]['mode'] === 'auto', 'Firmware can report safety stop');
    [$data] = request('admin', 'POST', '/api/actuators/command', ['id' => 2, 'mode' => 'manual', 'on' => false], $adminHeaders, 201);
    DB::query('UPDATE actuator_commands SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ?', [$data['id']]);
    request('device', 'POST', '/api/device/commands/ack', ['id' => (int) $data['id'], 'on' => false], $deviceHeaders, 409);
    [$pending] = request('device', 'GET', '/api/device/commands', headers: $deviceHeaders);
    check($pending['commands'] === [], 'Expired commands are not delivered');
    // Test tenant/device boundary without issuing a second key to the browser.
    DB::query("INSERT INTO devices (name) VALUES ('Other device')");
    DB::query("INSERT INTO actuators (device_id, name, max_seconds, cooldown_seconds) VALUES (2, 'Other relay', 60, 30)");
    request('device', 'POST', '/api/device/state', ['id' => 3, 'mode' => 'manual', 'on' => false], $deviceHeaders, 404);
    request('admin', 'POST', '/api/devices/key', ['id' => 1], $adminHeaders);
    request('device', 'GET', '/api/device/commands', headers: $deviceHeaders, expected: 401);
    $reset = new PasswordReset();
    $reset->create($adminId, hash('sha256', '123456'));
    for ($i = 0; $i < 5; $i++) check(!$reset->verifyCode($adminId, hash('sha256', 'wrong')), 'Wrong OTP is rejected');
    check(!$reset->verifyCode($adminId, hash('sha256', '123456')), 'OTP attempt limit enforced');
    $reset->create($adminId, hash('sha256', '654321'));
    check($reset->verifyCode($adminId, hash('sha256', '654321')), 'Valid OTP verified');
    check($reset->consumeVerified($adminId, password_hash('NewPassword123', PASSWORD_DEFAULT)), 'Verified reset consumed');
    check(!$reset->consumeVerified($adminId, password_hash('NewPassword123', PASSWORD_DEFAULT)), 'Reset cannot be replayed');
    request('admin', 'GET', '/api/dashboard', expected: 401);
    $adminToken = csrf('admin');
    form('admin', '/login', ['csrf_token' => $adminToken, 'email' => 'admin@example.test', 'password' => 'NewPassword123']);
    $adminHeaders = ['X-CSRF-Token: ' . $adminToken];
    request('admin', 'POST', '/api/security/confirm', ['password' => 'NewPassword123'], $adminHeaders);
    require __DIR__ . '/remember_login.php';
    require __DIR__ . '/operations.php';
    form('admin', '/logout', ['csrf_token' => $adminToken]);
    request('admin', 'GET', '/api/dashboard', expected: 401);
    echo "PASS: $checks integration checks.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($clients as $curl) curl_close($curl);
    // This identifier was generated above and never comes from user/application configuration.
    DB::connection()->query('DROP DATABASE `' . $testDatabase . '`');
    if (is_file($log)) unlink($log);
}
