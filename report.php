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

// 获取日期范围
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// 获取报表数据
try {
    if ($is_admin || $is_storage) {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.plan_name,
                p.product_code,
                p.created_at as plan_date,
                p.planned_quantity,
                p.created_at,
                u.username as user_name,
                COALESCE(SUM(o.outbound_quantity), 0) as total_outbound,
                (p.planned_quantity - COALESCE(SUM(o.outbound_quantity), 0)) as remaining_quantity,
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(
                            o2.outbound_date, '|',
                            o2.outbound_quantity, '|',
                            u2.username
                        ) SEPARATOR '||'
                    )
                    FROM outbound_records o2
                    LEFT JOIN users u2 ON o2.user_id = u2.id
                    WHERE o2.plan_id = p.id
                    ORDER BY o2.outbound_date DESC
                ) as outbound_details
            FROM production_plans p
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN outbound_records o ON p.id = o.plan_id
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            GROUP BY p.id, p.plan_name, p.product_code, p.created_at, p.planned_quantity, u.username
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$start_date, $end_date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.plan_name,
                p.product_code,
                p.created_at as plan_date,
                p.planned_quantity,
                p.created_at,
                COALESCE(SUM(o.outbound_quantity), 0) as total_outbound,
                (p.planned_quantity - COALESCE(SUM(o.outbound_quantity), 0)) as remaining_quantity,
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(
                            o2.outbound_date, '|',
                            o2.outbound_quantity, '|',
                            u2.username
                        ) SEPARATOR '||'
                    )
                    FROM outbound_records o2
                    LEFT JOIN users u2 ON o2.user_id = u2.id
                    WHERE o2.plan_id = p.id
                    ORDER BY o2.outbound_date DESC
                ) as outbound_details
            FROM production_plans p
            LEFT JOIN outbound_records o ON p.id = o.plan_id
            WHERE p.user_id = ? AND DATE(p.created_at) BETWEEN ? AND ?
            GROUP BY p.id, p.plan_name, p.product_code, p.created_at, p.planned_quantity
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$user_id, $start_date, $end_date]);
    }
    $plans = $stmt->fetchAll();

    // 计算统计数据
    $total_plans = count($plans);
    $total_quantity = 0;
    $total_outbound = 0;
    $completion_rate = 0;

    foreach ($plans as $plan) {
        $total_quantity += $plan['planned_quantity'];
        $total_outbound += $plan['total_outbound'];
    }

    if ($total_quantity > 0) {
        $completion_rate = round(($total_outbound / $total_quantity) * 100, 2);
    }
} catch(PDOException $e) {
    $error = "获取报表数据失败：" . $e->getMessage();
    $plans = [];
    $total_plans = 0;
    $total_quantity = 0;
    $total_outbound = 0;
    $completion_rate = 0;
}

