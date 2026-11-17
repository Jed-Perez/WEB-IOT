<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
if (count($argv) !== 2) exit('Usage: php scripts/restore-test.php path-to-trusted-backup.jsonl.gz' . PHP_EOL);
echo json_encode((new App\Core\Backup())->verifyRestore($argv[1]), JSON_THROW_ON_ERROR) . PHP_EOL;
