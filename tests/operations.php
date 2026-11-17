<?php
declare(strict_types=1);

use App\Core\{Database as DB, Backup, RateLimit};
use App\Models\{Greenhouse, Notifications, Report};

request('staff', 'GET', '/api/activity', expected: 403);
request('staff', 'GET', '/api/operations', expected: 403);
[$activity] = request('admin', 'GET', '/api/activity');
check(count($activity['rows']) > 5, 'Administrative and actuator actions are audited');
$actions = array_column($activity['rows'], 'action');
check(in_array('user.approve', $actions, true) && in_array('device.key_replace', $actions, true) && in_array('thresholds.update', $actions, true), 'Audit includes approvals, key replacements, and thresholds');
check(!str_contains(json_encode($activity), 'Password123') && !str_contains(json_encode($activity), 'api_key_hash'), 'Audit excludes passwords and device key hashes');
request('admin', 'POST', '/api/users/save', ['id' => $adminId, 'full_name' => 'Admin Test', 'email' => 'admin@example.test', 'role' => 'Administrator', 'status' => 'Suspended'], $adminHeaders, 409);
request('admin', 'POST', '/api/users/save', ['id' => $staffId, 'full_name' => 'Staff Test', 'email' => 'staff@example.test', 'role' => 'Farm Staff', 'status' => 'Suspended'], $adminHeaders);
request('staff', 'GET', '/api/dashboard', expected: 401);
request('admin', 'POST', '/api/users/save', ['id' => $staffId, 'full_name' => 'Staff Test', 'email' => 'staff@example.test', 'role' => 'Farm Staff', 'status' => 'Active'], $adminHeaders);
$staffToken = csrf('staff');
form('staff', '/login', ['csrf_token' => $staffToken, 'email' => 'staff@example.test', 'password' => 'Password123']);
$staffHeaders = ['X-CSRF-Token: ' . $staffToken];
$otherStaffToken = csrf('staff-other');
form('staff-other', '/login', ['csrf_token' => $otherStaffToken, 'email' => 'staff@example.test', 'password' => 'Password123', 'remember_me' => '1']);
request('staff', 'POST', '/api/security/logout-all', [], $staffHeaders, 428);
request('staff', 'POST', '/api/security/confirm', ['password' => 'Password123'], $staffHeaders);
request('staff', 'POST', '/api/security/logout-all', [], $staffHeaders);
request('staff', 'GET', '/api/dashboard', expected: 401);
request('staff-other', 'GET', '/api/dashboard', expected: 401);
curl_setopt($clients['staff-other'], CURLOPT_COOKIELIST, 'SESS');
request('staff-other', 'GET', '/api/dashboard', expected: 401);

// Test report arithmetic on isolated device data across a Manila midnight.
DB::query("INSERT INTO devices (name) VALUES ('Report fixture')");
$reportDevice = DB::connection()->insert_id;
DB::query("INSERT INTO actuators (device_id, name, max_seconds, cooldown_seconds) VALUES (?, 'Report fan', 120, 15)", [$reportDevice]);
$reportActuator = DB::connection()->insert_id;
$reportStart = '2026-01-01 15:59:00'; $reportEnd = '2026-01-01 16:04:00';
foreach ([['2026-01-01 15:59:00', 30, 1], ['2026-01-01 16:00:00', 20, 0], ['2026-01-01 16:03:00', 22, 0]] as [$at, $temperature, $outside]) {
    DB::query('INSERT INTO telemetry (device_id, temperature, humidity, ph, tds, recorded_at) VALUES (?, ?, 60, 6, 700, ?)', [$reportDevice, $temperature, $at]);
    $readingId = DB::connection()->insert_id;
    foreach (['temperature', 'humidity', 'ph', 'tds'] as $key) DB::query('INSERT INTO telemetry_ranges (telemetry_id, sensor_key, ideal_min, ideal_max, outside_range) VALUES (?, ?, 18, 25, ?)', [$readingId, $key, $key === 'temperature' ? $outside : 0]);
}
DB::query("INSERT INTO actuator_state_history (actuator_id, reported_on, mode, recorded_at) VALUES (?, 1, 'manual', ?), (?, 0, 'auto', '2026-01-01 16:00:00')", [$reportActuator, $reportStart, $reportActuator]);
$report = (new Report())->summary($reportDevice, $reportStart, $reportEnd);
check(count($report['days']) === 2 && $report['days'][0]['date'] === '2026-01-01' && $report['days'][1]['date'] === '2026-01-02', 'Reports split at Manila midnight');
check($report['days'][0]['sensors']['temperature']['outside_seconds'] === 60, 'Out-of-range duration uses historical threshold snapshots');
check($report['days'][1]['sensors']['temperature']['average'] === 21.0, 'Daily averages use actual samples');
check($report['days'][1]['sensors']['temperature']['unknown_seconds'] === 60, 'Sensor gaps over two minutes remain unknown');
check($report['days'][0]['actuators'][$reportActuator]['on_seconds'] === 60 && $report['days'][1]['actuators'][$reportActuator]['unknown_seconds'] === 120, 'Runtime comes from reported state and preserves unknown intervals');
request('admin', 'GET', '/api/reports?device_id=' . $reportDevice . '&from=2026-01-01T15:59:00Z&to=2026-01-01T16:04:00Z');
[, $summaryCsv] = request('admin', 'GET', '/api/reports?format=csv&device_id=' . $reportDevice . '&from=2026-01-01T15:59:00Z&to=2026-01-01T16:04:00Z');
check(str_contains($summaryCsv, 'Unknown seconds') && str_contains($summaryCsv, 'Report fan'), 'Daily report CSV includes runtime and unknown durations');
request('admin', 'GET', '/api/reports?from=2026-01-01T00:00:00Z&to=2026-03-01T00:00:00Z', expected: 422);

