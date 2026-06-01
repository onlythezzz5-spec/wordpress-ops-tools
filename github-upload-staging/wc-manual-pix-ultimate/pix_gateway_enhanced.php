<?php
/**
 * Plugin Name: WooCommerce Manual PIX (Ultimate Enhanced)
 * Description: 转化率为导向的 PIX 支付网关，具有完整的订单状态管理、自动过期处理和实时验证。
 * Version: 4.1.0 (Enhanced)
 * Author: zzz
 * License: GPL v2
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// 1. 数据库安装与升级
// ============================================================

function pix_enhanced_install_db() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // 主哈希表
    $table_hashes = $wpdb->prefix . 'pix_hashes';
    $sql_hashes = "CREATE TABLE IF NOT EXISTS $table_hashes (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        hash varchar(32) NOT NULL,
        order_id bigint(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY hash (hash),
        KEY order_id (order_id)
    ) $charset_collate;";

    // 订单支付记录表（新增）
    $table_payments = $wpdb->prefix . 'pix_payments';
    $sql_payments = "CREATE TABLE IF NOT EXISTS $table_payments (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        payment_key varchar(255),
        payment_amount decimal(10,2),
        claimed_time datetime,
        proof_uploaded_time datetime,
        proof_hash varchar(32),
        payment_expired_time datetime,
        status varchar(50) DEFAULT 'pending',
        retry_count int(3) DEFAULT 0,
        notes longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset_collate;";

    // 支付验证日志表（新增）
    $table_audit = $wpdb->prefix . 'pix_audit_log';
    $sql_audit = "CREATE TABLE IF NOT EXISTS $table_audit (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        action varchar(100),
        details longtext,
        triggered_by varchar(100),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY action (action),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_hashes);
    dbDelta($sql_payments);
    dbDelta($sql_audit);

    update_option('pix_enhanced_db_version', '2.0');
}

register_activation_hook(__FILE__, 'pix_enhanced_install_db');

// 注册自定义 cron 间隔（必须在 wp_schedule_event 之前注册）
add_filter('cron_schedules', function($schedules) {
    $schedules['pix_15_minutes'] = [
        'interval' => 900, // 15 * 60
        'display'  => 'Every 15 Minutes (PIX)'
    ];
    return $schedules;
});

// 注册定时任务
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('pix_enhanced_cleanup')) {
        wp_schedule_event(time(), 'hourly', 'pix_enhanced_cleanup');
    }
    if (!wp_next_scheduled('pix_enhanced_expire_check')) {
        wp_schedule_event(time(), 'pix_15_minutes', 'pix_enhanced_expire_check');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('pix_enhanced_cleanup');
    wp_clear_scheduled_hook('pix_enhanced_expire_check');
});

// ============================================================
// 2. 支付网关类
// ============================================================

add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_PIX_Enhanced';
    return $gateways;
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_PIX_Enhanced extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'pix_enhanced';
            $this->method_title       = 'PIX 手动支付 (增强版)';
            $this->method_description = '完整的 PIX 支付网关，转化率优先，自动订单状态管理。';
            $this->has_fields         = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->pix_key     = $this->get_option('pix_key');
            $this->qrcode_url  = $this->get_option('qrcode_url');
            $this->whatsapp    = $this->get_option('whatsapp_number');
            $this->expiration  = intval($this->get_option('expiration', 60));
            $this->max_orders  = intval($this->get_option('max_open_orders', 1));
            $this->auto_cancel = $this->get_option('auto_cancel_expired', 'yes');
            $this->enable_reminders = $this->get_option('enable_payment_reminders', 'yes');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
            add_action('woocommerce_checkout_process', [$this, 'check_pending_orders']);
            add_action('add_meta_boxes', [$this, 'add_payment_meta_box']);
            add_action('admin_menu', [$this, 'add_management_menu']);
            add_action('pix_enhanced_cleanup', [$this, 'cleanup_old_hashes']);
            add_action('pix_enhanced_expire_check', [$this, 'check_expired_payments']);
            add_action('admin_footer', [$this, 'admin_scripts']);
            add_action('wp_ajax_pix_check_status', [$this, 'ajax_check_payment_status']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => '启用',
                    'type'    => 'checkbox',
                    'label'   => '开启 PIX 增强版支付',
                    'default' => 'yes'
                ],
                'title' => [
                    'title'   => '标题 (前端显示)',
                    'type'    => 'text',
                    'default' => 'PIX - Pagamento Instantâneo ⚡'
                ],
                'description' => [
                    'title'   => '描述 (前端显示)',
                    'type'    => 'textarea',
                    'default' => 'Pague com PIX em segundos. Receba confirmação imediata.'
                ],
                'pix_key' => [
                    'title' => 'PIX Key',
                    'type'  => 'password'
                ],
                'qrcode_url' => [
                    'title'       => 'QR Code URL',
                    'type'        => 'text',
                    'description' => '自动生成或上传的动态二维码'
                ],
                'whatsapp_number' => [
                    'title'       => 'WhatsApp 客服号码',
                    'type'        => 'text',
                    'description' => '格式: 5511999999999 (留空隐藏)',
                    'placeholder' => '5511999999999'
                ],
                'expiration' => [
                    'title'   => '支付过期时间 (分钟)',
                    'type'    => 'number',
                    'default' => 60,
                    'description' => '支付凭证上传截止时间'
                ],
                'max_open_orders' => [
                    'title'   => '反垃圾限制 (最大未结单)',
                    'type'    => 'number',
                    'default' => 3
                ],
                'auto_cancel_expired' => [
                    'title'       => '自动取消过期订单',
                    'type'        => 'checkbox',
                    'label'       => '启用自动取消过期支付订单',
                    'default'     => 'yes',
                    'description' => '支付超时自动改为取消状态'
                ],
                'enable_payment_reminders' => [
                    'title'       => '启用支付提醒',
                    'type'        => 'checkbox',
                    'label'       => '启用邮件/WhatsApp 支付提醒',
                    'default'     => 'yes',
                    'description' => '定期提醒用户完成支付'
                ],
                'reminder_interval' => [
                    'title'       => '提醒间隔 (分钟)',
                    'type'        => 'number',
                    'default'     => 15,
                    'description' => '多少分钟后发送第一条提醒'
                ]
            ];
        }

        public function check_expired_payments() {
            global $wpdb;
            $table = $wpdb->prefix . 'pix_payments';
            
            // 查找过期的支付
            $expired = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s AND payment_expired_time < UTC_TIMESTAMP()",
                'pending'
            ));

            foreach ($expired as $payment) {
                $order = wc_get_order($payment->order_id);
                if (!$order) continue;

                // 记录审计日志
                $this->audit_log($payment->order_id, 'payment_expired', "支付已过期，订单号: {$payment->order_id}");

                // 自动取消
                if ($this->auto_cancel === 'yes') {
                    $order->set_status('cancelled');
                    $order->add_order_note('支付超时，订单已取消。(Payment expired)');
                    $order->save();

                    // 更新数据库状态
                    $wpdb->update($table, ['status' => 'expired'], ['id' => $payment->id]);
                }
            }
        }

        public function cleanup_old_hashes() {
            global $wpdb;
            $table = $wpdb->prefix . 'pix_hashes';
            $wpdb->query("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL 45 DAY)");
        }

        public function add_payment_meta_box() {
            add_meta_box('pix_enhanced_box', 'PIX 支付管理', [$this, 'render_payment_box'], 'shop_order', 'normal', 'high');
        }

        public function render_payment_box($post_or_order) {
            $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
            if (!$order) return;

            global $wpdb;
            $table = $wpdb->prefix . 'pix_payments';
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE order_id = %d",
                $order->get_id()
            ));

            $proof_id = $order->get_meta('_pix_proof_attachment');
            $proof_url = $order->get_meta('_pix_proof_attachment_url');
            $proof_filename = $order->get_meta('_pix_proof_filename');
            $proof_upload_time = $order->get_meta('_pix_proof_upload_time');
            $file_hash = $order->get_meta('_pix_proof_hash');
            ?>
            <div style="padding: 15px; background: #f9f9f9; border-radius: 8px;">
                <style>
                    .pix-status-box { padding: 12px; border-radius: 5px; margin-bottom: 15px; }
                    .pix-status-verified { background: #d4edda; border-left: 4px solid #28a745; }
                    .pix-status-pending { background: #fff3cd; border-left: 4px solid #ffc107; }
                    .pix-status-expired { background: #f8d7da; border-left: 4px solid #dc3545; }
                    .pix-status-icon { font-size: 20px; margin-right: 8px; }
                    .pix-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                    .pix-table td { padding: 10px; border-bottom: 1px solid #ddd; }
                    .pix-table tr:last-child td { border-bottom: none; }
                    .pix-table strong { display: inline-block; width: 150px; color: #333; }
                    .pix-proof-image { max-width: 100%; height: auto; border: 2px solid #ddd; border-radius: 8px; margin: 15px 0; }
                    .pix-proof-container { background: #fff; padding: 15px; border-radius: 5px; margin-top: 15px; }
                    .pix-no-proof { text-align: center; color: #999; padding: 30px; background: #fff; border-radius: 5px; border: 2px dashed #ddd; }
                    .pix-button-group { margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; }
                    .pix-button-group button { flex: 1; min-width: 120px; }
                </style>

                <h3 style="margin-top: 0; color: #0f172a;">💳 PIX 支付状态</h3>

                <!-- 支付状态徽章 -->
                <?php 
                    $status_icon = '⏳';
                    $status_text = '待支付';
                    $status_class = 'pix-status-pending';
                    
                    if ($payment) {
                        if ($payment->status === 'completed') {
                            $status_icon = '✅';
                            $status_text = '已完成';
                            $status_class = 'pix-status-verified';
                        } elseif ($payment->status === 'expired') {
                            $status_icon = '⏱';
                            $status_text = '已过期';
                            $status_class = 'pix-status-expired';
                        } else {
                            $status_icon = '⏳';
                            $status_text = '待验证';
                            $status_class = 'pix-status-pending';
                        }
                    }
                ?>
                <div class="pix-status-box <?php echo esc_attr($status_class); ?>">
                    <span class="pix-status-icon"><?php echo $status_icon; ?></span>
                    <strong><?php echo esc_html($status_text); ?></strong>
                    <?php if ($payment): ?>
                        <span style="color: #666; font-size: 12px;">(<?php echo esc_html($payment->status); ?>)</span>
                    <?php endif; ?>
                </div>

                <!-- 支付详情表格 -->
                <table class="pix-table">
                    <tr>
                        <td><strong>订单状态:</strong></td>
                        <td>
                            <span style="background: #e9ecef; padding: 4px 8px; border-radius: 3px;">
                                <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($payment): ?>
                        <tr>
                            <td><strong>支付金额:</strong></td>
                            <td><?php echo wc_price($payment->payment_amount); ?></td>
                        </tr>
                        <tr>
                            <td><strong>支付发起:</strong></td>
                            <td><?php echo esc_html($payment->created_at ? date('Y-m-d H:i:s', strtotime($payment->created_at)) : '-'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>过期时间:</strong></td>
                            <td>
                                <?php 
                                if ($payment->payment_expired_time) {
                                    $expired_time = strtotime($payment->payment_expired_time);
                                    $now = time();
                                    if ($now > $expired_time) {
                                        echo '<span style="color: #dc3545;">⏱ 已过期 (' . esc_html(date('Y-m-d H:i:s', $expired_time)) . ')</span>';
                                    } else {
                                        $remaining = ceil(($expired_time - $now) / 60);
                                        echo '<span style="color: #ffc107;">还有 ' . $remaining . ' 分钟 (' . esc_html(date('H:i', $expired_time)) . ')</span>';
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>凭证上传:</strong></td>
                            <td>
                                <?php 
                                if ($payment->proof_uploaded_time) {
                                    echo '<span style="color: #28a745;">✓ ' . esc_html(date('Y-m-d H:i:s', strtotime($payment->proof_uploaded_time))) . '</span>';
                                } else {
                                    echo '<span style="color: #999;">⏳ 待上传</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php if ($file_hash): ?>
                        <tr>
                            <td><strong>凭证哈希:</strong></td>
                            <td>
                                <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px; font-size: 11px;">
                                    <?php echo esc_html(substr($file_hash, 0, 16)) . '...' . esc_html(substr($file_hash, -4)); ?>
                                </code>
                                <span style="color: #999; font-size: 11px;">(防重复)</span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>重试次数:</strong></td>
                            <td><?php echo (int)$payment->retry_count; ?> 次</td>
                        </tr>
                    <?php endif; ?>
                </table>

                <!-- 支付凭证显示 -->
                <div style="border-top: 2px solid #ddd; padding-top: 15px;">
                    <h4 style="color: #0f172a; margin-top: 0;">📷 支付凭证</h4>
                    
                    <?php
                        // 改进：使用新的附件可用性检查函数
                        $attachment_available = false;
                        $availability_info = null;
                        
                        if ($proof_id) {
                            $availability_info = pix_verify_attachment_availability($proof_id);
                            $attachment_available = $availability_info['available'];
                        }
                    ?>
                    
                    <?php if ($attachment_available && $proof_url): ?>
                        <!-- 凭证已上传且可用 -->
                        <div class="pix-proof-container">
                            <div style="margin-bottom: 10px;">
                                <span style="background: #dcfce7; color: #166534; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                    ✓ 凭证已接收
                                </span>
                            </div>
                            
                            <!-- 显示凭证图片 - 改进版本 -->
                            <div style="background: #fff; border: 2px solid #ddd; border-radius: 8px; padding: 15px; text-align: center; margin-bottom: 15px;">
                                <div style="margin-bottom: 10px; font-size: 12px; color: #666;">
                                    <strong>📷 支付凭证预览</strong>
                                </div>
                                
                                <?php
                                    // 方法1：使用WordPress缩略图
                                    $img_html = wp_get_attachment_image($proof_id, 'large', false, [
                                        'style' => 'max-width: 100%; height: auto; max-height: 500px; border-radius: 5px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);',
                                        'alt' => '支付凭证',
                                        'loading' => 'lazy'
                                    ]);
                                    
                                    if ($img_html) {
                                        echo $img_html;
                                    } else {
                                        // 方法2：直接使用URL显示
                                        echo '<img src="' . esc_url($proof_url) . '" style="max-width: 100%; height: auto; max-height: 500px; border-radius: 5px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" alt="支付凭证" loading="lazy">';
                                    }
                                ?>
                                
                                <!-- 如果是PDF，显示提示 -->
                                <?php
                                    $file_ext = strtolower(pathinfo($proof_filename, PATHINFO_EXTENSION));
                                    if ($file_ext === 'pdf'):
                                ?>
                                    <div style="margin-top: 10px; padding: 10px; background: #e3f2fd; border-radius: 5px; font-size: 12px; color: #1976d2;">
                                        📄 这是一个PDF文件。点击"查看原图"按钮在新标签页中打开。
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 操作按钮 -->
                            <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                                <a href="<?php echo esc_url($proof_url); ?>" target="_blank" class="button button-primary" style="flex: 1; min-width: 120px;">
                                    🔍 查看原图
                                </a>
                                <a href="<?php echo esc_url($proof_url); ?>" download="proof_<?php echo esc_attr($order->get_id()); ?>" class="button button-secondary" style="flex: 1; min-width: 120px;">
                                    ⬇ 下载凭证
                                </a>
                            </div>

                            <!-- 凭证详细信息 -->
                            <div style="background: #f5f5f5; padding: 12px; border-radius: 5px; font-size: 12px;">
                                <?php if ($proof_filename): ?>
                                    <div style="margin-bottom: 8px;">
                                        <strong>📄 文件名:</strong> <?php echo esc_html($proof_filename); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($proof_upload_time): ?>
                                    <div style="margin-bottom: 8px;">
                                        <strong>⏰ 上传时间:</strong> <?php echo esc_html(date('Y-m-d H:i:s', strtotime($proof_upload_time))); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($availability_info && isset($availability_info['file_size'])): ?>
                                    <div style="margin-bottom: 8px;">
                                        <strong>💾 文件大小:</strong> <?php echo esc_html(round($availability_info['file_size'] / 1024, 2)); ?> KB
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($file_hash): ?>
                                    <div>
                                        <strong>🔐 文件哈希:</strong> <code style="background: #fff; padding: 2px 4px; border-radius: 3px;"><?php echo esc_html(substr($file_hash, 0, 16)); ?>...</code>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif ($proof_id && !$attachment_available): ?>
                        <!-- 凭证记录存在但不可用 - 显示详细错误信息 -->
                        <div style="background: #fee; border: 1px solid #fcc; padding: 15px; border-radius: 5px; color: #c33;">
                            <div style="margin-bottom: 10px;">
                                <strong>❌ 凭证不可用</strong>
                            </div>
                            <div style="font-size: 13px; margin-bottom: 10px;">
                                <strong>原因:</strong>
                                <?php
                                    $reason_map = [
                                        'attachment_not_found' => '附件不存在或已被删除',
                                        'file_not_found' => '文件不存在于服务器',
                                        'file_not_readable' => '文件无法读取（权限问题）',
                                        'url_generation_failed' => '无法生成附件URL',
                                        'attachment_id_empty' => '附件ID为空'
                                    ];
                                    $reason = $availability_info['reason'] ?? 'unknown';
                                    echo esc_html($reason_map[$reason] ?? $availability_info['message'] ?? '未知错误');
                                ?>
                            </div>
                            <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                <strong>附件ID:</strong> <?php echo esc_html($proof_id); ?>
                            </div>
                            <div style="background: #fff; padding: 10px; border-radius: 3px; font-size: 12px; margin-bottom: 10px; max-height: 150px; overflow-y: auto;">
                                <strong>调试信息:</strong><br>
                                <?php
                                    if ($availability_info && isset($availability_info['file_path'])) {
                                        echo '文件路径: ' . esc_html($availability_info['file_path']) . '<br>';
                                    }
                                    echo '检查时间: ' . esc_html(current_time('Y-m-d H:i:s'));
                                ?>
                            </div>
                            <div style="background: #fffacd; padding: 10px; border-radius: 3px; font-size: 12px;">
                                <strong>建议:</strong><br>
                                1. 检查服务器文件权限<br>
                                2. 检查上传目录是否存在<br>
                                3. 让客户重新上传凭证<br>
                                4. 如果问题持续，请联系技术支持
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- 凭证未上传 -->
                        <div class="pix-no-proof">
                            <div style="font-size: 40px; margin-bottom: 10px;">📋</div>
                            <div style="font-size: 14px; margin-bottom: 5px;">还未上传支付凭证</div>
                            <div style="font-size: 12px; color: #999;">
                                客户需要在订单确认页面上传交易凭证。<br>
                                <a href="<?php echo esc_url($order->get_checkout_order_received_url()); ?>" target="_blank" style="color: #0073aa;">查看订单页面</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 管理操作 -->
                <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 15px;">
                    <h4 style="color: #0f172a; margin-top: 0;">⚙ 管理操作</h4>
                    <div class="pix-button-group">
                        <button type="button" class="button button-primary" onclick="pix_verify_payment(<?php echo (int)$order->get_id(); ?>)">
                            ✓ 标记为已支付
                        </button>
                        <button type="button" class="button button-secondary" onclick="pix_extend_payment(<?php echo (int)$order->get_id(); ?>)">
                            ⏱ 延长期限
                        </button>
                        <?php if ($proof_id): ?>
                            <button type="button" class="button button-link-delete" onclick="if(confirm('确定要删除此凭证?')) { pix_delete_proof(<?php echo (int)$order->get_id(); ?>, <?php echo (int)$proof_id; ?>); }">
                                🗑 删除凭证
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <script>
            function pix_verify_payment(order_id) {
                if (!confirm('确定要标记此订单为已支付吗?')) return;
                jQuery.post(ajaxurl, {
                    action: 'pix_admin_verify_payment',
                    order_id: order_id,
                    nonce: '<?php echo wp_create_nonce('pix_admin_action'); ?>'
                }, function(res) {
                    if (res.success) {
                        alert('✓ 已标记为已支付');
                        location.reload();
                    } else {
                        alert('错误: ' + res.data);
                    }
                });
            }
            function pix_extend_payment(order_id) {
                var minutes = prompt('延长多少分钟?', '30');
                if (!minutes || isNaN(minutes)) return;
                jQuery.post(ajaxurl, {
                    action: 'pix_admin_extend_payment',
                    order_id: order_id,
                    minutes: parseInt(minutes),
                    nonce: '<?php echo wp_create_nonce('pix_admin_action'); ?>'
                }, function(res) {
                    if (res.success) {
                        alert('✓ 已延长 ' + minutes + ' 分钟');
                        location.reload();
                    } else {
                        alert('错误: ' + res.data);
                    }
                });
            }
            function pix_delete_proof(order_id, attachment_id) {
                jQuery.post(ajaxurl, {
                    action: 'pix_admin_delete_proof',
                    order_id: order_id,
                    attachment_id: attachment_id,
                    nonce: '<?php echo wp_create_nonce('pix_admin_action'); ?>'
                }, function(res) {
                    if (res.success) {
                        alert('✓ 凭证已删除');
                        location.reload();
                    } else {
                        alert('错误: ' + res.data);
                    }
                });
            }
            </script>
            <?php
        }

        public function add_management_menu() {
            add_submenu_page(
                'woocommerce',
                'PIX 支付管理',
                'PIX 支付管理',
                'manage_woocommerce',
                'pix-enhanced-dashboard',
                [$this, 'render_dashboard']
            );
        }

        public function render_dashboard() {
            global $wpdb;
            $payments_table = $wpdb->prefix . 'pix_payments';
            $audit_table = $wpdb->prefix . 'pix_audit_log';

            // 统计数据（合并为单条 GROUP BY 查询，减少数据库开销）
            $counts = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $payments_table WHERE status IN ('pending','completed','expired') GROUP BY status", OBJECT_K);
            $total_pending = isset($counts['pending']) ? $counts['pending']->cnt : 0;
            $total_completed = isset($counts['completed']) ? $counts['completed']->cnt : 0;
            $total_expired = isset($counts['expired']) ? $counts['expired']->cnt : 0;
            $recent_payments = $wpdb->get_results("SELECT * FROM $payments_table ORDER BY created_at DESC LIMIT 10");

            ?>
            <div class="wrap">
                <h1>🎯 PIX 支付仪表板</h1>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    <div style="background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #ffa500;"><?php echo $total_pending; ?></div>
                        <div style="color: #666; margin-top: 5px;">待处理支付</div>
                    </div>
                    <div style="background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo $total_completed; ?></div>
                        <div style="color: #666; margin-top: 5px;">已完成支付</div>
                    </div>
                    <div style="background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #dc3545;"><?php echo $total_expired; ?></div>
                        <div style="color: #666; margin-top: 5px;">已过期支付</div>
                    </div>
                </div>

                <h2>最近的支付记录</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>订单 ID</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>更新时间</th>
                            <th>重试次数</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment):
                            $edit_order = wc_get_order($payment->order_id);
                            $edit_url = $edit_order ? $edit_order->get_edit_order_url() : '#';
                        ?>
                            <tr>
                                <td><a href="<?php echo esc_url($edit_url); ?>">#<?php echo $payment->order_id; ?></a></td>
                                <td><span style="background: #f0f0f0; padding: 5px 10px; border-radius: 3px;"><?php echo ucfirst($payment->status); ?></span></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($payment->created_at)); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($payment->updated_at)); ?></td>
                                <td><?php echo $payment->retry_count; ?></td>
                                <td><a href="<?php echo esc_url($edit_url); ?>" class="button button-small">查看详情</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        public function audit_log($order_id, $action, $details, $triggered_by = 'system') {
            global $wpdb;
            $table = $wpdb->prefix . 'pix_audit_log';
            $wpdb->insert($table, [
                'order_id' => $order_id,
                'action' => $action,
                'details' => $details,
                'triggered_by' => $triggered_by
            ]);
        }

        public function check_pending_orders() {
            $method = WC()->session->get('chosen_payment_method');
            if ($method !== $this->id || $this->max_orders <= 0) return;

            $uid = get_current_user_id();
            $email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';

            $args = ['status' => ['pending', 'on-hold'], 'payment_method' => $this->id, 'limit' => -1, 'return' => 'ids'];
            $count = 0;

            if ($uid) {
                $args['customer_id'] = $uid;
                $count = count(wc_get_orders($args));
            } elseif ($email) {
                $args['billing_email'] = $email;
                $count = count(wc_get_orders($args));
            }

            if ($count >= $this->max_orders) {
                wc_add_notice('Limite de pedidos pendentes atingido. (Too many pending orders)', 'error');
            }
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            global $wpdb;
            $table = $wpdb->prefix . 'pix_payments';

            // 创建支付记录（使用 current_time 统一时区）
            $expire_time = gmdate('Y-m-d H:i:s', current_time('timestamp', true) + ($this->expiration * 60));
            $wpdb->insert($table, [
                'order_id' => $order_id,
                'payment_amount' => $order->get_total(),
                'status' => 'pending',
                'payment_expired_time' => $expire_time
            ]);

            $order->update_status('on-hold', 'Aguardando comprovante PIX (Awaiting PIX proof)');
            // 注意：不在此处减库存，改为管理员验证支付成功后再减

            // 审计日志
            $this->audit_log($order_id, 'payment_initiated', "订单已生成，过期时间: $expire_time");

            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) return;

            $status = $order->get_status();
            if (!in_array($status, ['pending', 'processing', 'on-hold'])) return;

            $proof = $order->get_meta('_pix_proof_attachment');
            $whatsapp_num = $this->get_option('whatsapp_number');
            $whatsapp_url = $whatsapp_num ? "https://wa.me/" . preg_replace('/[^0-9]/', '', $whatsapp_num) . "?text=" . urlencode("Olá, preciso de ajuda com o pedido #$order_id") : '';

            global $wpdb;
            $table = $wpdb->prefix . 'pix_payments';
            $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE order_id = %d", $order_id));

            // 计算剩余时间
            $remaining_minutes = $payment ? ceil((strtotime($payment->payment_expired_time) - current_time('timestamp', true)) / 60) : 0;
            $remaining_minutes = max(0, $remaining_minutes);

            $this->render_payment_page($order, $payment, $remaining_minutes);
        }

        private function render_payment_page($order, $payment, $remaining_minutes) {
            $order_id = $order->get_id();
            $proof = $order->get_meta('_pix_proof_attachment');
            $whatsapp_url = $this->get_option('whatsapp_number') ? 
                "https://wa.me/" . preg_replace('/[^0-9]/', '', $this->get_option('whatsapp_number')) . "?text=" . urlencode("Olá, preciso de ajuda com o pedido #$order_id") : '';
            ?>
            <style>
                .pix-enhanced-wrapper { background: #fff; padding: 40px; margin: 30px auto; max-width: 650px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                .pix-header { text-align: center; margin-bottom: 35px; }
                .pix-order-info { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; }
                .pix-order-number { font-size: 18px; font-weight: 700; color: #0f172a; }
                .pix-order-amount { font-size: 28px; font-weight: 800; color: #d4af37; margin: 10px 0; }
                .pix-countdown { font-size: 14px; color: #e74c3c; font-weight: 600; }
                .pix-timer { display: inline-block; background: #fee2e2; padding: 8px 15px; border-radius: 20px; margin-top: 10px; }
                .pix-step-container { margin: 30px 0; }
                .pix-step { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px solid #ecf0f1; }
                .pix-step:last-child { border-bottom: none; }
                .pix-step-header { display: flex; align-items: center; margin-bottom: 12px; }
                .pix-step-number { width: 36px; height: 36px; background: #d4af37; color: #fff; border-radius: 50%; text-align: center; line-height: 36px; font-weight: 700; margin-right: 12px; }
                .pix-step-title { font-size: 16px; font-weight: 700; color: #0f172a; }
                .pix-step-desc { color: #64748b; font-size: 13px; margin: 8px 0 12px 48px; line-height: 1.5; }
                .pix-key-display { background: #f0f0f0; border: 2px solid #ddd; padding: 15px; border-radius: 8px; margin: 10px 0; font-family: monospace; font-weight: 600; text-align: center; cursor: pointer; transition: all 0.3s; }
                .pix-key-display:hover { background: #fffcf5; border-color: #d4af37; }
                .pix-qrcode { text-align: center; margin: 15px 0; }
                .pix-qrcode img { max-width: 140px; border: 1px solid #ddd; border-radius: 8px; padding: 8px; }
                .pix-button { width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.3s; margin-top: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
                .pix-btn-primary { background: #0f172a; color: #fff; }
                .pix-btn-primary:hover { background: #1e293b; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(15,23,42,0.3); }
                .pix-btn-secondary { background: #16a34a; color: #fff; }
                .pix-btn-secondary:hover { background: #15803d; }
                .pix-success-badge { background: #dcfce7; color: #166534; padding: 12px; border-radius: 8px; text-align: center; font-weight: 600; margin: 15px 0; }
                .pix-file-preview { margin-top: 15px; text-align: center; }
                .pix-file-preview img { max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 8px; margin-top: 10px; }
                .pix-whatsapp { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 20px; padding-top: 20px; border-top: 1px dashed #ddd; }
                .pix-whatsapp a { color: #16a34a; text-decoration: none; font-weight: 600; font-size: 12px; }
                .pix-whatsapp a:hover { color: #15803d; }
                @media (max-width: 600px) {
                    .pix-enhanced-wrapper { padding: 20px; }
                    .pix-order-amount { font-size: 24px; }
                    .pix-step-desc { margin-left: 40px; }
                }
            </style>

            <div class="pix-enhanced-wrapper">
                <div class="pix-header">
                    <h2 style="margin: 0; font-size: 24px; color: #0f172a;">🎯 Finalize seu Pagamento</h2>
                </div>

                <div class="pix-order-info">
                    <div class="pix-order-number">Pedido #<?php echo $order_id; ?></div>
                    <div class="pix-order-amount"><?php echo wc_price($order->get_total()); ?></div>
                    <?php if ($remaining_minutes > 0): ?>
                        <div class="pix-countdown">
                            ⏱ Válido por <span class="pix-timer" id="countdown-timer"><?php echo $remaining_minutes; ?> min</span>
                        </div>
                    <?php else: ?>
                        <div class="pix-countdown" style="color: #dc2626;">⚠ Pagamento Expirado</div>
                    <?php endif; ?>
                </div>

                <?php if ($proof || $order->get_meta('_pix_paid_claimed')): ?>
                    <div class="pix-success-badge">
                        🎉 Comprovante Recebido! Aguardando verificação.
                    </div>
                <?php else: ?>
                    <div class="pix-step-container">
                        <div class="pix-step">
                            <div class="pix-step-header">
                                <div class="pix-step-number">1</div>
                                <div class="pix-step-title">Copie a Chave PIX</div>
                            </div>
                            <div class="pix-step-desc">Selecione a chave abaixo para copiar ou escaneie o QR Code com seu banco.</div>
                            <div class="pix-key-display" id="pix-key-display">
                                <?php echo esc_html($this->pix_key); ?>
                            </div>
                            <?php if ($this->qrcode_url): ?>
                                <div class="pix-qrcode">
                                    <img src="<?php echo esc_url($this->qrcode_url); ?>" alt="QR Code PIX">
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="pix-step">
                            <div class="pix-step-header">
                                <div class="pix-step-number">2</div>
                                <div class="pix-step-title">Envie o Comprovante</div>
                            </div>
                            <div class="pix-step-desc">Tire um print ou foto do comprovante e anexe aqui. Você receberá confirmação em segundos.</div>
                            <form id="pix-proof-form" enctype="multipart/form-data">
                                <?php wp_nonce_field('pix_enhanced_proof', 'pix_nonce'); ?>
                                <input type="file" id="pix-proof-file" name="pix_proof" accept="image/jpeg,image/png,application/pdf" style="display:none;" required>
                                <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
                                <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">
                                <button type="button" class="pix-button pix-btn-primary" onclick="document.getElementById('pix-proof-file').click()">
                                    📎 Selecionar Comprovante
                                </button>
                                <div id="file-name-display" style="font-size: 12px; color: #16a34a; margin-top: 8px; text-align: center;"></div>
                                <button type="submit" id="submit-proof-btn" class="pix-button pix-btn-secondary" style="display:none;">
                                    ✓ Confirmar Envio
                                </button>
                            </form>
                            <div id="pix-feedback" style="margin-top: 15px; padding: 12px; border-radius: 8px; text-align: center; font-weight: 600; display:none;"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($whatsapp_url): ?>
                    <div class="pix-whatsapp">
                        <a href="<?php echo esc_url($whatsapp_url); ?>" target="_blank">
                            💬 Precisa de ajuda? Fale conosco no WhatsApp
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 倒计时和表单逻辑由外部 pix_payment_script_enhanced.js 处理，避免重复初始化 -->

            <style>
                .pix-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
                .pix-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
            </style>
            <?php
        }

        public function ajax_check_payment_status() {
            check_ajax_referer('pix_enhanced_nonce', 'nonce');
            $order_id = intval($_POST['order_id']);
            $order = wc_get_order($order_id);

            if (!$order) {
                wp_send_json_error('Order not found');
            }

            global $wpdb;
            $table = $wpdb->prefix . 'pix_payments';
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE order_id = %d",
                $order_id
            ));

            wp_send_json_success([
                'order_status' => $order->get_status(),
                'payment_status' => $payment ? $payment->status : 'none',
                'has_proof' => (bool) $order->get_meta('_pix_proof_attachment'),
                'remaining_minutes' => $payment ? ceil((strtotime($payment->payment_expired_time) - time()) / 60) : 0
            ]);
        }

        public function admin_scripts() {
            if (isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['section']) && $_GET['section'] === 'pix_enhanced') {
                ?>
                <script>
                jQuery(document).ready(function($) {
                    console.log('PIX Enhanced admin scripts loaded');
                });
                </script>
                <?php
            }
        }
    }
});

// ============================================================
// 3. 辅助函数 - 附件可用性检查
// ============================================================

/**
 * 验证附件是否真实可用
 * 检查：1) 附件是否存在 2) 文件是否存在 3) URL是否可生成
 */
