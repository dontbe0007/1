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

// 获取出库记录ID
$outbound_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$outbound_id) {
    header('Location: index.php');
    exit;
}

// 获取出库记录信息
try {
    if ($is_admin) {
        $stmt = $pdo->prepare("
            SELECT o.*, p.plan_name, p.planned_quantity, p.product_code,
                   COALESCE(SUM(COALESCE(o2.outbound_quantity, 0)), 0) as total_outbound
            FROM outbound_records o
            JOIN production_plans p ON o.plan_id = p.id
            LEFT JOIN outbound_records o2 ON o.plan_id = o2.plan_id AND o2.id != o.id
            WHERE o.id = ?
            GROUP BY o.id
        ");
        $stmt->execute([$outbound_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT o.*, p.plan_name, p.planned_quantity, p.product_code,
                   COALESCE(SUM(COALESCE(o2.outbound_quantity, 0)), 0) as total_outbound
            FROM outbound_records o
            JOIN production_plans p ON o.plan_id = p.id
            LEFT JOIN outbound_records o2 ON o.plan_id = o2.plan_id AND o2.id != o.id
            WHERE o.id = ? AND o.user_id = ?
            GROUP BY o.id
        ");
        $stmt->execute([$outbound_id, $user_id]);
    }
    $outbound = $stmt->fetch();

    if (!$outbound) {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $error = '获取出库记录失败：' . $e->getMessage();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_quantity = trim($_POST['outbound_quantity']);
    
    if (empty($new_quantity) || !is_numeric($new_quantity) || $new_quantity <= 0) {
        $error = '请输入有效的出库数量';
    } else {
        try {
            // 开始事务
            $pdo->beginTransaction();
            
            // 计算新的总出库量
            $new_total = $outbound['total_outbound'] - $outbound['outbound_quantity'] + $new_quantity;
            
            // 检查是否超过计划数量
            if ($new_total > $outbound['planned_quantity']) {
                throw new Exception('修改后的出库数量不能超过计划数量');
            }

            // 处理照片上传
            $photo_path = $outbound['photo_path'];
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
                    // 删除旧照片
                    if ($photo_path && file_exists($photo_path)) {
                        unlink($photo_path);
                    }
                    $photo_path = $target_path;
                } else {
                    throw new Exception('照片上传失败');
                }
            }
            
            // 更新出库记录
            $stmt = $pdo->prepare("UPDATE outbound_records SET outbound_quantity = ?, photo_path = ? WHERE id = ?");
            $stmt->execute([$new_quantity, $photo_path, $outbound_id]);
            
            // 提交事务
            $pdo->commit();
            
            $success = '出库记录修改成功！';
            
            // 刷新出库记录信息
            if ($is_admin) {
                $stmt = $pdo->prepare("
                    SELECT o.*, p.plan_name, p.planned_quantity, p.product_code,
                           COALESCE(SUM(COALESCE(o2.outbound_quantity, 0)), 0) as total_outbound
                    FROM outbound_records o
                    JOIN production_plans p ON o.plan_id = p.id
                    LEFT JOIN outbound_records o2 ON o.plan_id = o2.plan_id AND o2.id != o.id
                    WHERE o.id = ?
                    GROUP BY o.id
                ");
                $stmt->execute([$outbound_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT o.*, p.plan_name, p.planned_quantity, p.product_code,
                           COALESCE(SUM(COALESCE(o2.outbound_quantity, 0)), 0) as total_outbound
                    FROM outbound_records o
                    JOIN production_plans p ON o.plan_id = p.id
                    LEFT JOIN outbound_records o2 ON o.plan_id = o2.plan_id AND o2.id != o.id
                    WHERE o.id = ? AND o.user_id = ?
                    GROUP BY o.id
                ");
                $stmt->execute([$outbound_id, $user_id]);
            }
            $outbound = $stmt->fetch();
        } catch (Exception $e) {
            // 回滚事务
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = '修改出库记录失败：' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改出库记录 - 仓库管理系统</title>
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
                        <h5 class="mb-0">修改出库记录</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">计划名称</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($outbound['plan_name']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">产品编码</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($outbound['product_code']); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">计划数量</label>
                                <input type="text" class="form-control" value="<?php echo $outbound['planned_quantity']; ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">已出库数量（不含当前记录）</label>
                                <input type="text" class="form-control" value="<?php echo $outbound['total_outbound']; ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">出库数量</label>
                                <input type="number" class="form-control" name="outbound_quantity" value="<?php echo $outbound['outbound_quantity']; ?>" required min="1" max="<?php echo $outbound['planned_quantity'] - $outbound['total_outbound'] + $outbound['outbound_quantity']; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">当前照片</label>
                                <?php if ($outbound['photo_path']): ?>
                                    <div class="mb-2">
                                        <img src="<?php echo htmlspecialchars($outbound['photo_path']); ?>" alt="出库照片" style="max-width: 200px; max-height: 200px;">
                                    </div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="photo" accept="image/*" capture="environment">
                                <small class="text-muted">支持拍照或从相册选择，留空则保持原照片不变</small>
                            </div>
                            <div class="text-end">
                                <a href="index.php" class="btn btn-secondary">返回</a>
                                <button type="submit" class="btn btn-primary">保存修改</button>
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