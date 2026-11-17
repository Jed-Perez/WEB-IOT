<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{ApiController, AccountSecurity, Database as DB, HttpException, RateLimit, RememberLogin};
use App\Models\{Activity, Greenhouse};

final class SecurityController extends ApiController
{
    public function confirm(): void
    {
        $user = $this->user(write: true);
        $input = $this->input();
        RateLimit::hit('confirm-user', (string) $user['id'], 5, 900);
        $hash = DB::query('SELECT password FROM users WHERE id = ?', [$user['id']])->get_result()->fetch_assoc()['password'];
        if (!is_string($input['password'] ?? null) || !password_verify($input['password'], $hash)) throw new HttpException(422, 'Incorrect password.');
        RateLimit::clear('confirm-user', (string) $user['id']);
        $_SESSION['password_confirmed_at'] = time();
        $this->json(['message' => 'Password confirmed for five minutes.']);
    }

    public function logoutAll(): void
    {
        $user = $this->user(write: true);
        AccountSecurity::requireConfirmation();
        Greenhouse::transaction(function () use ($user) {
            AccountSecurity::revoke((int) $user['id']);
            Activity::record((int) $user['id'], 'sessions.revoke_all', 'user', $user['id']);
        });
        RememberLogin::forget();
        $_SESSION = [];
        session_regenerate_id(true);
        $this->json(['message' => 'Signed out on all devices.', 'redirect' => url('login')]);
    }

    public function activity(): void
    {
        $this->user(admin: true);
        $before = isset($_GET['before']) ? $this->id($_GET['before']) : PHP_INT_MAX;
        $rows = DB::query('SELECT * FROM activity_log WHERE id < ? ORDER BY id DESC LIMIT 51', [$before])->get_result()->fetch_all(MYSQLI_ASSOC);
        $page = array_slice($rows, 0, 50);
        $this->json(['rows' => $page, 'next' => count($rows) > 50 ? (int) end($page)['id'] : null]);
    }

    public function operations(): void
    {
        $this->user(admin: true);
        $this->json(['runs' => DB::query('SELECT * FROM maintenance_runs ORDER BY job')->get_result()->fetch_all(MYSQLI_ASSOC),
            'deliveries' => DB::query('SELECT d.id, d.recipient, d.status, d.attempts, d.sent_at, d.last_error, i.kind, i.message FROM notification_deliveries d JOIN notification_incidents i ON i.id = d.incident_id ORDER BY d.id DESC LIMIT 50')->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }
}