function pix_verify_attachment_availability($attachment_id) {
    if (!$attachment_id) {
        return [
            'available' => false,
            'reason' => 'attachment_id_empty',
            'message' => '附件ID为空'
        ];
    }

    // 检查附件是否存在
    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return [
            'available' => false,
            'reason' => 'attachment_not_found',
            'message' => '附件不存在或已被删除'
        ];
    }

    // 检查文件是否存在
    $file_path = get_attached_file($attachment_id);
    if (!$file_path || !file_exists($file_path)) {
        return [
            'available' => false,
            'reason' => 'file_not_found',
            'message' => '文件不存在于服务器',
            'file_path' => $file_path
        ];
    }

    // 检查文件是否可读
    if (!is_readable($file_path)) {
        return [
            'available' => false,
            'reason' => 'file_not_readable',
            'message' => '文件无法读取（权限问题）'
        ];
    }

    // 检查URL是否可生成
    $url = wp_get_attachment_url($attachment_id);
    if (!$url) {
        return [
            'available' => false,
            'reason' => 'url_generation_failed',
            'message' => '无法生成附件URL'
        ];
    }

    return [
        'available' => true,
        'reason' => 'ok',
        'message' => '附件可用',
        'url' => $url,
        'file_path' => $file_path,
        'file_size' => filesize($file_path)
    ];
}

