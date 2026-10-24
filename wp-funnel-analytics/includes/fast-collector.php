<?php
/**
 * WP Funnel Analytics - 超轻量级极速上报端点
 *
 * 采用 SHORTINIT 模式跳过绝大多数插件和主题载入，
 * 将 PHP 执行耗时压缩至 3ms 左右，极速入库，绝不阻塞主站。
 *
 * @package WP_Funnel_Analytics
 */

// 允许 OPTIONS 跨域预检
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('HTTP/1.1 204 No Content');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 寻找 wp-load.php
$wp_load_path = '';
$dir = dirname(__FILE__);
for ($i = 0; $i < 6; $i++) {
    if (file_exists($dir . '/wp-load.php')) {
        $wp_load_path = $dir . '/wp-load.php';
        break;
    }
    $dir = dirname($dir);
}

if (!$wp_load_path) {
    http_response_code(500);
    echo json_encode(['error' => 'wp-load not found']);
    exit;
}

// 开启 SHORTINIT 极速模式（仅加载 wpdb 数据库层）
define('SHORTINIT', true);
require_once $wp_load_path;

global $wpdb;
if (!$wpdb) {
    http_response_code(500);
    echo json_encode(['error' => 'db error']);
    exit;
}

// 读取 Raw POST 数据
$raw_input = file_get_contents('php://input');
if (empty($raw_input)) {
    http_response_code(400);
    echo json_encode(['error' => 'empty payload']);
    exit;
}

$payload = json_decode($raw_input, true);
if (!is_array($payload) || empty($payload['session_id']) || empty($payload['visitor_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

// 快速解析 User-Agent 与爬虫过滤
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
$is_bot = 0;
if (preg_match('/(bot|crawl|spider|slurp|facebookexternalhit|whatsapp|ahrefs|semrush|curl|wget|python)/i', $ua)) {
    $is_bot = 1;
}

// 访客 IP 脱敏 Hash
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $remote_ip = trim($parts[0]);
}
$ip_hash = hash('sha256', $remote_ip . 'wpfa_salt');

// 地理识别 (优先读 Cloudflare CDN)
$country_code = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ($_SERVER['HTTP_X_COUNTRY_CODE'] ?? ''));
if (strlen($country_code) !== 2) {
    $country_code = 'UN'; // Unknown
}

// 设备识别
$device = 'desktop';
if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
    $device = 'tablet';
} elseif (preg_match('/(mobi|ipod|iphone|android|opera mini|blackberry|iemobile)/i', $ua)) {
    $device = 'mobile';
}

// 操作系统
$os = 'Unknown';
if (preg_match('/windows nt 10/i', $ua)) $os = 'Windows 10/11';
elseif (preg_match('/windows/i', $ua)) $os = 'Windows';
elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
elseif (preg_match('/iphone|ipad|ipod/i', $ua)) $os = 'iOS';
elseif (preg_match('/android/i', $ua)) $os = 'Android';
elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

// 浏览器
$browser = 'Unknown';
if (preg_match('/edg/i', $ua)) $browser = 'Edge';
elseif (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opera/i', $ua)) $browser = 'Chrome';
elseif (preg_match('/firefox|fxios/i', $ua)) $browser = 'Firefox';
elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) $browser = 'Safari';
elseif (preg_match('/opr|opera/i', $ua)) $browser = 'Opera';
elseif (preg_match('/micromessenger/i', $ua)) $browser = 'WeChat';

$session_id = substr(sanitize_text_field($payload['session_id']), 0, 64);
$visitor_id = substr(sanitize_text_field($payload['visitor_id']), 0, 64);
$action     = sanitize_text_field($payload['action'] ?? 'event');

$now = gmdate('Y-m-d H:i:s');
$table_sessions = $wpdb->prefix . 'wpfa_sessions';
$table_events   = $wpdb->prefix . 'wpfa_events';

