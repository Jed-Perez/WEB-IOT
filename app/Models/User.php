<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public function findByEmail(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, full_name, email, password, status, session_version FROM users WHERE email = ?'
        );
        $statement->bind_param('s', $email);
        $statement->execute();

        $user = $statement->get_result()->fetch_assoc();
        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $database = Database::connection();
        $statement = $database->prepare('SELECT id, full_name, email, role, status, session_version FROM users WHERE id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();

        $user = $statement->get_result()->fetch_assoc();
        return $user ?: null;
    }

    public function create(string $name, string $email, string $passwordHash): string
    {
        $database = Database::connection();
        $lockName = 'greenpulse-register-' . ($database->query('SELECT DATABASE() name')->fetch_assoc()['name']);
        $lock = Database::query('SELECT GET_LOCK(?, 5) acquired', [$lockName])->get_result()->fetch_assoc();
        if ((int) $lock['acquired'] !== 1) throw new \RuntimeException('Registration is busy.');
        try {
            $role = (int) $database->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] === 0
                ? 'Administrator' : 'Farm Staff';
            $status = $role === 'Administrator' ? 'Active' : 'Pending';
            $statement = $database->prepare('INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?)');
            $statement->bind_param('sssss', $name, $email, $passwordHash, $role, $status);
            $statement->execute();
            return $status;
        } finally {
            Database::query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