/**
 * 同步支付数据 - 确保订单元数据和数据库一致
 */
function pix_sync_payment_data($order_id) {
    global $wpdb;
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }

    $payments_table = $wpdb->prefix . 'pix_payments';
    
    // 获取订单元数据
    $proof_id = $order->get_meta('_pix_proof_attachment');
    $proof_url = $order->get_meta('_pix_proof_attachment_url');
    $proof_hash = $order->get_meta('_pix_proof_hash');
    $upload_time = $order->get_meta('_pix_proof_upload_time');

    // 检查支付记录是否存在
    $payment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $payments_table WHERE order_id = %d",
        $order_id
    ));

    if (!$payment) {
        return false;
    }

    // 同步数据到数据库
    $result = $wpdb->update(
        $payments_table,
        [
            'proof_uploaded_time' => $upload_time,
            'proof_hash' => $proof_hash
        ],
        ['order_id' => $order_id],
        ['%s', '%s'],
        ['%d']
    );

    return $result !== false;
}

/**
 * 记录调试日志
 */
function pix_debug_log($order_id, $action, $details) {
    global $wpdb;
    $audit_table = $wpdb->prefix . 'pix_audit_log';
    
    $wpdb->insert(
        $audit_table,
        [
            'order_id' => $order_id,
            'action' => $action,
            'details' => is_array($details) ? json_encode($details) : $details,
            'triggered_by' => 'system',
            'created_at' => current_time('mysql')
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );
}

// ============================================================
// 4. AJAX 处理器
// ============================================================

add_action('wp_ajax_pix_enhanced_upload_proof', 'pix_enhanced_upload_proof');
add_action('wp_ajax_nopriv_pix_enhanced_upload_proof', 'pix_enhanced_upload_proof');

function pix_enhanced_upload_proof() {
    check_ajax_referer('pix_enhanced_proof', 'pix_nonce');

    // ============================================================
    // 1. 速率限制 (放宽限制以支持重试)
    // ============================================================
    $ip = WC_Geolocation::get_ip_address();
    $rate_limit_key = 'pix_upload_' . md5($ip);
    $upload_attempts = (int) get_transient($rate_limit_key) ?: 0;
    
    if ($upload_attempts > 5) {
        wp_send_json_error([
            'message' => 'Muitas tentativas. Tente novamente em 1 minuto.',
            'code' => 'RATE_LIMITED'
        ]);
    }

    // ============================================================
    // 2. 获取订单并验证归属（安全修复：防止订单枚举攻击）
    // ============================================================
    $order_id = intval($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        wp_send_json_error([
            'message' => 'ID do pedido inválido',
            'code' => 'INVALID_ORDER_ID'
        ]);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error([
            'message' => 'Pedido não encontrado',
            'code' => 'ORDER_NOT_FOUND'
        ]);
    }

    // 安全校验：验证 order_key 防止枚举攻击
    $submitted_key = sanitize_text_field($_POST['order_key'] ?? '');
    if (empty($submitted_key) || $submitted_key !== $order->get_order_key()) {
        wp_send_json_error([
            'message' => 'Token de verificação inválido',
            'code' => 'INVALID_ORDER_KEY'
        ]);
    }

    // ============================================================
    // 3. 检查文件上传错误
    // ============================================================
    if (!isset($_FILES['pix_proof'])) {
        wp_send_json_error([
            'message' => 'Arquivo não foi enviado',
            'code' => 'NO_FILE'
        ]);
    }

    $file = $_FILES['pix_proof'];
    $upload_error_messages = [
        UPLOAD_ERR_INI_SIZE => 'Arquivo excede o tamanho máximo do servidor',
        UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo do formulário',
        UPLOAD_ERR_PARTIAL => 'Upload foi parcialmente interrompido',
        UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo no disco',
        UPLOAD_ERR_EXTENSION => 'Extensão de arquivo não permitida'
    ];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_msg = $upload_error_messages[$file['error']] ?? 'Erro desconhecido no upload';
        wp_send_json_error([
            'message' => $error_msg,
            'code' => 'UPLOAD_ERROR_' . $file['error']
        ]);
    }

    // ============================================================
    // 4. 验证文件大小
    // ============================================================
    $max_file_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_file_size) {
        wp_send_json_error([
            'message' => 'Arquivo muito grande! Máximo 5MB. Seu arquivo: ' . round($file['size'] / 1024 / 1024, 2) . 'MB',
            'code' => 'FILE_TOO_LARGE'
        ]);
    }

    if ($file['size'] < 1024) { // 最少 1KB
        wp_send_json_error([
            'message' => 'Arquivo muito pequeno ou vazio',
            'code' => 'FILE_TOO_SMALL'
        ]);
    }

    // ============================================================
    // 5. 验证文件类型（严格检查）
    // ============================================================
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
    
    // 使用多种方法检测文件类型
    $file_type = wp_check_filetype($file['name']);
    if (empty($file_type['type']) || !in_array($file_type['type'], $allowed_types)) {
        wp_send_json_error([
            'message' => 'Formato de arquivo inválido. Use: JPG, PNG ou PDF',
            'code' => 'INVALID_FILE_TYPE',
            'detected' => $file_type['type'] ?? 'unknown'
        ]);
    }

    // 再次使用 mime_content_type 检查
    if (function_exists('mime_content_type')) {
        $file_mime = @mime_content_type($file['tmp_name']);
        if ($file_mime && !in_array($file_mime, $allowed_types)) {
            wp_send_json_error([
                'message' => 'Tipo MIME do arquivo inválido: ' . $file_mime,
                'code' => 'INVALID_MIME_TYPE'
            ]);
        }
    }

    // ============================================================
    // 6. 计算文件哈希
    // ============================================================
    if (!is_readable($file['tmp_name'])) {
        wp_send_json_error([
            'message' => 'Arquivo não pode ser lido',
            'code' => 'FILE_NOT_READABLE'
        ]);
    }

    $file_hash = md5_file($file['tmp_name']);
    if (!$file_hash) {
        wp_send_json_error([
            'message' => 'Falha ao processar arquivo',
            'code' => 'HASH_FAILED'
        ]);
    }

    // ============================================================
    // 7. 检查黑名单
    // ============================================================
    $blacklist = get_option('pix_proof_blacklist', []);
    if (isset($blacklist[$file_hash])) {
        wp_send_json_error([
            'message' => 'Comprovante bloqueado - já foi denunciado como fraude',
            'code' => 'BLACKLISTED'
        ]);
    }

    // ============================================================
    // 8. 检查重复凭证
    // ============================================================
    global $wpdb;
    $hashes_table = $wpdb->prefix . 'pix_hashes';
    $dup = $wpdb->get_var($wpdb->prepare(
        "SELECT order_id FROM $hashes_table WHERE hash = %s LIMIT 1",
        $file_hash
    ));

    if ($dup && $dup != $order_id) {
        wp_send_json_error([
            'message' => 'Comprovante já foi usado em outro pedido (#' . $dup . ')',
            'code' => 'DUPLICATE_PROOF'
        ]);
    }

    // ============================================================
    // 9. 处理文件上传
    // ============================================================
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $aid = media_handle_upload('pix_proof', 0);

    if (is_wp_error($aid)) {
        set_transient($rate_limit_key, $upload_attempts + 1, 60); // 增加重试计数
        wp_send_json_error([
            'message' => 'Erro ao fazer upload: ' . $aid->get_error_message(),
            'code' => 'MEDIA_UPLOAD_FAILED'
        ]);
    }

    // ============================================================
    // 10. 验证附件是否真的保存了
    // ============================================================
    $attachment = get_post($aid);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        wp_send_json_error([
            'message' => 'Anexo não foi salvo corretamente',
            'code' => 'ATTACHMENT_INVALID'
        ]);
    }

    // 改进：使用多种方法获取URL（备用方案）
    $attachment_url = wp_get_attachment_url($aid);
    
    // 备用方案 1：如果主方法失败，尝试直接构建URL
    if (!$attachment_url) {
        $attachment_file = get_attached_file($aid);
        if ($attachment_file) {
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'], '', $attachment_file);
            $attachment_url = $upload_dir['baseurl'] . $relative_path;
        }
    }

    // 备用方案 2：如果仍然失败，使用 guid
    if (!$attachment_url) {
        $attachment_obj = get_post($aid);
        if ($attachment_obj && $attachment_obj->guid) {
            $attachment_url = $attachment_obj->guid;
        }
    }

    if (!$attachment_url) {
        // 记录详细的调试信息
        pix_debug_log($order_id, 'attachment_url_failed', [
            'attachment_id' => $aid,
            'file_path' => get_attached_file($aid),
            'attachment_obj' => get_post($aid)
        ]);
        
        wp_send_json_error([
            'message' => 'URL do anexo não pode ser gerada',
            'code' => 'ATTACHMENT_URL_FAILED'
        ]);
    }

    // ============================================================
    // 11. 验证附件可用性（新增）
    // ============================================================
    $availability = pix_verify_attachment_availability($aid);
    if (!$availability['available']) {
        pix_debug_log($order_id, 'attachment_unavailable', $availability);
        
        // 删除失效的附件
        wp_delete_attachment($aid, true);
        
        wp_send_json_error([
            'message' => '附件验证失败: ' . $availability['message'],
            'code' => 'ATTACHMENT_VERIFICATION_FAILED',
            'reason' => $availability['reason']
        ]);
    }

    // ============================================================
    // 12. 更新订单元数据（改进：添加事务处理）
    // ============================================================
    $current_time = current_time('mysql');
    
    $order->update_meta_data('_pix_proof_attachment', $aid);
    $order->update_meta_data('_pix_proof_attachment_url', $attachment_url);
    $order->update_meta_data('_pix_proof_hash', $file_hash);
    $order->update_meta_data('_pix_proof_filename', basename($file['name']));
    $order->update_meta_data('_pix_proof_upload_time', $current_time);
    
    // 添加订单备注，包括支付验证信息
    $order->add_order_note(
        '✓ Comprovante PIX recebido: ' . basename($file['name']) . ' \n' .
        'Hash: ' . substr($file_hash, 0, 8) . '... \n' .
        'Tamanho: ' . round($file['size'] / 1024, 2) . 'KB \n' .
        'Aguardando verificação do administrador.',
        0
    );
    
    if (!$order->save()) {
        // 回滚：删除附件
        wp_delete_attachment($aid, true);
        
        pix_debug_log($order_id, 'order_save_failed', [
            'attachment_id' => $aid,
            'error' => 'Failed to save order metadata'
        ]);
        
        wp_send_json_error([
            'message' => 'Falha ao salvar dados do pedido',
            'code' => 'ORDER_SAVE_FAILED'
        ]);
    }

    // ============================================================
    // 13. 记录支付信息到数据库（改进：添加更多字段）
    // ============================================================
    $payments_table = $wpdb->prefix . 'pix_payments';
    $update_result = $wpdb->update(
        $payments_table,
        [
            'proof_uploaded_time' => $current_time,
            'proof_hash' => $file_hash,
            'status' => 'pending', // 待验证状态
            'notes' => '附件ID: ' . $aid . ', 文件名: ' . basename($file['name'])
        ],
        ['order_id' => $order_id],
        ['%s', '%s', '%s', '%s'],
        ['%d']
    );

    if ($update_result === false) {
        // 回滚：删除附件和订单元数据
        wp_delete_attachment($aid, true);
        $order->delete_meta_data('_pix_proof_attachment');
        $order->delete_meta_data('_pix_proof_attachment_url');
        $order->delete_meta_data('_pix_proof_hash');
        $order->delete_meta_data('_pix_proof_filename');
        $order->delete_meta_data('_pix_proof_upload_time');
        $order->save();
        
        pix_debug_log($order_id, 'db_update_failed', [
            'attachment_id' => $aid,
            'error' => 'Failed to update pix_payments table'
        ]);
        
        wp_send_json_error([
            'message' => 'Falha ao registrar pagamento no banco de dados',
            'code' => 'DB_UPDATE_FAILED'
        ]);
    }

    // ============================================================
    // 14. 记录哈希到去重表
    // ============================================================
    $insert_result = $wpdb->insert(
        $hashes_table,
        [
            'hash' => $file_hash,
            'order_id' => $order_id
        ],
        ['%s', '%d']
    );

    if ($insert_result === false) {
        pix_debug_log($order_id, 'hash_insert_failed', [
            'hash' => $file_hash,
            'error' => 'Failed to insert into pix_hashes table'
        ]);
        // 不中断流程，但记录错误
    }

    // ============================================================
    // 15. 同步支付数据（新增）
    // ============================================================
    pix_sync_payment_data($order_id);

    // ============================================================
    // 16. 发送成功响应
    // ============================================================
    set_transient($rate_limit_key, $upload_attempts + 1, 60); // 记录成功上传
    
    pix_debug_log($order_id, 'upload_success', [
        'attachment_id' => $aid,
        'file_hash' => substr($file_hash, 0, 8),
        'file_size' => $file['size'],
        'timestamp' => $current_time
    ]);

    // 通知管理员有新凭证上传
    $admin_email = get_option('admin_email');
    $subject = '[PIX] Novo comprovante - Pedido #' . $order_id;
    $edit_url = $order->get_edit_order_url();
    $message = sprintf(
        "Um novo comprovante PIX foi enviado para o pedido #%d.\n\nValor: %s\nArquivo: %s\n\nVerifique: %s",
        $order_id,
        $order->get_formatted_order_total(),
        basename($file['name']),
        $edit_url
    );
    wp_mail($admin_email, $subject, $message);
    
    wp_send_json_success([
        'message' => '✓ Comprovante recebido com sucesso!',
        'attachment_id' => $aid,
        'attachment_url' => $attachment_url,
        'file_hash' => substr($file_hash, 0, 8) . '...',
        'order_id' => $order_id,
        'timestamp' => $current_time
    ]);
}

