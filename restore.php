<?php
session_start();
require_once 'config.php';

// 检查是否已登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$backup_dir = 'backups';

// 处理恢复请求
if (isset($_POST['restore'])) {
    $backup_file = $_POST['backup_file'];
    
    if (empty($backup_file)) {
        $error = '请选择要恢复的备份文件';
    } else {
        $file_path = $backup_dir . '/' . basename($backup_file);
        
        if (!file_exists($file_path)) {
            $error = '备份文件不存在';
        } else {
            try {
                $pdo->beginTransaction();
                
                // 清空现有数据
                $pdo->exec("DELETE FROM outbound_records");
                $pdo->exec("DELETE FROM production_plans");
                
                // 读取备份文件
                $handle = fopen($file_path, 'r');
                if ($handle === false) {
                    throw new Exception('无法打开备份文件');
                }
                
                // 跳过标题行
                fgetcsv($handle);
                
                // 读取数据
                while (($data = fgetcsv($handle)) !== false) {
                    if (count($data) >= 5) {
                        $id = $data[0];
                        $product_code = $data[1];
                        $planned_quantity = $data[2];
                        $plan_name = $data[3];
                        $outbound_records = $data[4];
                        
                        // 插入生产计划
                        $stmt = $pdo->prepare("INSERT INTO production_plans (id, product_code, planned_quantity, plan_name) VALUES (?, ?, ?, ?)");
                        $stmt->execute(array($id, $product_code, $planned_quantity, $plan_name));
                        
                        // 处理出库记录
                        if (!empty($outbound_records)) {
                            $records = explode('|', $outbound_records);
                            foreach ($records as $record) {
                                if (!empty($record)) {
                                    $parts = explode(';', $record);
                                    if (count($parts) >= 3) {
                                        $quantity = $parts[0];
                                        $remark = $parts[1];
                                        $date = $parts[2];
                                        $stmt = $pdo->prepare("INSERT INTO outbound_records (plan_id, outbound_quantity, remark, outbound_date) VALUES (?, ?, ?, ?)");
                                        $stmt->execute(array($id, $quantity, $remark, $date));
                                    }
                                }
                            }
                        }
                    }
                }
                
                fclose($handle);
                $pdo->commit();
                $success = '数据恢复成功！';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = '恢复失败：' . $e->getMessage();
            }
        }
    }
}

// 获取备份文件列表
$backup_files = array();
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'csv') {
            $backup_files[] = $file;
        }
    }
    rsort($backup_files); // 按文件名降序排序（最新的在前）
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>恢复数据 - 仓库出库记录系统</title>
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
        .alert {
            border-radius: 10px;
        }
        .backup-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .backup-item {
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .backup-item:last-child {
            border-bottom: none;
        }
        .backup-actions {
            display: flex;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">恢复数据</h1>
            <div>
                <a href="backup.php" class="btn btn-outline-primary me-2">返回备份管理</a>
                <a href="index.php" class="btn btn-outline-secondary">返回主页</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">选择备份文件</h5>
            </div>
            <div class="card-body">
                <?php if (empty($backup_files)): ?>
                    <div class="alert alert-info">暂无可用的备份文件</div>
                <?php else: ?>
                    <div class="backup-list">
                        <?php foreach ($backup_files as $file): ?>
                            <div class="backup-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($file); ?></strong>
                                    <small class="text-muted ms-2">
                                        <?php echo date('Y-m-d H:i:s', filemtime($backup_dir . '/' . $file)); ?>
                                    </small>
                                </div>
                                <div class="backup-actions">
                                    <a href="download_backup.php?file=<?php echo urlencode($file); ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        下载
                                    </a>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($file); ?>">
                                        <button type="submit" name="restore" class="btn btn-sm btn-warning" 
                                                onclick="return confirm('确定要恢复此备份吗？当前数据将被覆盖。')">
                                            恢复
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 