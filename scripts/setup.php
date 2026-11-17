<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';
$config = require dirname(__DIR__) . '/config/database.php';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['database'])) {
    throw new RuntimeException('DB_DATABASE must contain only letters, digits, and underscores.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($config['host'], $config['username'], $config['password'], '', $config['port']);
$db->set_charset('utf8mb4');
$db->query('CREATE DATABASE IF NOT EXISTS `' . $config['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->select_db($config['database']);
foreach (['login_system.sql', 'database/greenhouse.sql', 'database/operations.sql'] as $file) {
    $sql = file_get_contents(dirname(__DIR__) . '/' . $file);
    $sql = preg_replace('/^(CREATE DATABASE[^;]+;|USE [^;]+;)\s*/m', '', $sql);
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
}
echo 'Database ' . $config['database'] . " is ready. Register your first administrator in the browser.\n";
