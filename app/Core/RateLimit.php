<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimit
{
    public static function hit(string $scope, string $identity, int $limit, int $seconds): void
    {
        $bucket = hash('sha256', $scope . ':' . strtolower(trim($identity)));
        $db = Database::connection();
        $db->begin_transaction();
        try {
            Database::query('INSERT IGNORE INTO rate_limits (bucket, attempts, resets_at) VALUES (?, 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))', [$bucket, $seconds]);
            $row = Database::query('SELECT attempts, resets_at FROM rate_limits WHERE bucket = ? FOR UPDATE', [$bucket])->get_result()->fetch_assoc();
            $expired = strtotime($row['resets_at']) <= time();
            if (!$expired && (int) $row['attempts'] >= $limit) {
                $db->commit();
                $retryAfter = max(1, strtotime($row['resets_at']) - time());
                header('Retry-After: ' . $retryAfter);
                $minutes = (int) ceil($retryAfter / 60);
                throw new HttpException(429, 'Too many attempts. Try again in ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.'));
            }
            if ($expired) Database::query('UPDATE rate_limits SET attempts = 1, resets_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE bucket = ?', [$seconds, $bucket]);
            else Database::query('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = ?', [$bucket]);
            $db->commit();
        } catch (\Throwable $error) { $db->rollback(); throw $error; }
    }

    public static function clear(string $scope, string $identity): void
    {
        Database::query('DELETE FROM rate_limits WHERE bucket = ?', [hash('sha256', $scope . ':' . strtolower(trim($identity)))]);
    }

    public static function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'local'; }
}