// 前端脚本加载
add_action('wp_enqueue_scripts', function() {
    if (!is_order_received_page()) return;

    wp_enqueue_script(
        'pix-enhanced-js',
        plugins_url('pix_payment_script_enhanced.js', __FILE__),
        ['jquery'],
        '4.0.0',
        true
    );

    // 直接从订单对象获取 order_key，不依赖 URL 参数（兼容邮件链接等场景）
    $order_key_for_js = '';
    $order_id = absint(get_query_var('order-received'));
    if ($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order_key_for_js = $order->get_order_key();
        }
    }

    wp_localize_script('pix-enhanced-js', 'pixEnhanced', [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pix_enhanced_nonce'),
        'order_key' => $order_key_for_js
    ]);
});

function pix_claim_paid_enhanced_handler() {
    check_ajax_referer('pix_enhanced_nonce', 'nonce');
    $order = wc_get_order(intval($_POST['order_id']));

    if (!$order) {
        wp_send_json_error('Order not found');
    }

    // 安全校验：验证 order_key 防止枚举攻击
    $submitted_key = sanitize_text_field($_POST['order_key'] ?? '');
    if (empty($submitted_key) || $submitted_key !== $order->get_order_key()) {
        wp_send_json_error([
            'message' => 'Token de verificação inválido',
            'code' => 'INVALID_ORDER_KEY'
        ]);
    }

    if ($order->get_meta('_pix_paid_claimed')) {
        wp_send_json_error('Já declarado como pago');
    }

    $order->update_meta_data('_pix_paid_claimed', time());
    $order->add_order_note('Usuário declarou pagamento (User claimed payment)');
    $order->save();

    wp_send_json_success('Status atualizado');
}
add_action('wp_ajax_pix_claim_paid_enhanced', 'pix_claim_paid_enhanced_handler');
add_action('wp_ajax_nopriv_pix_claim_paid_enhanced', 'pix_claim_paid_enhanced_handler');

