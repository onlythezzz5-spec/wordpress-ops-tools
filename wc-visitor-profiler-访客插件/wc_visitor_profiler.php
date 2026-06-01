<?php
/**
 * Plugin Name: WooCommerce Visitor Profiler (Hawk Eye)
 * Description: 独立访客分析系统。通过设备指纹、行为特征和 IP 画像，精准识别真人、爬虫与潜在欺诈者。
 * Version: 1.6.5 (UI Fix: Force Toolbar Visibility)
 * Author: zzz
 */

if (!defined('ABSPATH')) exit;

class WC_Visitor_Profiler {

    private $table_name;
    private $api_url = 'http://ip-api.com/json/'; 
    private $option_group = 'wcvp_settings_group';
    private $option_name = 'wcvp_options';
    private $timezone_string = 'America/Los_Angeles';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wcvp_logs';

        register_activation_hook(__FILE__, [$this, 'install_db']);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_footer', [$this, 'inject_tracking_script']);
        
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_links']);
        
        add_action('wp_ajax_wcvp_log_visit', [$this, 'handle_visit_log']);
        add_action('wp_ajax_nopriv_wcvp_log_visit', [$this, 'handle_visit_log']);
        add_action('wp_ajax_wcvp_gen_test_data', [$this, 'generate_test_data']);
        
        // CSV Export Handler
        add_action('admin_post_wcvp_export_csv', [$this, 'handle_csv_export']);

        if (!wp_next_scheduled('wcvp_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wcvp_daily_cleanup');
        }
        add_action('wcvp_daily_cleanup', [$this, 'cleanup_logs']);

