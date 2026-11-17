<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $result = (new App\Models\Notifications())->run();
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['status'] === 'warning' ? 1 : 0);
} catch (Throwable $error) {
    error_log((string) $error);
    exit(1);
}
