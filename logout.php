<?php
// 设置session配置
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));

session_start();

// 清除所有会话数据
session_unset();

// 销毁会话
session_destroy();

// 重定向到登录页面
header('Location: login.php');
exit; 