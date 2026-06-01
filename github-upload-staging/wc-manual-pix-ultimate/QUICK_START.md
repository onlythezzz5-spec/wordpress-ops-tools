# PIX 增强版 - 快速启动指南

## ⚡ 5分钟快速安装

### 第1步: 上传文件 (1分钟)

1. 在 WordPress 后台 → 插件 → 上传插件
2. 或 FTP 上传到 `/wp-content/plugins/` 目录

### 第2步: 启用插件 (30秒)

1. 插件 → 已安装的插件
2. 找到 "WooCommerce Manual PIX Enhanced"
3. 点击 "启用"
4. 系统自动创建数据库表

### 第3步: 基本配置 (3分钟)

**路径**: WooCommerce → 设置 → 付款 → PIX 手动支付 (增强版)

**必填项**:

```ini
# PIX 信息
PIX Key = [你的 PIX 密钥，如: seu@email.com]
QR Code URL = [你的二维码链接]
WhatsApp = [你的电话号码，如: 5511999999999]

# 支付配置
支付过期时间 = 60 分钟
反垃圾限制 = 2 个未结单
自动取消过期 = ✓ 启用
启用提醒 = ✓ 启用
```

### 第4步: 测试 (1分钟)

1. 前台下单，选择 PIX 支付
2. 查看支付页面是否正常
3. 测试文件上传功能

✅ 完成！现在已启用完整的 PIX 支付。

---

## 🎯 核心功能速览

### 1. 支付流程

```
客户下单
  ↓
选择 PIX 支付
  ↓
看到支付页面 (Step 1: 复制/扫码, Step 2: 上传凭证)
  ↓
用户在银行转账
  ↓
用户上传转账凭证
  ↓
自动验证 (MD5去重 + 黑名单检查)
  ↓
订单状态变为 "处理中"
  ↓
发货 → 完成
```

### 2. 自动化功能

| 功能 | 触发条件 | 结果 |
|------|---------|------|
| **支付倒计时** | 订单创建 | 显示剩余时间，5分钟时变红 |
| **自动过期检查** | 每15分钟 | 超时订单标记为过期/取消 |
| **审计日志** | 每次操作 | 记录所有支付相关事件 |
| **重复检测** | 凭证上传 | 检查黑名单和已用凭证 |
| **支付提醒** | 可配置间隔 | 邮件/WhatsApp 提醒未支付用户 |

### 3. 管理功能

**仪表板**: WooCommerce → PIX 支付管理

```
📊 实时统计
├─ 待处理: 12个
├─ 已完成: 156个
└─ 已过期: 8个

📋 最近支付记录
├─ 订单 #1234 → Pending (2分钟前)
├─ 订单 #1233 → Completed (5分钟前)
└─ ...
```

---

## 💰 转化率优化技巧

### ✅ 推荐配置

```ini
# 高转化率配置 (推荐用于新店)
支付过期时间 = 90 分钟
自动取消 = 关闭 (手动管理更人性化)
提醒间隔 = 20 分钟 (不要过于频繁)
反垃圾 = 3 个订单

# 安全优先配置 (高风险商品)
支付过期时间 = 30 分钟
自动取消 = 开启
提醒间隔 = 10 分钟
反垃圾 = 1 个订单
```

### 📱 移动优化

- ✅ 已内置响应式设计
- ✅ 大按钮 (易点击)
- ✅ 自动复制功能
- ✅ 无需缩放即可看全

### 💬 改进文案

编辑 `pix_payment_script_enhanced.js` 中的 `PixConfig.text`:

```javascript
// 示例: 修改"上传"按钮文案
text: {
    selectFile: '没有凭证? 拍照上传', // 改为更友好的文案
    success: '✓ 凭证已接收! 请稍候...', // 添加更多安心感
}
```

---

## 🔧 常见配置

### 配置 A: 小店模式 (0-50单/天)

```php
// functions.php 中添加
add_filter('pix_enhanced_expiration_minutes', function($minutes) {
    return 120; // 2小时，给客户充足时间
});

add_filter('pix_enhanced_max_open_orders', function($count) {
    return 5; // 允许较多未结单
});
```

### 配置 B: 中型店铺 (50-500单/天)

```php
add_filter('pix_enhanced_expiration_minutes', function($minutes) {
    return 60; // 标准1小时
});

// 启用自动取消
add_filter('pix_enhanced_auto_cancel', '__return_true');
```

### 配置 C: 大型店铺 (500+单/天)

