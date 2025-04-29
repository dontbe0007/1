<?php
session_start();
require_once 'config.php';

// 检查是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['role'] === 'admin';
$error = '';
$success = '';

// 获取计划ID
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$plan_id) {
    header('Location: index.php');
    exit;
}

// 获取计划信息
try {
    if ($is_admin) {
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   COALESCE(SUM(o.outbound_quantity), 0) as total_outbound
            FROM production_plans p
            LEFT JOIN outbound_records o ON p.id = o.plan_id
            WHERE p.id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$plan_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   COALESCE(SUM(o.outbound_quantity), 0) as total_outbound
            FROM production_plans p
            LEFT JOIN outbound_records o ON p.id = o.plan_id
            WHERE p.id = ? AND p.user_id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$plan_id, $user_id]);
    }
    $plan = $stmt->fetch();

    if (!$plan) {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $error = '获取计划信息失败：' . $e->getMessage();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_quantity = trim($_POST['planned_quantity']);
    
    if (empty($new_quantity) || !is_numeric($new_quantity) || $new_quantity <= 0) {
        $error = '请输入有效的计划数量';
    } else if ($new_quantity < $plan['total_outbound']) {
        $error = '计划数量不能小于已出库数量';
    } else {
        try {
            // 开始事务
            $pdo->beginTransaction();
            
            // 更新计划数量
            $stmt = $pdo->prepare("UPDATE production_plans SET planned_quantity = ? WHERE id = ?");
            $stmt->execute([$new_quantity, $plan_id]);
            
            // 提交事务
            $pdo->commit();
            
            $success = '计划数量修改成功！';
            
            // 刷新计划信息
            if ($is_admin) {
                $stmt = $pdo->prepare("
                    SELECT p.*, 
                           COALESCE(SUM(o.outbound_quantity), 0) as total_outbound
                    FROM production_plans p
                    LEFT JOIN outbound_records o ON p.id = o.plan_id
                    WHERE p.id = ?
                    GROUP BY p.id
                ");
                $stmt->execute([$plan_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT p.*, 
                           COALESCE(SUM(o.outbound_quantity), 0) as total_outbound
                    FROM production_plans p
                    LEFT JOIN outbound_records o ON p.id = o.plan_id
                    WHERE p.id = ? AND p.user_id = ?
                    GROUP BY p.id
                ");
                $stmt->execute([$plan_id, $user_id]);
            }
            $plan = $stmt->fetch();
        } catch (Exception $e) {
            // 回滚事务
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = '修改计划数量失败：' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改计划数量 - 仓库管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            padding: 15px;
            background-color: #f8f9fa;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 10px 10px 0 0 !important;
        }
        .form-control {
            border-radius: 5px;
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

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">修改计划数量</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">计划名称</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($plan['plan_name']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">产品编码</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($plan['product_code']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">计划日期</label>
                                <input type="text" class="form-control" value="<?php echo date('Y-m-d', strtotime($plan['plan_date'])); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">已出库数量</label>
                                <input type="text" class="form-control" value="<?php echo $plan['total_outbound']; ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">计划数量</label>
                                <input type="number" name="planned_quantity" class="form-control" 
                                       value="<?php echo $plan['planned_quantity']; ?>" 
                                       required min="<?php echo $plan['total_outbound']; ?>">
                                <div class="form-text">
                                    最小可修改数量：<?php echo $plan['total_outbound']; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">创建时间</label>
                                <input type="text" class="form-control" value="<?php echo date('Y-m-d H:i:s', strtotime($plan['created_at'])); ?>" readonly>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">保存修改</button>
                                <a href="index.php" class="btn btn-secondary">返回</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 