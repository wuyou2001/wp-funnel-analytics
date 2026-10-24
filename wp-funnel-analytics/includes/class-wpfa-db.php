<?php
/**
 * 数据库建表、升级与日常数据归档清理
 *
 * @package WP_Funnel_Analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPFA_DB {

    const DB_VERSION = '1.0.0';

    /**
     * 初始化与安装数据表
     */
    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';
        $table_stats    = $wpdb->prefix . 'wpfa_daily_stats';
        $table_funnels  = $wpdb->prefix . 'wpfa_funnels';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. 会话表 (访客会话与画像)
        $sql_sessions = "CREATE TABLE $table_sessions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            visitor_id varchar(64) NOT NULL,
            ip_hash varchar(64) NOT NULL DEFAULT '',
            device varchar(20) NOT NULL DEFAULT 'desktop',
            os varchar(32) NOT NULL DEFAULT '',
            browser varchar(32) NOT NULL DEFAULT '',
            country varchar(64) NOT NULL DEFAULT '',
            country_code varchar(8) NOT NULL DEFAULT '',
            city varchar(64) NOT NULL DEFAULT '',
            channel varchar(32) NOT NULL DEFAULT 'direct',
            referrer varchar(500) NOT NULL DEFAULT '',
            referrer_domain varchar(128) NOT NULL DEFAULT '',
            utm_source varchar(64) NOT NULL DEFAULT '',
            utm_medium varchar(64) NOT NULL DEFAULT '',
            utm_campaign varchar(64) NOT NULL DEFAULT '',
            utm_content varchar(64) NOT NULL DEFAULT '',
            utm_term varchar(64) NOT NULL DEFAULT '',
            landing_page varchar(500) NOT NULL DEFAULT '',
            exit_page varchar(500) NOT NULL DEFAULT '',
            pageviews int(11) unsigned NOT NULL DEFAULT 1,
            duration int(11) unsigned NOT NULL DEFAULT 0,
            is_bounce tinyint(1) NOT NULL DEFAULT 1,
            is_bot tinyint(1) NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY visitor_id (visitor_id),
            KEY created_at (created_at),
            KEY channel (channel),
            KEY device (device),
            KEY country_code (country_code),
            KEY is_bot (is_bot),
            KEY utm_source (utm_source)
        ) $charset_collate;";

        // 2. 事件与行为漏斗表
        $sql_events = "CREATE TABLE $table_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            visitor_id varchar(64) NOT NULL,
            event_type varchar(64) NOT NULL,
            event_category varchar(64) NOT NULL DEFAULT 'system',
            event_label varchar(255) NOT NULL DEFAULT '',
            page_url varchar(500) NOT NULL DEFAULT '',
            page_title varchar(255) NOT NULL DEFAULT '',
            event_value decimal(12,2) NOT NULL DEFAULT 0.00,
            custom_data text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY visitor_id (visitor_id),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY type_date (event_type, created_at)
        ) $charset_collate;";

        // 3. 按日聚合统计表（秒级汇总）
        $sql_stats = "CREATE TABLE $table_stats (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stat_date date NOT NULL,
            dimension_type varchar(32) NOT NULL DEFAULT 'overall',
            dimension_value varchar(64) NOT NULL DEFAULT '',
            pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
            visitors bigint(20) unsigned NOT NULL DEFAULT 0,
            sessions bigint(20) unsigned NOT NULL DEFAULT 0,
            bounces bigint(20) unsigned NOT NULL DEFAULT 0,
            total_duration bigint(20) unsigned NOT NULL DEFAULT 0,
            conversions bigint(20) unsigned NOT NULL DEFAULT 0,
            revenue decimal(14,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            UNIQUE KEY date_dim (stat_date, dimension_type, dimension_value),
            KEY stat_date (stat_date)
        ) $charset_collate;";

        // 4. 自定义漏斗配置表
        $sql_funnels = "CREATE TABLE $table_funnels (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(128) NOT NULL,
            description text NULL,
            steps longtext NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql_sessions);
        dbDelta($sql_events);
        dbDelta($sql_stats);
        dbDelta($sql_funnels);

        update_option('wpfa_db_version', self::DB_VERSION);

        // 如果漏斗表为空，初始化默认预置漏斗
        self::seed_default_funnels();
    }

    /**
     * 预置默认常用漏斗
     */
    public static function seed_default_funnels() {
        global $wpdb;
        $table_funnels = $wpdb->prefix . 'wpfa_funnels';

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_funnels");
        if ($count === 0) {
            // 预置：电商交易漏斗
            $ecom_steps = [
                ['name' => '进站访问', 'event' => 'page_view'],
                ['name' => '浏览商品详情', 'event' => 'view_product'],
                ['name' => '加入购物车', 'event' => 'add_to_cart'],
                ['name' => '进入结账台', 'event' => 'begin_checkout'],
                ['name' => '购买成功支付', 'event' => 'purchase'],
            ];

            // 预置：询盘与线索转化漏斗
            $lead_steps = [
                ['name' => '访客进站', 'event' => 'page_view'],
                ['name' => '深度内容互动', 'event' => 'engaged_visit'],
                ['name' => '点击沟通/联系方式', 'event' => 'contact_click'],
                ['name' => '提交询盘表单', 'event' => 'form_submit'],
            ];

            $wpdb->insert($table_funnels, [
                'name'        => '全链路电商转化漏斗 (WooCommerce)',
                'description' => '从进站、浏览商品、加购、进入结算到最终支付完成的全流程转化率漏斗',
                'steps'       => wp_json_encode($ecom_steps),
                'is_active'   => 1,
                'created_at'  => current_time('mysql'),
            ]);

            $wpdb->insert($table_funnels, [
                'name'        => 'B2B/外贸询盘与线索漏斗',
                'description' => '适合外贸与企业展示站，统计深度访问、点击 WhatsApp/邮箱与表单提交转化',
                'steps'       => wp_json_encode($lead_steps),
                'is_active'   => 1,
                'created_at'  => current_time('mysql'),
            ]);
        }
    }

    /**
     * 定时任务：每日数据归档汇总与过期日志清理
     * 保障数据库轻量小巧，长久运行不卡顿
     */
    public static function daily_maintenance() {
        global $wpdb;

        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';
        $table_stats    = $wpdb->prefix . 'wpfa_daily_stats';

        $retention_days = (int) get_option('wpfa_data_retention_days', 90);
        if ($retention_days < 7) {
            $retention_days = 90;
        }

        // 1. 对过去 7 天内未归档或需更新的日期重新计算汇总（确保最终数据绝对准确）
        for ($i = 1; $i <= 7; $i++) {
            $target_date = gmdate('Y-m-d', strtotime("-$i days"));
            self::aggregate_date($target_date);
        }

        // 2. 清理超过保留天数的历史明细日志（汇总表保留，明细归档清理）
        $cutoff_date = gmdate('Y-m-d 00:00:00', strtotime("-$retention_days days"));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_events WHERE created_at < %s",
            $cutoff_date
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_sessions WHERE created_at < %s",
            $cutoff_date
        ));
    }

    /**
     * 聚合特定日期的统计指标
     */
    public static function aggregate_date($date) {
        global $wpdb;

        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';
        $table_stats    = $wpdb->prefix . 'wpfa_daily_stats';

        $start_dt = $date . ' 00:00:00';
        $end_dt   = $date . ' 23:59:59';

        // 总体汇总
        $overall = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as sessions,
                COUNT(DISTINCT visitor_id) as visitors,
                COALESCE(SUM(pageviews), 0) as pageviews,
                COALESCE(SUM(is_bounce), 0) as bounces,
                COALESCE(SUM(duration), 0) as total_duration
             FROM $table_sessions 
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0",
            $start_dt, $end_dt
        ));

        // 转化数与金额
        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as conversions,
                COALESCE(SUM(event_value), 0) as revenue
             FROM $table_events 
             WHERE created_at BETWEEN %s AND %s AND event_type IN ('purchase', 'form_submit')",
            $start_dt, $end_dt
        ));

        if ($overall && $overall->sessions > 0) {
            $wpdb->replace($table_stats, [
                'stat_date'       => $date,
                'dimension_type'  => 'overall',
                'dimension_value' => 'all',
                'pageviews'       => (int) $overall->pageviews,
                'visitors'        => (int) $overall->visitors,
                'sessions'        => (int) $overall->sessions,
                'bounces'         => (int) $overall->bounces,
                'total_duration'  => (int) $overall->total_duration,
                'conversions'     => (int) ($conv->conversions ?? 0),
                'revenue'         => (float) ($conv->revenue ?? 0.00),
            ]);
        }
    }

    /**
     * 清空所有统计数据（管理后台维护功能）
     */
    public static function truncate_data() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wpfa_sessions");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wpfa_events");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wpfa_daily_stats");
        delete_transient('wpfa_cached_overview');
    }
}
