<?php
session_start();
require_once 'config.php';

// 检查是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 检查是否是管理员
if ($_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// 处理备份请求
if (isset($_POST['backup'])) {
    try {
        // 创建备份目录
        $backup_dir = 'backups';
        if (!file_exists($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }

        // 生成备份文件名
        $backup_file = $backup_dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // 获取所有表名
        $tables = array();
        $result = $pdo->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        // 开始备份
        $output = '';
        foreach ($tables as $table) {
            // 获取表结构
            $result = $pdo->query("SHOW CREATE TABLE $table");
            $row = $result->fetch(PDO::FETCH_NUM);
            $output .= "\n\n" . $row[1] . ";\n\n";

            // 获取表数据
            $result = $pdo->query("SELECT * FROM $table");
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $row = array_map(function($value) use ($pdo) {
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $pdo->quote($value);
                }, $row);

                $output .= "INSERT INTO $table VALUES (" . implode(',', $row) . ");\n";
            }
        }

        // 保存备份文件
        file_put_contents($backup_file, $output);
        $success = '数据库备份成功！';
    } catch(PDOException $e) {
        $error = '备份失败：' . $e->getMessage();
    }
}

// 处理恢复请求
if (isset($_POST['restore'])) {
    try {
        if (isset($_FILES['backup_file'])) {
            // 从文件上传恢复
            $file = $_FILES['backup_file'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $temp_file = $file['tmp_name'];
                $sql = file_get_contents($temp_file);
            } else {
                throw new Exception('文件上传失败');
            }
        } else if (isset($_POST['backup_file'])) {
            // 从备份文件列表恢复
            $backup_file = 'backups/' . $_POST['backup_file'];
            if (!file_exists($backup_file)) {
                throw new Exception('备份文件不存在');
            }
            $sql = file_get_contents($backup_file);
        } else {
            throw new Exception('未指定备份文件');
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        // 执行SQL语句
        $pdo->exec($sql);
        
        // 提交事务
        $pdo->commit();
        
        $success = '数据库恢复成功！';
    } catch(Exception $e) {
        // 回滚事务
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '恢复失败：' . $e->getMessage();
    }
}

// 获取备份文件列表
$backup_files = array();
$backup_dir = 'backups';
if (file_exists($backup_dir)) {
    $files = glob($backup_dir . '/*.sql');
    foreach ($files as $file) {
        $backup_files[] = array(
            'name' => basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file))
        );
    }
    // 按日期降序排序
    usort($backup_files, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库备份管理 - 仓库管理系统</title>
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
                    <li class="nav-item">
                        <a class="nav-link" href="manage_users.php">用户管理</a>
                    </li>
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
            <h1 class="h3">数据库备份管理</h1>
            <a href="index.php" class="btn btn-secondary">返回主页</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- 备份和恢复表单 -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">创建备份</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <button type="submit" name="backup" class="btn btn-primary w-100">创建数据库备份</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">恢复备份</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">选择备份文件</label>
                                <input type="file" name="backup_file" class="form-control" accept=".sql" required>
                            </div>
                            <button type="submit" name="restore" class="btn btn-warning w-100" onclick="return confirm('确定要恢复此备份吗？这将覆盖当前数据库！')">恢复数据库</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 备份文件列表 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">备份文件列表</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>文件名</th>
                                <th>大小</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($backup_files)): ?>
                                <?php foreach ($backup_files as $file): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($file['name']); ?></td>
                                        <td><?php echo number_format($file['size'] / 1024, 2) . ' KB'; ?></td>
                                        <td><?php echo $file['date']; ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="<?php echo $backup_dir . '/' . $file['name']; ?>" class="btn btn-sm btn-info" download>下载</a>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($file['name']); ?>">
                                                    <button type="submit" name="restore" class="btn btn-sm btn-warning" onclick="return confirm('确定要恢复此备份吗？这将覆盖当前数据库！')">恢复</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">暂无备份文件</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 