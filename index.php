<?php
session_start();

// 检查是否已安装
if (!file_exists('config.php')) {
    header('Location: install_check.php');
    exit;
}

require_once 'config.php';

// 检查是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 获取用户信息
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$is_admin = $_SESSION['role'] === ROLE_ADMIN;
$is_storage = $_SESSION['role'] === ROLE_STORAGE;

$error = '';
$success = '';

// 处理添加生产计划
if (isset($_POST['add_plan'])) {
    $product_code = trim($_POST['product_code']);
    $planned_quantity = trim($_POST['planned_quantity']);
    $plan_name = trim($_POST['plan_name']);
    $plan_date = date('Y-m-d'); // 使用当前日期，格式为YYYY-MM-DD
    
    // 验证输入
    if (empty($plan_name) || empty($product_code) || empty($planned_quantity)) {
        $error = '所有字段都必须填写';
    } else {
        try {
            // 先检查表结构
            $check_table = $pdo->query("SHOW COLUMNS FROM production_plans LIKE 'plan_date'");
            if ($check_table->rowCount() == 0) {
                // 如果plan_date字段不存在，添加它
                $pdo->exec("ALTER TABLE production_plans ADD COLUMN plan_date DATE NOT NULL");
                $pdo->exec("UPDATE production_plans SET plan_date = CURRENT_DATE WHERE plan_date IS NULL");
            }
            
            // 验证日期格式
            $date = DateTime::createFromFormat('Y-m-d', $plan_date);
            if (!$date) {
                throw new Exception('无效的日期格式');
            }
            
            // 使用预处理语句插入数据
            $stmt = $pdo->prepare("INSERT INTO production_plans (product_code, planned_quantity, plan_name, plan_date, user_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute(array(
                $product_code,
                $planned_quantity,
                $plan_name,
                $date->format('Y-m-d'),
                $user_id
            ));
            
            header('Location: index.php');
            exit;
        } catch(Exception $e) {
            $error = '添加生产计划失败：' . $e->getMessage();
        }
    }
}

// 处理添加出库记录
if (isset($_POST['add_outbound'])) {
    $plan_id = $_POST['plan_id'];
    $quantity = $_POST['quantity'];
    $remark = $_POST['remark'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO outbound_records (plan_id, outbound_quantity, remark) VALUES (?, ?, ?)");
        $stmt->execute(array($plan_id, $quantity, $remark));
        header('Location: index.php');
        exit;
    } catch(PDOException $e) {
        $error = '添加出库记录失败：' . $e->getMessage();
    }
}

// 处理出库请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['outbound_quantity'])) {
    $plan_id = (int)$_POST['plan_id'];
    $outbound_quantity = (int)$_POST['outbound_quantity'];
    $remark = trim($_POST['remark']);
    
    if (empty($remark)) {
        $remark = '识别码';
    }
    
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 检查计划是否存在
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
            throw new Exception('计划不存在');
        }
        
        // 检查出库数量
        if ($outbound_quantity <= 0) {
            throw new Exception('出库数量必须大于0');
        }
        
        // 检查是否超过计划数量
        if ($outbound_quantity > ($plan['planned_quantity'] - $plan['total_outbound'])) {
            throw new Exception('出库数量不能超过计划剩余数量');
        }

        // 处理照片上传
        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/outbound_photos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception('只允许上传 JPG, JPEG, PNG 或 GIF 格式的图片');
            }
            
            $new_filename = uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_path)) {
                $photo_path = $target_path;
            } else {
                throw new Exception('照片上传失败');
            }
        }
        
        // 添加出库记录
        $stmt = $pdo->prepare("
            INSERT INTO outbound_records (plan_id, user_id, outbound_quantity, remark, photo_path, outbound_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$plan_id, $user_id, $outbound_quantity, $remark, $photo_path]);
        
        // 提交事务
        $pdo->commit();
        
        $success = '出库成功！';
        
        // 刷新计划列表
        if ($is_admin) {
            $stmt = $pdo->prepare("
                SELECT p.*, 
                       COALESCE(SUM(o.outbound_quantity), 0) as total_outbound,
                       p.planned_quantity - COALESCE(SUM(o.outbound_quantity), 0) as remaining_quantity,
                       u.username as user_name
                FROM production_plans p
                LEFT JOIN outbound_records o ON p.id = o.plan_id
                LEFT JOIN users u ON p.user_id = u.id
                GROUP BY p.id
                ORDER BY p.plan_date DESC, p.created_at DESC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT p.*, 
                       COALESCE(SUM(o.outbound_quantity), 0) as total_outbound,
                       p.planned_quantity - COALESCE(SUM(o.outbound_quantity), 0) as remaining_quantity
                FROM production_plans p
                LEFT JOIN outbound_records o ON p.id = o.plan_id
                WHERE p.user_id = ?
                GROUP BY p.id
                ORDER BY p.plan_date DESC, p.created_at DESC
            ");
            $stmt->execute([$user_id]);
        }
        $plans = $stmt->fetchAll();
    } catch (Exception $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '出库失败：' . $e->getMessage();
    }
}

