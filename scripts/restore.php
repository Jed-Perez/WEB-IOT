<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
if (count($argv) !== 3) exit('Usage: php scripts/restore.php trusted-backup.jsonl.gz greenpulse_recovered_NAME' . PHP_EOL);
$result = (new App\Core\Backup())->restore($argv[1], $argv[2]);
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
echo 'Recovery verified. The current application database was not changed. To use this copy, set DB_DATABASE=' . $argv[2] . ' in .env. Old sessions and reset codes were revoked.' . PHP_EOL;
