# PIX 收据截图稳定性改进方案

## 问题诊断

### 当前症状
- 上传看似成功但后台显示不可用
- 订单后台无法看到凭证截图
- 附件链接失效或无法访问

### 根本原因分析

#### 1. 附件URL生成不稳定
**位置**: [`pix_gateway_enhanced.php:1102-1108`](pix_gateway_enhanced.php:1102)
```php
$attachment_url = wp_get_attachment_url($aid);
if (!$attachment_url) {
    wp_send_json_error([...]);
}
```
**问题**:
- `wp_get_attachment_url()` 可能因为权限、路径配置、CDN设置等原因返回空值
- 没有备用方案或重试机制
- 没有记录失败原因

#### 2. 数据库同步不完整
**位置**: [`pix_gateway_enhanced.php:1113-1117`](pix_gateway_enhanced.php:1113)
```php
$order->update_meta_data('_pix_proof_attachment', $aid);
$order->update_meta_data('_pix_proof_attachment_url', $attachment_url);
```
**问题**:
- 订单元数据和 `pix_payments` 表可能不同步
- 如果 `$order->save()` 失败，数据库记录仍然存在
- 没有事务处理确保原子性

#### 3. 后台显示逻辑缺陷
**位置**: [`pix_gateway_enhanced.php:383-437`](pix_gateway_enhanced.php:383)
```php
if ($proof_id && $proof_url):
    // 显示凭证
else:
    // 显示警告
endif;
```
**问题**:
- 只检查元数据是否存在，不验证文件是否真实存在
- 没有检查URL是否可访问
- 没有显示具体的错误原因

#### 4. 缺少调试日志
**问题**:
- 无法追踪上传失败的具体步骤
- 管理员无法诊断问题

#### 5. 没有恢复机制
**问题**:
- 附件失效后无法修复
- 用户无法重新上传

---

## 解决方案

### 方案 1: 改进上传函数 (pix_enhanced_upload_proof)

**改进点**:
1. 添加详细的调试日志记录每个步骤
2. 验证附件文件是否真实存在
3. 使用多种方法获取附件URL（备用方案）
4. 添加事务处理确保数据一致性
5. 改进错误处理和恢复机制

**关键修改**:
```php
// 新增：详细的日志记录
$debug_log = [];

// 新增：验证附件文件是否存在
$attachment_file = get_attached_file($aid);
if (!$attachment_file || !file_exists($attachment_file)) {
    // 记录错误并返回
}

// 新增：多种方法获取URL
$attachment_url = wp_get_attachment_url($aid);
if (!$attachment_url) {
    // 备用方案：直接构建URL
    $attachment_url = wp_get_attachment_url($aid, 'full');
}

// 新增：事务处理
$order->update_meta_data('_pix_proof_attachment', $aid);
$order->update_meta_data('_pix_proof_attachment_url', $attachment_url);
if (!$order->save()) {
    // 回滚：删除附件
    wp_delete_attachment($aid, true);
}
```

### 方案 2: 附件可用性检查函数

**新增函数**: `pix_verify_attachment_availability()`
```php
function pix_verify_attachment_availability($attachment_id) {
    // 检查附件是否存在
    $attachment = get_post($attachment_id);
    if (!$attachment) return false;
    
    // 检查文件是否存在
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) return false;
    
    // 检查URL是否可访问
    $url = wp_get_attachment_url($attachment_id);
    if (!$url) return false;
    
    return true;
}
```

### 方案 3: 改进后台显示逻辑

**改进点**:
1. 调用附件可用性检查函数
2. 显示具体的错误原因
3. 提供修复选项（重新上传、删除并重新上传）

**关键修改**:
```php
$proof_id = $order->get_meta('_pix_proof_attachment');
$proof_url = $order->get_meta('_pix_proof_attachment_url');

if ($proof_id) {
    // 验证附件是否真实可用
    if (pix_verify_attachment_availability($proof_id)) {
        // 显示凭证
    } else {
        // 显示"凭证不可用"警告
        // 提供修复选项
    }
}
```

### 方案 4: 数据库同步修复

**新增函数**: `pix_sync_payment_data()`
```php
function pix_sync_payment_data($order_id) {
    global $wpdb;
    $order = wc_get_order($order_id);
    $payments_table = $wpdb->prefix . 'pix_payments';
    
    // 获取订单元数据
    $proof_id = $order->get_meta('_pix_proof_attachment');
    $proof_url = $order->get_meta('_pix_proof_attachment_url');
    $proof_hash = $order->get_meta('_pix_proof_hash');
    $upload_time = $order->get_meta('_pix_proof_upload_time');
    
    // 同步到数据库
    $wpdb->update(
        $payments_table,
        [
            'proof_uploaded_time' => $upload_time,
            'proof_hash' => $proof_hash
        ],
        ['order_id' => $order_id]
    );
}
```

### 方案 5: 调试工具

**新增AJAX端点**: `pix_debug_attachment`
```php
add_action('wp_ajax_pix_debug_attachment', function() {
    check_ajax_referer('pix_admin_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('权限不足');
    }
    
    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);
    
    $debug_info = [
        'attachment_id' => $order->get_meta('_pix_proof_attachment'),
        'attachment_url' => $order->get_meta('_pix_proof_attachment_url'),
        'file_exists' => file_exists(get_attached_file($order->get_meta('_pix_proof_attachment'))),
        'url_accessible' => /* 检查URL是否可访问 */,
        'database_status' => /* 数据库中的状态 */
    ];
    
    wp_send_json_success($debug_info);
});
```

### 方案 6: 恢复机制

**改进删除凭证函数**:
- 添加"修复"选项而不仅仅是"删除"
- 允许用户重新上传
- 自动清理失效的附件

---

## 实现步骤

### 第一阶段：核心修复
1. 改进 `pix_enhanced_upload_proof()` 函数
   - 添加详细日志
   - 验证附件文件存在性
   - 改进错误处理

2. 创建 `pix_verify_attachment_availability()` 函数
   - 检查附件是否存在
   - 检查文件是否存在
   - 检查URL是否可访问

3. 改进后台显示逻辑
   - 调用可用性检查
   - 显示具体错误原因

### 第二阶段：数据同步
1. 创建 `pix_sync_payment_data()` 函数
2. 在上传成功后调用同步函数
3. 添加定时任务检查数据一致性

### 第三阶段：调试和恢复
1. 添加调试AJAX端点
2. 改进删除凭证函数
3. 添加恢复选项

### 第四阶段：测试
1. 测试正常上传流程
2. 测试各种失败场景
3. 测试后台显示
4. 测试恢复机制

---

## 预期效果

✅ 上传稳定性提高 - 详细的验证和错误处理
✅ 后台显示准确 - 真实反映附件状态
✅ 数据一致性 - 订单元数据和数据库同步
✅ 易于诊断 - 详细的日志和调试工具
✅ 用户友好 - 提供修复和恢复选项

---

## 文件修改清单

- `pix_gateway_enhanced.php` - 主要改进
  - 改进 `pix_enhanced_upload_proof()` 函数
  - 添加 `pix_verify_attachment_availability()` 函数
  - 添加 `pix_sync_payment_data()` 函数
  - 改进后台显示逻辑
  - 添加调试AJAX端点

- `ADMIN_AJAX_FUNCTIONS.php` - 可选更新
  - 改进删除凭证函数
  - 添加恢复选项