add_action('wp_ajax_pix_admin_verify_payment', function() {
    check_ajax_referer('pix_admin_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('权限不足');
    }
    $order_id = intval($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('订单不存在');
    }
    $proof_id = $order->get_meta('_pix_proof_attachment');
    if (!$proof_id) {
        wp_send_json_error('订单没有上传凭证');
    }
    global $wpdb;
    $payments_table = $wpdb->prefix . 'pix_payments';
    $wpdb->update($payments_table, ['status' => 'completed'], ['order_id' => $order_id], ['%s'], ['%d']);
    $order->set_status('processing');
    $order->add_order_note('✓ 管理员已验证 PIX 支付。(Admin verified payment)', 0);
    $order->save();
    // 验证通过后减库存
    wc_reduce_stock_levels($order_id);
    do_action('woocommerce_order_status_processing_notification', $order_id);
    wp_send_json_success('支付已验证');
});

add_action('wp_ajax_pix_admin_extend_payment', function() {
    check_ajax_referer('pix_admin_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('权限不足');
    }
    $order_id = intval($_POST['order_id'] ?? 0);
    $minutes = intval($_POST['minutes'] ?? 30);
    if ($minutes <= 0 || $minutes > 1440) {
        wp_send_json_error('分钟数无效 (1-1440)');
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('订单不存在');
    }
    $new_expiration = gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
    global $wpdb;
    $payments_table = $wpdb->prefix . 'pix_payments';
    $wpdb->update($payments_table, ['payment_expired_time' => $new_expiration], ['order_id' => $order_id], ['%s'], ['%d']);
    $order->add_order_note('⏱ 管理员延长支付期限 ' . $minutes . ' 分钟，新过期时间: ' . $new_expiration, 0);
    $order->save();
    wp_send_json_success('期限已延长');
});

