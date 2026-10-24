<?php
/**
 * Plugin Name: WordPress 智能极速数据漏斗与全景分析系统
 * Plugin URI: https://github.com/wuyou2001/wp-funnel-analytics
 * Description: 专为 WordPress 与 WooCommerce 打造的超轻量级全景数据分析与全链路转化漏斗系统。包含 PV/UV/会话/跳出率/停留时长、多维电商与询盘漏斗、来源归因与 UTM 营销追踪、受众终端画像与实时在线大屏。纯原生无依赖设计，极致优化加载速度，不拖慢网站。
 * Version: 1.0.0
 * Author: Wwnine
 * Author URI: https://github.com/wuyou2001
 * Text Domain: wp-funnel-analytics
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// 自动静默更新器集成 (严格遵循 GitHub 自动更新协议)
if (is_admin()) {
    require_once __DIR__ . '/includes/class-github-updater.php';
    new WP_GitHub_Plugin_Updater(__FILE__, '', 'wuyou2001/wp-funnel-analytics');
}

// 加载核心模块类文件
require_once __DIR__ . '/includes/class-wpfa-db.php';
require_once __DIR__ . '/includes/class-wpfa-tracker.php';
require_once __DIR__ . '/includes/class-wpfa-analytics.php';
require_once __DIR__ . '/includes/class-wpfa-admin.php';

/**
 * 插件主控单例
 */
class WP_Funnel_Analytics {

    private static $instance = null;
    public $db;
    public $tracker;
    public $admin;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->tracker = new WPFA_Tracker();

        if (is_admin()) {
            $this->admin = new WPFA_Admin();
        }

        // 挂载每日自动归档与过期明细清理定时任务
        add_action('wpfa_daily_maintenance_cron', ['WPFA_DB', 'daily_maintenance']);
    }
}

/**
 * 插件激活回调：创建或升级数据表，初始化 Cron 任务
 */
function wpfa_activate() {
    WPFA_DB::install();

    if (!wp_next_scheduled('wpfa_daily_maintenance_cron')) {
        wp_schedule_event(time() + 3600, 'daily', 'wpfa_daily_maintenance_cron');
    }
}
register_activation_hook(__FILE__, 'wpfa_activate');

/**
 * 插件停用回调：清除定时任务
 */
function wpfa_deactivate() {
    $timestamp = wp_next_scheduled('wpfa_daily_maintenance_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wpfa_daily_maintenance_cron');
    }
}
register_deactivation_hook(__FILE__, 'wpfa_deactivate');

// 启动插件
function wp_funnel_analytics() {
    return WP_Funnel_Analytics::get_instance();
}
add_action('plugins_loaded', 'wp_funnel_analytics');
