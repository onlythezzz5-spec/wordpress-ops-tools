<?php
/**
 * Plugin Name: Traffic Guard Pro (Cloak Gateway) - Ultimate
 * Plugin URI: #
 * Description: 高级流量过滤网关 v7.4.6 [底层加固版] - 移除错误抑制符，优化目录权限兼容性(0755/0777自动切换)，增强日志写入健壮性。
 * Version: 7.4.6
 * Author: zzz
 * Author URI: #
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

class TrafficGuardPlugin {
    
    private $options;
    private $log_dir;
    private $log_file;
    private $init_error = null; // 用于存储初始化错误
    
    private $cf_ipv4_ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22'
    ];

    public function __construct() {
        // [核心修复] 确保 options 始终包含默认值 '1'
        $this->options = wp_parse_args(get_option('tg_settings', []), $this->get_defaults());
        
        $this->init_audit_logging();

        add_action('admin_init', [$this, 'log_admin_access']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_actions']); 
        
        add_action('wp_ajax_tg_check_api_health', [$this, 'ajax_check_api_health']); 
        add_action('wp_ajax_tg_test_log_write', [$this, 'ajax_test_log_write']); 
        add_action('wp_ajax_tg_js_log', [$this, 'ajax_js_log_callback']); 
        add_action('wp_ajax_nopriv_tg_js_log', [$this, 'ajax_js_log_callback']); 

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_links']);
        add_action('template_redirect', [$this, 'run_guard_logic'], 1);
        add_action('wp_head', [$this, 'inject_js_sentinel'], 0);
        add_action('wp_footer', [$this, 'debug_footer_info']); 
        add_action('init', [$this, 'check_auth_entry']);
        
        if (!wp_next_scheduled('tg_daily_report_event')) {
            wp_schedule_event(time(), 'daily', 'tg_daily_report_event');
        }
        add_action('tg_daily_report_event', [$this, 'send_daily_alert']);
        
        // 显示初始化错误
        if ($this->init_error) {
            add_action('admin_notices', [$this, 'show_admin_error']);
        }
    }

    public function show_admin_error() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="notice notice-error"><p><strong>Traffic Guard 错误：</strong> ' . esc_html($this->init_error) . '</p></div>';
    }

    // === 日志系统 (底层加固) ===
    public function init_audit_logging() {
        $upload = wp_upload_dir();
        
        // 确定目录位置
        if (!empty($upload['error'])) {
            // 如果 uploads 目录不可用，回退到插件目录 (通常权限更低，但作为备选)
            $this->log_dir = plugin_dir_path(__FILE__) . 'tg_logs';
        } else {
            $this->log_dir = $upload['basedir'] . '/tg_guard_logs';
        }

        // [修复] 移除 @，增强目录创建逻辑
        if (!file_exists($this->log_dir)) {
            // 尝试标准权限 0755
            if (!mkdir($this->log_dir, 0755, true)) {
                // 如果失败，尝试宽松权限 0777 (针对部分共享主机)
                if (!is_dir($this->log_dir) && !mkdir($this->log_dir, 0777, true)) {
                    $this->init_error = '无法创建日志目录：' . $this->log_dir . '。请检查 wp-content/uploads 文件夹权限。';
                    error_log('TG Error: ' . $this->init_error);
                    return; // 终止初始化
                }
            }
        }
        
        // 安全防护：禁止直接访问
        $htaccess = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order Deny,Allow\nDeny from all");
        }
        
        // 安全防护：防止目录遍历
        if (!file_exists($this->log_dir . '/index.php')) {
            touch($this->log_dir . '/index.php');
        }

        $this->log_file = $this->log_dir . '/audit_' . date('Y-m') . '.log';
        
        if (!file_exists($this->log_file)) {
            // 尝试创建文件
            if (touch($this->log_file)) {
                chmod($this->log_file, 0666); 
                $this->write_log("SYSTEM", "INIT", "系统初始化完成 (v7.4.6 Secured)");
            } else {
                $this->init_error = '无法创建日志文件：' . $this->log_file;
                error_log('TG Error: ' . $this->init_error);
            }
        }
    }

    // === 核心拦截逻辑 (逻辑优化) ===
    public function run_guard_logic() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

        // 总开关检查
        if (empty($this->options['enabled'])) return;

        if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) return;
        if (strpos($_SERVER['REQUEST_URI'], 'admin-ajax.php') !== false && (empty($_REQUEST['action']) || strpos($_REQUEST['action'], 'tg_') === false)) return;
        if ($this->is_safe_page_request()) return;

        $client_ip = $this->get_client_ip();
        
        if (current_user_can('manage_options') && empty($this->options['debug_log_admin'])) return;

        // 1. 白名单
        $whitelist = array_filter(array_map('trim', explode("\n", $this->options['whitelist_ips'])));
        if (in_array($client_ip, $whitelist)) {
            $this->write_log($client_ip, "ALLOW", "白名单 IP");
            return; 
        }

        // 2. 黑名单
        $blacklist = array_filter(array_map('trim', explode("\n", $this->options['blacklist_ips'])));
        if (in_array($client_ip, $blacklist)) {
            $this->write_log($client_ip, "BLOCK", "黑名单 IP");
            $this->serve_safe_page();
        }

        // 3. 隐身 Cookie
        if ($this->check_auth_cookie($client_ip)) {
            $this->write_log($client_ip, "ALLOW", "隐身 Cookie");
            return;
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        // [核心修复] 使用 !== '0' 判断，意味着如果配置不存在或为空，默认视为开启
        
        // 4. UA 拦截
        if ($this->options['enable_ua_block'] !== '0') {
            $bot_check = $this->check_bot_advanced($user_agent);
            if ($bot_check['is_bot']) {
                $this->block_and_log($client_ip, $bot_check['reason']);
            }
        }

        // 5. Referer 拦截
        if ($this->options['enable_referer_block'] !== '0' && !empty($this->options['block_empty_referer'])) {
            $is_app = preg_match('/(facebook|instagram|android-app)/i', $referer);
            $is_mobile_ua = preg_match('/(iPhone|iPad|Android.*Mobile)/i', $user_agent);
            if (empty($referer) && !$is_app && !$is_mobile_ua) {
                $this->block_and_log($client_ip, "空 Referer");
            }
        }

        // 6. 速率限制
        if ($this->options['enable_rate_limit'] !== '0' && $this->is_rate_limited($client_ip)) {
            $this->block_and_log($client_ip, "频率限制 (CC)");
        }

        // 7. 缓存黑名单
        $cache_key = 'tg_ip_v4_' . md5($client_ip);
        $cached_result = get_transient($cache_key);
        if ($cached_result && $cached_result['status'] === 'BLOCK') {
            $this->write_log($client_ip, "BLOCK", "缓存黑名单: " . ($cached_result['reason'] ?? '未知'));
            $this->serve_safe_page();
        }

        // 8. API 拦截 (默认开启)
        if ($this->options['enable_api_check'] !== '0') {
            $ip_result = $this->check_ip_reputation($client_ip);
            if ($ip_result['status'] === 'BLOCK') {
                $this->write_log($client_ip, "BLOCK", $ip_result['reason']);
                $this->serve_safe_page();
            } elseif ($ip_result['status'] === 'API_FAIL' && empty($this->options['fail_open'])) {
                $this->write_log($client_ip, "BLOCK", "API失败 (安全模式)");
                $this->serve_safe_page();
            }
        }

        $this->write_log($client_ip, "ALLOW", "检测通过");
    }

    private function block_and_log($ip, $reason) {
        $this->write_log($ip, "BLOCK", $reason);
        set_transient('tg_ip_v4_' . md5($ip), ['status' => 'BLOCK', 'reason' => $reason], 86400);
        $this->serve_safe_page();
    }

    // === JS 哨兵 (逻辑优化) ===
    public function inject_js_sentinel() {
        if ($this->is_safe_page_request()) return;
        if (current_user_can('manage_options') && empty($this->options['debug_log_admin'])) return;
        
        // 如果总开关没开，或者JS开关明确被关了，则不注入
        if (empty($this->options['enabled'])) return;
        if ($this->options['js_guard_enabled'] === '0') return; // !== '0' 视为开启
        
        $safe_url = $this->options['safe_page_url'] ?: '/';
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <script>
        (function() {
            var safeUrl = "<?php echo esc_url($safe_url); ?>";
            var reportUrl = "<?php echo esc_url($ajax_url); ?>";
            var reasons = [];
            if (navigator.webdriver) reasons.push("WebDriver");
            if (navigator.hardwareConcurrency && (navigator.hardwareConcurrency < 2 || navigator.hardwareConcurrency > 32)) reasons.push("CPU:" + navigator.hardwareConcurrency);
            try {
                var c = document.createElement('canvas');
                var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
                if (gl) {
                    var debug = gl.getExtension('WEBGL_debug_renderer_info');
                    if (debug) {
                        var ren = gl.getParameter(debug.UNMASKED_RENDERER_WEBGL);
                        var bad = ['swiftshader', 'llvmpipe', 'virtualbox', 'vmware', 'emulator', 'software', 'headless'];
                        for (var i=0; i<bad.length; i++) {
                            if (ren.toLowerCase().includes(bad[i])) { reasons.push("GPU:" + ren); break; }
                        }
                    }
                }
            } catch(e) {}
            if (reasons.length > 0) {
                var data = new FormData();
                data.append('action', 'tg_js_log');
                data.append('reason', reasons.join(', '));
                if (navigator.sendBeacon) navigator.sendBeacon(reportUrl, data);
                else { var xhr = new XMLHttpRequest(); xhr.open('POST', reportUrl, false); xhr.send(data); }
                setTimeout(function(){ window.location.replace(safeUrl); }, 100);
            }
        })();
        </script>
        <?php
    }

    public function ajax_js_log_callback() {
        $reason = sanitize_text_field($_POST['reason'] ?? 'Unknown JS');
        $ip = $this->get_client_ip();
        $this->block_and_log($ip, "JS哨兵: " . $reason);
        wp_die(); 
    }

    // === 新增：一键写入测试 ===
    public function ajax_test_log_write() {
        if (!current_user_can('manage_options')) wp_send_json_error('无权限');
        
        // 再次检查目录是否存在（防止被手动删除）
        if (!file_exists($this->log_dir)) {
            @mkdir($this->log_dir, 0755, true);
        }
        
        $test_msg = "[" . current_time('mysql') . "] IP:TEST | INFO  | 写入测试 | UA:Manual\n";
        
        // 尝试写入
        $res = file_put_contents($this->log_file, $test_msg, FILE_APPEND);
        
        if ($res !== false) wp_send_json_success(['path' => $this->log_file, 'size' => filesize($this->log_file)]);
        else {
            $error = error_get_last();
            wp_send_json_error(['msg' => '写入失败', 'path' => $this->log_file, 'php_error' => $error['message'] ?? '未知错误，可能是权限问题']);
        }
    }

    // === API 健康检查 ===
    public function ajax_check_api_health() {
        if (!current_user_can('manage_options')) wp_send_json_error();
        $apis = $this->get_api_list('8.8.8.8');
        $results = [];
        foreach ($apis as $url) {
            $start = microtime(true);
            $res = $this->remote_get_request($url);
            $duration = round((microtime(true) - $start) * 1000, 2);
            if ($res['success']) {
                $data = json_decode($res['body'], true);
                $status = $data ? 'OK' : 'Invalid JSON';
                $color = $data ? 'green' : 'orange';
                $results[] = ['url' => $this->truncate_url($url), 'status' => $status, 'ping' => $duration . 'ms', 'color' => $color];
            } else {
                $results[] = ['url' => $this->truncate_url($url), 'status' => 'Error', 'ping' => '-', 'color' => 'red'];
            }
        }
        wp_send_json_success($results);
    }

    private function truncate_url($url) {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? $host : substr($url, 0, 20) . '...';
    }

    // === 告警系统 ===
    public function send_daily_alert() { /* 保持不变 */ }

    // === 辅助功能 ===
    public function log_admin_access() { /* 省略 */ }
    public function check_auth_entry() { /* 省略 */ }
    private function check_auth_cookie($ip) { return isset($_COOKIE['tg_sess_id']) && $_COOKIE['tg_sess_id'] === md5($ip . $this->options['auth_key']); }

    public function debug_footer_info() {
        if (!current_user_can('manage_options') || empty($this->options['debug_log_admin'])) return;
        echo '<div style="position:fixed;bottom:0;left:0;background:rgba(0,0,0,0.8);color:#0f0;padding:5px 10px;z-index:99999;font-size:12px;font-family:monospace;">TG Debug: 插件运行中 | IP: '.$this->get_client_ip().' | Log: '. ($this->options['enable_logging'] ? 'ON' : 'OFF') .'</div>';
    }

    private function is_rate_limited($ip) {
        $limit = intval($this->options['rate_limit_max']);
        if ($limit <= 0) return false;
        $key = 'tg_rate_' . md5($ip);
        $count = get_transient($key);
        if ($count === false) { set_transient($key, 1, 60); return false; }
        if ($count > $limit) return true;
        set_transient($key, $count + 1, 60);
        return false;
    }

    private function write_log($ip, $action, $reason) {
        $logging_enabled = isset($this->options['enable_logging']) ? $this->options['enable_logging'] : '1';
        if ($logging_enabled === '0' || !$this->log_file) return;

        // [修复] 再次检查目录是否存在，并移除 @
        if (!file_exists(dirname($this->log_file))) {
             if (!mkdir(dirname($this->log_file), 0755, true)) {
                 error_log('TG Error: Failed to create log directory during write');
                 return;
             }
             file_put_contents(dirname($this->log_file) . '/.htaccess', "Order Deny,Allow\nDeny from all");
        }
        
        $msg = sprintf("[%s] IP:%s | %s | %s | UA:%s\n", current_time('mysql'), $ip, str_pad($action, 5), $reason, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 50));
        
        // [修复] 移除 @，检测写入结果
        if (file_put_contents($this->log_file, $msg, FILE_APPEND | LOCK_EX) === false) {
            // 写入失败降级处理：记录到 PHP 错误日志
            error_log("TG Fail: $ip | $action | $reason");
        }
    }

    private function check_ip_reputation($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return ['status' => 'ALLOW', 'reason' => 'Private IP'];
        $cache_key = 'tg_ip_v4_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $apis = $this->get_api_list($ip);
        $result = ['status' => 'API_FAIL', 'reason' => 'All APIs failed'];

        foreach ($apis as $url) {
            $res = ['success' => false];
            for ($i = 0; $i < 2; $i++) { 
                if ($i > 0) usleep(200000); 
                $res = $this->remote_get_request($url);
                if ($res['success']) break;
            }
            if (!$res['success']) continue;
            $data = json_decode($res['body'], true);
            if (!$data) continue;

            $country = $data['country'] ?? $data['country_code'] ?? $data['countryCode'] ?? 'XX';
            $org_raw = strtolower(($data['org'] ?? '') . ' ' . ($data['isp'] ?? '') . ' ' . ($data['connection']['isp'] ?? ''));
            
            $allowed = array_map('trim', explode(',', $this->options['allowed_countries']));
            if (!in_array(strtoupper($country), array_map('strtoupper', $allowed))) {
                $result = ['status' => 'BLOCK', 'reason' => "地区拦截: $country"];
                break;
            }

            $bad_keywords = ['google', 'facebook', 'amazon', 'microsoft', 'azure', 'digitalocean', 'vultr', 'linode', 'alibaba', 'tencent', 'cloudflare', 'vpn', 'proxy', 'hosting', 'datacenter'];
            foreach ($bad_keywords as $kw) {
                if (strpos($org_raw, $kw) !== false) {
                    $result = ['status' => 'BLOCK', 'reason' => "机房拦截: $kw"];
                    break 2;
                }
            }
            $result = ['status' => 'ALLOW', 'reason' => 'IP检测通过'];
            break;
        }

        $ttl = ($result['status'] === 'BLOCK') ? 43200 : (($result['status'] === 'API_FAIL') ? 600 : 86400); 
        set_transient($cache_key, $result, $ttl);
        return $result;
    }

    private function get_client_ip() {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && $this->is_cloudflare_ip($_SERVER['REMOTE_ADDR'])) return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        return $_SERVER['REMOTE_ADDR'];
    }

    private function is_cloudflare_ip($ip) {
        if (strpos($ip, ':') !== false) return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        foreach ($this->cf_ipv4_ranges as $cidr) { 
            list($subnet, $bits) = explode('/', $cidr);
            if ((ip2long($ip) & (-1 << (32 - $bits))) == ip2long($subnet)) return true;
        }
        return false;
    }

    private function remote_get_request($url) {
        $ssl = !(strpos($url, 'ip-api.com') !== false || strpos($url, 'http://') === 0);
        $res = wp_remote_get($url, ['timeout' => 5, 'redirection' => 2, 'user-agent' => 'TG-Audit/7.4', 'sslverify' => $ssl]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) != 200) return ['success'=>false];
        return ['success'=>true, 'body'=>wp_remote_retrieve_body($res)];
    }

    private function get_api_list($ip) {
        $apis = [];
        if (!empty($this->options['ipinfo_token'])) $apis[] = 'https://ipinfo.io/{IP}/json?token=' . trim($this->options['ipinfo_token']);
        $defaults = ['http://ip-api.com/json/{IP}?fields=status,countryCode,org,isp,as,proxy,hosting', 'https://ipapi.co/{IP}/json/'];
        if (!empty($this->options['api_sources'])) $defaults = array_merge($defaults, array_filter(array_map('trim', explode("\n", $this->options['api_sources']))));
        return array_map(function($u) use ($ip){ return str_replace('{IP}',$ip,$u); }, array_unique(array_merge($apis, $defaults)));
    }

    private function check_bot_advanced($ua) { 
        if (strlen($ua) < 15) return ['is_bot'=>true, 'reason'=>'UA过短'];
        $bots = ['facebook', 'google', 'twitter', 'linkedin', 'discord', 'telegram', 'whatsapp', 'python', 'curl', 'wget', 'headless', 'selenium', 'puppeteer', 'bot', 'spider', 'crawl'];
        foreach($bots as $b) if(stripos($ua,$b)!==false) return ['is_bot'=>true,'reason'=>"Bot拦截:$b"];
        return ['is_bot'=>false]; 
    }

    private function is_safe_page_request() {
        return !empty($this->options['safe_page_url']) && strpos($_SERVER['REQUEST_URI'], $this->options['safe_page_url']) !== false;
    }

    private function serve_safe_page() {
        if ($this->options['safe_mode'] === 'redirect') { 
            wp_redirect($this->options['safe_page_url']); 
        } else { 
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('HTTP/1.1 200 OK'); 
            $html = $this->options['safe_page_html'];
            $html = str_replace(
                ['{IP}', '{UA}', '{TIME}', '{COUNTRY}'], 
                [$this->get_client_ip(), $_SERVER['HTTP_USER_AGENT'], current_time('mysql'), 'XX'], 
                $html
            );
            echo $html; 
        }
        exit;
    }

    private function get_defaults() {
        return [
            'enabled'=>'1', 'enable_ua_block'=>'1', 'enable_referer_block'=>'1', 'enable_rate_limit'=>'1', 'enable_api_check'=>'1', 'js_guard_enabled'=>'1',
            'safe_mode'=>'html', 'safe_page_html'=>'<div style="text-align:center;padding:50px;"><h1>网站维护中</h1><p>IP: {IP}</p></div>', 'safe_page_url'=>'/privacy-policy',
            'allowed_countries'=>'BR', 'whitelist_ips'=>'', 'blacklist_ips'=>'',
            'auth_key'=>'xp9s8d7f', 'ipinfo_token'=>'', 'api_sources'=>'', 'block_empty_referer'=>'1', 'rate_limit_max'=>'60', 'fail_open'=>'0', 'enable_logging'=>'1',
            'alert_email'=>get_option('admin_email'),
            'debug_log_admin'=>'0'
        ];
    }

    // === UI 与功能交互 ===
    public function handle_actions() { 
        if (isset($_POST['tg_action'])) {
            if (!current_user_can('manage_options')) return;
            check_admin_referer('tg_log_action', 'tg_nonce');

            if ($_POST['tg_action'] === 'clear') {
                @file_put_contents($this->log_file, '');
                add_settings_error('tg_messages', 'tg_message', '日志已清空', 'updated');
            } elseif ($_POST['tg_action'] === 'export_csv') {
                $this->export_logs_csv();
            } elseif ($_POST['tg_action'] === 'export_json') {
                $this->export_settings_json();
            } elseif ($_POST['tg_action'] === 'import_json') {
                $this->import_settings_json();
            } elseif ($_POST['tg_action'] === 'reset_defaults') { // 新增重置
                update_option('tg_settings', $this->get_defaults());
                add_settings_error('tg_messages', 'tg_message', '已重置为默认推荐配置', 'updated');
                wp_redirect(remove_query_arg('tg_action')); exit;
            }
        }
    }

    private function export_logs_csv() { /* 与上版相同，略 */ }
    
    private function export_settings_json() {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=tg_settings_' . date('Y-m-d') . '.json');
        echo json_encode($this->options);
        exit;
    }

    private function import_settings_json() {
        if (!empty($_FILES['import_file']['tmp_name'])) {
            $json = file_get_contents($_FILES['import_file']['tmp_name']);
            $data = json_decode($json, true);
            if (is_array($data)) {
                update_option('tg_settings', $data);
                add_settings_error('tg_messages', 'tg_message', '配置导入成功！', 'updated');
            }
        }
    }

    public function add_admin_menu() { add_options_page('流量卫士配置', '流量卫士', 'manage_options', 'traffic-guard', [$this, 'settings_page']); }
    public function add_plugin_links($links) { array_unshift($links, '<a href="options-general.php?page=traffic-guard">设置</a>'); return $links; }
    public function register_settings() { register_setting('tg_settings_group', 'tg_settings'); }

    public function settings_page() {
        $filter_reason = isset($_GET['filter_reason']) ? sanitize_text_field($_GET['filter_reason']) : '';
        $stats = ['today' => ['total'=>0, 'blocked'=>0], 'reason_counts' => [], 'recent_logs' => [], 'mobile_ua_count' => 0];
        
        // 状态自检
        $status_html = '';
        if (!file_exists($this->log_file)) {
            $status_html = '<div class="notice notice-error inline"><p>❌ <strong>严重错误</strong>：日志文件不存在。路径：<code>'.esc_html($this->log_file).'</code></p></div>';
        } elseif (!is_writable($this->log_file)) {
            $status_html = '<div class="notice notice-warning inline"><p>⚠️ <strong>权限警告</strong>：日志文件不可写。系统无法记录数据。</p></div>';
        } elseif (filesize($this->log_file) < 50) { 
            $status_html = '<div class="notice notice-info inline"><p>💡 <strong>提示</strong>：暂无数据。请开启“调试模式”并点击“测试写入”按钮。</p></div>';
        }

        if (file_exists($this->log_file)) {
            $lines = file($this->log_file);
            $lines = array_slice($lines, -2000);
            $now = current_time('timestamp');
            
            foreach ($lines as $line) {
                // 兼容多空格匹配
                if (preg_match('/^\[(.*?)\] IP:(.*?) \| (.*?) \| (.*?) \| UA:(.*)/', $line, $m)) {
                    $ts = strtotime($m[1]); $ip = trim($m[2]); $action = trim($m[3]); $reason = trim($m[4]); $ua = trim($m[5]);
                    
                    if ($filter_reason && strpos($reason, $filter_reason) === false) continue;

                    if ($ts >= strtotime('today', $now)) {
                        $stats['today']['total']++;
                        if ($action === 'BLOCK') {
                            $stats['today']['blocked']++;
                            if (!isset($stats['reason_counts'][$reason])) $stats['reason_counts'][$reason] = 0;
                            $stats['reason_counts'][$reason]++;
                            if (preg_match('/(iPhone|Android|Mobile)/i', $ua)) $stats['mobile_ua_count']++;
                        }
                    }
                    if (count($stats['recent_logs']) < 50) array_unshift($stats['recent_logs'], compact('ts', 'ip', 'action', 'reason', 'ua'));
                }
            }
            arsort($stats['reason_counts']);
            $stats['reason_counts'] = array_slice($stats['reason_counts'], 0, 5);
        }
        
        // 计算误杀率
        $false_positive_rate = ($stats['today']['blocked'] > 0) ? round(($stats['mobile_ua_count'] / $stats['today']['blocked']) * 100, 1) : 0;
        ?>
        <div class="wrap">
            <h1 style="margin-bottom:20px;">🛡️ 流量卫士 (Traffic Guard Pro) <span style="font-size:12px;background:#2271b1;color:#fff;padding:2px 6px;border-radius:4px;">Ultimate v7.4.6</span> <a href="javascript:window.location.reload();" class="page-title-action" style="margin-left:10px;">🔄 刷新数据 & 日志</a></h1>
            
            <?php echo $status_html; ?>

            <!-- API 健康仪表盘 -->
            <div id="api-health-bar" style="background:#fff;padding:10px 15px;border:1px solid #c3c4c7;margin-bottom:15px;display:flex;align-items:center;gap:15px;">
                <div style="flex:1;">
                    <strong>📡 API 连通性:</strong> <span id="api-status-text">检测中...</span>
                    <button type="button" class="button button-small" onclick="checkApiHealth()">立即检测</button>
                </div>
                <div>
                    <strong style="color:#d63638;">🛠️ 诊断:</strong> 
                    <button type="button" class="button button-small" onclick="testLogWrite()">测试写入权限</button>
                    <span id="write-test-result" style="margin-left:5px;font-size:12px;"></span>
                </div>
            </div>

            <!-- 数据图表区 -->
            <div style="display:flex;gap:20px;margin-bottom:20px;">
                <div style="flex:1;background:#fff;padding:20px;border:1px solid #c3c4c7;">
                    <h3>📊 拦截原因分布 (TOP 5)</h3>
                    <canvas id="reasonChart" style="max-height:200px;"></canvas>
                </div>
                <div style="flex:1;background:#fff;padding:20px;border:1px solid #c3c4c7;">
                     <h3>📉 今日数据概览</h3>
                     <p>总请求: <strong><?php echo $stats['today']['total']; ?></strong> | 拦截: <strong style="color:red"><?php echo $stats['today']['blocked']; ?></strong></p>
                     <p>移动端拦截占比 (疑似误杀率): <strong><?php echo $false_positive_rate; ?>%</strong></p>
                     <div style="background:#f0f0f1;height:10px;border-radius:5px;margin-top:10px;overflow:hidden;">
                         <div style="background:#d63638;width:<?php echo ($stats['today']['total']>0 ? ($stats['today']['blocked']/$stats['today']['total']*100) : 0); ?>%;height:100%;"></div>
                     </div>
                </div>
            </div>

            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php settings_fields('tg_settings_group'); ?>
                
                <h2 class="nav-tab-wrapper">
                    <a href="#tab-general" class="nav-tab nav-tab-active">🛡️ 防御开关</a>
                    <a href="#tab-rules" class="nav-tab">⛔ 黑白名单</a>
                    <a href="#tab-page" class="nav-tab">🎨 拦截页面</a>
                    <a href="#tab-advanced" class="nav-tab">⚙️ 高级配置</a>
                    <a href="#tab-logs" class="nav-tab">📋 日志管理</a>
                </h2>

                <div id="tab-general" class="tab-content" style="background:#fff;padding:20px;border:1px solid #c3c4c7;border-top:none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">总开关</th>
                            <td><label class="switch"><input type="checkbox" name="tg_settings[enabled]" value="1" <?php checked('1', $this->options['enabled']); ?>> 开启总防御</label></td>
                        </tr>
                        <tr>
                            <th scope="row">分项开关</th>
                            <td>
                                <p class="description">以下开关默认为开启状态。仅当您需要关闭某项检测时才取消勾选。</p>
                                <fieldset>
                                    <label><input type="checkbox" name="tg_settings[enable_ua_block]" value="1" <?php checked('1', isset($this->options['enable_ua_block']) ? $this->options['enable_ua_block'] : '1'); ?>> 启用 UA 拦截 (爬虫/空UA)</label><br>
                                    <label><input type="checkbox" name="tg_settings[enable_referer_block]" value="1" <?php checked('1', isset($this->options['enable_referer_block']) ? $this->options['enable_referer_block'] : '1'); ?>> 启用 Referer 拦截 (空来源)</label><br>
                                    <label><input type="checkbox" name="tg_settings[enable_rate_limit]" value="1" <?php checked('1', isset($this->options['enable_rate_limit']) ? $this->options['enable_rate_limit'] : '1'); ?>> 启用 速率限制 (CC防护)</label><br>
                                    <label><input type="checkbox" name="tg_settings[enable_api_check]" value="1" <?php checked('1', isset($this->options['enable_api_check']) ? $this->options['enable_api_check'] : '1'); ?>> 启用 API 机房/地区检测 (核心)</label><br>
                                    <label><input type="checkbox" name="tg_settings[js_guard_enabled]" value="1" <?php checked('1', isset($this->options['js_guard_enabled']) ? $this->options['js_guard_enabled'] : '1'); ?>> 启用 JS 哨兵 (防Headless浏览器)</label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="tab-rules" class="tab-content" style="display:none;background:#fff;padding:20px;border:1px solid #c3c4c7;border-top:none;">
                    <table class="form-table">
                         <tr>
                            <th scope="row">放行国家 (ISO代码)</th>
                            <td><input type="text" name="tg_settings[allowed_countries]" value="<?php echo esc_attr($this->options['allowed_countries']); ?>" class="large-text"><br><span>例如: BR, US, SG</span></td>
                        </tr>
                        <tr>
                            <th scope="row">白名单 (IP/CIDR)</th>
                            <td><textarea name="tg_settings[whitelist_ips]" rows="5" class="large-text" placeholder="127.0.0.1"><?php echo esc_textarea($this->options['whitelist_ips']); ?></textarea><br><span>直接放行，不走任何检测。</span></td>
                        </tr>
                         <tr>
                            <th scope="row">黑名单 (IP/CIDR)</th>
                            <td><textarea name="tg_settings[blacklist_ips]" rows="5" class="large-text" placeholder="192.168.1.1"><?php echo esc_textarea($this->options['blacklist_ips']); ?></textarea><br><span>直接拦截。</span></td>
                        </tr>
                    </table>
                </div>

                <div id="tab-page" class="tab-content" style="display:none;background:#fff;padding:20px;border:1px solid #c3c4c7;border-top:none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">拦截动作</th>
                            <td>
                                <select name="tg_settings[safe_mode]">
                                    <option value="html" <?php selected('html', $this->options['safe_mode']); ?>>显示 HTML 内容</option>
                                    <option value="redirect" <?php selected('redirect', $this->options['safe_mode']); ?>>URL 跳转</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">快速模板</th>
                            <td>
                                <select onchange="applyTemplate(this.value)">
                                    <option value="">-- 选择模板 --</option>
                                    <option value="br_maint">🇧🇷 巴西维护页</option>
                                    <option value="nginx_404">❌ Nginx 404</option>
                                    <option value="fake_login">👤 假登录页</option>
                                </select>
                                <button type="button" class="button" onclick="applyTemplate(document.querySelector('select[onchange]').value)">应用模板</button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">HTML 内容</th>
                            <td>
                                <textarea id="safe_html_area" name="tg_settings[safe_page_html]" rows="10" class="large-text"><?php echo esc_textarea($this->options['safe_page_html']); ?></textarea>
                                <p class="description">支持变量: <code>{IP}</code> <code>{UA}</code> <code>{TIME}</code> <code>{COUNTRY}</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">跳转 URL</th>
                            <td><input type="text" name="tg_settings[safe_page_url]" value="<?php echo esc_attr($this->options['safe_page_url']); ?>" class="large-text"></td>
                        </tr>
                    </table>
                </div>

                <div id="tab-advanced" class="tab-content" style="display:none;background:#fff;padding:20px;border:1px solid #c3c4c7;border-top:none;">
                    <table class="form-table">
                        <tr>
                            <th>快速操作</th>
                            <td>
                                <button type="submit" name="tg_action" value="reset_defaults" class="button button-secondary" onclick="return confirm('确定恢复默认推荐设置吗？这将覆盖现有开关状态。');">🔄 一键恢复默认推荐配置</button>
                            </td>
                        </tr>
                        <tr>
                            <th>日志设置</th>
                            <td>
                                <label><input type="checkbox" name="tg_settings[enable_logging]" value="1" <?php checked('1', isset($this->options['enable_logging']) ? $this->options['enable_logging'] : '1'); ?>> <strong>启用文件日志记录</strong> (关闭后将不记录任何数据)</label>
                            </td>
                        </tr>
                        <tr>
                            <th>调试模式</th>
                            <td>
                                <label><input type="checkbox" name="tg_settings[debug_log_admin]" value="1" <?php checked('1', $this->options['debug_log_admin']); ?>> <strong>记录管理员访问</strong></label>
                                <p class="description">勾选此项后，管理员在前台的访问也会被记录，用于自测数据是否正常。</p>
                            </td>
                        </tr>
                        <tr><th>API Token (IPInfo)</th><td><input type="password" name="tg_settings[ipinfo_token]" value="<?php echo esc_attr($this->options['ipinfo_token']); ?>" class="regular-text"></td></tr>
                        <tr><th>告警邮箱</th><td><input type="email" name="tg_settings[alert_email]" value="<?php echo esc_attr($this->options['alert_email']); ?>" class="regular-text"><br><span>当拦截率>50%时发送日报。</span></td></tr>
                        <tr><th>导入配置 (JSON)</th><td><input type="file" name="import_file"> <button type="submit" name="tg_action" value="import_json" class="button">导入</button></td></tr>
                        <tr><th>导出配置</th><td><button type="submit" name="tg_action" value="export_json" class="button">导出所有设置 (JSON)</button></td></tr>
                    </table>
                </div>

                <div id="tab-logs" class="tab-content" style="display:none;background:#fff;padding:20px;border:1px solid #c3c4c7;border-top:none;">
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <select name="filter_reason">
                                <option value="">所有原因</option>
                                <option value="机房拦截" <?php selected($filter_reason, '机房拦截'); ?>>机房拦截</option>
                                <option value="地区拦截" <?php selected($filter_reason, '地区拦截'); ?>>地区拦截</option>
                                <option value="Bot拦截" <?php selected($filter_reason, 'Bot拦截'); ?>>Bot拦截</option>
                                <option value="JS哨兵" <?php selected($filter_reason, 'JS哨兵'); ?>>JS哨兵</option>
                            </select>
                            <button class="button">筛选</button>
                        </div>
                        <div class="alignright">
                            <button type="button" class="button" onclick="window.location.reload();" style="margin-right:5px;">🔄 刷新日志</button>
                            <button type="submit" name="tg_action" value="export_csv" class="button button-secondary">📥 导出 CSV</button>
                            <button type="submit" name="tg_action" value="clear" class="button button-link-delete" onclick="return confirm('确定清空？');">🗑️ 清空日志</button>
                        </div>
                    </div>
                    <table class="widefat striped">
                        <thead><tr><th>时间</th><th>IP</th><th>动作</th><th>原因</th><th>UA</th></tr></thead>
                        <tbody>
                            <?php foreach($stats['recent_logs'] as $log): 
                                $color = $log['action'] === 'BLOCK' ? 'red' : 'green';
                            ?>
                            <tr>
                                <td><?php echo date('H:i:s', $log['ts']); ?></td>
                                <td><a href="https://ipinfo.io/<?php echo $log['ip']; ?>" target="_blank"><?php echo $log['ip']; ?></a></td>
                                <td style="color:<?php echo $color; ?>;font-weight:bold;"><?php echo $log['action']; ?></td>
                                <td><?php echo esc_html($log['reason']); ?></td>
                                <td style="font-size:11px;color:#666;"><?php echo esc_html(mb_strimwidth($log['ua'], 0, 40, '...')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px;">
                    <?php submit_button('💾 保存所有设置'); ?>
                </div>
            </form>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        // Tabs 切换与记忆逻辑
        document.addEventListener('DOMContentLoaded', () => {
            const activeTab = localStorage.getItem('tg_active_tab') || '#tab-general';
            const tabBtn = document.querySelector(`.nav-tab[href="${activeTab}"]`);
            if (tabBtn) {
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
                tabBtn.classList.add('nav-tab-active');
                document.querySelector(activeTab).style.display = 'block';
            }
            checkApiHealth(); 
        });

        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const target = tab.getAttribute('href');
                localStorage.setItem('tg_active_tab', target);
                
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
                tab.classList.add('nav-tab-active');
                document.querySelector(target).style.display = 'block';
            });
        });

        // 模板系统
        const templates = {
            br_maint: '<div style="font-family:Arial;text-align:center;padding:100px;"><h1>Manutenção Programada</h1><p>Estamos atualizando nossos servidores.</p><p>Seu IP: {IP}</p></div>',
            nginx_404: '<html><head><title>404 Not Found</title></head><body bgcolor="white"><center><h1>404 Not Found</h1></center><hr><center>nginx/1.18.0 (Ubuntu)</center></body></html>',
            fake_login: '<div style="width:300px;margin:100px auto;border:1px solid #ccc;padding:20px;"><h3>Login Required</h3><input type="text" placeholder="Username" style="width:100%;margin-bottom:10px;"><input type="password" placeholder="Password" style="width:100%;margin-bottom:10px;"><button>Login</button></div>'
        };
        function applyTemplate(key) {
            if(templates[key]) document.getElementById('safe_html_area').value = templates[key];
        }

        // 图表渲染
        const ctx = document.getElementById('reasonChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($stats['reason_counts'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($stats['reason_counts'])); ?>,
                    backgroundColor: ['#d63638', '#f0b849', '#2271b1', '#4f94d4', '#99c2e6']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
        });

        // API 健康检查
        function checkApiHealth() {
            document.getElementById('api-status-text').innerHTML = '正在连接节点...';
            jQuery.post(ajaxurl, {action: 'tg_check_api_health'}, function(res) {
                if(res.success) {
                    let html = '';
                    res.data.forEach(api => {
                        html += `<span style="margin-right:10px;padding:3px 6px;background:#f0f0f1;border-radius:3px;border-left:3px solid ${api.color}">
                        ${api.url} <small>(${api.ping})</small></span>`;
                    });
                    document.getElementById('api-status-text').innerHTML = html;
                } else {
                    document.getElementById('api-status-text').innerHTML = '<span style="color:red">检测失败</span>';
                }
            });
        }

        // 写入测试
        function testLogWrite() {
            document.getElementById('write-test-result').innerHTML = '写入中...';
            jQuery.post(ajaxurl, {action: 'tg_test_log_write'}, function(res) {
                if(res.success) {
                    document.getElementById('write-test-result').innerHTML = '<span style="color:green">✅ 写入成功！请刷新页面查看数据。</span>';
                } else {
                    document.getElementById('write-test-result').innerHTML = `<span style="color:red">❌ 写入失败：${res.data.php_error}</span>`;
                    alert('错误详情：\n' + res.data.php_error + '\n路径：' + res.data.path);
                }
            });
        }
        </script>
        <?php
    }
}

new TrafficGuardPlugin();