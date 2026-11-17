<?php
declare(strict_types=1);

return [
    'notifications_enabled' => filter_var(getenv('NOTIFICATIONS_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'recipients' => array_values(array_filter(array_map('trim', explode(',', getenv('ALERT_EMAILS') ?: '')))),
    'abnormal_delay' => max(60, (int) (getenv('ALERT_DELAY_SECONDS') ?: 300)),
    'offline_delay' => max(120, (int) (getenv('OFFLINE_DELAY_SECONDS') ?: 300)),
    'command_delay' => max(30, (int) (getenv('COMMAND_ALERT_DELAY_SECONDS') ?: 60)),
    'repeat_seconds' => max(300, (int) (getenv('ALERT_REPEAT_SECONDS') ?: 3600)),
    'max_emails' => max(1, min(10, (int) (getenv('ALERT_MAX_EMAILS') ?: 3))),
    'hourly_limit' => max(1, min(100, (int) (getenv('ALERT_HOURLY_LIMIT') ?: 10))),
    'fresh_seconds' => 120,
    'backup_directory' => dirname(__DIR__) . '/storage/backups',
    'backup_keep' => max(2, (int) (getenv('BACKUP_KEEP') ?: 14)),
];
