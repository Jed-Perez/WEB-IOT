<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiController;
use App\Core\Database as DB;
use App\Core\HttpException;
use App\Core\AccountSecurity;
use App\Models\Activity;
use App\Models\Greenhouse;

final class UserController extends ApiController
{
    public function index(): void
    {
        $this->user(admin: true);
        $this->json(['users' => DB::query('SELECT id, full_name, email, role, status FROM users ORDER BY id')->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }

    public function save(): void
    {
        $actor = $this->user(admin: true, write: true);
        AccountSecurity::requireConfirmation();
        $input = $this->input();
        $id = isset($input['id']) ? $this->id($input['id']) : null;
        foreach (['full_name', 'email', 'role', 'password', 'status'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) throw new HttpException(422, 'Account fields must be strings.');
        }
        $name = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? 'Farm Staff';
        $password = $input['password'] ?? '';
        $status = $input['status'] ?? null;
        if ($status !== null && !in_array($status, ['Pending', 'Active', 'Suspended', 'Rejected'], true)) throw new HttpException(422, 'Invalid account status.');
        if ($name === '' || mb_strlen($name) > 100 || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpException(422, 'Enter a name (up to 100 characters) and valid email (up to 150 characters).');
        if (!in_array($role, ['Administrator', 'Farm Staff'], true)) throw new HttpException(422, 'Invalid role.');
        if (($id === null || $password !== '') && (strlen($password) < 8 || strlen($password) > 72)) throw new HttpException(422, 'Passwords must contain 8 to 72 bytes.');
        try {
            Greenhouse::transaction(function () use ($id, $actor, $name, $email, $role, $password, $status) {
                $users = DB::query('SELECT id, full_name, email, role, status FROM users ORDER BY id FOR UPDATE')->get_result()->fetch_all(MYSQLI_ASSOC);
                $admins = array_filter($users, fn($u) => $u['role'] === 'Administrator' && $u['status'] === 'Active');
                if (!array_filter($admins, fn($u) => (int) $u['id'] === (int) $actor['id'])) throw new HttpException(403, 'Administrator access is required.');
                if ($id !== null) {
                    $existing = array_values(array_filter($users, fn($u) => (int) $u['id'] === $id))[0] ?? null;
                    if (!$existing) throw new HttpException(404, 'User not found.');
                    $nextStatus = $status ?? $existing['status'];
                    if ($existing['role'] === 'Administrator' && $existing['status'] === 'Active' && ($role !== 'Administrator' || $nextStatus !== 'Active') && count($admins) <= 1) throw new HttpException(409, 'Keep at least one active administrator.');
                    DB::query('UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?', [$name, $email, $role, $nextStatus, $id]);
                    if ($password !== '') {
                        DB::query('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                    if ($password !== '' || $existing['status'] !== $nextStatus || $existing['role'] !== $role) AccountSecurity::revoke($id);
                    Activity::record((int) $actor['id'], $existing['status'] === 'Pending' && $nextStatus === 'Active' ? 'user.approve' : 'user.update', 'user', $id, $existing,
                        ['full_name' => $name, 'email' => $email, 'role' => $role, 'status' => $nextStatus, 'password_changed' => $password !== '']);
                } else {
                    DB::query('INSERT INTO users (full_name, email, role, password, status) VALUES (?, ?, ?, ?, ?)', [$name, $email, $role, password_hash($password, PASSWORD_DEFAULT), $status ?? 'Active']);
                    $createdId = DB::connection()->insert_id;
                    Activity::record((int) $actor['id'], 'user.create', 'user', $createdId, null, ['full_name' => $name, 'email' => $email, 'role' => $role, 'status' => $status ?? 'Active']);
                }
            });
        } catch (\mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) throw new HttpException(409, 'That email is already registered.');
            throw $exception;
        }
        $this->json(['message' => 'Account saved.'], $id === null ? 201 : 200);
    }

    public function delete(): void
    {
        $actor = $this->user(admin: true, write: true);
        AccountSecurity::requireConfirmation();
        $input = $this->input();
        $id = $this->id($input['id'] ?? null);
        if ($id === (int) $actor['id']) throw new HttpException(409, 'You cannot delete your own account.');
        Greenhouse::transaction(function () use ($id, $actor) {
            $users = DB::query('SELECT id, full_name, email, role, status FROM users ORDER BY id FOR UPDATE')->get_result()->fetch_all(MYSQLI_ASSOC);
            $admins = array_filter($users, fn($u) => $u['role'] === 'Administrator' && $u['status'] === 'Active');
            if (!array_filter($admins, fn($u) => (int) $u['id'] === (int) $actor['id'])) throw new HttpException(403, 'Administrator access is required.');
            $existing = array_values(array_filter($users, fn($u) => (int) $u['id'] === $id))[0] ?? null;
            if (!$existing) throw new HttpException(404, 'User not found.');
            if ($existing['role'] === 'Administrator' && $existing['status'] === 'Active' && count($admins) <= 1) throw new HttpException(409, 'Keep at least one active administrator.');
            Activity::record((int) $actor['id'], 'user.delete', 'user', $id, $existing);
            DB::query('DELETE FROM users WHERE id = ?', [$id]);
        });
        $this->json(['message' => 'Account deleted.']);
    }
}