add_action('wp_ajax_pix_admin_delete_proof', function() {
    check_ajax_referer('pix_admin_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('权限不足');
    }
    $order_id = intval($_POST['order_id'] ?? 0);
    $attachment_id = intval($_POST['attachment_id'] ?? 0);
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('订单不存在');
    }
    
    // 改进：记录删除操作
    pix_debug_log($order_id, 'proof_deleted_by_admin', [
        'attachment_id' => $attachment_id,
        'admin_user' => wp_get_current_user()->user_login,
        'timestamp' => current_time('mysql')
    ]);
    
    wp_delete_attachment($attachment_id, true);
    $order->delete_meta_data('_pix_proof_attachment');
    $order->delete_meta_data('_pix_proof_attachment_url');
    $order->delete_meta_data('_pix_proof_hash');
    $order->delete_meta_data('_pix_proof_filename');
    $order->delete_meta_data('_pix_proof_upload_time');
    global $wpdb;
    $payments_table = $wpdb->prefix . 'pix_payments';
    $wpdb->update($payments_table, ['status' => 'pending', 'proof_uploaded_time' => null, 'proof_hash' => null], ['order_id' => $order_id], ['%s', '%s', '%s'], ['%d']);
    $order->add_order_note('🗑 管理员删除了上传的凭证，订单需要重新上传。(Admin deleted proof)', 0);
    $order->save();
    wp_send_json_success('凭证已删除');
});

