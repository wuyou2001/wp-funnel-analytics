<?php
/**
 * 后台管理控制台、仪表盘渲染与 AJAX 数据接口
 *
 * @package WP_Funnel_Analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPFA_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX 接口
        add_action('wp_ajax_wpfa_fetch_data', [$this, 'ajax_fetch_data']);
        add_action('wp_ajax_wpfa_fetch_realtime', [$this, 'ajax_fetch_realtime']);
        add_action('wp_ajax_wpfa_save_funnel', [$this, 'ajax_save_funnel']);
        add_action('wp_ajax_wpfa_delete_funnel', [$this, 'ajax_delete_funnel']);
        add_action('wp_ajax_wpfa_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_wpfa_truncate_data', [$this, 'ajax_truncate_data']);
        add_action('admin_post_wpfa_export_csv', [$this, 'handle_export_csv']);
    }

    /**
     * 注册管理菜单
     */
    public function register_admin_menus() {
        $capability = 'manage_options';

        add_menu_page(
            '漏斗与数据分析',
            '数据分析漏斗',
            $capability,
            'wp-funnel-analytics',
            [$this, 'render_dashboard_page'],
            'dashicons-filter',
            26
        );

        add_submenu_page(
            'wp-funnel-analytics',
            '数据大屏与漏斗',
            '数据大屏与漏斗',
            $capability,
            'wp-funnel-analytics',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'wp-funnel-analytics',
            '自定义漏斗',
            '自定义漏斗',
            $capability,
            'wpfa-funnels',
            [$this, 'render_funnels_page']
        );

        add_submenu_page(
            'wp-funnel-analytics',
            '分析设置',
            '分析设置',
            $capability,
            'wpfa-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * 仅在插件后台页面加载 CSS 和 JS
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wp-funnel-analytics') === false && strpos($hook, 'wpfa-') === false) {
            return;
        }

        $css_file = dirname(__DIR__) . '/assets/css/admin-dashboard.css';
        $js_file  = dirname(__DIR__) . '/assets/js/admin-dashboard.js';
        $css_ver  = file_exists($css_file) ? filemtime($css_file) : '1.0.1';
        $js_ver   = file_exists($js_file) ? filemtime($js_file) : '1.0.1';

        wp_enqueue_style(
            'wpfa-admin-css',
            plugins_url('assets/css/admin-dashboard.css', dirname(__FILE__)),
            [],
            $css_ver
        );

        wp_enqueue_script(
            'wpfa-admin-js',
            plugins_url('assets/js/admin-dashboard.js', dirname(__FILE__)),
            ['jquery'],
            $js_ver,
            true
        );

        // 获取已有漏斗列表供选择器使用
        global $wpdb;
        $funnels = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}wpfa_funnels ORDER BY id ASC", ARRAY_A);

        wp_localize_script('wpfa-admin-js', 'wpfa_admin_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wpfa_admin_nonce'),
            'funnels'  => $funnels ?: [],
            'is_wc'    => class_exists('WooCommerce'),
        ]);
    }

    /**
     * AJAX: 获取全量仪表盘数据
     */
    public function ajax_fetch_data() {
        check_ajax_referer('wpfa_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $range        = sanitize_text_field($_POST['range'] ?? '7d');
        $custom_start = sanitize_text_field($_POST['custom_start'] ?? '');
        $custom_end   = sanitize_text_field($_POST['custom_end'] ?? '');
        $funnel_id    = (int) ($_POST['funnel_id'] ?? 0);

        $date_info = WPFA_Analytics::parse_date_range($range, $custom_start, $custom_end);

        $overview    = WPFA_Analytics::get_overview($date_info);
        $timeline    = WPFA_Analytics::get_timeline($date_info);
        $funnel      = WPFA_Analytics::get_funnel_data($funnel_id, $date_info);
        $acquisition = WPFA_Analytics::get_acquisition_data($date_info);
        $behavior    = WPFA_Analytics::get_behavior_data($date_info);
        $audience    = WPFA_Analytics::get_audience_data($date_info);
        $realtime    = WPFA_Analytics::get_realtime_data();

        wp_send_json_success([
            'date_info'   => $date_info,
            'overview'    => $overview,
            'timeline'    => $timeline,
            'funnel'      => $funnel,
            'acquisition' => $acquisition,
            'behavior'    => $behavior,
            'audience'    => $audience,
            'realtime'    => $realtime,
        ]);
    }

    /**
     * AJAX: 仅获取实时活跃大屏数据 (轻量轮询)
     */
    public function ajax_fetch_realtime() {
        check_ajax_referer('wpfa_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $data = WPFA_Analytics::get_realtime_data();
        wp_send_json_success($data);
    }

    /**
     * AJAX: 保存自定义漏斗
     */
    public function ajax_save_funnel() {
        check_ajax_referer('wpfa_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        global $wpdb;
        $table_funnels = $wpdb->prefix . 'wpfa_funnels';

        $id          = (int) ($_POST['id'] ?? 0);
        $name        = sanitize_text_field($_POST['name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $steps_raw   = wp_unslash($_POST['steps'] ?? '[]');
        $steps       = json_decode($steps_raw, true);

        if (empty($name)) {
            wp_send_json_error(['message' => '请输入漏斗名称']);
        }
        if (empty($steps) || !is_array($steps)) {
            wp_send_json_error(['message' => '请至少添加一个漏斗步骤']);
        }

        $data = [
            'name'        => $name,
            'description' => $description,
            'steps'       => wp_json_encode($steps),
            'is_active'   => 1,
        ];

        if ($id > 0) {
            $wpdb->update($table_funnels, $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table_funnels, $data);
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id, 'message' => '漏斗已成功保存']);
    }

    /**
     * AJAX: 删除自定义漏斗
     */
    public function ajax_delete_funnel() {
        check_ajax_referer('wpfa_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(['message' => '无效 ID']);
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpfa_funnels', ['id' => $id]);
        wp_send_json_success(['message' => '漏斗已成功删除']);
    }

    /**
     * AJAX: 保存设置
     */
    public function ajax_save_settings() {
        check_ajax_referer('wpfa_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $collector_mode = sanitize_text_field($_POST['collector_mode'] ?? 'rest');
        $exclude_roles  = is_array($_POST['exclude_roles'] ?? null) ? array_map('sanitize_text_field', $_POST['exclude_roles']) : [];
        $filter_bots    = !empty($_POST['filter_bots']) ? 1 : 0;
        $anonymize_ip   = !empty($_POST['anonymize_ip']) ? 1 : 0;
        $respect_dnt    = !empty($_POST['respect_dnt']) ? 1 : 0;
        $retention_days = max(7, (int) ($_POST['retention_days'] ?? 90));

        update_option('wpfa_collector_mode', $collector_mode);
        update_option('wpfa_exclude_roles', $exclude_roles);
        update_option('wpfa_filter_bots', $filter_bots);
        update_option('wpfa_anonymize_ip', $anonymize_ip);
        update_option('wpfa_respect_dnt', $respect_dnt);
        update_option('wpfa_data_retention_days', $retention_days);

        wp_send_json_success(['message' => '设置已成功保存并立即生效']);
    }

    /**
     * AJAX: 清空所有分析数据
     */
    public function ajax_truncate_data() {
        check_ajax_referer('wpfa_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        WPFA_DB::truncate_data();
        wp_send_json_success(['message' => '所有分析数据与事件记录已清空']);
    }

    /**
     * 导出 CSV 报表
     */
    public function handle_export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('wpfa_export_action', '_wpnonce');

        $range = sanitize_text_field($_GET['range'] ?? '30d');
        $date_info = WPFA_Analytics::parse_date_range($range);

        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, session_id, channel, device, os, browser, country_code, landing_page, pageviews, duration, is_bounce
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0
             ORDER BY created_at DESC LIMIT 5000",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wpfa-analytics-' . $date_info['start'] . '-to-' . $date_info['end'] . '.csv');

        $output = fopen('php://output', 'w');
        // 输出 UTF-8 BOM 避免 Excel 中文乱码
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['访问时间', '会话ID', '渠道来源', '设备终端', '操作系统', '浏览器', '国家代码', '着陆页', '浏览PV', '停留时长(秒)', '是否跳出']);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['created_at'],
                $row['session_id'],
                $row['channel'],
                $row['device'],
                $row['os'],
                $row['browser'],
                $row['country_code'],
                $row['landing_page'],
                $row['pageviews'],
                $row['duration'],
                $row['is_bounce'] ? '是' : '否',
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * 渲染控制台仪表盘主界面
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap wpfa-wrap">
            <!-- 顶部头栏 -->
            <div class="wpfa-header">
                <div class="wpfa-header-title">
                    <h1>
                        <span class="dashicons dashicons-filter wpfa-logo-icon"></span>
                        数据漏斗与全景分析系统
                        <span class="wpfa-badge-pro">Ultra-Fast 零负载</span>
                    </h1>
                    <p class="wpfa-subtitle">高精度独立会话追踪 · 多维电商与线索全链路漏斗 · 纯原生轻量无感上报</p>
                </div>

                <div class="wpfa-header-controls">
                    <!-- 实时在线指示器 -->
                    <div class="wpfa-realtime-pill" title="过去 30 分钟内活跃访客">
                        <span class="wpfa-pulse-dot"></span>
                        <span class="wpfa-realtime-label">实时活跃:</span>
                        <strong id="wpfa-rt-visitors" class="wpfa-rt-num">-</strong>
                    </div>

                    <!-- 时间区间选择 -->
                    <div class="wpfa-date-filter-group">
                        <button type="button" class="wpfa-date-btn" data-range="today">今日</button>
                        <button type="button" class="wpfa-date-btn" data-range="yesterday">昨日</button>
                        <button type="button" class="wpfa-date-btn active" data-range="7d">近 7 天</button>
                        <button type="button" class="wpfa-date-btn" data-range="30d">近 30 天</button>
                        <button type="button" class="wpfa-date-btn" data-range="this_month">本月</button>
                    </div>

                    <!-- 导出 CSV -->
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpfa_export_csv&range=7d'), 'wpfa_export_action')); ?>" id="wpfa-export-csv-btn" class="button wpfa-btn-action" title="导出当前周期数据报表">
                        <span class="dashicons dashicons-download"></span> 导出 CSV
                    </a>
                </div>
            </div>

            <!-- 加载骨架屏/指示器 -->
            <div id="wpfa-loading-overlay" class="wpfa-loading-overlay">
                <div class="wpfa-spinner"></div>
                <div class="wpfa-loading-text">正在毫秒级聚合全站数据...</div>
            </div>

            <!-- KPI 概览卡片区 -->
            <div class="wpfa-kpi-grid">
                <div class="wpfa-kpi-card">
                    <div class="wpfa-kpi-header">
                        <span class="wpfa-kpi-title">总浏览量 (PV)</span>
                        <span class="dashicons dashicons-visibility wpfa-kpi-icon"></span>
                    </div>
                    <div class="wpfa-kpi-value" id="kpi-pv">-</div>
                    <div class="wpfa-kpi-footer">
                        <span class="wpfa-trend" id="kpi-pv-diff">-</span>
                        <span class="wpfa-trend-label">较上一周期</span>
                    </div>
                </div>

                <div class="wpfa-kpi-card">
                    <div class="wpfa-kpi-header">
                        <span class="wpfa-kpi-title">独立访客 (UV)</span>
                        <span class="dashicons dashicons-admin-users wpfa-kpi-icon"></span>
                    </div>
                    <div class="wpfa-kpi-value" id="kpi-uv">-</div>
                    <div class="wpfa-kpi-footer">
                        <span class="wpfa-trend" id="kpi-uv-diff">-</span>
                        <span class="wpfa-trend-label">较上一周期</span>
                    </div>
                </div>

                <div class="wpfa-kpi-card">
                    <div class="wpfa-kpi-header">
                        <span class="wpfa-kpi-title">访问会话 (Sessions)</span>
                        <span class="dashicons dashicons-randomize wpfa-kpi-icon"></span>
                    </div>
                    <div class="wpfa-kpi-value" id="kpi-sessions">-</div>
                    <div class="wpfa-kpi-footer">
                        <span class="wpfa-trend" id="kpi-sessions-diff">-</span>
                        <span class="wpfa-trend-label">较上一周期</span>
                    </div>
                </div>

                <div class="wpfa-kpi-card">
                    <div class="wpfa-kpi-header">
                        <span class="wpfa-kpi-title">跳出率 (Bounce Rate)</span>
                        <span class="dashicons dashicons-undo wpfa-kpi-icon"></span>
                    </div>
                    <div class="wpfa-kpi-value" id="kpi-bounce">-</div>
                    <div class="wpfa-kpi-footer">
                        <span class="wpfa-trend-info">仅浏览1页即离开</span>
                    </div>
                </div>

                <div class="wpfa-kpi-card">
                    <div class="wpfa-kpi-header">
                        <span class="wpfa-kpi-title">平均停留时长</span>
                        <span class="dashicons dashicons-clock wpfa-kpi-icon"></span>
                    </div>
                    <div class="wpfa-kpi-value" id="kpi-duration">-</div>
                    <div class="wpfa-kpi-footer">
                        <span class="wpfa-trend-info">深度阅读与交互时长</span>
                    </div>
                </div>

                <div class="wpfa-kpi-card wpfa-kpi-highlight">
                    <div class="wpfa-kpi-header">
                        <span class="wpfa-kpi-title">总转化次数 / 转化率</span>
                        <span class="dashicons dashicons-awards wpfa-kpi-icon"></span>
                    </div>
                    <div class="wpfa-kpi-value">
                        <span id="kpi-conversions">-</span>
                        <small class="wpfa-kpi-subval" id="kpi-conversion-rate">(-%)</small>
                    </div>
                    <div class="wpfa-kpi-footer">
                        <span class="wpfa-trend" id="kpi-conversions-diff">-</span>
                        <span class="wpfa-trend-label">转化总额: <strong id="kpi-revenue" style="color:#10b981;">-</strong></span>
                    </div>
                </div>
            </div>

            <!-- 核心数据漏斗模块 (Funnel Analytics Showcase) -->
            <div class="wpfa-panel wpfa-panel-funnel">
                <div class="wpfa-panel-header">
                    <div class="wpfa-panel-title-group">
                        <h2 class="wpfa-panel-title">
                            <span class="dashicons dashicons-filter"></span>
                            转化链路漏斗分析 (Conversion Funnel)
                        </h2>
                        <span class="wpfa-panel-subtitle" id="wpfa-funnel-desc">监控从流量进站到最终成交的各节点流失与转化率</span>
                    </div>
                    <div class="wpfa-funnel-switcher">
                        <label for="wpfa-funnel-select">选择分析漏斗：</label>
                        <select id="wpfa-funnel-select" class="wpfa-select">
                            <!-- 动态载入 -->
                        </select>
                    </div>
                </div>

                <div class="wpfa-panel-body">
                    <!-- 漏斗可视化阶梯图容器 -->
                    <div id="wpfa-funnel-container" class="wpfa-funnel-container">
                        <!-- 动态渲染漏斗条目与流失指标 -->
                    </div>
                </div>
            </div>

            <!-- 时序趋势图 (Trend Timeline) -->
            <div class="wpfa-panel wpfa-panel-trend">
                <div class="wpfa-panel-header">
                    <div class="wpfa-panel-title-group">
                        <h2 class="wpfa-panel-title">
                            <span class="dashicons dashicons-chart-area"></span>
                            流量与访问趋势图 (Traffic & Engagement Timeline)
                        </h2>
                        <span class="wpfa-panel-subtitle">多维全站指标全景走势 · 鼠标悬停查看详细数据对比</span>
                    </div>

                    <div class="wpfa-chart-actions">
                        <!-- 图表类型切换 -->
                        <div class="wpfa-view-toggle">
                            <button type="button" class="wpfa-toggle-btn active" data-chart-type="line" title="折线面积趋势">
                                <span class="dashicons dashicons-chart-line"></span> 曲线图
                            </button>
                            <button type="button" class="wpfa-toggle-btn" data-chart-type="bar" title="指标对比柱状图">
                                <span class="dashicons dashicons-chart-bar"></span> 柱状图
                            </button>
                        </div>

                        <!-- 交互指标图例与快速开关 -->
                        <div class="wpfa-chart-legend" id="wpfa-chart-legend">
                            <button type="button" class="wpfa-legend-btn active" data-metric="pv" title="点击显示/隐藏浏览量">
                                <span class="wpfa-marker-circle"></span>
                                <span class="wpfa-legend-text">浏览量 (PV)</span>
                                <strong id="legend-pv-sum" class="wpfa-legend-val">-</strong>
                            </button>
                            <button type="button" class="wpfa-legend-btn active" data-metric="uv" title="点击显示/隐藏独立访客">
                                <span class="wpfa-marker-diamond"></span>
                                <span class="wpfa-legend-text">独立访客 (UV)</span>
                                <strong id="legend-uv-sum" class="wpfa-legend-val">-</strong>
                            </button>
                            <button type="button" class="wpfa-legend-btn active" data-metric="sessions" title="点击显示/隐藏访问会话">
                                <span class="wpfa-marker-dashed"></span>
                                <span class="wpfa-legend-text">访问会话</span>
                                <strong id="legend-sessions-sum" class="wpfa-legend-val">-</strong>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="wpfa-panel-body" style="padding: 20px 24px 24px 24px;">
                    <!-- 自适应全宽 SVG 图表渲染容器 -->
                    <div class="wpfa-svg-chart-container" id="wpfa-svg-chart-container">
                        <svg id="wpfa-trend-svg" class="wpfa-trend-svg" viewBox="0 0 1000 320" preserveAspectRatio="none">
                            <!-- 动态生成 SVG 元素 -->
                        </svg>
                        <!-- 悬浮交互 Tooltip -->
                        <div id="wpfa-chart-tooltip" class="wpfa-chart-tooltip"></div>
                    </div>
                </div>
            </div>

            <!-- 双列多维下钻分析区 -->
            <div class="wpfa-grid-2col">
                <!-- 渠道来源分析 -->
                <div class="wpfa-panel">
                    <div class="wpfa-panel-header">
                        <h2 class="wpfa-panel-title"><span class="dashicons dashicons-share-alt"></span> 流量获客渠道 (Acquisition)</h2>
                    </div>
                    <div class="wpfa-panel-body">
                        <div id="wpfa-channels-list" class="wpfa-list-container"></div>
                    </div>
                </div>

                <!-- 营销战役 (UTM Campaigns) -->
                <div class="wpfa-panel">
                    <div class="wpfa-panel-header">
                        <h2 class="wpfa-panel-title"><span class="dashicons dashicons-megaphone"></span> 营销战役表现 (UTM Campaigns)</h2>
                    </div>
                    <div class="wpfa-panel-body">
                        <div id="wpfa-campaigns-list" class="wpfa-list-container"></div>
                    </div>
                </div>
            </div>

            <!-- 第二行双列分析：受访页面与国家终端 -->
            <div class="wpfa-grid-2col">
                <!-- 热门页面与着陆页 -->
                <div class="wpfa-panel">
                    <div class="wpfa-panel-header">
                        <h2 class="wpfa-panel-title"><span class="dashicons dashicons-admin-page"></span> 热门受访页面 Top Pages</h2>
                    </div>
                    <div class="wpfa-panel-body">
                        <div id="wpfa-pages-list" class="wpfa-list-container"></div>
                    </div>
                </div>

                <!-- 地理与终端分布 -->
                <div class="wpfa-panel">
                    <div class="wpfa-panel-header">
                        <h2 class="wpfa-panel-title"><span class="dashicons dashicons-admin-site-alt3"></span> 访客国家与终端设备画像</h2>
                    </div>
                    <div class="wpfa-panel-body">
                        <div class="wpfa-device-split">
                            <div class="wpfa-sub-section">
                                <h4 class="wpfa-sub-title">设备终端占比</h4>
                                <div id="wpfa-device-list" class="wpfa-progress-group"></div>
                            </div>
                            <div class="wpfa-sub-section">
                                <h4 class="wpfa-sub-title">主要国家/地区排行</h4>
                                <div id="wpfa-country-list" class="wpfa-progress-group"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 底部实时动态日志流 -->
            <div class="wpfa-panel">
                <div class="wpfa-panel-header">
                    <h2 class="wpfa-panel-title">
                        <span class="dashicons dashicons-rss"></span>
                        实时访客动态流 (Live Activity Stream)
                    </h2>
                    <span class="wpfa-panel-subtitle">自动刷新活跃访客最新事件</span>
                </div>
                <div class="wpfa-panel-body">
                    <div id="wpfa-realtime-feed" class="wpfa-feed-container"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染自定义漏斗管理界面
     */
    public function render_funnels_page() {
        global $wpdb;
        $funnels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpfa_funnels ORDER BY id ASC");
        ?>
        <div class="wrap wpfa-wrap">
            <div class="wpfa-header">
                <div class="wpfa-header-title">
                    <h1><span class="dashicons dashicons-filter wpfa-logo-icon"></span> 自定义漏斗构建器</h1>
                    <p class="wpfa-subtitle">自由配置多步骤业务漏斗，洞悉用户在关键转化节点上的流失率与留存率</p>
                </div>
                <div>
                    <button type="button" class="button button-primary wpfa-btn-action" id="wpfa-create-funnel-btn">
                        <span class="dashicons dashicons-plus-alt"></span> 创建新漏斗
                    </button>
                </div>
            </div>

            <div class="wpfa-panel">
                <div class="wpfa-panel-header">
                    <h2 class="wpfa-panel-title">已有漏斗方案</h2>
                </div>
                <div class="wpfa-panel-body">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th style="width:220px;">漏斗名称</th>
                                <th>包含步骤</th>
                                <th style="width:160px;">创建时间</th>
                                <th style="width:140px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($funnels)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:30px;">暂无自定义漏斗，点击右上角创建。</td></tr>
                            <?php else: foreach ($funnels as $f): 
                                $steps = json_decode($f->steps, true) ?: [];
                                $step_names = array_column($steps, 'name');
                            ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($f->id); ?></strong></td>
                                    <td><strong><?php echo esc_html($f->name); ?></strong><br><small style="color:#666;"><?php echo esc_html($f->description); ?></small></td>
                                    <td>
                                        <div class="wpfa-steps-tags">
                                            <?php foreach ($step_names as $idx => $sn): ?>
                                                <span class="wpfa-step-tag">Step <?php echo esc_html($idx + 1); ?>: <?php echo esc_html($sn); ?></span>
                                                <?php if ($idx < count($step_names) - 1): ?>&rarr;<?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($f->created_at); ?></td>
                                    <td>
                                        <button type="button" class="button button-small wpfa-edit-funnel-btn" data-funnel='<?php echo esc_attr(wp_json_encode($f)); ?>'>编辑</button>
                                        <button type="button" class="button button-small button-link-delete wpfa-del-funnel-btn" data-id="<?php echo esc_attr($f->id); ?>">删除</button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 漏斗编辑弹窗 Modal -->
            <div id="wpfa-funnel-modal" class="wpfa-modal" style="display:none;">
                <div class="wpfa-modal-overlay"></div>
                <div class="wpfa-modal-box">
                    <div class="wpfa-modal-header">
                        <h3 id="wpfa-modal-title">编辑转化漏斗</h3>
                        <button type="button" class="wpfa-modal-close">&times;</button>
                    </div>
                    <form id="wpfa-funnel-form">
                        <input type="hidden" id="wpfa-form-funnel-id" value="0">
                        <div class="wpfa-form-row">
                            <label>漏斗名称：</label>
                            <input type="text" id="wpfa-form-funnel-name" class="regular-text" required placeholder="如：注册转化漏斗">
                        </div>
                        <div class="wpfa-form-row">
                            <label>描述说明：</label>
                            <input type="text" id="wpfa-form-funnel-desc" class="regular-text" placeholder="描述此漏斗的应用场景">
                        </div>
                        <div class="wpfa-form-row">
                            <label>漏斗步骤 (按流转顺序)：</label>
                            <div id="wpfa-modal-steps-container"></div>
                            <button type="button" class="button button-secondary" id="wpfa-add-step-row-btn" style="margin-top:10px;">+ 添加步骤</button>
                        </div>
                        <div class="wpfa-modal-footer">
                            <button type="button" class="button wpfa-modal-cancel">取消</button>
                            <button type="submit" class="button button-primary">保存漏斗</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染设置中心界面
     */
    public function render_settings_page() {
        $collector_mode = get_option('wpfa_collector_mode', 'rest');
        $exclude_roles  = get_option('wpfa_exclude_roles', ['administrator']);
        $filter_bots    = (bool) get_option('wpfa_filter_bots', 1);
        $anonymize_ip   = (bool) get_option('wpfa_anonymize_ip', 1);
        $respect_dnt    = (bool) get_option('wpfa_respect_dnt', 1);
        $retention_days = (int) get_option('wpfa_data_retention_days', 90);

        global $wp_roles;
        $all_roles = $wp_roles->roles ?? [];
        ?>
        <div class="wrap wpfa-wrap">
            <div class="wpfa-header">
                <div class="wpfa-header-title">
                    <h1><span class="dashicons dashicons-admin-settings wpfa-logo-icon"></span> 性能与隐私设置</h1>
                    <p class="wpfa-subtitle">掌控数据采集模式、隐私合规与历史数据生命周期</p>
                </div>
            </div>

            <div class="wpfa-panel">
                <div class="wpfa-panel-header">
                    <h2 class="wpfa-panel-title">采集与性能参数</h2>
                </div>
                <div class="wpfa-panel-body">
                    <form id="wpfa-settings-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row">数据采集通道</th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="radio" name="collector_mode" value="rest" <?php checked($collector_mode, 'rest'); ?>>
                                            <strong>WordPress REST API 模式 (默认推荐)</strong>
                                            <p class="description">兼容性极佳，依托官方 REST 机制安全接收信标。</p>
                                        </label>
                                        <br><br>
                                        <label>
                                            <input type="radio" name="collector_mode" value="fast" <?php checked($collector_mode, 'fast'); ?>>
                                            <strong>独立极速 Endpoint 模式 (Ultra-Fast 3ms 响应)</strong>
                                            <p class="description">绕过 95% 插件与主题重型加载，直接连接数据库快速入库，超高并发抗压。</p>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">排除已登录角色</th>
                                <td>
                                    <?php foreach ($all_roles as $role_key => $role_data): ?>
                                        <label style="margin-right:15px;display:inline-block;">
                                            <input type="checkbox" name="exclude_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked(in_array($role_key, (array)$exclude_roles, true)); ?>>
                                            <?php echo esc_html(translate_user_role($role_data['name'])); ?> (<?php echo esc_html($role_key); ?>)
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description">选中的角色登录访问时，系统完全不加载前端追踪脚本，零产生测试垃圾数据。</p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">爬虫与机器人过滤</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_bots" value="1" <?php checked($filter_bots); ?>>
                                        自动过滤并丢弃已知搜索引擎爬虫与测试机器人流量
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">隐私合规 (GDPR)</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="anonymize_ip" value="1" <?php checked($anonymize_ip); ?>>
                                        访客 IP 强制加盐 Hash 单向匿名化 (不记录明文敏感 IP)
                                    </label>
                                    <br><br>
                                    <label>
                                        <input type="checkbox" name="respect_dnt" value="1" <?php checked($respect_dnt); ?>>
                                        严格遵守浏览器 "Do Not Track (DNT)" 防追踪偏好
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">明细日志保留周期</th>
                                <td>
                                    <select name="retention_days" class="regular-text">
                                        <option value="30" <?php selected($retention_days, 30); ?>>30 天 (超轻量)</option>
                                        <option value="60" <?php selected($retention_days, 60); ?>>60 天</option>
                                        <option value="90" <?php selected($retention_days, 90); ?>>90 天 (推荐)</option>
                                        <option value="180" <?php selected($retention_days, 180); ?>>180 天 (半年)</option>
                                        <option value="365" <?php selected($retention_days, 365); ?>>365 天 (一年)</option>
                                    </select>
                                    <p class="description">每天凌晨 Cron 自动将超出天数的明细归档并清理，日度聚合表将永久保留，数据库永远健康。</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary wpfa-btn-action">保存配置</button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- 数据维护区 -->
            <div class="wpfa-panel" style="border-left: 4px solid #ef4444;">
                <div class="wpfa-panel-header">
                    <h2 class="wpfa-panel-title" style="color:#ef4444;"><span class="dashicons dashicons-trash"></span> 危险区域：数据清理与重置</h2>
                </div>
                <div class="wpfa-panel-body">
                    <p>若您处于开发测试阶段，或需要重新开始统计，可在此清空所有历史访问记录与事件表。</p>
                    <button type="button" class="button button-link-delete" id="wpfa-truncate-btn">清空所有统计数据</button>
                </div>
            </div>
        </div>
        <?php
    }
}
