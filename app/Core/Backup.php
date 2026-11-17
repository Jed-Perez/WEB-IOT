<?php
declare(strict_types=1);

namespace App\Core;

final class Backup
{
    private static function identifier(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $value)) throw new \RuntimeException('Invalid database identifier.');
        return '`' . $value . '`';
    }

    private static function ordering(\mysqli $db, string $table): string
    {
        $keys = $db->query('SHOW KEYS FROM ' . self::identifier($table) . " WHERE Key_name = 'PRIMARY'")->fetch_all(MYSQLI_ASSOC);
        usort($keys, fn($a, $b) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
        if (!$keys) throw new \RuntimeException('Backup verification requires a primary key on ' . $table);
        return implode(', ', array_map(fn($key) => self::identifier($key['Column_name']), $keys));
    }

    public function create(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/operations.php';
        $directory = $config['backup_directory'];
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new \RuntimeException('Cannot create backup directory.');
        $directory = realpath($directory);
        $path = $directory . '/greenpulse-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jsonl.gz';
        $stream = gzopen($path . '.tmp', 'wb6');
        if (!$stream) throw new \RuntimeException('Cannot write backup.');
        $write = static function (array $record) use ($stream): void {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            if (gzwrite($stream, $line) !== strlen($line)) throw new \RuntimeException('Backup write failed.');
        };
        $db = Database::connection();
        $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        try {
            $tables = array_column($db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM), 0);
            sort($tables);
            $write(['type' => 'header', 'format' => 1, 'created_at' => gmdate('c'), 'tables' => $tables]);
            foreach ($tables as $table) {
                $order = self::ordering($db, $table);
                $schema = $db->query('SHOW CREATE TABLE ' . self::identifier($table))->fetch_row()[1];
                $write(['type' => 'table', 'name' => $table, 'schema' => $schema]);
                $result = $db->query('SELECT * FROM ' . self::identifier($table) . ' ORDER BY ' . $order, MYSQLI_USE_RESULT);
                $count = 0; $hash = hash_init('sha256');
                while ($row = $result->fetch_assoc()) {
                    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
                    $write(['type' => 'row', 'data' => $row]);
                    $count++;
                }
                $result->free();
                $write(['type' => 'table_end', 'count' => $count, 'sha256' => hash_final($hash)]);
            }
            $write(['type' => 'end']);
            $db->commit();
            gzclose($stream);
            if (!rename($path . '.tmp', $path)) throw new \RuntimeException('Cannot finalize backup.');
            if (file_put_contents($path . '.sha256', hash_file('sha256', $path)) === false) throw new \RuntimeException('Cannot write backup checksum.');
            @chmod($path, 0600); @chmod($path . '.sha256', 0600);
            return $path;
        } catch (\Throwable $error) {
            $db->rollback();
            if (is_resource($stream)) gzclose($stream);
            if (is_file($path . '.tmp')) unlink($path . '.tmp');
            throw $error;
        }
    }

    // Restores trusted local backups only, into a fresh disposable database.
    // Application database names cannot be supplied or overwritten by this method.
    public function verifyRestore(string $path): array
    {
        return $this->restore($path);
    }

    public function restore(string $path, ?string $target = null): array
    {
        if (!is_file($path) || !is_file($path . '.sha256') || !hash_equals(trim(file_get_contents($path . '.sha256')), hash_file('sha256', $path))) throw new \RuntimeException('Backup checksum is missing or does not match.');
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $db = new \mysqli($config['host'], $config['username'], $config['password'], '', $config['port']);
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone = '+00:00'");
        if ($target !== null && (!preg_match('/^greenpulse_recovered_[a-zA-Z0-9_]{1,40}$/D', $target) || $target === $config['database'])) throw new \RuntimeException('Recovery requires a new greenpulse_recovered_* database name.');
        $name = $target ?? ('greenpulse_restore_' . bin2hex(random_bytes(8)));
        $db->query('CREATE DATABASE ' . self::identifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->select_db($name);
        $stream = gzopen($path, 'rb');
        $tables = []; $table = null; $manifest = null; $complete = false; $success = false;
        try {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            while (($line = gzgets($stream)) !== false) {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                switch ($record['type'] ?? '') {
                    case 'header':
                        if ($manifest !== null || ($record['format'] ?? null) !== 1) throw new \RuntimeException('Invalid backup header.');
                        $manifest = $record['tables'];
                        break;
                    case 'table':
                        $table = $record['name'];
                        if (!is_array($manifest) || !in_array($table, $manifest, true) || isset($tables[$table]) || !str_starts_with($record['schema'], 'CREATE TABLE ' . self::identifier($table) . ' (')) throw new \RuntimeException('Invalid table schema.');
                        $db->query($record['schema']);
                        break;
                    case 'row':
                        if ($table === null) throw new \RuntimeException('Row outside table.');
                        $row = $record['data'];
                        $columns = implode(', ', array_map([self::class, 'identifier'], array_keys($row)));
                        $statement = $db->prepare('INSERT INTO ' . self::identifier($table) . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
                        $statement->execute(array_values($row)); $statement->close();
                        break;
                    case 'table_end':
                        if ($table === null) throw new \RuntimeException('Missing table.');
                        $count = 0; $hash = hash_init('sha256');
                        $result = $db->query('SELECT * FROM ' . self::identifier($table) . ' ORDER BY ' . self::ordering($db, $table), MYSQLI_USE_RESULT);
                        while ($row = $result->fetch_assoc()) { hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)); $count++; }
                        $result->free();
                        if ($count !== $record['count'] || !hash_equals($record['sha256'], hash_final($hash))) throw new \RuntimeException('Restored data differs in ' . $table);
                        $tables[$table] = $count; $table = null;
                        break;
                    case 'end': $complete = true; break;
                    default: throw new \RuntimeException('Unknown backup record.');
                }
            }
            if (!$complete || $table !== null || count($tables) !== count($manifest ?? [])) throw new \RuntimeException('Backup is incomplete.');
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
            if ($target !== null) {
                // A recovered copy must not revive old browser sessions or reset codes.
                $revoke = $db->prepare('UPDATE users SET session_version = ? WHERE id = ?');
                foreach ($db->query('SELECT id FROM users')->fetch_all(MYSQLI_ASSOC) as $user) $revoke->execute([random_int(1000000, 2000000000), $user['id']]);
                $revoke->close();
                $db->query('DELETE FROM remember_tokens');
                $db->query('DELETE FROM password_resets');
            }
            $success = true;
            return ['tables' => count($tables), 'rows' => array_sum($tables), 'verified' => true, 'database' => $target];
        } finally {
            if (is_resource($stream)) gzclose($stream);
            if ($target === null || !$success) $db->query('DROP DATABASE ' . self::identifier($name));
            $db->close();
        }
    }

    public function prune(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/operations.php';
        $directory = realpath($config['backup_directory']);
        if (!$directory) return;
        $files = glob($directory . '/greenpulse-*.jsonl.gz');
        rsort($files);
        foreach (array_slice($files, $config['backup_keep']) as $file) {
            foreach ([$file, $file . '.sha256'] as $target) {
                if (is_file($target) && !is_link($target) && dirname(realpath($target)) === $directory) unlink($target);
            }
        }
    }
}
