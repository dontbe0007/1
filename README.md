仓库管理系统
主要包含以下功能：
用户管理功能：
用户登录/登出系统
用户角色管理（管理员、仓管员、普通用户）
密码修改功能
用户管理界面
生产计划管理：
创建生产计划
编辑生产计划数量
删除生产计划
生产计划查询和展示
出库管理：
出库记录管理
出库数量记录
出库备注添加
出库照片上传功能
报表功能：
日常报表生成
报表查询和导出
生产计划统计
数据备份与恢复：
数据库备份功能
备份文件下载
数据库恢复功能
自动备份管理
系统安全：
用户认证和授权
数据库连接安全配置
错误处理和日志记录
文件管理：
上传文件管理（uploads目录）
备份文件管理（backups目录）
数据库管理：
数据库结构维护
数据库更新功能
数据库检查和修复
系统采用了PHP作为后端语言，MySQL作为数据库，使用了PDO进行数据库操作，支持UTF-8字符集。系统架构清晰，功能模块划分明确，适合用于中小型仓库的管理需求。
本系统使用方法
搭建好PHP和MYSQL环境，修改config.php中数据库配置信息，导入如数据库文件backups目录下backup_2025-04-12_20-41-42.sql文件，
把所有文件上传到网站根目录，ok
