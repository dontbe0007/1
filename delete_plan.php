<?php
session_start();
require_once 'config.php';

// 检查是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// 获取计划ID
if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$plan_id = $_GET['id'];
$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['role'] === 'admin';

// 获取计划信息
try {
    if ($is_admin) {
        $stmt = $pdo->prepare("SELECT * FROM production_plans WHERE id = ?");
        $stmt->execute([$plan_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM production_plans WHERE id = ? AND user_id = ?");
        $stmt->execute([$plan_id, $user_id]);
    }
    $plan = $stmt->fetch();
    
    if (!$plan) {
        header('Location: index.php');
        exit;
    }
} catch(PDOException $e) {
    $error = '获取计划信息失败：' . $e->getMessage();
}

// 处理删除请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 删除相关的出库记录
        $stmt = $pdo->prepare("DELETE FROM outbound_records WHERE plan_id = ?");
        $stmt->execute([$plan_id]);
        
        // 删除计划
        if ($is_admin) {
            $stmt = $pdo->prepare("DELETE FROM production_plans WHERE id = ?");
            $stmt->execute([$plan_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM production_plans WHERE id = ? AND user_id = ?");
            $stmt->execute([$plan_id, $user_id]);
        }
        
        // 提交事务
        $pdo->commit();
        
        $success = '计划删除成功！';
        header('Location: index.php');
        exit;
    } catch(PDOException $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '删除失败：' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>删除计划 - 仓库管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">仓库管理系统</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">排产计划</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="daily_report.php">日报表</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="report.php">一键报表</a>
                    </li>
                    <?php if ($is_admin): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_users.php">用户管理</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link">欢迎，<?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="change_password.php">修改密码</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">退出</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">删除计划</h1>
            <a href="index.php" class="btn btn-secondary">返回主页</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="alert alert-warning">
                    <h5 class="alert-heading">警告</h5>
                    <p>您确定要删除以下计划吗？此操作将同时删除所有相关的出库记录，且无法恢复。</p>
                </div>

                <div class="mb-4">
                    <h5>计划信息</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>计划名称：</strong><?php echo htmlspecialchars($plan['plan_name']); ?></p>
                            <p><strong>计划日期：</strong><?php echo $plan['plan_date']; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>计划数量：</strong><?php echo $plan['planned_quantity']; ?></p>
                            <p><strong>创建时间：</strong><?php echo $plan['created_at']; ?></p>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <div class="d-grid gap-2">
                        <button type="submit" name="confirm_delete" class="btn btn-danger">确认删除</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 