// ============================================================
// 5. 调试工具 - 附件诊断
// ============================================================

add_action('wp_ajax_pix_debug_attachment', function() {
    check_ajax_referer('pix_admin_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('权限不足');
    }
    
    $order_id = intval($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error('订单不存在');
    }
    
    $attachment_id = $order->get_meta('_pix_proof_attachment');
    $attachment_url = $order->get_meta('_pix_proof_attachment_url');
    $proof_hash = $order->get_meta('_pix_proof_hash');
    $upload_time = $order->get_meta('_pix_proof_upload_time');
    
    // 获取数据库中的支付记录
    global $wpdb;
    $payments_table = $wpdb->prefix . 'pix_payments';
    $payment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $payments_table WHERE order_id = %d",
        $order_id
    ));
    
    // 检查附件可用性
    $availability = null;
    if ($attachment_id) {
        $availability = pix_verify_attachment_availability($attachment_id);
    }
    
    // 获取审计日志
    $audit_table = $wpdb->prefix . 'pix_audit_log';
    $audit_logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $audit_table WHERE order_id = %d ORDER BY created_at DESC LIMIT 10",
        $order_id
    ));
    
    $debug_info = [
        'order_id' => $order_id,
        'order_status' => $order->get_status(),
        'order_total' => $order->get_total(),
        'attachment_metadata' => [
            'attachment_id' => $attachment_id,
            'attachment_url' => $attachment_url,
            'proof_hash' => $proof_hash,
            'upload_time' => $upload_time
        ],
        'attachment_availability' => $availability,
        'database_payment_record' => $payment ? [
            'id' => $payment->id,
            'status' => $payment->status,
            'payment_amount' => $payment->payment_amount,
            'proof_uploaded_time' => $payment->proof_uploaded_time,
            'proof_hash' => $payment->proof_hash,
            'payment_expired_time' => $payment->payment_expired_time,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at
        ] : null,
        'data_sync_status' => pix_check_data_sync($order_id),
        'recent_audit_logs' => array_map(function($log) {
            return [
                'action' => $log->action,
                'details' => $log->details,
                'triggered_by' => $log->triggered_by,
                'created_at' => $log->created_at
            ];
        }, $audit_logs),
        'diagnostic_timestamp' => current_time('mysql')
    ];
    
    wp_send_json_success($debug_info);
});

