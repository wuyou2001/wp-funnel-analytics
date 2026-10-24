<?php
/**
 * 全景数据分析与漏斗转化计算引擎
 *
 * @package WP_Funnel_Analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPFA_Analytics {

    /**
     * 解析时间区间
     */
    public static function parse_date_range($range = '7d', $custom_start = '', $custom_end = '') {
        $now = current_time('timestamp');
        $today_str = gmdate('Y-m-d', $now);

        switch ($range) {
            case 'today':
                $start = $today_str;
                $end   = $today_str;
                $prev_start = gmdate('Y-m-d', strtotime('-1 day', $now));
                $prev_end   = $prev_start;
                $group_by   = 'hour';
                break;

            case 'yesterday':
                $start = gmdate('Y-m-d', strtotime('-1 day', $now));
                $end   = $start;
                $prev_start = gmdate('Y-m-d', strtotime('-2 days', $now));
                $prev_end   = $prev_start;
                $group_by   = 'hour';
                break;

            case '30d':
                $start = gmdate('Y-m-d', strtotime('-29 days', $now));
                $end   = $today_str;
                $prev_start = gmdate('Y-m-d', strtotime('-59 days', $now));
                $prev_end   = gmdate('Y-m-d', strtotime('-30 days', $now));
                $group_by   = 'day';
                break;

            case 'this_month':
                $start = gmdate('Y-m-01', $now);
                $end   = $today_str;
                $prev_start = gmdate('Y-m-01', strtotime('-1 month', $now));
                $prev_end   = gmdate('Y-m-t', strtotime('-1 month', $now));
                $group_by   = 'day';
                break;

            case 'last_month':
                $start = gmdate('Y-m-01', strtotime('-1 month', $now));
                $end   = gmdate('Y-m-t', strtotime('-1 month', $now));
                $prev_start = gmdate('Y-m-01', strtotime('-2 months', $now));
                $prev_end   = gmdate('Y-m-t', strtotime('-2 months', $now));
                $group_by   = 'day';
                break;

            case 'custom':
                $start = !empty($custom_start) ? sanitize_text_field($custom_start) : gmdate('Y-m-d', strtotime('-6 days', $now));
                $end   = !empty($custom_end) ? sanitize_text_field($custom_end) : $today_str;
                $days_diff = max(1, round((strtotime($end) - strtotime($start)) / 86400));
                $prev_start = gmdate('Y-m-d', strtotime("-$days_diff days", strtotime($start)));
                $prev_end   = gmdate('Y-m-d', strtotime("-1 day", strtotime($start)));
                $group_by   = ($days_diff <= 2) ? 'hour' : 'day';
                break;

            case '7d':
            default:
                $start = gmdate('Y-m-d', strtotime('-6 days', $now));
                $end   = $today_str;
                $prev_start = gmdate('Y-m-d', strtotime('-13 days', $now));
                $prev_end   = gmdate('Y-m-d', strtotime('-7 days', $now));
                $group_by   = 'day';
                break;
        }

        return [
            'start'      => $start,
            'end'        => $end,
            'start_dt'   => $start . ' 00:00:00',
            'end_dt'     => $end . ' 23:59:59',
            'prev_start' => $prev_start,
            'prev_end'   => $prev_end,
            'prev_start_dt' => $prev_start . ' 00:00:00',
            'prev_end_dt'   => $prev_end . ' 23:59:59',
            'group_by'   => $group_by,
        ];
    }

    /**
     * 获取全站核心概览 KPI
     */
    public static function get_overview($date_info) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';

        // 当前周期会话与流量
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as sessions,
                COUNT(DISTINCT visitor_id) as visitors,
                COALESCE(SUM(pageviews), 0) as pageviews,
                COALESCE(SUM(is_bounce), 0) as bounces,
                COALESCE(SUM(duration), 0) as total_duration
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0",
            $date_info['start_dt'], $date_info['end_dt']
        ));

        // 当前周期转化数与营收
        $current_conv = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as conversions,
                COALESCE(SUM(event_value), 0) as revenue
             FROM $table_events
             WHERE created_at BETWEEN %s AND %s AND event_type IN ('purchase', 'form_submit')",
            $date_info['start_dt'], $date_info['end_dt']
        ));

        // 上一周期对比数据 (环比)
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as sessions,
                COUNT(DISTINCT visitor_id) as visitors,
                COALESCE(SUM(pageviews), 0) as pageviews,
                COALESCE(SUM(is_bounce), 0) as bounces,
                COALESCE(SUM(duration), 0) as total_duration
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0",
            $date_info['prev_start_dt'], $date_info['prev_end_dt']
        ));

        $prev_conv = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as conversions,
                COALESCE(SUM(event_value), 0) as revenue
             FROM $table_events
             WHERE created_at BETWEEN %s AND %s AND event_type IN ('purchase', 'form_submit')",
            $date_info['prev_start_dt'], $date_info['prev_end_dt']
        ));

        $sessions = (int) ($current->sessions ?? 0);
        $visitors = (int) ($current->visitors ?? 0);
        $pageviews = (int) ($current->pageviews ?? 0);
        $bounces = (int) ($current->bounces ?? 0);
        $total_duration = (int) ($current->total_duration ?? 0);
        $conversions = (int) ($current_conv->conversions ?? 0);
        $revenue = (float) ($current_conv->revenue ?? 0.00);

        $bounce_rate = $sessions > 0 ? round(($bounces / $sessions) * 100, 1) : 0;
        $avg_duration = $sessions > 0 ? round($total_duration / $sessions) : 0;
        $conversion_rate = $sessions > 0 ? round(($conversions / $sessions) * 100, 2) : 0;

        // 计算增长率
        $prev_sessions = (int) ($prev->sessions ?? 0);
        $prev_visitors = (int) ($prev->visitors ?? 0);
        $prev_pv = (int) ($prev->pageviews ?? 0);
        $prev_conv_count = (int) ($prev_conv->conversions ?? 0);
        $prev_revenue = (float) ($prev_conv->revenue ?? 0.00);

        return [
            'pageviews'       => $pageviews,
            'pageviews_diff'  => self::calc_growth($pageviews, $prev_pv),
            'visitors'        => $visitors,
            'visitors_diff'   => self::calc_growth($visitors, $prev_visitors),
            'sessions'        => $sessions,
            'sessions_diff'   => self::calc_growth($sessions, $prev_sessions),
            'bounce_rate'     => $bounce_rate,
            'avg_duration'    => $avg_duration,
            'avg_duration_formatted' => gmdate('i:s', $avg_duration),
            'conversions'     => $conversions,
            'conversions_diff'=> self::calc_growth($conversions, $prev_conv_count),
            'conversion_rate' => $conversion_rate,
            'revenue'         => $revenue,
            'revenue_diff'    => self::calc_growth($revenue, $prev_revenue),
            'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
        ];
    }

    /**
     * 计算增长率
     */
    private static function calc_growth($curr, $prev) {
        if ($prev <= 0) {
            return $curr > 0 ? 100.0 : 0.0;
        }
        return round((($curr - $prev) / $prev) * 100, 1);
    }

    /**
     * 获取时序趋势折线图数据
     */
    public static function get_timeline($date_info) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';

        if ($date_info['group_by'] === 'hour') {
            // 按小时细分
            $sql = $wpdb->prepare(
                "SELECT 
                    DATE_FORMAT(created_at, '%%H:00') as point,
                    COUNT(*) as sessions,
                    COUNT(DISTINCT visitor_id) as visitors,
                    COALESCE(SUM(pageviews), 0) as pageviews
                 FROM $table_sessions
                 WHERE created_at BETWEEN %s AND %s AND is_bot = 0
                 GROUP BY point
                 ORDER BY point ASC",
                $date_info['start_dt'], $date_info['end_dt']
            );
        } else {
            // 按天细分
            $sql = $wpdb->prepare(
                "SELECT 
                    DATE_FORMAT(created_at, '%%Y-%%m-%%d') as point,
                    COUNT(*) as sessions,
                    COUNT(DISTINCT visitor_id) as visitors,
                    COALESCE(SUM(pageviews), 0) as pageviews
                 FROM $table_sessions
                 WHERE created_at BETWEEN %s AND %s AND is_bot = 0
                 GROUP BY point
                 ORDER BY point ASC",
                $date_info['start_dt'], $date_info['end_dt']
            );
        }

        $results = $wpdb->get_results($sql, ARRAY_A);
        $map = [];
        foreach ($results as $row) {
            $map[$row['point']] = $row;
        }

        // 构建完整连续点
        $labels = [];
        $pv_data = [];
        $uv_data = [];
        $session_data = [];

        if ($date_info['group_by'] === 'hour') {
            for ($h = 0; $h < 24; $h++) {
                $p = sprintf('%02d:00', $h);
                $labels[] = $p;
                $pv_data[] = isset($map[$p]) ? (int) $map[$p]['pageviews'] : 0;
                $uv_data[] = isset($map[$p]) ? (int) $map[$p]['visitors'] : 0;
                $session_data[] = isset($map[$p]) ? (int) $map[$p]['sessions'] : 0;
            }
        } else {
            $current_cursor = strtotime($date_info['start']);
            $end_cursor     = strtotime($date_info['end']);
            while ($current_cursor <= $end_cursor) {
                $p = gmdate('Y-m-d', $current_cursor);
                $labels[] = gmdate('m/d', $current_cursor);
                $pv_data[] = isset($map[$p]) ? (int) $map[$p]['pageviews'] : 0;
                $uv_data[] = isset($map[$p]) ? (int) $map[$p]['visitors'] : 0;
                $session_data[] = isset($map[$p]) ? (int) $map[$p]['sessions'] : 0;
                $current_cursor = strtotime('+1 day', $current_cursor);
            }
        }

        return [
            'labels'   => $labels,
            'pv'       => $pv_data,
            'uv'       => $uv_data,
            'sessions' => $session_data,
        ];
    }

    /**
     * 核心漏斗转化分析计算 (支持预置电商漏斗、询盘漏斗与自定义漏斗)
     */
    public static function get_funnel_data($funnel_id, $date_info) {
        global $wpdb;
        $table_funnels  = $wpdb->prefix . 'wpfa_funnels';
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';

        $funnel = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_funnels WHERE id = %d", $funnel_id));
        if (!$funnel) {
            // 兜底读取第一个漏斗
            $funnel = $wpdb->get_row("SELECT * FROM $table_funnels ORDER BY id ASC LIMIT 1");
        }

        if (!$funnel) {
            return ['funnel_name' => '无可用漏斗', 'steps' => []];
        }

        $steps_config = json_decode($funnel->steps, true);
        if (!is_array($steps_config)) {
            $steps_config = [];
        }

        // 计算漏斗每一步的去重到达量 (Distinct Sessions / Visitors)
        $processed_steps = [];
        $first_step_count = 0;
        $prev_step_count = 0;

        foreach ($steps_config as $index => $step) {
            $step_name = $step['name'] ?? ('步骤 ' . ($index + 1));
            $event_type = $step['event'] ?? '';

            if ($event_type === 'page_view' && $index === 0) {
                // 第一步：全站进站访客
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT session_id) FROM $table_sessions 
                     WHERE created_at BETWEEN %s AND %s AND is_bot = 0",
                    $date_info['start_dt'], $date_info['end_dt']
                ));
            } else {
                // 后续步骤：查找在指定时间区间内触发了该事件的独立会话数
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT session_id) FROM $table_events 
                     WHERE event_type = %s AND created_at BETWEEN %s AND %s",
                    $event_type, $date_info['start_dt'], $date_info['end_dt']
                ));
            }

            if ($index === 0) {
                $first_step_count = max(1, $count);
                $prev_step_count  = $count;
                $step_conversion_rate = 100.0;
                $overall_conversion_rate = 100.0;
                $drop_rate = 0.0;
                $drop_count = 0;
            } else {
                $overall_conversion_rate = round(($count / $first_step_count) * 100, 1);
                $step_conversion_rate = $prev_step_count > 0 ? round(($count / $prev_step_count) * 100, 1) : 0.0;
                $drop_count = max(0, $prev_step_count - $count);
                $drop_rate = $prev_step_count > 0 ? round(($drop_count / $prev_step_count) * 100, 1) : 0.0;
                $prev_step_count = $count;
            }

            $processed_steps[] = [
                'step_number' => $index + 1,
                'name'        => $step_name,
                'event_type'  => $event_type,
                'count'       => $count,
                'overall_rate'=> $overall_conversion_rate,
                'step_rate'   => $step_conversion_rate,
                'drop_count'  => $drop_count,
                'drop_rate'   => $drop_rate,
            ];
        }

        return [
            'funnel_id'   => (int) $funnel->id,
            'funnel_name' => $funnel->name,
            'description' => $funnel->description,
            'steps'       => $processed_steps,
        ];
    }

    /**
     * 流量来源与获客渠道分析 (Acquisition Breakdown)
     */
    public static function get_acquisition_data($date_info) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';

        // 1. 渠道大类分布
        $channels_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT channel, COUNT(*) as sessions, COUNT(DISTINCT visitor_id) as visitors, COALESCE(SUM(pageviews), 0) as pageviews, COALESCE(SUM(is_bounce), 0) as bounces
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0
             GROUP BY channel
             ORDER BY sessions DESC",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        $channel_names = [
            'direct'   => '直接访问 (Direct)',
            'organic'  => '自然搜索 (Organic Search)',
            'social'   => '社交媒体 (Social Media)',
            'referral' => '外部引荐 (Referral)',
            'campaign' => '广告营销 (Campaign)',
        ];

        $total_sessions = 0;
        foreach ($channels_raw as $c) {
            $total_sessions += (int) $c['sessions'];
        }

        $channels = [];
        foreach ($channels_raw as $c) {
            $s_count = (int) $c['sessions'];
            $b_count = (int) $c['bounces'];
            $channels[] = [
                'channel'      => $c['channel'],
                'label'        => $channel_names[$c['channel']] ?? $c['channel'],
                'sessions'     => $s_count,
                'visitors'     => (int) $c['visitors'],
                'pageviews'    => (int) $c['pageviews'],
                'bounce_rate'  => $s_count > 0 ? round(($b_count / $s_count) * 100, 1) : 0,
                'share'        => $total_sessions > 0 ? round(($s_count / $total_sessions) * 100, 1) : 0,
            ];
        }

        // 2. 引荐域名排行 Top 10
        $referrers = $wpdb->get_results($wpdb->prepare(
            "SELECT referrer_domain, COUNT(*) as sessions, COUNT(DISTINCT visitor_id) as visitors
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND referrer_domain != ''
             GROUP BY referrer_domain
             ORDER BY sessions DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 3. 营销战役 UTM 表现 Top 10
        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) as sessions, COUNT(DISTINCT visitor_id) as visitors
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND (utm_source != '' OR utm_campaign != '')
             GROUP BY utm_source, utm_medium, utm_campaign
             ORDER BY sessions DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        return [
            'channels'  => $channels,
            'referrers' => $referrers,
            'campaigns' => $campaigns,
        ];
    }

    /**
     * 受访页面与行为内容分析 (Top Pages, Landings, Events)
     */
    public static function get_behavior_data($date_info) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';

        // 1. 最热门受访页面 Top 10
        $top_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, page_title, COUNT(*) as pv, COUNT(DISTINCT visitor_id) as uv
             FROM $table_events
             WHERE event_type = 'page_view' AND created_at BETWEEN %s AND %s
             GROUP BY page_url, page_title
             ORDER BY pv DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 2. 核心着陆页 Landing Pages Top 10
        $landing_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT landing_page, COUNT(*) as sessions, COALESCE(SUM(is_bounce), 0) as bounces
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND landing_page != ''
             GROUP BY landing_page
             ORDER BY sessions DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        foreach ($landing_pages as &$lp) {
            $s = (int) $lp['sessions'];
            $b = (int) $lp['bounces'];
            $lp['bounce_rate'] = $s > 0 ? round(($b / $s) * 100, 1) : 0;
        }

        // 3. 主要跳出退出页 Exit Pages Top 10
        $exit_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT exit_page, COUNT(*) as exits
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND exit_page != ''
             GROUP BY exit_page
             ORDER BY exits DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 4. 事件触发频次统计 Top 10
        $events_summary = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, event_label, COUNT(*) as count, COUNT(DISTINCT visitor_id) as visitors
             FROM $table_events
             WHERE created_at BETWEEN %s AND %s AND event_type != 'page_view'
             GROUP BY event_type, event_label
             ORDER BY count DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        return [
            'top_pages'     => $top_pages,
            'landing_pages' => $landing_pages,
            'exit_pages'    => $exit_pages,
            'events'        => $events_summary,
        ];
    }

    /**
     * 受众画像与技术设备分析 (Audience & Tech)
     */
    public static function get_audience_data($date_info) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';

        // 1. 设备终端分布
        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT device, COUNT(*) as sessions, COUNT(DISTINCT visitor_id) as visitors
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0
             GROUP BY device
             ORDER BY sessions DESC",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 2. 操作系统分布
        $os_list = $wpdb->get_results($wpdb->prepare(
            "SELECT os, COUNT(*) as sessions
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND os != ''
             GROUP BY os
             ORDER BY sessions DESC
             LIMIT 6",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 3. 浏览器分布
        $browsers = $wpdb->get_results($wpdb->prepare(
            "SELECT browser, COUNT(*) as sessions
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND browser != ''
             GROUP BY browser
             ORDER BY sessions DESC
             LIMIT 6",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 4. 国家与地区分布 Top 10
        $countries = $wpdb->get_results($wpdb->prepare(
            "SELECT country, country_code, COUNT(*) as sessions, COUNT(DISTINCT visitor_id) as visitors
             FROM $table_sessions
             WHERE created_at BETWEEN %s AND %s AND is_bot = 0 AND country_code != ''
             GROUP BY country, country_code
             ORDER BY sessions DESC
             LIMIT 10",
            $date_info['start_dt'], $date_info['end_dt']
        ), ARRAY_A);

        // 补法国旗 Emoji
        foreach ($countries as &$c) {
            $c['flag'] = self::country_code_to_flag($c['country_code']);
        }

        return [
            'devices'   => $devices,
            'os_list'   => $os_list,
            'browsers'  => $browsers,
            'countries' => $countries,
        ];
    }

    /**
     * 将 2 位国家代码转为 Flag Emoji
     */
    public static function country_code_to_flag($code) {
        $code = strtoupper($code);
        if (strlen($code) !== 2 || $code === 'UN') {
            return '🌐';
        }
        $first = ord($code[0]) - ord('A') + 0x1F1E6;
        $second = ord($code[1]) - ord('A') + 0x1F1E6;
        return mb_chr($first, 'UTF-8') . mb_chr($second, 'UTF-8');
    }

    /**
     * 实时活跃访客大屏数据 (过去 30 分钟)
     */
    public static function get_realtime_data() {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wpfa_sessions';
        $table_events   = $wpdb->prefix . 'wpfa_events';

        $since = gmdate('Y-m-d H:i:s', strtotime('-30 minutes'));

        // 活跃访客数
        $active_visitors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT visitor_id) FROM $table_sessions 
             WHERE updated_at >= %s AND is_bot = 0",
            $since
        ));

        // 实时正在浏览的页面 Top 5
        $live_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT page_url, page_title, COUNT(*) as count 
             FROM $table_events 
             WHERE created_at >= %s AND event_type = 'page_view'
             GROUP BY page_url, page_title
             ORDER BY count DESC 
             LIMIT 5",
            $since
        ), ARRAY_A);

        // 最近 10 条实时动态流
        $recent_activities = $wpdb->get_results($wpdb->prepare(
            "SELECT e.event_type, e.event_label, e.page_title, e.page_url, e.created_at, s.country_code, s.device, s.channel
             FROM $table_events e
             LEFT JOIN $table_sessions s ON e.session_id = s.session_id
             WHERE e.created_at >= %s
             ORDER BY e.created_at DESC
             LIMIT 8",
            $since
        ), ARRAY_A);

        foreach ($recent_activities as &$act) {
            $act['flag'] = self::country_code_to_flag($act['country_code'] ?? '');
            $act['time_ago'] = human_time_diff(strtotime($act['created_at']), current_time('timestamp')) . '前';
        }

        return [
            'active_visitors'   => $active_visitors,
            'live_pages'        => $live_pages,
            'recent_activities' => $recent_activities,
        ];
    }
}