// Notification tests use an injected in-memory mail transport: no external messages.
putenv('ALERT_EMAILS=alerts@example.test');
putenv('NOTIFICATIONS_ENABLED=true');
$sentMessages = [];
$send = static function ($email, $subject, $message) use (&$sentMessages) { $sentMessages[] = [$email, $subject, $message]; };
$worker = new Notifications();
DB::query('UPDATE devices SET api_key_hash = NULL');
DB::query("UPDATE alerts SET status = 'Resolved'");
DB::query("UPDATE actuator_commands SET status = 'acknowledged'");
DB::query("INSERT INTO alerts (device_id, sensor_key, message, created_at, updated_at) VALUES (1, 'temperature', 'Temperature high', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$noticeAlert = DB::connection()->insert_id;
$worker->run($send);
check(count($sentMessages) === 0, 'Brief abnormal readings do not trigger email');
DB::query("UPDATE notification_incidents SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MINUTE) WHERE incident_key = ?", ['alert:' . $noticeAlert]);
$worker->run($send);
check(count($sentMessages) === 1, 'Prolonged abnormal reading sends one email');
$worker->run($send);
check(count($sentMessages) === 1, 'Repeated workers do not duplicate the first notification');
for ($repeat = 0; $repeat < 4; $repeat++) {
    DB::query('UPDATE notification_incidents SET last_queued_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)');
    $worker->run($send);
}
check(count($sentMessages) === 3, 'An incident stops after three email rounds');
DB::query("UPDATE alerts SET status = 'Resolved' WHERE id = ?", [$noticeAlert]);
$worker->run($send);
check(DB::query('SELECT resolved_at FROM notification_incidents WHERE incident_key = ?', ['alert:' . $noticeAlert])->get_result()->fetch_assoc()['resolved_at'] !== null, 'Resolved conditions close notification incidents');
DB::query("UPDATE devices SET api_key_hash = ?, last_seen_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = 1", [hash('sha256', 'fixture')]);
$worker->run($send);
check(count($sentMessages) === 4 && str_contains($sentMessages[3][1], 'offline'), 'Offline devices generate delayed notifications');
DB::query('UPDATE devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = 1');
DB::query("INSERT INTO actuator_commands (actuator_id, requested_by, mode, desired_on, duration_seconds, created_at, expires_at) VALUES (1, ?, 'manual', 0, 0, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE))", [$adminId]);
$worker->run($send);
check(count($sentMessages) === 5 && str_contains($sentMessages[4][1], 'command'), 'Unacknowledged expired commands generate notifications');
DB::query("INSERT INTO actuator_commands (actuator_id, requested_by, mode, desired_on, duration_seconds, created_at, expires_at) VALUES (2, ?, 'manual', 0, 0, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE))", [$adminId]);
$fail = static function () { throw new RuntimeException('Simulated mail failure'); };
$worker->run($fail);
$deliveryId = DB::query("SELECT MAX(id) id FROM notification_deliveries WHERE status = 'pending'")->get_result()->fetch_assoc()['id'];
check($deliveryId !== null, 'Mail failure is retained for retry');
for ($attempt = 0; $attempt < 2; $attempt++) {
    DB::query('UPDATE notification_deliveries SET next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ?', [$deliveryId]);
    $worker->run($fail);
}
$failedDelivery = DB::query('SELECT status, attempts FROM notification_deliveries WHERE id = ?', [$deliveryId])->get_result()->fetch_assoc();
check($failedDelivery['status'] === 'failed' && (int) $failedDelivery['attempts'] === 3, 'SMTP retries stop after three failed attempts');
putenv('ALERT_HOURLY_LIMIT=1');
DB::query("INSERT INTO alerts (device_id, sensor_key, message, created_at, updated_at) VALUES (1, 'humidity', 'Humidity high', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MINUTE), UTC_TIMESTAMP())");
$beforeCap = count($sentMessages);
$worker->run($send);
check(count($sentMessages) === $beforeCap, 'Recipient hourly cap prevents floods across separate incidents');
putenv('ALERT_HOURLY_LIMIT');
putenv('ALERT_EMAILS'); putenv('NOTIFICATIONS_ENABLED');
request('admin', 'GET', '/api/operations');

// Back up this test database, restore to a new generated database, and compare all rows.
$backup = new Backup();
$backupPath = $backup->create();
try {
    $verification = $backup->verifyRestore($backupPath);
    check($verification['verified'] && $verification['tables'] >= 15 && $verification['rows'] > 20, 'Backup restores with matching table counts and row hashes');
    $recoveredName = 'greenpulse_recovered_test_' . bin2hex(random_bytes(5));
    try {
        $recovered = $backup->restore($backupPath, $recoveredName);
        check($recovered['database'] === $recoveredName, 'Recovery writes to a new isolated database');
        check((int) DB::query('SELECT COUNT(*) total FROM `' . $recoveredName . '`.remember_tokens')->get_result()->fetch_assoc()['total'] === 0, 'Recovery invalidates saved login tokens');
        $refusedExisting = false;
        try { $backup->restore($backupPath, $recoveredName); } catch (mysqli_sql_exception) { $refusedExisting = true; }
        check($refusedExisting, 'Recovery cannot overwrite an existing database');
        check((int) DB::query('SELECT COUNT(*) total FROM `' . $recoveredName . '`.users')->get_result()->fetch_assoc()['total'] > 0, 'Refusing overwrite preserves the recovered database');
    } finally { DB::query('DROP DATABASE IF EXISTS `' . $recoveredName . '`'); }
    $originalChecksum = file_get_contents($backupPath . '.sha256');
    file_put_contents($backupPath . '.sha256', str_repeat('0', 64));
    $rejected = false;
    try { $backup->verifyRestore($backupPath); } catch (RuntimeException) { $rejected = true; }
    check($rejected, 'Corrupted backup checksum is rejected');
    file_put_contents($backupPath . '.sha256', $originalChecksum);
} finally {
    unlink($backupPath); unlink($backupPath . '.sha256');
}

$limitedCsrf = csrf('limited');
for ($attempt = 0; $attempt < 5; $attempt++) form('limited', '/login', ['csrf_token' => $limitedCsrf, 'email' => 'limited@example.test', 'password' => 'wrong'], 200);
form('limited', '/login', ['csrf_token' => $limitedCsrf, 'email' => 'limited@example.test', 'password' => 'wrong'], 429);
for ($attempt = 0; $attempt < 3; $attempt++) form('limited', '/forgot-password', ['csrf_token' => $limitedCsrf, 'email' => 'unknown@example.test'], 200);
[, $limitedReset] = form('limited', '/forgot-password', ['csrf_token' => $limitedCsrf, 'email' => 'unknown@example.test'], 429);
check(str_contains($limitedReset, 'Try again in ') && str_contains($limitedReset, 'name="email"') && str_contains($limitedReset, 'Back to sign in'), 'Reset rate limit keeps the form and shows the wait time');
for ($attempt = 0; $attempt < 5; $attempt++) form('limited', '/verify-reset-otp', ['csrf_token' => $limitedCsrf, 'email' => 'unknown@example.test', 'code' => '000000'], 200);
[, $limitedOtp] = form('limited', '/verify-reset-otp', ['csrf_token' => $limitedCsrf, 'email' => 'unknown@example.test', 'code' => '000000'], 429);
check(str_contains($limitedOtp, 'Try again in ') && str_contains($limitedOtp, 'name="code"'), 'OTP rate limit keeps the code form and shows the wait time');
for ($attempt = 0; $attempt < 5; $attempt++) request('admin', 'POST', '/api/security/confirm', ['password' => 'wrong'], $adminHeaders, 422);
request('admin', 'POST', '/api/security/confirm', ['password' => 'NewPassword123'], $adminHeaders, 429);
