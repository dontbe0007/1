<?php
require_once 'config.php';

try {
    // 检查 photo_path 字段是否已存在
    $stmt = $pdo->query("SHOW COLUMNS FROM outbound_records LIKE 'photo_path'");
    $columnExists = $stmt->rowCount() > 0;

    if (!$columnExists) {
        // 添加 photo_path 字段
        $sql = "ALTER TABLE outbound_records ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER remark";
        $pdo->exec($sql);
        echo "成功添加 photo_path 字段到 outbound_records 表\n";
    } else {
        echo "photo_path 字段已存在，无需更新\n";
    }

    // 创建照片上传目录
    $uploadDir = 'uploads/outbound_photos/';
    if (!file_exists($uploadDir)) {
        if (mkdir($uploadDir, 0777, true)) {
            echo "成功创建照片上传目录: $uploadDir\n";
        } else {
            echo "创建照片上传目录失败，请手动创建目录: $uploadDir\n";
        }
    } else {
        echo "照片上传目录已存在: $uploadDir\n";
    }

    echo "数据库更新完成！\n";

} catch (PDOException $e) {
    echo "数据库更新失败: " . $e->getMessage() . "\n";
}
?> 