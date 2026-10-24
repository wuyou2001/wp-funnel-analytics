<?php
/**
 * 前端极速脚本注入与 REST API 接收器
 *
 * @package WP_Funnel_Analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPFA_Tracker {

    public function __construct() {
        // 前端脚本挂载
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_tracker']);
        add_filter('script_loader_tag', [$this, 'add_async_defer_attributes'], 10, 3);

        // REST API 端点注册
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // WooCommerce 服务端动作兜底捕获（例如异步结账完成 webhook）
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_thankyou', [$this, 'capture_wc_order_complete'], 10, 1);
        }
    }

    /**
     * 判断当前访问是否应被排除（如管理员、爬虫等）
     */
    public function should_exclude() {
        // 排除管理员/特定角色
        $exclude_roles = get_option('wpfa_exclude_roles', ['administrator']);
        if (is_user_logged_in() && !empty($exclude_roles)) {
            $user = wp_get_current_user();
            foreach ($exclude_roles as $role) {
                if (in_array($role, (array) $user->roles, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 极速异步挂载前端追踪脚本
     */
    public function enqueue_frontend_tracker() {
        if ($this->should_exclude()) {
            return;
        }

        // 决定数据上报端点
        $collector_mode = get_option('wpfa_collector_mode', 'rest'); // 'rest' 或 'fast'
        if ($collector_mode === 'fast') {
            $endpoint = plugins_url('includes/fast-collector.php', dirname(__FILE__));
        } else {
            $endpoint = rest_url('wp-funnel-analytics/v1/track');
        }

        $respect_dnt = (bool) get_option('wpfa_respect_dnt', 1);

        // 获取 WooCommerce 页面上下文
        $wc_data = null;
        if (class_exists('WooCommerce')) {
            $wc_data = [
                'isProduct'  => is_product(),
                'isCart'     => is_cart(),
                'isCheckout' => is_checkout() && !is_order_received_page(),
                'isThankYou' => is_order_received_page(),
            ];

            if (is_product()) {
                global $product;
                if (!is_a($product, 'WC_Product')) {
                    $product = wc_get_product(get_the_ID());
                }
                if ($product) {
                    $wc_data['product'] = [
                        'id'    => $product->get_id(),
                        'name'  => $product->get_name(),
                        'price' => (float) $product->get_price(),
                    ];
                }
            } elseif (is_order_received_page()) {
                global $wp;
                $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0;
                if ($order_id > 0) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $wc_data['order'] = [
                            'id'       => $order->get_id(),
                            'total'    => (float) $order->get_total(),
                            'currency' => $order->get_currency(),
                        ];
                    }
                }
            }
        }

        $tracker_version = get_option('wpfa_db_version', '1.0.0');

        wp_enqueue_script(
            'wpfa-tracker',
            plugins_url('assets/js/tracker.js', dirname(__FILE__)),
            [],
            $tracker_version,
            true // 放在页脚
        );

        wp_localize_script('wpfa-tracker', 'wpfa_config', [
            'endpoint'   => $endpoint,
            'respectDnt' => $respect_dnt,
            'wc'         => $wc_data,
        ]);
    }

    /**
     * 为追踪脚本添加 defer async 属性，零阻塞主线程
     */
    public function add_async_defer_attributes($tag, $handle, $src) {
        if ('wpfa-tracker' === $handle) {
            return '<script src="' . esc_url($src) . '" id="wpfa-tracker-js" defer async></script>';
        }
        return $tag;
    }

    /**
     * 注册 REST API 路由
     */
    public function register_rest_routes() {
        register_rest_route('wp-funnel-analytics/v1', '/track', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_rest_track'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * REST API 数据接收与入库
     */
    public function handle_rest_track(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (empty($params) || !is_array($params)) {
            $params = $request->get_body_params();
        }

        if (empty($params['session_id']) || empty($params['visitor_id'])) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Missing session or visitor ID'], 400);
        }

        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';

        $session_id = substr(sanitize_text_field($params['session_id']), 0, 64);
        $visitor_id = substr(sanitize_text_field($params['visitor_id']), 0, 64);
        $action     = sanitize_text_field($params['action'] ?? 'event');

        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        // 机器人过滤
        $filter_bots = (bool) get_option('wpfa_filter_bots', 1);
        $is_bot = 0;
        if (preg_match('/(bot|crawl|spider|slurp|facebookexternalhit|whatsapp|ahrefs|semrush|curl|wget|python)/i', $ua)) {
            $is_bot = 1;
            if ($filter_bots) {
                // 如果开启严格过滤且是爬虫，直接静默返回成功，不写库
                return new WP_REST_Response(['status' => 'success', 'bot' => true], 200);
            }
        }

        // IP 脱敏处理 (GDPR)
        $remote_ip = $this->get_client_ip();
        $anonymize_ip = (bool) get_option('wpfa_anonymize_ip', 1);
        if ($anonymize_ip) {
            $ip_hash = hash('sha256', $remote_ip . 'wpfa_salt');
        } else {
            $ip_hash = substr(sanitize_text_field($remote_ip), 0, 64);
        }

        // 地理信息识别
        $geo = $this->detect_geo();
        $device_info = $this->detect_device($ua);

        $now = current_time('mysql');

        if ($action === 'pageview') {
            $page_url = substr(esc_url_raw($params['page_url'] ?? ''), 0, 500);
            $referrer = substr(esc_url_raw($params['referrer'] ?? ''), 0, 500);
            $referrer_domain = '';
            if (!empty($referrer)) {
                $parsed = parse_url($referrer);
                $referrer_domain = $parsed['host'] ?? '';
            }

            $utm = is_array($params['utm'] ?? null) ? $params['utm'] : [];
            $utm_source   = substr(sanitize_text_field($utm['source'] ?? ''), 0, 64);
            $utm_medium   = substr(sanitize_text_field($utm['medium'] ?? ''), 0, 64);
            $utm_campaign = substr(sanitize_text_field($utm['campaign'] ?? ''), 0, 64);
            $utm_content  = substr(sanitize_text_field($utm['content'] ?? ''), 0, 64);
            $utm_term     = substr(sanitize_text_field($utm['term'] ?? ''), 0, 64);

            // 渠道归因分类
            $channel = 'direct';
            if (!empty($utm_source) || !empty($utm_campaign)) {
                $channel = 'campaign';
            } elseif (!empty($referrer_domain)) {
                $site_host = parse_url(home_url(), PHP_URL_HOST);
                if ($referrer_domain === $site_host) {
                    $channel = 'direct';
                } elseif (preg_match('/(google|bing|baidu|yahoo|duckduckgo|sogou|yandex|ecosia)/i', $referrer_domain)) {
                    $channel = 'organic';
                } elseif (preg_match('/(facebook|instagram|twitter|x\.com|linkedin|pinterest|youtube|tiktok|reddit|whatsapp)/i', $referrer_domain)) {
                    $channel = 'social';
                } else {
                    $channel = 'referral';
                }
            }

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_sessions WHERE session_id = %s", $session_id));
            if (!$existing) {
                $wpdb->insert($table_sessions, [
                    'session_id'      => $session_id,
                    'visitor_id'      => $visitor_id,
                    'ip_hash'         => $ip_hash,
                    'device'          => $device_info['device'],
                    'os'              => $device_info['os'],
                    'browser'         => $device_info['browser'],
                    'country'         => $geo['country'],
                    'country_code'    => $geo['country_code'],
                    'city'            => $geo['city'],
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
                    'user_id'         => get_current_user_id(),
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_sessions SET pageviews = pageviews + 1, is_bounce = 0, exit_page = %s, updated_at = %s WHERE session_id = %s",
                    $page_url, $now, $session_id
                ));
            }

            // 写入页面浏览事件
            $wpdb->insert($table_events, [
                'session_id'     => $session_id,
                'visitor_id'     => $visitor_id,
                'event_type'     => 'page_view',
                'event_category' => 'navigation',
                'event_label'    => substr(sanitize_text_field($params['page_title'] ?? ''), 0, 255),
                'page_url'       => $page_url,
                'page_title'     => substr(sanitize_text_field($params['page_title'] ?? ''), 0, 255),
                'event_value'    => 0.00,
                'custom_data'    => '',
                'created_at'     => $now,
            ]);
        } elseif ($action === 'duration') {
            $duration = (int) ($params['duration'] ?? 0);
            $exit_page = substr(esc_url_raw($params['exit_page'] ?? ''), 0, 500);

            $wpdb->query($wpdb->prepare(
                "UPDATE $table_sessions SET duration = duration + %d, exit_page = %s, updated_at = %s WHERE session_id = %s",
                $duration, $exit_page, $now, $session_id
            ));
        } elseif ($action === 'event') {
            $event_type     = substr(sanitize_text_field($params['event_type'] ?? 'custom'), 0, 64);
            $event_category = substr(sanitize_text_field($params['event_category'] ?? 'general'), 0, 64);
            $event_label    = substr(sanitize_text_field($params['event_label'] ?? ''), 0, 255);
            $event_value    = (float) ($params['event_value'] ?? 0.00);
            $page_url       = substr(esc_url_raw($params['page_url'] ?? ''), 0, 500);
            $page_title     = substr(sanitize_text_field($params['page_title'] ?? ''), 0, 255);
            $custom_data    = sanitize_text_field($params['custom_data'] ?? '');

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

            // 发生实质交互行为取消跳出状态
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_sessions SET is_bounce = 0, updated_at = %s WHERE session_id = %s",
                $now, $session_id
            ));
        }

        return new WP_REST_Response(['status' => 'success'], 200);
    }

    /**
     * WooCommerce 结账完成服务端兜底捕获
     */
    public function capture_wc_order_complete($order_id) {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        // 避免重复记录同一订单
        $already_recorded = get_post_meta($order_id, '_wpfa_recorded', true);
        if ($already_recorded) return;
        update_post_meta($order_id, '_wpfa_recorded', 1);

        // 从 Cookie/参数或当前访客中识别会话
        global $wpdb;
        $now = current_time('mysql');
        $total = (float) $order->get_total();

        // 记录 purchase 事件
        $wpdb->insert($wpdb->prefix . 'wpfa_events', [
            'session_id'     => 'server_wc_' . $order_id,
            'visitor_id'     => 'cust_' . $order->get_customer_id(),
            'event_type'     => 'purchase',
            'event_category' => 'ecommerce',
            'event_label'    => 'Order #' . $order_id,
            'page_url'       => esc_url_raw($order->get_checkout_order_received_url()),
            'page_title'     => 'Order Received',
            'event_value'    => $total,
            'custom_data'    => wp_json_encode(['order_id' => $order_id, 'currency' => $order->get_currency()]),
            'created_at'     => $now,
        ]);
    }

    /**
     * 获取客户端真实 IP
     */
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return sanitize_text_field(trim($parts[0]));
        }
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * 地理位置识别
     */
    private function detect_geo() {
        $country_code = strtoupper(sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ($_SERVER['HTTP_X_COUNTRY_CODE'] ?? '')));
        $country = '';
        $city = '';

        if (strlen($country_code) !== 2) {
            $country_code = 'UN';
            $country = '未知国家/地区';
        } else {
            $country = $this->country_code_to_name($country_code);
        }

        return [
            'country_code' => $country_code,
            'country'      => $country,
            'city'         => $city,
        ];
    }

    /**
     * 常见国家代码转中文名称映射
     */
    private function country_code_to_name($code) {
        $map = [
            'CN' => '中国',
            'US' => '美国',
            'HK' => '中国香港',
            'TW' => '中国台湾',
            'JP' => '日本',
            'KR' => '韩国',
            'GB' => '英国',
            'DE' => '德国',
            'FR' => '法国',
            'CA' => '加拿大',
            'AU' => '澳大利亚',
            'SG' => '新加坡',
            'MY' => '马来西亚',
            'RU' => '俄罗斯',
            'IN' => '印度',
            'BR' => '巴西',
            'IT' => '意大利',
            'ES' => '西班牙',
            'NL' => '荷兰',
            'VN' => '越南',
            'TH' => '泰国',
            'ID' => '印度尼西亚',
            'PH' => '菲律宾',
            'AE' => '阿联酋',
            'SA' => '沙特阿拉伯',
        ];
        return $map[$code] ?? $code;
    }

    /**
     * 识别设备、操作系统与浏览器
     */
    private function detect_device($ua) {
        // 设备
        $device = 'desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/(mobi|ipod|iphone|android|opera mini|blackberry|iemobile)/i', $ua)) {
            $device = 'mobile';
        }

        // 操作系统
        $os = 'Other';
        if (preg_match('/windows nt 10/i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('/windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
        elseif (preg_match('/iphone|ipad|ipod/i', $ua)) $os = 'iOS';
        elseif (preg_match('/android/i', $ua)) $os = 'Android';
        elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

        // 浏览器
        $browser = 'Other';
        if (preg_match('/edg/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/micromessenger/i', $ua)) $browser = 'WeChat';
        elseif (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opera/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/firefox|fxios/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/opr|opera/i', $ua)) $browser = 'Opera';

        return [
            'device'  => $device,
            'os'      => $os,
            'browser' => $browser,
        ];
    }
}
