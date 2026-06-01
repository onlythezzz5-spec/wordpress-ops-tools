# PIX 收据截图稳定性改进 - 完整指南

## 📋 改进概述

本次更新解决了收据截图上传不稳定、后台显示不可用的问题。通过多层验证、数据同步和调试工具，确保收据上传、存储和显示的完整性。

---

## 🔧 核心改进

### 1. 改进的上传函数 (`pix_enhanced_upload_proof`)

**改进点**:
- ✅ 多种方法获取附件URL（备用方案）
- ✅ 验证附件文件是否真实存在
- ✅ 事务处理确保数据一致性
- ✅ 详细的错误日志记录
- ✅ 失败时自动回滚

**工作流程**:
```
1. 文件验证 (大小、类型、哈希)
2. 上传到WordPress媒体库
3. 验证附件是否真实保存
4. 获取附件URL (多种方法)
5. 验证附件可用性
6. 更新订单元数据
7. 同步到数据库
8. 记录审计日志
```

**关键改进代码**:
```php
// 备用方案 1：直接构建URL
if (!$attachment_url) {
    $attachment_file = get_attached_file($aid);
    if ($attachment_file) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'], '', $attachment_file);
        $attachment_url = $upload_dir['baseurl'] . $relative_path;
    }
}

// 备用方案 2：使用 guid
if (!$attachment_url) {
    $attachment_obj = get_post($aid);
    if ($attachment_obj && $attachment_obj->guid) {
        $attachment_url = $attachment_obj->guid;
    }
}

// 验证附件可用性
$availability = pix_verify_attachment_availability($aid);
if (!$availability['available']) {
    wp_delete_attachment($aid, true); // 回滚
    wp_send_json_error([...]);
}
```

---

### 2. 附件可用性检查函数 (`pix_verify_attachment_availability`)

**功能**: 验证附件是否真实可用

**检查项**:
- ✅ 附件是否存在于数据库
- ✅ 文件是否存在于服务器
- ✅ 文件是否可读
- ✅ URL是否可生成

**返回值**:
```php
[
    'available' => true/false,
    'reason' => 'ok|attachment_not_found|file_not_found|file_not_readable|url_generation_failed',
    'message' => '详细描述',
    'url' => '附件URL',
    'file_path' => '文件路径',
    'file_size' => 文件大小(字节)
]
```

**使用示例**:
```php
$availability = pix_verify_attachment_availability($attachment_id);
if ($availability['available']) {
    echo '附件可用: ' . $availability['url'];
} else {
    echo '错误: ' . $availability['message'];
}
```

---

### 3. 数据同步函数 (`pix_sync_payment_data`)

**功能**: 确保订单元数据和数据库一致

**同步内容**:
- 上传时间
- 文件哈希
- 支付状态

**使用示例**:
```php
pix_sync_payment_data($order_id);
```

---

### 4. 调试日志函数 (`pix_debug_log`)

**功能**: 记录上传过程中的关键步骤

**记录内容**:
- 上传成功/失败
- 附件验证结果
- 数据库操作
- 管理员操作

**使用示例**:
```php
pix_debug_log($order_id, 'upload_success', [
    'attachment_id' => $aid,
    'file_hash' => substr($file_hash, 0, 8),
    'file_size' => $file['size'],
    'timestamp' => current_time('mysql')
]);
```

**查看日志**:
在 WordPress 数据库中查询 `wp_pix_audit_log` 表:
```sql
SELECT * FROM wp_pix_audit_log 
WHERE order_id = 123 
ORDER BY created_at DESC;
```

---

### 5. 改进的后台显示逻辑

**显示状态**:

#### ✅ 凭证可用
- 显示缩略图
- 显示查看/下载按钮
- 显示文件信息（名称、大小、上传时间）

#### ❌ 凭证不可用
- 显示详细的错误原因
- 显示调试信息（附件ID、文件路径）
- 提供修复建议

#### 📋 凭证未上传
- 提示客户上传
- 提供订单页面链接

---

### 6. 调试工具 - 附件诊断 (`pix_debug_attachment`)

**功能**: 诊断附件问题

