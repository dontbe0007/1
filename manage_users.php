<?php
session_start();
require_once 'config.php';

// 检查是否登录且是管理员
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 处理用户添加
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (!empty($username) && !empty($password)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            } catch(PDOException $e) {
                $error = "添加用户失败：" . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] == 'delete' && isset($_POST['user_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND id != ?");
            $stmt->execute([$_POST['user_id'], $_SESSION['user_id']]);
        } catch(PDOException $e) {
            $error = "删除用户失败：" . $e->getMessage();
        }
    }
}

// 获取所有用户
try {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "获取用户列表失败：" . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>用户管理 - 仓库管理系统</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            padding: 15px;
            background-color: #f8f9fa;
        }
        .card { 
            margin-bottom: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn {
            border-radius: 5px;
        }
        .alert {
            border-radius: 10px;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .table td {
            vertical-align: middle;
        }
        .navbar {
            background-color: rgba(255, 255, 255, 0.9) !important;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .navbar-brand {
            color: #333 !important;
        }
        .navbar-nav .nav-link {
            color: #333 !important;
            transition: color 0.3s ease;
        }
        .navbar-nav .nav-link:hover {
            color: #007bff !important;
        }
        .navbar-nav .nav-link.active {
            color: #007bff !important;
        }
        .navbar-toggler {
            border-color: rgba(0, 0, 0, 0.1);
        }
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(0, 0, 0, 0.75)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h2>用户管理</h2>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- 添加用户表单 -->
        <div class="card mb-4">
            <div class="card-header">
                添加新用户
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="username" class="form-label">用户名</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="password" class="form-label">密码</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="role" class="form-label">角色</label>
                                <select name="role" class="form-control" required>
                                    <option value="<?php echo ROLE_USER; ?>">普通用户</option>
                                    <option value="<?php echo ROLE_STORAGE; ?>">仓管</option>
                                    <option value="<?php echo ROLE_ADMIN; ?>">管理员</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">添加用户</button>
                </form>
            </div>
        </div>

        <!-- 用户列表 -->
        <div class="card">
            <div class="card-header">
                用户列表
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>角色</th>
                            <th>创建时间</th>
                            <th>最后登录</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td>
                                    <select name="role" class="form-control" required>
                                        <option value="<?php echo ROLE_USER; ?>" <?php echo $user['role'] === ROLE_USER ? 'selected' : ''; ?>>普通用户</option>
                                        <option value="<?php echo ROLE_STORAGE; ?>" <?php echo $user['role'] === ROLE_STORAGE ? 'selected' : ''; ?>>仓管</option>
                                        <option value="<?php echo ROLE_ADMIN; ?>" <?php echo $user['role'] === ROLE_ADMIN ? 'selected' : ''; ?>>管理员</option>
                                    </select>
                                </td>
                                <td><?php echo $user['created_at']; ?></td>
                                <td><?php echo $user['last_login'] ?? '从未登录'; ?></td>
                                <td>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除此用户吗？')">删除</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">
            <a href="index.php" class="btn btn-secondary">返回主页</a>
        </div>
    </div>

    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-light mb-4">
        <!-- 导航栏内容 -->
    </nav>
</body>
</html> 