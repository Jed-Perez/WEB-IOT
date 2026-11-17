<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

final class AccountSecurity
{
    public static function current(): ?array
    {
        if (!isset($_SESSION['user_id'])) return null;
        $user = (new User())->findById((int) $_SESSION['user_id']);
        if (!$user || $user['status'] !== 'Active' || (int) $user['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
            RememberLogin::forget();
            $_SESSION = [];
            session_regenerate_id(true);
            return null;
        }
        return $user;
    }

    public static function requireConfirmation(): void
    {
        if (time() - (int) ($_SESSION['password_confirmed_at'] ?? 0) > 300) {
            throw new HttpException(428, 'Confirm your password to continue.');
        }
    }

    // Call within the transaction that changes account credentials or status.
    public static function revoke(int $userId): void
    {
        Database::query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
    }
}
