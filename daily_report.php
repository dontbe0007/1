<?php
session_start();
require_once 'config.php';

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 获取用户信息
$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['role'] === ROLE_ADMIN;
$is_storage = $_SESSION['role'] === ROLE_STORAGE;

// 初始化错误变量
$error = '';

// 获取日期参数
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 获取日报表数据
try {
    if ($is_admin || $is_storage) {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.plan_name,
                p.product_code,
                p.planned_quantity,
                p.plan_date,
                u.username as creator_name,
                COALESCE(SUM(o.outbound_quantity), 0) as total_outbound,
                COUNT(DISTINCT o.id) as outbound_count
            FROM production_plans p
            LEFT JOIN outbound_records o ON p.id = o.plan_id 
                AND DATE(o.outbound_date) = ?
            LEFT JOIN users u ON p.user_id = u.id
            WHERE DATE(p.plan_date) = ?
            GROUP BY p.id
            ORDER BY p.plan_date DESC, p.id DESC
        ");
        $stmt->execute([$date, $date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.plan_name,
                p.product_code,
                p.planned_quantity,
                p.plan_date,
                u.username as creator_name,
                COALESCE(SUM(o.outbound_quantity), 0) as total_outbound,
                COUNT(DISTINCT o.id) as outbound_count
            FROM production_plans p
            LEFT JOIN outbound_records o ON p.id = o.plan_id 
                AND DATE(o.outbound_date) = ?
            LEFT JOIN users u ON p.user_id = u.id
            WHERE DATE(p.plan_date) = ? AND p.user_id = ?
            GROUP BY p.id
            ORDER BY p.plan_date DESC, p.id DESC
        ");
        $stmt->execute([$date, $date, $user_id]);
    }
    $plans = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = '获取日报表数据失败：' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>日报表 - 仓库管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #64748b;
            --success: #059669;
            --light-bg: #f1f5f9;
            --border-color: #e2e8f0;
        }

        body { 
            background-color: var(--light-bg);
            min-height: 100vh;
        }

        .navbar {
            background-color: white !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0;
        }

        .navbar-brand {
            color: var(--primary) !important;
            font-weight: 600;
            font-size: 1.125rem;
        }

        .nav-link {
            color: var(--secondary) !important;
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--primary) !important;
            background-color: rgba(37, 99, 235, 0.05);
        }

        .page-header {
            background: white;
            padding: 1.5rem;
            margin: 1rem 0;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.25rem;
        }

        .search-form {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        .form-control {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn {
            padding: 0.625rem 1rem;
            font-weight: 500;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
        }

        .btn-secondary {
            background-color: #f8fafc;
            border-color: var(--border-color);
            color: var(--secondary);
        }

        .btn-secondary:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
            color: #475569;
        }

        .report-card {
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border-color);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .report-date {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: #1e293b;
            background-color: #f8fafc;
            font-size: 0.9375rem;
        }

        .report-item {
            padding: 1rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .report-item:last-child {
            border-bottom: none;
        }

        .product-code {
            color: #1e293b;
            font-weight: 500;
            font-size: 0.9375rem;
        }

        .quantity {
            background-color: #f1f5f9;
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            color: var(--primary);
            font-weight: 600;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 0.75rem 1rem;
            }

            .navbar-collapse {
                background: white;
                padding: 1rem;
                border-radius: 0.75rem;
                margin-top: 0.5rem;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            }

            .nav-link {
                padding: 0.75rem 1rem;
            }

            .page-header {
                margin: 0.75rem 0;
                padding: 1.25rem;
                border-radius: 0.5rem;
            }

            .search-form {
                flex-direction: column;
                width: 100%;
            }

            .search-form .form-control,
            .search-form .btn {
                width: 100%;
            }

            .report-card {
                border-radius: 0.5rem;
                margin: 0.75rem 0;
            }

            .report-item {
                padding: 0.875rem 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-light">
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
                        <a class="nav-link active" href="daily_report.php">日报表</a>
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
        <div class="page-header">
            <h1 class="page-title">出库日报表</h1>
            <form method="GET" class="search-form">
                <input type="date" name="date" class="form-control" value="<?php echo $date; ?>" max="<?php echo date('Y-m-d'); ?>">
                <button type="submit" class="btn btn-primary">查询</button>
                <a href="index.php" class="btn btn-secondary">返回主页</a>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php 
        $current_date = null;
        foreach ($plans as $plan): 
            $date = date('Y-m-d', strtotime($plan['plan_date']));
            if ($current_date !== $date) {
                if ($current_date !== null) {
                    echo '</div>';
                }
                echo '<div class="report-card">';
                echo '<div class="report-date">' . $date . ' 出库记录</div>';
                $current_date = $date;
            }
        ?>
            <div class="report-item">
                <span class="product-code"><?php echo htmlspecialchars($plan['product_code'] ?? '未设置'); ?></span>
                <span class="quantity"><?php echo $plan['total_outbound']; ?> 个</span>
            </div>
        <?php endforeach; ?>
        <?php if ($current_date !== null): ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 