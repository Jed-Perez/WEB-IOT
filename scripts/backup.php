<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Core\{Backup, Database as DB};
$lock = 'gp-backup-' . DB::query('SELECT DATABASE() db')->get_result()->fetch_assoc()['db'];
if (!(int) DB::query('SELECT GET_LOCK(?, 0) acquired', [$lock])->get_result()->fetch_assoc()['acquired']) exit('Backup already running.' . PHP_EOL);
try {
    $backup = new Backup();
    $path = $backup->create();
    $result = $backup->verifyRestore($path);
    $backup->prune();
    $detail = basename($path) . '; restore verified: ' . $result['tables'] . ' tables, ' . $result['rows'] . ' rows.';
    DB::query("INSERT INTO maintenance_runs (job, last_run_at, status, detail) VALUES ('backup', UTC_TIMESTAMP(), 'ok', ?) ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), status = VALUES(status), detail = VALUES(detail)", [$detail]);
    echo $detail . PHP_EOL;
} catch (Throwable $error) {
    DB::query("INSERT INTO maintenance_runs (job, last_run_at, status, detail) VALUES ('backup', UTC_TIMESTAMP(), 'failed', 'Backup or restore verification failed. Check server logs.') ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), status = VALUES(status), detail = VALUES(detail)");
    error_log((string) $error);
    exit(1);
} finally { DB::query('SELECT RELEASE_LOCK(?)', [$lock]); }