// ============================================================
// 6. 数据同步检查函数
// ============================================================

function pix_check_data_sync($order_id) {
    global $wpdb;
    $order = wc_get_order($order_id);
    if (!$order) {
        return ['synced' => false, 'reason' => 'order_not_found'];
    }
    
    $payments_table = $wpdb->prefix . 'pix_payments';
    $payment = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $payments_table WHERE order_id = %d",
        $order_id
    ));
    
    if (!$payment) {
        return ['synced' => false, 'reason' => 'no_payment_record'];
    }
    
    // 检查订单元数据和数据库是否一致
    $meta_upload_time = $order->get_meta('_pix_proof_upload_time');
    $meta_hash = $order->get_meta('_pix_proof_hash');
    
    $sync_issues = [];
    
    if ($meta_upload_time && $payment->proof_uploaded_time) {
        if (strtotime($meta_upload_time) !== strtotime($payment->proof_uploaded_time)) {
            $sync_issues[] = 'upload_time_mismatch';
        }
    }
    
    if ($meta_hash && $payment->proof_hash) {
        if ($meta_hash !== $payment->proof_hash) {
            $sync_issues[] = 'hash_mismatch';
        }
    }
    
    return [
        'synced' => empty($sync_issues),
        'issues' => $sync_issues,
        'metadata' => [
            'upload_time' => $meta_upload_time,
            'hash' => $meta_hash
        ],
        'database' => [
            'upload_time' => $payment->proof_uploaded_time,
            'hash' => $payment->proof_hash
        ]
    ];
}

// ============================================================
// 7. 修复数据同步的AJAX端点
// ============================================================

add_action('wp_ajax_pix_fix_data_sync', function() {
    check_ajax_referer('pix_admin_action', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('权限不足');
    }
    
    $order_id = intval($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error('订单不存在');
    }
    
    // 执行同步
    $result = pix_sync_payment_data($order_id);
    
    if ($result) {
        pix_debug_log($order_id, 'data_sync_fixed', [
            'admin_user' => wp_get_current_user()->user_login,
            'timestamp' => current_time('mysql')
        ]);
        
        wp_send_json_success('数据已同步');
    } else {
        wp_send_json_error('同步失败');
    }
});

// ============================================================
// 8. 重新上传凭证的AJAX端点（允许用户重新上传）
// ============================================================

add_action('wp_ajax_pix_reupload_proof', function() {
    check_ajax_referer('pix_enhanced_proof', 'pix_nonce');
    
    $order_id = intval($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error([
            'message' => '订单不存在',
            'code' => 'ORDER_NOT_FOUND'
        ]);
    }
    
    // 删除旧的凭证
    $old_attachment_id = $order->get_meta('_pix_proof_attachment');
    if ($old_attachment_id) {
        wp_delete_attachment($old_attachment_id, true);
        pix_debug_log($order_id, 'old_proof_deleted_for_reupload', [
            'old_attachment_id' => $old_attachment_id,
            'timestamp' => current_time('mysql')
        ]);
    }
    
    // 清除旧的元数据
    $order->delete_meta_data('_pix_proof_attachment');
    $order->delete_meta_data('_pix_proof_attachment_url');
    $order->delete_meta_data('_pix_proof_hash');
    $order->delete_meta_data('_pix_proof_filename');
    $order->delete_meta_data('_pix_proof_upload_time');
    $order->save();
    
    // 重置数据库状态
    global $wpdb;
    $payments_table = $wpdb->prefix . 'pix_payments';
    $wpdb->update(
        $payments_table,
        ['status' => 'pending', 'proof_uploaded_time' => null, 'proof_hash' => null],
        ['order_id' => $order_id],
        ['%s', '%s', '%s'],
        ['%d']
    );
    
    wp_send_json_success([
        'message' => '旧凭证已删除，请重新上传',
        'order_id' => $order_id
    ]);
});