// 初始化错误变量
if (!isset($error)) {
    $error = '';
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>一键报表 - 仓库管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { 
            padding: 15px;
            background-color: #f8f9fa;
        }
        .plan-card { 
            margin-bottom: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            background-color: white;
            overflow: hidden;
        }
        .plan-header {
            background-color: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
        }
        .plan-body {
            padding: 15px;
        }
        .plan-info {
            margin-bottom: 15px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 8px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        .outbound-records {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .outbound-item {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 4px 8px;
            font-weight: 500;
            color: #0d6efd;
        }
        .alert {
            border-radius: 10px;
        }
        @media print {
            .plan-card {
                break-inside: avoid;
                margin-bottom: 20px;
            }
            .no-print {
                display: none;
            }
        }
        #copyContent {
            position: absolute;
            left: -9999px;
        }
        .header-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .header-buttons .btn {
            flex: 1;
            min-width: 120px;
            white-space: nowrap;
        }
        @media (max-width: 768px) {
            .header-buttons {
                width: 100%;
                margin-top: 10px;
            }
            .header-buttons .btn {
                width: 100%;
                margin: 4px 0;
            }
        }
        .report-section {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .product-info {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 5px;
            align-items: center;
        }
        .info-line {
            padding: 5px 0;
            font-size: 14px;
            font-weight: 500;
        }
        .info-line:nth-child(odd) {
            color: #666;
        }
        .info-line:nth-child(even) {
            color: #333;
            font-weight: 600;
        }
        .card-title {
            font-weight: 600;
        }
        .display-6 {
            font-weight: 600;
        }
        @media print {
            .navbar, .btn, .card-header {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .card-body {
                padding: 0 !important;
            }
            .report-section {
                page-break-inside: avoid;
                border: none;
                padding: 0;
                margin-bottom: 30px;
            }
        }
        .outbound-date-card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .outbound-date {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #dee2e6;
        }
        .outbound-record-card {
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .outbound-product-code {
            font-weight: 500;
            color: #333;
        }
        .outbound-quantity {
            font-weight: 600;
            color: #0d6efd;
        }
        .outbound-record-card:last-child {
            margin-bottom: 0;
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
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">一键报表</h1>
            <div class="d-flex gap-2">
                <form method="GET" class="d-flex gap-2">
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                    <button type="submit" class="btn btn-primary">查询</button>
                </form>
                <button onclick="copyReport()" class="btn btn-success">一键复制</button>
                <a href="index.php" class="btn btn-secondary">返回主页</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div id="reportContent">
            <!-- 统计信息 -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">计划总数</h5>
                            <p class="card-text display-6"><?php echo $total_plans; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">计划总数量</h5>
                            <p class="card-text display-6"><?php echo $total_quantity; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">已出库数量</h5>
                            <p class="card-text display-6"><?php echo $total_outbound; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">完成率</h5>
                            <p class="card-text display-6"><?php echo $completion_rate; ?>%</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 详细数据 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">出库日报表</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($plans as $plan): ?>
                    <div class="report-section mb-4">
                        <div class="product-info">
                            <div class="info-line">计划名称：</div>
                            <div class="info-line"><?php echo htmlspecialchars($plan['plan_name']); ?></div>
                            <div class="info-line">产品编号：</div>
                            <div class="info-line"><?php echo htmlspecialchars($plan['product_code'] ?? '未设置'); ?></div>
                            <div class="info-line">计划数量：</div>
                            <div class="info-line"><?php echo $plan['planned_quantity']; ?> 个</div>
                            <div class="info-line">已出库数量：</div>
                            <div class="info-line"><?php echo $plan['total_outbound']; ?> 个</div>
                            <div class="info-line">剩余数量：</div>
                            <div class="info-line"><?php echo max(0, $plan['remaining_quantity']); ?> 个</div>
                            <div class="info-line">出库记录</div>
                            <div class="info-line">
                                <?php
                                try {
                                    if ($is_admin || $is_storage) {
                                        $stmt = $pdo->prepare("
                                            SELECT outbound_quantity 
                                            FROM outbound_records 
                                            WHERE plan_id = ? 
                                            ORDER BY outbound_date ASC
                                        ");
                                        $stmt->execute([$plan['id']]);
                                    } else {
                                        $stmt = $pdo->prepare("
                                            SELECT outbound_quantity 
                                            FROM outbound_records 
                                            WHERE plan_id = ? AND user_id = ? 
                                            ORDER BY outbound_date ASC
                                        ");
                                        $stmt->execute([$plan['id'], $user_id]);
                                    }
                                    $outbound_records = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    echo implode(' ', $outbound_records);
                                } catch(PDOException $e) {
                                    echo '<div class="text-danger">获取出库记录失败</div>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 用于复制的隐藏文本区域 -->
    <textarea id="copyContent" readonly></textarea>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function copyReport() {
        let reportText = '';
        const reportSections = document.querySelectorAll('.report-section');
        
        reportSections.forEach(section => {
            const planName = section.querySelector('.info-line:nth-child(2)').textContent;
            const productCode = section.querySelector('.info-line:nth-child(4)').textContent;
            const plannedQuantity = section.querySelector('.info-line:nth-child(6)').textContent;
            const totalOutbound = section.querySelector('.info-line:nth-child(8)').textContent;
            const remainingQuantity = section.querySelector('.info-line:nth-child(10)').textContent;
            const outboundRecords = section.querySelector('.info-line:nth-child(12)').textContent;
            
            reportText += `计划名称：${planName}\n`;
            reportText += `产品编号：${productCode}\n`;
            reportText += `计划数量：${plannedQuantity}\n`;
            reportText += `已出库数量：${totalOutbound}\n`;
            reportText += `剩余数量：${remainingQuantity}\n`;
            reportText += `出库记录：${outboundRecords}\n\n`;
        });
        
        navigator.clipboard.writeText(reportText).then(() => {
            alert('报表已复制到剪贴板');
        }).catch(err => {
            console.error('复制失败:', err);
            alert('复制失败，请手动复制');
        });
    }
    </script>
</body>
</html> 