        add_action('plugins_loaded', [$this, 'check_db_update']);
    }

    public function add_plugin_links($links) {
        $settings_link = '<a href="admin.php?page=wcvp-dashboard&tab=settings">设置</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function install_db() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip varchar(45) NOT NULL,
            location varchar(100) DEFAULT '',
            isp varchar(100) DEFAULT '',
            user_agent text NOT NULL,
            fingerprint varchar(32) DEFAULT '',
            device_info text,
            risk_score int(3) DEFAULT 0,
            risk_reasons text,
            is_bot tinyint(1) DEFAULT 0,
            page_url varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY ip (ip),
            KEY fingerprint (fingerprint)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        add_option('wcvp_db_version', '1.0');
    }

    public function check_db_update() {
        if (get_option('wcvp_db_version') !== '1.0') {
            $this->install_db();
        }
    }

    public function register_settings() {
        register_setting($this->option_group, $this->option_name);
    }

    public function inject_tracking_script() {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('wcvp_tracker');
        ?>
        <script>
        (function() {
            function getFingerprint() {
                try {
                    var c = document.createElement('canvas');
                    var ctx = c.getContext('2d');
                    var txt = "HawkEye-v1";
                    ctx.textBaseline = "top"; ctx.font = "14px 'Arial'";
                    ctx.fillStyle = "#f60"; ctx.fillRect(125,1,62,20);
                    ctx.fillStyle = "#069"; ctx.fillText(txt, 2, 15);
                    ctx.fillStyle = "rgba(102, 204, 0, 0.7)"; ctx.fillText(txt, 4, 17);
                    var str = c.toDataURL();
                    var hash = 0;
                    for (var i = 0; i < str.length; i++) {
                        var char = str.charCodeAt(i);
                        hash = ((hash<<5)-hash)+char;
                        hash = hash & hash;
                    }
                    return Math.abs(hash).toString(16);
                } catch(e) { return 'unknown'; }
            }
            function collectData() {
                return {
                    fp: getFingerprint(),
                    res: window.screen.width + 'x' + window.screen.height,
                    tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
                    lang: navigator.language,
                    cores: navigator.hardwareConcurrency || 0,
                    mem: navigator.deviceMemory || 0,
                    ref: document.referrer,
                    url: window.location.href
                };
            }
            function sendLog() {
                var data = collectData();
                var formData = new FormData();
                formData.append('action', 'wcvp_log_visit');
                formData.append('nonce', '<?php echo $nonce; ?>');
                formData.append('data', JSON.stringify(data));
                if (navigator.sendBeacon) { navigator.sendBeacon('<?php echo $ajax_url; ?>', formData); } 
                else { fetch('<?php echo $ajax_url; ?>', { method: 'POST', body: formData }); }
            }
            if (document.readyState === 'complete') setTimeout(sendLog, 1000);
            else window.addEventListener('load', function() { setTimeout(sendLog, 1000); });
        })();
        </script>
        <?php
    }

    public function handle_visit_log() {
        global $wpdb;
        $ip = $this->get_client_ip();
        
        try {
            $date = new DateTime('now', new DateTimeZone($this->timezone_string));
            $now_str = $date->format('Y-m-d H:i:s');
            $check_time = clone $date;
            $check_time->modify('-5 seconds');
            $limit_str = $check_time->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $now_str = current_time('mysql');
            $limit_str = date('Y-m-d H:i:s', strtotime('-5 seconds'));
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $this->table_name WHERE ip = %s AND created_at > %s", 
            $ip, $limit_str
        ));
        if ($exists) wp_die();

        $client_data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : [];
        $fingerprint = $client_data['fp'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'];

        $risk_score = 0;
        $risk_reasons = [];
        $is_bot = 0;

        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python|headless/i', $user_agent)) {
            $is_bot = 1;
            $risk_score += 50;
            $risk_reasons[] = '爬虫UA';
        }

        if (!empty($client_data)) {
            if ($client_data['res'] === '0x0') {
                $risk_score += 30;
                $risk_reasons[] = '无头浏览器';
            }
        }

        $geo_cache = 'wcvp_geo_' . md5($ip);
        $geo_data = get_transient($geo_cache);
        if (false === $geo_data) {
            $api_res = wp_remote_get("http://ip-api.com/json/{$ip}?fields=status,country,city,isp,mobile,proxy", ['timeout'=>2]);
            if (!is_wp_error($api_res)) {
                $geo_data = json_decode(wp_remote_retrieve_body($api_res), true);
                set_transient($geo_cache, $geo_data, 86400 * 7);
            }
        }

        $location_str = '本地/未知';
        $isp_str = 'Private/Local';
        
        if (is_array($geo_data) && isset($geo_data['status']) && $geo_data['status'] === 'success') {
            $location_str = $geo_data['country'] . ', ' . $geo_data['city'];
            $isp_str = $geo_data['isp'];
            if (isset($geo_data['hosting']) && $geo_data['hosting']) {
                $risk_score += 40;
                $risk_reasons[] = '数据中心IP';
            }
        }

        $wpdb->insert($this->table_name, [
            'ip' => $ip,
            'location' => $location_str,
            'isp' => $isp_str,
            'user_agent' => substr($user_agent, 0, 200) . '...',
            'fingerprint' => $fingerprint,
            'device_info' => json_encode($client_data),
            'risk_score' => $risk_score,
            'risk_reasons' => implode(', ', array_unique($risk_reasons)),
            'is_bot' => $is_bot,
            'page_url' => $client_data['url'] ?? '',
            'created_at' => $now_str
        ]);
        
        wp_die();
    }

    public function generate_test_data() {
        if (!current_user_can('manage_options')) wp_die();
        global $wpdb;
        
        if($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {
            $this->install_db();
        }

        try {
            $date = new DateTime('now', new DateTimeZone($this->timezone_string));
            $now_str = $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $now_str = current_time('mysql');
        }

        $wpdb->insert($this->table_name, [
            'ip' => '1.2.3.4',
            'location' => 'United States, Los Angeles (Test)',
            'isp' => 'Google Cloud (Test)',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            'fingerprint' => 'test_fingerprint',
            'device_info' => json_encode(['res'=>'1920x1080', 'tz'=>$this->timezone_string]),
            'risk_score' => 80,
            'risk_reasons' => '模拟测试数据',
            'is_bot' => 0,
            'page_url' => home_url('/test'),
            'created_at' => $now_str
        ]);
        
        wp_send_json_success('测试数据已写入！请点击刷新。');
    }

    // --- ★ 新增：导出 CSV ---
    public function handle_csv_export() {
        // 安全检查
        check_admin_referer('wcvp_export_csv', 'wcvp_nonce');
        if (!current_user_can('manage_options')) wp_die('无权限');

        global $wpdb;
        
        // 输出头
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wcvp_export_' . date('Y-m-d_H-i') . '.csv');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($output, ['ID', '时间', 'IP', '地理位置', 'ISP', '指纹', '风险分', '原因', '类型', 'URL', 'UA']);

        $offset = 0;
        $limit = 500; // 分批处理

        while(true) {
            $logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $this->table_name ORDER BY id DESC LIMIT %d OFFSET %d", 
                $limit, $offset
            ), ARRAY_A);

            if (empty($logs)) break;

            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    $log['created_at'],
                    $log['ip'],
                    $log['location'],
                    $log['isp'],
                    $log['fingerprint'],
                    $log['risk_score'],
                    $log['risk_reasons'],
                    $log['is_bot'] ? '机器人' : '真人',
                    $log['page_url'],
                    $log['user_agent']
                ]);
            }
            $offset += $limit;
            if ($offset > 10000) break; // 限制最多导出 1万条
        }
        fclose($output);
        exit;
    }

    public function add_admin_menu() {
        add_menu_page('鹰眼访客', '鹰眼访客监控', 'manage_options', 'wcvp-dashboard', [$this, 'render_dashboard'], 'dashicons-visibility', 99);
    }

    public function render_dashboard() {
        global $wpdb;
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'monitor';
        $options = get_option($this->option_name, []);
        $high_risk_threshold = isset($options['risk_threshold']) ? intval($options['risk_threshold']) : 80;

        ?>
        <style>
            .wcvp-wrap { max-width: 1200px; margin: 20px 0; }
            .wcvp-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
            .wcvp-title { display: flex; align-items: center; gap: 10px; font-size: 24px; font-weight: 600; color: #1e293b; margin: 0; }
            .wcvp-nav { border-bottom: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; align-items: center; }
            .wcvp-nav a { display: inline-block; padding: 10px 20px; text-decoration: none; color: #64748b; font-weight: 500; border-bottom: 2px solid transparent; transition: all 0.2s; }
            .wcvp-nav a.active { color: #2563eb; border-bottom-color: #2563eb; }
            .wcvp-nav a:hover { color: #0f172a; }
            
            .wcvp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; margin-top: 15px; }
            .wcvp-table { width: 100%; border-collapse: collapse; }
            .wcvp-table th { background: #f8fafc; color: #475569; font-weight: 600; text-align: left; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
            .wcvp-table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 13px; vertical-align: top; }
            .wcvp-table tr:hover { background: #f8fafc; }
            
            .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
            .badge-human { background: #dcfce7; color: #166534; }
            .badge-bot { background: #fee2e2; color: #991b1b; }
            .badge-vpn { background: #fef9c3; color: #854d0e; }
            .score-high { color: #dc2626; font-weight: bold; }
            .score-mid { color: #d97706; font-weight: bold; }
            .score-low { color: #16a34a; font-weight: bold; }
            .fp-box { font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #475569; }
            .ua-box { font-size: 11px; color: #94a3b8; margin-top: 4px; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        </style>

        <div class="wrap wcvp-wrap">
            <div class="wcvp-header">
                <h1 class="wcvp-title">👁️ 鹰眼访客监控 <span style="font-size:12px;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:12px;">v1.6.4</span></h1>
            </div>

            <div class="wcvp-nav">
                <a href="?page=wcvp-dashboard&tab=monitor" class="<?php echo $tab === 'monitor' ? 'active' : ''; ?>">📊 实时监控</a>
                <a href="?page=wcvp-dashboard&tab=settings" class="<?php echo $tab === 'settings' ? 'active' : ''; ?>">⚙️ 系统设置</a>
            </div>

            <?php if ($tab === 'monitor'): 
                if (isset($_POST['wcvp_clear']) && check_admin_referer('wcvp_clear')) {
                    $wpdb->query("TRUNCATE TABLE $this->table_name");
                    echo '<div class="notice notice-success is-dismissible"><p>✅ 日志已清空。</p></div>';
                }
                
                if($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {
                    $this->install_db(); 
                    echo '<div class="notice notice-warning"><p>正在初始化数据库表，请刷新页面...</p></div>';
                }
                
                $logs = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY id DESC LIMIT 100");
            ?>
                <!-- ★ 工具栏：强制显示 -->
                <?php 
                // 确保 Dashicons 加载
                wp_enqueue_style('dashicons'); 
                ?>
                <style>
                /* 强制显示工具栏 */
                .wcvp-force-show { display: block !important; visibility: visible !important; opacity: 1 !important; height: auto !important; overflow: visible !important; }
                .wcvp-force-flex { display: flex !important; }
                </style>

                <div class="wcvp-force-show" style="margin: 15px 0;">
                    <div class="wcvp-force-flex" style="justify-content: flex-end; gap: 10px; align-items: center;">
                        <?php 
                        // 导出按钮
                        $export_url = wp_nonce_url(
                            admin_url('admin-post.php?action=wcvp_export_csv'), 
                            'wcvp_export_csv', 
                            'wcvp_nonce'
                        );
                        ?>
                        <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary wcvp-force-flex" style="align-items: center; gap: 5px; text-decoration: none;">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                            <span>导出 CSV</span>
                        </a>
                        
                        <button type="button" onclick="location.href=location.href" class="button button-primary button-large wcvp-force-flex" style="align-items: center; gap: 5px;">
                            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                            <span>刷新数据</span>
                        </button>
                    </div>
                </div>

                <!-- JavaScript 降级方案 -->
                <script>
                (function() {
                    // 确保工具栏可见
                    document.addEventListener('DOMContentLoaded', function() {
                        var toolbar = document.querySelector('.wcvp-force-show');
                        if (toolbar) {
                            toolbar.style.display = 'block';
                            toolbar.style.visibility = 'visible';
                            toolbar.style.opacity = '1';
                        }
                    });
                })();
                </script>

                <div class="wcvp-card">
                    <table class="wcvp-table">
                        <thead>
                            <tr>
                                <th>时间 (洛杉矶)</th>
                                <th>访客画像</th>
                                <th>设备指纹</th>
                                <th>环境信息</th>
                                <th>类型</th>
                                <th>风险分</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">
                                    <p style="font-size:16px;margin-bottom:10px;">暂无数据</p>
                                    <p style="font-size:12px;">请确保插件已启用，并访问前台首页产生记录。</p>
                                </td></tr>
                            <?php else: foreach ($logs as $log): 
                                $dev = json_decode($log->device_info, true);
                                $res = isset($dev['res']) ? $dev['res'] : '-';
                                
                                $score_class = 'score-low';
                                if ($log->risk_score >= $high_risk_threshold) $score_class = 'score-high';
                                elseif ($log->risk_score >= 40) $score_class = 'score-mid';
                                
                                $type_badge = $log->is_bot ? '<span class="badge badge-bot">🤖 机器人</span>' : '<span class="badge badge-human">👤 真人</span>';
                                if (strpos($log->risk_reasons, 'Data Center') !== false) $type_badge .= ' <span class="badge badge-vpn">VPN</span>';
                            ?>
                                <tr>
                                    <td width="130" style="color:#64748b;"><?php echo $log->created_at; ?></td>
                                    <td>
                                        <div style="font-weight:600;font-size:14px;"><?php echo esc_html($log->ip); ?></div>
                                        <div style="font-size:12px;color:#475569;margin-top:2px;">📍 <?php echo esc_html($log->location); ?></div>
                                    </td>
                                    <td>
                                        <span class="fp-code"><?php echo esc_html(substr($log->fingerprint, 0, 10)); ?>...</span>
                                        <div style="font-size:11px;color:#64748b;margin-top:2px;">Res: <?php echo esc_html($res); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight:bold;margin-bottom:2px;"><?php echo esc_html($log->isp); ?></div>
                                        <div class="ua-box" title="<?php echo esc_attr($log->user_agent); ?>"><?php echo esc_html($log->user_agent); ?></div>
                                        <?php if ($log->page_url): ?>
                                            <div style="font-size:10px;color:#3b82f6;margin-top:2px;">🔗 <?php echo parse_url($log->page_url, PHP_URL_PATH); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $type_badge; ?></td>
                                    <td>
                                        <div style="font-size:16px;" class="<?php echo $score_class; ?>"><?php echo $log->risk_score; ?></div>
                                        <?php if ($log->risk_reasons): ?>
                                            <div style="font-size:10px;color:#dc2626;margin-top:2px;line-height:1.2;"><?php echo esc_html($log->risk_reasons); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($logs)): ?>
                    <div style="padding:15px;border-top:1px solid #e2e8f0;text-align:right;">
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('wcvp_clear'); ?>
                            <button type="submit" name="wcvp_clear" class="button button-link-delete" style="color:#ef4444;" onclick="return confirm('确定清空？')">🗑️ 清空所有日志</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'settings'): ?>
                <div class="wcvp-card" style="padding:20px 30px;">
                    <!-- 调试工具 -->
                    <div style="margin-bottom:30px;padding-bottom:20px;border-bottom:1px dashed #e2e8f0;">
                        <h3>🛠️ 调试工具</h3>
                        <p class="description">如果“实时监控”没有数据，请先尝试点击下方按钮，检查数据库写入是否正常。</p>
                        <button type="button" id="btn-gen-data" class="button button-secondary">生成一条测试数据</button>
                        <span id="gen-msg" style="margin-left:10px;font-weight:bold;"></span>
                        
                        <script>
                        jQuery('#btn-gen-data').click(function() {
                            var btn = jQuery(this);
                            btn.prop('disabled', true).text('生成中...');
                            jQuery.post(ajaxurl, {action: 'wcvp_gen_test_data'}, function(res) {
                                btn.prop('disabled', false).text('生成一条测试数据');
                                if(res.success) {
                                    jQuery('#gen-msg').css('color', 'green').text(res.data);
                                } else {
                                    jQuery('#gen-msg').css('color', 'red').text('失败');
                                }
                            });
                        });
                        </script>
                    </div>

                    <form method="post" action="options.php">
                        <?php 
                        settings_fields($this->option_group);
                        $opts = get_option($this->option_name);
                        ?>
                        <table class="form-table settings-form">
                            <tr valign="top">
                                <th scope="row">日志保留天数</th>
                                <td>
                                    <input type="number" name="<?php echo $this->option_name; ?>[retention_days]" value="<?php echo isset($opts['retention_days']) ? esc_attr($opts['retention_days']) : 7; ?>" min="1" max="365" class="small-text"> 天
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">高风险阈值</th>
                                <td>
                                    <input type="number" name="<?php echo $this->option_name; ?>[risk_threshold]" value="<?php echo isset($opts['risk_threshold']) ? esc_attr($opts['risk_threshold']) : 80; ?>" min="0" max="100" class="small-text"> 分
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('保存设置'); ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function cleanup_logs() {
        global $wpdb;
        $opts = get_option($this->option_name);
        $days = isset($opts['retention_days']) ? intval($opts['retention_days']) : 7;
        if ($days < 1) $days = 7;
        $wpdb->query($wpdb->prepare("DELETE FROM $this->table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days));
    }

    private function get_client_ip() {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        return WC_Geolocation::get_ip_address();
    }
}

new WC_Visitor_Profiler();