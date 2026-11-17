<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database as DB;

final class Activity
{
    public static function record(?int $actor, string $action, string $entity, int|string $id, ?array $before = null, ?array $after = null): void
    {
        $name = $actor ? (DB::query('SELECT full_name FROM users WHERE id = ?', [$actor])->get_result()->fetch_assoc()['full_name'] ?? 'Deleted account') : 'System / device';
        DB::query('INSERT INTO activity_log (actor_id, actor_name, action, entity_type, entity_id, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$actor, $name, $action, $entity, (string) $id, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR)]);
    }
}
