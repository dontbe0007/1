-- 检查users表是否存在
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'
);

-- 检查必要的字段是否存在
ALTER TABLE users
    MODIFY COLUMN password VARCHAR(255) NOT NULL,
    MODIFY COLUMN role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    MODIFY COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active';

-- 检查索引
CREATE INDEX IF NOT EXISTS idx_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_status ON users(status);

-- 检查默认管理员账户是否存在
INSERT IGNORE INTO users (username, password, role, status) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active'); 