**访问方式**: 管理员AJAX端点

**返回信息**:
```json
{
    "order_id": 123,
    "order_status": "on-hold",
    "attachment_metadata": {
        "attachment_id": 456,
        "attachment_url": "https://...",
        "proof_hash": "abc123...",
        "upload_time": "2024-05-12 10:30:00"
    },
    "attachment_availability": {
        "available": true,
        "reason": "ok",
        "url": "https://...",
        "file_size": 102400
    },
    "database_payment_record": {
        "id": 1,
        "status": "pending",
        "proof_uploaded_time": "2024-05-12 10:30:00",
        "proof_hash": "abc123..."
    },
    "data_sync_status": {
        "synced": true,
        "issues": []
    },
    "recent_audit_logs": [...]
}
```

---

### 7. 数据同步检查函数 (`pix_check_data_sync`)

**功能**: 检查订单元数据和数据库是否同步

**返回值**:
```php
[
    'synced' => true/false,
    'issues' => ['upload_time_mismatch', 'hash_mismatch'],
    'metadata' => [...],
    'database' => [...]
]
```

---

### 8. 修复数据同步的AJAX端点 (`pix_fix_data_sync`)

**功能**: 手动修复数据不同步问题

**使用场景**:
- 订单元数据和数据库不一致
- 需要重新同步数据

---

### 9. 重新上传凭证的AJAX端点 (`pix_reupload_proof`)

**功能**: 允许用户重新上传凭证

**工作流程**:
1. 删除旧的附件
2. 清除旧的元数据
3. 重置数据库状态
4. 允许用户重新上传

---

## 🚀 使用指南

### 对于用户

#### 上传凭证
1. 进入订单确认页面
2. 点击"📎 Selecionar Comprovante"（选择凭证）
3. 选择JPG、PNG或PDF文件
4. 点击"✓ Confirmar Envio"（确认上传）
5. 等待上传完成

#### 重新上传
如果上传失败或需要更换凭证：
1. 等待页面显示错误信息
2. 点击"📎 Selecionar Comprovante"重新选择
3. 上传新文件

### 对于管理员

#### 查看凭证
1. 进入订单详情页面
2. 在"💳 PIX 支付管理"部分查看凭证
3. 如果凭证不可用，会显示详细的错误原因

#### 验证支付
1. 查看凭证
2. 点击"✓ 标记为已支付"
3. 订单状态变为"处理中"
4. 客户收到确认邮件

#### 删除凭证
1. 点击"🗑 删除凭证"
2. 确认删除
3. 凭证被删除，用户可重新上传

#### 延长支付期限
1. 点击"⏱ 延长期限"
2. 输入延长分钟数
3. 期限被延长

#### 诊断问题
1. 打开浏览器开发者工具（F12）
2. 进入"Console"标签
3. 执行诊断AJAX请求：
```javascript
jQuery.post(ajaxurl, {
    action: 'pix_debug_attachment',
    order_id: 123,
    nonce: 'your_nonce'
}, function(response) {
    console.log(response.data);
});
```

#### 查看审计日志
在 WordPress 数据库中查询：
```sql
SELECT * FROM wp_pix_audit_log 
WHERE order_id = 123 
ORDER BY created_at DESC 
LIMIT 20;
```

---

## 🔍 故障排查

### 问题：上传看似成功但后台显示不可用

**可能原因**:
1. 文件权限问题
2. 上传目录不存在
3. URL生成失败
4. 数据库同步失败

**解决方案**:
1. 检查 `/wp-content/uploads/` 目录权限（应为755）
2. 检查文件是否真实存在
3. 运行数据同步修复
4. 查看审计日志获取详细错误信息

### 问题：后台显示"凭证不可用"

**可能原因**:
- 文件被删除
- 文件权限不足
- 上传目录配置错误

**解决方案**:
1. 让用户重新上传
2. 检查服务器文件权限
3. 检查 WordPress 上传目录配置

### 问题：数据不同步

**可能原因**:
- 订单保存失败
- 数据库更新失败
- 并发操作冲突

**解决方案**:
1. 使用"修复数据同步"功能
2. 检查数据库连接
3. 查看错误日志

