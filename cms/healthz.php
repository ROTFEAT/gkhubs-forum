<?php
// 独立运行 —— 不引导 WP，用于 Dokploy 健康检查。
// 即使 WP 慢，也必须 100ms 内返回。
// 用 mysqli（wordpress:6.5-apache 镜像默认带），不用 PDO（默认未装 pdo_mysql）。
header('Content-Type: application/json');
$db_ok = false;
$error = null;
$host = getenv('WORDPRESS_DB_HOST') ?: 'gkhubs-cms-db';
$name = getenv('WORDPRESS_DB_NAME') ?: 'wordpress';
$user = getenv('WORDPRESS_DB_USER') ?: 'wordpress';
$pass = getenv('WORDPRESS_DB_PASSWORD') ?: '';
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_init();
if ($conn) {
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    if (@mysqli_real_connect($conn, $host, $user, $pass, $name)) {
        if (@mysqli_query($conn, 'SELECT 1')) {
            $db_ok = true;
        } else {
            $error = mysqli_error($conn);
        }
        mysqli_close($conn);
    } else {
        $error = mysqli_connect_error();
    }
} else {
    $error = 'mysqli_init failed';
}
http_response_code($db_ok ? 200 : 503);
echo json_encode(['ok' => $db_ok, 'error' => $error]);
