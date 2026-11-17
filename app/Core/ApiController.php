<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Models\Greenhouse;

abstract class ApiController extends Controller
{
    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }

    protected function user(bool $admin = false, bool $write = false): array
    {
        $user = AccountSecurity::current();
        if (!$user) throw new HttpException(401, 'Sign in to continue.');
        if ($admin && $user['role'] !== 'Administrator') throw new HttpException(403, 'Administrator access is required.');
        if ($write && !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new HttpException(403, 'Your session expired. Reload the page and try again.');
        return $user;
    }

    protected function device(): array
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer (.+)$/i', $authorization, $matches)) throw new HttpException(401, 'A device bearer token is required.');
        return (new Greenhouse())->authenticateDevice($matches[1]);
    }

    protected function input(): array
    {
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new HttpException(415, 'Send application/json.');
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) throw new HttpException(413, 'Request is too large.');
        try { $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(400, 'Invalid JSON.'); }
        if (!is_array($input) || !str_starts_with(ltrim($raw), '{')) throw new HttpException(400, 'Send a JSON object.');
        return $input;
    }

    protected function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);
        if ($id === false) throw new HttpException(422, 'A valid positive ID is required.');
        return $id;
    }

    protected function mode(mixed $mode): string
    {
        if (!in_array($mode, ['auto', 'manual'], true)) throw new HttpException(422, 'Mode must be auto or manual.');
        return $mode;
    }

    protected function boolean(mixed $value): bool
    {
        if (!is_bool($value)) throw new HttpException(422, 'The on field must be a JSON boolean.');
        return $value;
    }
}