---

## 📊 数据库表结构

### `wp_pix_payments` 表
```sql
CREATE TABLE wp_pix_payments (
    id bigint(20) PRIMARY KEY AUTO_INCREMENT,
    order_id bigint(20) UNIQUE,
    payment_key varchar(255),
    payment_amount decimal(10,2),
    claimed_time datetime,
    proof_uploaded_time datetime,      -- 凭证上传时间
    proof_hash varchar(32),             -- 凭证文件哈希
    payment_expired_time datetime,
    status varchar(50),                 -- pending|completed|expired
    retry_count int(3),
    notes longtext,
    created_at datetime,
    updated_at datetime
);
```

### `wp_pix_audit_log` 表
```sql
CREATE TABLE wp_pix_audit_log (
    id bigint(20) PRIMARY KEY AUTO_INCREMENT,
    order_id bigint(20),
    action varchar(100),                -- upload_success|upload_failed|proof_deleted|...
    details longtext,                   -- JSON格式的详细信息
    triggered_by varchar(100),          -- system|admin_user|customer
    created_at datetime
);
```

---

## 🔐 安全特性

### 文件验证
- ✅ 文件大小限制（5MB）
- ✅ 文件类型检查（JPG、PNG、PDF）
- ✅ MIME类型验证
- ✅ 文件哈希计算

### 黑名单机制
- ✅ 检测重复凭证
- ✅ 支持管理员标记欺诈凭证
- ✅ 防止相同凭证被多个订单使用

### 权限控制
- ✅ 用户只能上传自己订单的凭证
- ✅ 管理员操作需要权限验证
- ✅ AJAX请求需要nonce验证

### 审计日志
- ✅ 记录所有上传操作
- ✅ 记录管理员操作
- ✅ 记录错误和异常

---

## 📈 性能优化

### 缓存策略
- 附件URL缓存在订单元数据中
- 减少重复的数据库查询

### 数据库优化
- 使用索引加速查询
- 定期清理过期数据

### 文件处理
- 使用WordPress媒体库处理上传
- 自动生成缩略图

---

## 🔄 升级说明

### 从旧版本升级

1. **备份数据库**
   ```sql
   mysqldump -u user -p database > backup.sql
   ```

2. **替换文件**
   - 替换 `pix_gateway_enhanced.php`

3. **激活插件**
   - 访问 WordPress 插件页面
   - 激活"WooCommerce Manual PIX (Ultimate Enhanced)"

4. **数据库迁移**
   - 插件会自动创建新表
   - 现有数据保持不变

5. **测试**
   - 创建测试订单
   - 测试上传功能
   - 验证后台显示

---

## 📞 技术支持

### 常见问题

**Q: 如何查看上传日志？**
A: 在 WordPress 数据库中查询 `wp_pix_audit_log` 表

**Q: 如何重新上传凭证？**
A: 用户可以在订单页面重新选择文件并上传

**Q: 如何修复数据不同步？**
A: 使用管理员面板的"修复数据同步"功能

**Q: 如何删除失效的凭证？**
A: 在订单详情页面点击"🗑 删除凭证"

---

## 📝 版本历史

### v4.0.0 (Enhanced - 稳定性改进)
- ✅ 改进上传函数，添加多层验证
- ✅ 实现附件可用性检查
- ✅ 改进后台显示逻辑
- ✅ 添加数据同步机制
- ✅ 添加调试工具和审计日志
- ✅ 实现重新上传和修复功能

---

## 🎯 最佳实践

### 对于用户
1. 确保网络连接稳定
2. 使用清晰的凭证截图
3. 文件大小不超过5MB
4. 支持的格式：JPG、PNG、PDF

### 对于管理员
1. 定期检查审计日志
2. 及时验证支付凭证
3. 定期备份数据库
4. 监控上传错误率

### 对于开发者
1. 使用提供的调试工具诊断问题
2. 查看审计日志了解操作历史
3. 使用数据同步检查确保数据一致性
4. 定期测试上传功能

---

## 📄 许可证

GPL v2

---

**最后更新**: 2024-05-12
**维护者**: PIX Payment Gateway Team
