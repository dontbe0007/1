<?php
session_start();
require_once 'config.php';

// 检查是否已登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// 检查文件参数
if (!isset($_GET['file'])) {
    die('未指定文件');
}

$filename = basename($_GET['file']);
$filepath = 'backups/' . $filename;

// 检查文件是否存在
if (!file_exists($filepath)) {
    die('文件不存在');
}

// 检查文件扩展名
if (pathinfo($filename, PATHINFO_EXTENSION) !== 'csv') {
    die('无效的文件类型');
}

// 输出文件内容
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit; 