// 处理删除出库记录
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_outbound'])) {
    $outbound_id = $_POST['outbound_id'];
    
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 获取出库记录信息
        if ($is_admin) {
            $stmt = $pdo->prepare("SELECT * FROM outbound_records WHERE id = ?");
            $stmt->execute([$outbound_id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM outbound_records WHERE id = ? AND user_id = ?");
            $stmt->execute([$outbound_id, $user_id]);
        }
        $outbound = $stmt->fetch();
        
        if (!$outbound) {
            throw new Exception('出库记录不存在或无权限删除');
        }
        
        // 删除出库记录
        $stmt = $pdo->prepare("DELETE FROM outbound_records WHERE id = ?");
        $stmt->execute([$outbound_id]);
        
        // 提交事务
        $pdo->commit();
        
        $success = '出库记录删除成功！';
    } catch (Exception $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '删除出库记录失败：' . $e->getMessage();
    }
}

// 处理删除指定时间计划
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_plans_by_date']) && $is_admin) {
    try {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        // 验证日期
        if (empty($start_date) || empty($end_date)) {
            throw new Exception('请选择开始和结束日期');
        }
        
        if ($start_date > $end_date) {
            throw new Exception('开始日期不能大于结束日期');
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 先删除指定日期范围内的出库记录
        $stmt = $pdo->prepare("
            DELETE o FROM outbound_records o
            INNER JOIN production_plans p ON o.plan_id = p.id
            WHERE DATE(p.plan_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        
        // 再删除指定日期范围内的生产计划
        $stmt = $pdo->prepare("
            DELETE FROM production_plans 
            WHERE DATE(plan_date) BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        
        // 提交事务
        $pdo->commit();
        
        $success = '指定时间范围内的计划已成功删除！';
    } catch (Exception $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '删除指定时间计划失败：' . $e->getMessage();
    }
}

// 获取日期参数，默认为今天
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 获取所有计划及其出库记录
try {
    // 根据用户角色获取计划列表
    if ($is_admin || $is_storage) {
        $stmt = $pdo->prepare("
            SELECT p.*, u.username as user_name,
            COALESCE((SELECT SUM(outbound_quantity) FROM outbound_records WHERE plan_id = p.id), 0) as total_outbound,
            p.planned_quantity - COALESCE((SELECT SUM(outbound_quantity) FROM outbound_records WHERE plan_id = p.id), 0) as remaining_quantity
            FROM production_plans p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE DATE(p.plan_date) = ?
            ORDER BY p.plan_date DESC, p.id DESC
        ");
        $stmt->execute([$selected_date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, u.username as user_name,
            COALESCE((SELECT SUM(outbound_quantity) FROM outbound_records WHERE plan_id = p.id), 0) as total_outbound,
            p.planned_quantity - COALESCE((SELECT SUM(outbound_quantity) FROM outbound_records WHERE plan_id = p.id), 0) as remaining_quantity
            FROM production_plans p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.user_id = ? AND DATE(p.plan_date) = ?
            ORDER BY p.plan_date DESC, p.id DESC
        ");
        $stmt->execute([$user_id, $selected_date]);
    }
    $plans = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "获取计划列表失败：" . $e->getMessage();
}

// 处理删除所有计划
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_all_plans']) && $is_admin) {
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 先删除所有出库记录
        $pdo->exec("DELETE FROM outbound_records");
        
        // 再删除所有生产计划
        $pdo->exec("DELETE FROM production_plans");
        
        // 提交事务
        $pdo->commit();
        
        $success = '所有计划已成功删除！';
    } catch (Exception $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '删除所有计划失败：' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仓库出库记录系统</title>
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
        .table {
            margin-bottom: 0;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .btn {
            border-radius: 5px;
        }
        .form-control {
            border-radius: 5px;
        }
        .alert {
            border-radius: 10px;
        }
        .plan-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .plan-info {
            flex: 1;
            margin-bottom: 15px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .info-label {
            color: #666;
        }
        .info-value {
            font-weight: 500;
        }
        .product-code {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        .plan-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        .plan-actions {
            margin-top: auto;
        }
        .nav-buttons {
            display: flex;
            gap: 10px;
        }
        .plan-actions .btn {
            padding: 8px 12px;
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
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            .d-flex.justify-content-between {
                flex-direction: column;
                gap: 15px;
            }
            .nav-buttons {
                flex-direction: row;
                flex-wrap: wrap;
                width: 100%;
                justify-content: flex-start;
            }
            .nav-buttons .btn {
                flex: 1;
                min-width: 120px;
                margin-bottom: 5px;
            }
            .plan-card {
                padding: 12px;
            }
            .info-item {
                font-size: 13px;
            }
            .plan-actions .btn {
                padding: 8px 15px;
            }
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-light mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">仓库管理系统</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">排产计划</a>
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
                    <li class="nav-item">
                        <button type="button" class="nav-link btn btn-link text-danger" data-bs-toggle="modal" data-bs-target="#deletePlansByDateModal">
                            删除指定时间计划
                        </button>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link">欢迎，<?php echo htmlspecialchars($username); ?></span>
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
            <h1 class="h3">生产计划管理</h1>
            <div>
                <div class="d-flex gap-2">
                    <div class="nav-buttons">
                        <?php if ($is_admin || $is_storage): ?>
                        <a href="backup.php" class="btn btn-info">备份管理</a>
                        <?php endif; ?>
                        <a href="report.php" class="btn btn-info">一键报表</a>
                        <a href="daily_report.php" class="btn btn-info">出库日报表</a>
                        <a href="change_password.php" class="btn btn-warning">修改密码</a>
                        <a href="logout.php" class="btn btn-danger">退出登录</a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- 添加新计划表单 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">添加新计划</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">计划名称</label>
                                <input type="text" name="plan_name" class="form-control" value="<?php echo date('n月j日'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">产品编码</label>
                                <input type="text" name="product_code" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">计划数量</label>
                                <input type="number" name="planned_quantity" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="add_plan" class="btn btn-primary w-100">添加计划</button>
                </form>
            </div>
        </div>

        <!-- 计划列表 -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">计划列表</h5>
                <form method="GET" class="d-flex gap-2">
                    <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>">
                    <button type="submit" class="btn btn-primary">查看</button>
                </form>
            </div>
            <div class="card-body">
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    <?php foreach ($plans as $plan): ?>
                        <div class="col">
                            <div class="plan-card">
                                <div class="plan-info">
                                    <div class="info-item">
                                        <span class="info-label">计划名称：</span>
                                        <span class="info-value"><?php echo htmlspecialchars($plan['plan_name']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">产品编号：</span>
                                        <span class="info-value"><?php echo htmlspecialchars($plan['product_code'] ?? '未设置'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">计划数量：</span>
                                        <span class="info-value"><?php echo $plan['planned_quantity']; ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">已出库：</span>
                                        <span class="info-value"><?php echo $plan['total_outbound']; ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">剩余数量：</span>
                                        <span class="info-value"><?php echo $plan['remaining_quantity']; ?></span>
                                    </div>
                                    <?php if ($is_admin): ?>
                                    <div class="info-item">
                                        <span class="info-label">创建用户：</span>
                                        <span class="info-value"><?php echo htmlspecialchars($plan['user_name']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="plan-actions">
                                    <div class="nav-buttons">
                                        <button type="button" class="btn btn-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#outboundModal<?php echo $plan['id']; ?>"
                                                <?php if ($is_storage) echo 'style="display:none;"'; ?>>
                                            出库
                                        </button>
                                        <button type="button" class="btn btn-info" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#outboundRecordsModal<?php echo $plan['id']; ?>">
                                            出库记录
                                        </button>
                                        <?php if ($is_admin || (!$is_storage && $plan['user_id'] == $user_id)): ?>
                                        <a href="edit_plan_quantity.php?id=<?php echo $plan['id']; ?>" 
                                           class="btn btn-warning">修改数量</a>
                                        <a href="delete_plan.php?id=<?php echo $plan['id']; ?>" 
                                           class="btn btn-danger">删除计划</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 出库记录模态框 -->
                        <div class="modal fade" id="outboundRecordsModal<?php echo $plan['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">出库记录 - <?php echo htmlspecialchars($plan['plan_name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <strong>产品编码：</strong> <?php echo htmlspecialchars($plan['product_code'] ?? '未设置'); ?>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>出库日期</th>
                                                        <th>出库数量</th>
                                                        <?php if ($is_admin || $is_storage): ?>
                                                        <th>操作人</th>
                                                        <?php endif; ?>
                                                        <th>备注</th>
                                                        <th>操作</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    try {
                                                        if ($is_admin || $is_storage) {
                                                            $stmt = $pdo->prepare("
                                                                SELECT o.*, u.username as operator_name
                                                                FROM outbound_records o
                                                                LEFT JOIN users u ON o.user_id = u.id
                                                                WHERE o.plan_id = ?
                                                                ORDER BY o.outbound_date DESC
                                                            ");
                                                            $stmt->execute([$plan['id']]);
                                                        } else {
                                                            $stmt = $pdo->prepare("
                                                                SELECT o.*, u.username as operator_name
                                                                FROM outbound_records o
                                                                LEFT JOIN users u ON o.user_id = u.id
                                                                WHERE o.plan_id = ? AND o.user_id = ?
                                                                ORDER BY o.outbound_date DESC
                                                            ");
                                                            $stmt->execute([$plan['id'], $user_id]);
                                                        }
                                                        $records = $stmt->fetchAll();
                                                        
                                                        if (empty($records)) {
                                                            echo '<tr><td colspan="' . ($is_admin || $is_storage ? '5' : '4') . '" class="text-center">暂无出库记录</td></tr>';
                                                        } else {
                                                            foreach ($records as $record) {
                                                                echo '<tr>';
                                                                echo '<td>' . htmlspecialchars($record['outbound_date']) . '</td>';
                                                                echo '<td>' . $record['outbound_quantity'] . '</td>';
                                                                if ($is_admin || $is_storage) {
                                                                    echo '<td>' . htmlspecialchars($record['operator_name']) . '</td>';
                                                                }
                                                                echo '<td>' . htmlspecialchars($record['remark'] ?? '') . '</td>';
                                                                echo '<td>';
                                                                if ($record['photo_path']) {
                                                                    echo '<img src="' . htmlspecialchars($record['photo_path']) . '" alt="出库照片" style="max-width: 100px; max-height: 100px; cursor: pointer;" onclick="showPhoto(this.src)">';
                                                                }
                                                                echo '</td>';
                                                                echo '<td>';
                                                                if ($is_admin || $record['user_id'] == $user_id) {
                                                                    echo '<div class="d-flex gap-2">';
                                                                    echo '<a href="edit_outbound.php?id=' . $record['id'] . '" class="btn btn-sm btn-primary">修改</a>';
                                                                    echo '<button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteOutboundModal' . $record['id'] . '">删除</button>';
                                                                    echo '</div>';
                                                                }
                                                                echo '</td>';
                                                                echo '</tr>';
                                                            }
                                                        }
                                                    } catch(PDOException $e) {
                                                        echo '<tr><td colspan="' . ($is_admin || $is_storage ? '5' : '4') . '" class="text-center text-danger">获取出库记录失败</td></tr>';
                                                    }
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 删除出库记录确认模态框 -->
                        <?php foreach ($records as $record): ?>
                        <div class="modal fade" id="deleteOutboundModal<?php echo $record['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">确认删除</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>确定要删除这条出库记录吗？</p>
                                        <p>出库时间：<?php echo $record['outbound_date']; ?></p>
                                        <p>出库数量：<?php echo $record['outbound_quantity']; ?></p>
                                        <p>操作人：<?php echo htmlspecialchars($record['operator_name']); ?></p>
                                    </div>
                                    <div class="modal-footer">
                                        <form method="POST">
                                            <input type="hidden" name="outbound_id" value="<?php echo $record['id']; ?>">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                            <button type="submit" name="delete_outbound" class="btn btn-danger">确认删除</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- 出库模态框 -->
                        <div class="modal fade" id="outboundModal<?php echo $plan['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">出库 - <?php echo htmlspecialchars($plan['plan_name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">出库数量</label>
                                                <input type="number" class="form-control" name="outbound_quantity" required min="1" max="<?php echo $plan['planned_quantity'] - $plan['total_outbound']; ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">备注</label>
                                                <input type="text" class="form-control" name="remark" placeholder="识别码">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">上传照片</label>
                                                <input type="file" class="form-control" name="photo" accept="image/*" capture="environment">
                                                <small class="text-muted">支持拍照或从相册选择</small>
                                            </div>
                                            <div class="text-end">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                <button type="submit" class="btn btn-primary">确认出库</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 删除指定时间计划确认模态框 -->
    <div class="modal fade" id="deletePlansByDateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">删除指定时间计划</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <h6>警告！</h6>
                            <p>此操作将删除选定日期范围内的所有生产计划和相关的出库记录，且无法恢复。</p>
                            <p>请确保在执行此操作前已备份所有重要数据。</p>
                        </div>
                        <div class="mb-3">
                            <label for="start_date" class="form-label">开始日期</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="end_date" class="form-label">结束日期</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" name="delete_plans_by_date" class="btn btn-danger">确认删除</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 添加照片查看模态框 -->
    <div class="modal fade" id="photoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">查看照片</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="photoView" src="" alt="出库照片" style="max-width: 100%;">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showPhoto(src) {
        document.getElementById('photoView').src = src;
        new bootstrap.Modal(document.getElementById('photoModal')).show();
    }
    </script>
</body>
</html> 