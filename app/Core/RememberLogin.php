<?php
declare(strict_types=1);

namespace App\Core;

final class RememberLogin
{
    public const COOKIE = 'greenpulse_remember';
    private const LIFETIME = 30 * 86400;

    private static function cookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => BASE_PATH . '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function cookieHash(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            ? hash('sha256', $token) : null;
    }

    public static function forget(): void
    {
        // Track the issued token in the session too, so logout still revokes it
        // if the browser cookie was removed or changed.
        $hashes = array_unique(array_filter([self::cookieHash(), $_SESSION['remember_token_hash'] ?? null]));
        foreach ($hashes as $hash) Database::query('DELETE FROM remember_tokens WHERE token_hash = ?', [$hash]);
        unset($_SESSION['remember_token_hash']);
        if (isset($_COOKIE[self::COOKIE]) || $hashes) self::cookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
    }

    public static function issue(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = time() + self::LIFETIME;
        Database::query('DELETE FROM remember_tokens WHERE expires_at <= UTC_TIMESTAMP()');
        Database::query('INSERT INTO remember_tokens (token_hash, user_id, expires_at) VALUES (?, ?, ?)',
            [$hash, $userId, gmdate('Y-m-d H:i:s', $expires)]);
        $_SESSION['remember_token_hash'] = $hash;
        self::cookie($token, $expires);
    }

    public static function restore(): void
    {
        if (isset($_SESSION['user_id']) || !isset($_COOKIE[self::COOKIE])) return;
        $hash = self::cookieHash();
        if ($hash === null) { self::cookie('', time() - 3600); return; }

        $db = Database::connection();
        $db->begin_transaction();
        try {
            $remembered = Database::query("SELECT r.user_id, r.expires_at, u.session_version FROM remember_tokens r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.expires_at > UTC_TIMESTAMP() AND u.status = 'Active' FOR UPDATE", [$hash])->get_result()->fetch_assoc();
            if (!$remembered) {
                Database::query('DELETE FROM remember_tokens WHERE token_hash = ?', [$hash]);
                $db->commit();
                self::cookie('', time() - 3600);
                return;
            }
            // Rotate the secret atomically, retaining the original 30-day deadline.
            $token = bin2hex(random_bytes(32));
            $nextHash = hash('sha256', $token);
            Database::query('UPDATE remember_tokens SET token_hash = ? WHERE token_hash = ?', [$nextHash, $hash]);
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollback();
            throw $exception;
        }

        session_regenerate_id(true);
        $_SESSION = ['user_id' => (int) $remembered['user_id'], 'session_version' => (int) $remembered['session_version'], 'remember_token_hash' => $nextHash];
        self::cookie($token, strtotime($remembered['expires_at']));
    }
}
