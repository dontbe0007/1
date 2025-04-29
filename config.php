<?php
// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 's1038115db0');
define('DB_USER', 's1038115db0');
define('DB_PASS', 'd6a9bfbb');

// 用户角色定义
define('ROLE_ADMIN', 'admin');
define('ROLE_STORAGE', 'storage');  // 添加仓管角色
define('ROLE_USER', 'user');

// 开启错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}
?> 