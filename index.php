<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/routes/web.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (BASE_PATH !== '' && ($requestPath === BASE_PATH || str_starts_with($requestPath, BASE_PATH . '/'))) {
    $requestPath = substr($requestPath, strlen(BASE_PATH)) ?: '/';
}

try {
    if (!str_starts_with($requestPath, '/api/device/')) {
        App\Core\AccountSecurity::current();
        App\Core\RememberLogin::restore();
    }
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath);
} catch (Throwable $exception) {
    $status = $exception instanceof App\Core\HttpException ? $exception->status : 500;
    $message = $status === 500 ? 'The service is unavailable. Check the database setup and server logs.' : $exception->getMessage();
    if ($status === 500) error_log((string) $exception);
    http_response_code($status);
    if (str_starts_with($requestPath, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]);
    } else {
        echo e($message);
    }
}
