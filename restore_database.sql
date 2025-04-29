-- 删除现有数据库（如果存在）
DROP DATABASE IF EXISTS warehouse_management;

-- 创建数据库
CREATE DATABASE IF NOT EXISTS warehouse_management DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE warehouse_management;

-- 创建用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 创建生产计划表
CREATE TABLE IF NOT EXISTS production_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_name VARCHAR(100) NOT NULL,
    product_code VARCHAR(50) NOT NULL,
    planned_quantity INT NOT NULL,
    plan_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 创建出库记录表
CREATE TABLE IF NOT EXISTS outbound_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    user_id INT NOT NULL,
    outbound_quantity INT NOT NULL,
    remark TEXT DEFAULT NULL,
    outbound_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES production_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 插入用户数据
INSERT INTO users (username, password, role) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('user1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user'),
('user2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user');

-- 插入生产计划数据
INSERT INTO production_plans (user_id, plan_name, product_code, planned_quantity, plan_date) VALUES
(1, '计划1', 'P001', 100, '2024-03-20'),
(1, '计划2', 'P002', 200, '2024-03-20'),
(2, '计划3', 'P003', 150, '2024-03-20'),
(3, '计划4', 'P004', 300, '2024-03-20'),
(1, '计划5', 'P005', 250, '2024-03-21'),
(2, '计划6', 'P006', 180, '2024-03-21'),
(3, '计划7', 'P007', 220, '2024-03-21');

-- 插入出库记录数据
INSERT INTO outbound_records (plan_id, user_id, outbound_quantity, remark, outbound_date) VALUES
(1, 1, 50, '识别码', '2024-03-20 10:00:00'),
(1, 1, 30, '识别码', '2024-03-20 14:00:00'),
(2, 1, 100, '识别码', '2024-03-20 11:00:00'),
(3, 2, 75, '识别码', '2024-03-20 12:00:00'),
(4, 3, 150, '识别码', '2024-03-20 13:00:00'),
(5, 1, 125, '识别码', '2024-03-21 09:00:00'),
(6, 2, 90, '识别码', '2024-03-21 10:00:00'),
(7, 3, 110, '识别码', '2024-03-21 11:00:00'); 