```php
add_filter('pix_enhanced_expiration_minutes', function($minutes) {
    return 30; // 快速周转
});

// 启用所有安全功能
add_filter('pix_enhanced_anti_fraud_level', function() {
    return 'maximum'; // 最高风险防范
});

// 启用高级审计
add_filter('pix_enhanced_audit_level', function() {
    return 'verbose'; // 详细日志
});
```

---

## 📊 数据查询示例

### 查看所有待处理支付

```sql
SELECT * FROM wp_pix_payments 
WHERE status = 'pending' 
ORDER BY created_at DESC;
```

### 查看已完成支付

```sql
SELECT 
    p.order_id,
    o.total,
    p.payment_amount,
    p.proof_uploaded_time,
    p.updated_at
FROM wp_pix_payments p
JOIN wp_posts o ON p.order_id = o.ID
WHERE p.status = 'completed'
ORDER BY p.updated_at DESC;
```

### 查看重复凭证

```sql
SELECT 
    hash, 
    COUNT(*) as count,
    GROUP_CONCAT(order_id) as orders
FROM wp_pix_hashes
GROUP BY hash
HAVING COUNT(*) > 1;
```

### 查看某订单的完整历史

```sql
SELECT * FROM wp_pix_audit_log 
WHERE order_id = 1234 
ORDER BY created_at ASC;
```

---

## 🚨 故障排除速查表

| 问题 | 原因 | 解决 |
|------|------|------|
| 凭证上传失败 | 文件格式错误 | 只支持 JPG/PNG/PDF |
| 显示"文件太大" | 超过 5MB | 压缩图片或使用 PDF |
| 上传后订单未变化 | 需要手动验证 | 管理员审核后手动标记 |
| 倒计时不显示 | JS 加载失败 | 清除浏览器缓存，检查 CDN |
| 支付过期未取消 | 自动取消关闭 | 设置 → 付款中勾选"自动取消" |
| 黑名单不生效 | 权限问题 | 确保用户是 Admin 权限 |
| 数据库表不存在 | 插件未完全激活 | 停用后重新启用插件 |

---

## 📈 性能监控

### 检查数据库大小

```sql
SELECT 
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size in MB'
FROM information_schema.tables
WHERE table_schema = 'wordpress_db' 
AND table_name LIKE '%pix%';
```

### 监控慢查询

编辑 `wp-config.php`:

```php
define('SAVEQUERIES', true);
// 然后在模板中查看: global $wpdb; print_r($wpdb->queries);
```

### 清理旧数据

```php
// 删除30天以上的审计日志
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}pix_audit_log 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
```

---

## 🔐 安全检查清单

- [ ] 启用 HTTPS (必须)
- [ ] 定期更改 PIX 密钥
- [ ] 限制管理员账户数量
- [ ] 启用 WordPress 防火墙 (Wordfence/Sucuri)
- [ ] 定期备份数据库
- [ ] 检查异常登录活动
- [ ] 监控黑名单列表
- [ ] 审计日志定期导出

---

## 💡 使用技巧

### 技巧 1: 快速复制 PIX 密钥

用户在支付页面点击密钥框即可一键复制。

### 技巧 2: 查看支付凭证

订单详情 → PIX 支付管理 → 直接查看上传的图片

### 技巧 3: 手动延长支付期限

进入订单编辑 → PIX 支付管理 → "延长期限" (计划推出)

### 技巧 4: 批量操作

使用 SQL 命令快速处理多个订单:

```sql
-- 标记多个订单为已完成
UPDATE wp_pix_payments 
SET status = 'completed', updated_at = NOW()
WHERE order_id IN (123, 124, 125);
```

### 技巧 5: 自定义提醒文案

在 `functions.php` 中添加:

```php
add_filter('pix_enhanced_reminder_message', function($msg, $order) {
    return "Olá {$order->get_billing_first_name()}, " .
           "não se esqueça de confirmar seu pagamento!";
}, 10, 2);
```

---

## 📞 获取帮助

### 官方资源
- 📖 完整文档: README.md
- 🎓 最佳实践: BEST_PRACTICES.md
- 🔌 API 文档: API_REFERENCE.md
- 🐛 已知问题: KNOWN_ISSUES.md

### 社区支持
- GitHub Issues: [报告 Bug]
- GitHub Discussions: [功能建议]
- 中文论坛: [WooCommerce 中文]
- WhatsApp 群: [加入讨论]

---

**祝你的 PIX 支付顺利上线! 🚀**

有任何问题欢迎反馈。我们持续改进以提供最佳体验。
