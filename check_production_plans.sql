-- 检查production_plans表是否存在
CREATE TABLE IF NOT EXISTS production_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) NOT NULL,
    planned_quantity INT NOT NULL,
    plan_name VARCHAR(255) NOT NULL,
    plan_date DATE NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 检查字段是否存在，如果不存在则添加
ALTER TABLE production_plans
ADD COLUMN IF NOT EXISTS product_code VARCHAR(50) NOT NULL AFTER id,
ADD COLUMN IF NOT EXISTS plan_date DATE NOT NULL AFTER plan_name;

-- 检查索引是否存在，如果不存在则创建
CREATE INDEX IF NOT EXISTS idx_user_id ON production_plans(user_id);
CREATE INDEX IF NOT EXISTS idx_plan_date ON production_plans(plan_date); 