// 处理 1: 页面访问 (pageview)
if ($action === 'pageview') {
    $page_url = substr(esc_url_raw($payload['page_url'] ?? ''), 0, 500);
    $referrer = substr(esc_url_raw($payload['referrer'] ?? ''), 0, 500);
    $referrer_domain = '';
    if (!empty($referrer)) {
        $parsed = parse_url($referrer);
        $referrer_domain = $parsed['host'] ?? '';
    }

    $utm = is_array($payload['utm'] ?? null) ? $payload['utm'] : [];
    $utm_source   = substr(sanitize_text_field($utm['source'] ?? ''), 0, 64);
    $utm_medium   = substr(sanitize_text_field($utm['medium'] ?? ''), 0, 64);
    $utm_campaign = substr(sanitize_text_field($utm['campaign'] ?? ''), 0, 64);
    $utm_content  = substr(sanitize_text_field($utm['content'] ?? ''), 0, 64);
    $utm_term     = substr(sanitize_text_field($utm['term'] ?? ''), 0, 64);

    // 渠道分类
    $channel = 'direct';
    if (!empty($utm_source) || !empty($utm_campaign)) {
        $channel = 'campaign';
    } elseif (!empty($referrer_domain)) {
        if (preg_match('/(google|bing|baidu|yahoo|duckduckgo|sogou|yandex)/i', $referrer_domain)) {
            $channel = 'organic';
        } elseif (preg_match('/(facebook|instagram|twitter|x\.com|linkedin|pinterest|youtube|tiktok|reddit)/i', $referrer_domain)) {
            $channel = 'social';
        } else {
            $channel = 'referral';
        }
    }

    // 检查会话是否存在
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_sessions WHERE session_id = %s", $session_id));
    if (!$existing) {
        $wpdb->insert($table_sessions, [
            'session_id'      => $session_id,
            'visitor_id'      => $visitor_id,
            'ip_hash'         => $ip_hash,
            'device'          => $device,
            'os'              => $os,
            'browser'         => $browser,
            'country'         => '',
            'country_code'    => $country_code,
            'city'            => '',
            'channel'         => $channel,
            'referrer'        => $referrer,
            'referrer_domain' => $referrer_domain,
            'utm_source'      => $utm_source,
            'utm_medium'      => $utm_medium,
            'utm_campaign'    => $utm_campaign,
            'utm_content'     => $utm_content,
            'utm_term'        => $utm_term,
            'landing_page'    => $page_url,
            'exit_page'       => $page_url,
            'pageviews'       => 1,
            'duration'        => 0,
            'is_bounce'       => 1,
            'is_bot'          => $is_bot,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    } else {
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_sessions SET pageviews = pageviews + 1, is_bounce = 0, exit_page = %s, updated_at = %s WHERE session_id = %s",
            $page_url, $now, $session_id
        ));
    }

    // 写入事件表 (page_view)
    $wpdb->insert($table_events, [
        'session_id'     => $session_id,
        'visitor_id'     => $visitor_id,
        'event_type'     => 'page_view',
        'event_category' => 'navigation',
        'event_label'    => substr(sanitize_text_field($payload['page_title'] ?? ''), 0, 255),
        'page_url'       => $page_url,
        'page_title'     => substr(sanitize_text_field($payload['page_title'] ?? ''), 0, 255),
        'event_value'    => 0.00,
        'custom_data'    => '',
        'created_at'     => $now,
    ]);
}
// 处理 2: 离开页面更新停留时长 (duration)
elseif ($action === 'duration') {
    $duration = (int) ($payload['duration'] ?? 0);
    $exit_page = substr(esc_url_raw($payload['exit_page'] ?? ''), 0, 500);

    $wpdb->query($wpdb->prepare(
        "UPDATE $table_sessions SET duration = duration + %d, exit_page = %s, updated_at = %s WHERE session_id = %s",
        $duration, $exit_page, $now, $session_id
    ));
}
// 处理 3: 自定义事件 / 漏斗行为事件 (event)
elseif ($action === 'event') {
    $event_type     = substr(sanitize_text_field($payload['event_type'] ?? 'custom'), 0, 64);
    $event_category = substr(sanitize_text_field($payload['event_category'] ?? 'general'), 0, 64);
    $event_label    = substr(sanitize_text_field($payload['event_label'] ?? ''), 0, 255);
    $event_value    = (float) ($payload['event_value'] ?? 0.00);
    $page_url       = substr(esc_url_raw($payload['page_url'] ?? ''), 0, 500);
    $page_title     = substr(sanitize_text_field($payload['page_title'] ?? ''), 0, 255);
    $custom_data    = sanitize_text_field($payload['custom_data'] ?? '');

    $wpdb->insert($table_events, [
        'session_id'     => $session_id,
        'visitor_id'     => $visitor_id,
        'event_type'     => $event_type,
        'event_category' => $event_category,
        'event_label'    => $event_label,
        'page_url'       => $page_url,
        'page_title'     => $page_title,
        'event_value'    => $event_value,
        'custom_data'    => $custom_data,
        'created_at'     => $now,
    ]);

    // 发生有意义的交互行为（加购、提交表单、购买等），该会话不再被计为跳出
    $wpdb->query($wpdb->prepare(
        "UPDATE $table_sessions SET is_bounce = 0, updated_at = %s WHERE session_id = %s",
        $now, $session_id
    ));
}

echo json_encode(['status' => 'success']);
exit;
