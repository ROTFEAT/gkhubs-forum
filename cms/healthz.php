<?php
// 独立运行 —— 不引导 WP，用于 Dokploy 健康检查。
// 即使 WP 慢，也必须 100ms 内返回。
header('Content-Type: application/json');
$db_ok = false;
$error = null;
try {
    $host = getenv('WORDPRESS_DB_HOST') ?: 'gkhubs-cms-db';
    $name = getenv('WORDPRESS_DB_NAME') ?: 'wordpress';
    $user = getenv('WORDPRESS_DB_USER') ?: 'wordpress';
    $pass = getenv('WORDPRESS_DB_PASSWORD') ?: '';
    $pdo = new PDO("mysql:host=$host;dbname=$name", $user, $pass, [
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->query('SELECT 1');
    $db_ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
http_response_code($db_ok ? 200 : 503);
echo json_encode(['ok' => $db_ok, 'error' => $error]);
