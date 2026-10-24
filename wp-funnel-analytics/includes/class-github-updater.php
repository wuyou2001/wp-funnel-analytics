<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_GitHub_Plugin_Updater')) {
    /**
     * 通用 WordPress 插件 GitHub 自动更新器（静默后台检测，无第三方依赖）。
     *
     * 支持 Release API 优先与 Git Tag + 仓库内 Raw ZIP 文件双层兜底，
     * 兼容全版本 WordPress 原生更新 API 与升级目录重命名修正。
     */
    class WP_GitHub_Plugin_Updater
    {
        protected $plugin_file;
        protected $plugin_basename;
        protected $slug;
        protected $version;
        protected $github_repo;
        protected $zip_filename;

        /**
         * 构造函数。
         *
         * @param string $plugin_file     插件主文件绝对路径 (__FILE__)
         * @param string $version         当前插件版本号（如 '1.0.0'）
         * @param string $github_repo     GitHub 仓库标识（如 'wuyou2001/my-plugin'）
         * @param string $zip_filename    可选。仓库中打包的 ZIP 文件名
         */
        public function __construct($plugin_file, $version, $github_repo, $zip_filename = '')
        {
            $this->plugin_file     = $plugin_file;
            $this->plugin_basename = plugin_basename($plugin_file);
            $this->slug            = dirname($this->plugin_basename);
            if ($this->slug === '.' || empty($this->slug)) {
                $this->slug = sanitize_title(pathinfo($plugin_file, PATHINFO_FILENAME));
            }
            if (empty($version)) {
                if (function_exists('get_file_data')) {
                    $meta = get_file_data($this->plugin_file, ['Version' => 'Version']);
                    $this->version = !empty($meta['Version']) ? trim($meta['Version']) : '1.0.0';
                } else {
                    $this->version = '1.0.0';
                }
            } else {
                $this->version = trim($version);
            }
            $this->github_repo     = trim($github_repo);
            $this->zip_filename    = !empty($zip_filename) ? $zip_filename : ($this->slug . '.zip');

            // 挂钩 WordPress 核心更新检查（保存瞬态与读取瞬态双向挂钩）
            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
            add_filter('site_transient_update_plugins', [$this, 'check_update']);

            // 接管详情弹窗与安装目录校正
            add_filter('plugins_api', [$this, 'plugin_popup'], 20, 3);
            add_filter('upgrader_source_selection', [$this, 'source_selection'], 10, 4);
            add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);

            // 插件列表快捷操作：一键立即检查更新
            add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_check_link']);
            add_action('admin_init', [$this, 'handle_check_action']);
        }

        /**
         * 获取远程最新版本信息（支持 Releases 与 Tags 双兜底）。
         *
         * @param bool $force_check 是否强制跳过缓存立即请求
         * @return array|false
         */
        public function get_release_info($force_check = false)
        {
            global $pagenow;
            if (!$force_check) {
                if ($pagenow === 'update-core.php' || !empty($_GET['force-check']) || !empty($_GET['wpgh_action'])) {
                    $force_check = true;
                }
            }

            $transient_key = 'wpgh_update_' . md5($this->github_repo . '_' . $this->slug);
            if ($force_check) {
                delete_transient($transient_key);
            } else {
                $cached = get_transient($transient_key);
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $headers = [
                'timeout'    => 10,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                'headers'    => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
            ];

            // 1. 优先读取 GitHub Releases
            $api_url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
            $response = wp_remote_get($api_url, $headers);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($data) && !empty($data['tag_name'])) {
                    set_transient($transient_key, $data, 1 * HOUR_IN_SECONDS);
                    return $data;
                }
            }

            // 2. 兜底容错 1：直接读取 Git Tags 并使用标准英文直链 Raw ZIP
            $tags_url = 'https://api.github.com/repos/' . $this->github_repo . '/tags';
            $tags_response = wp_remote_get($tags_url, $headers);

            if (!is_wp_error($tags_response) && wp_remote_retrieve_response_code($tags_response) === 200) {
                $tags_data = json_decode(wp_remote_retrieve_body($tags_response), true);
                if (is_array($tags_data) && !empty($tags_data[0]['name'])) {
                    $latest_tag = $tags_data[0];
                    $tag_name = $latest_tag['name'];

                    $data = [
                        'tag_name'     => $tag_name,
                        'html_url'     => 'https://github.com/' . $this->github_repo . '/releases/tag/' . rawurlencode($tag_name),
                        'body'         => '版本 ' . $tag_name . ' 更新已发布。',
                        'zipball_url'  => 'https://raw.githubusercontent.com/' . $this->github_repo . '/' . rawurlencode($tag_name) . '/' . rawurlencode($this->zip_filename),
                        'assets'       => [
                            [
                                'name'                 => $this->zip_filename,
                                'browser_download_url' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/' . rawurlencode($tag_name) . '/' . rawurlencode($this->zip_filename),
                            ],
                            [
                                'name'                 => 'package.zip',
                                'browser_download_url' => 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/' . rawurlencode($this->zip_filename),
                            ]
                        ],
                        'published_at' => gmdate('Y-m-d H:i:s'),
                    ];
                    set_transient($transient_key, $data, 1 * HOUR_IN_SECONDS);
                    return $data;
                }
            }

            // 3. 兜底容错 2 (免 API 限流 / 国内加速 CDN)：jsDelivr 全球加速源
            $cdn_url = 'https://cdn.jsdelivr.net/gh/' . $this->github_repo . '@main/version.json';
            $cdn_response = wp_remote_get($cdn_url, ['timeout' => 8, 'user-agent' => 'WordPress/' . get_bloginfo('version')]);
            if (!is_wp_error($cdn_response) && wp_remote_retrieve_response_code($cdn_response) === 200) {
                $cdn_data = json_decode(wp_remote_retrieve_body($cdn_response), true);
                if (is_array($cdn_data) && !empty($cdn_data['tag_name'])) {
                    set_transient($transient_key, $cdn_data, 1 * HOUR_IN_SECONDS);
                    return $cdn_data;
                }
            }

            // 4. 兜底容错 3：GitHub Raw 直链
            $raw_url = 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/version.json';
            $raw_response = wp_remote_get($raw_url, ['timeout' => 8, 'user-agent' => 'WordPress/' . get_bloginfo('version')]);
            if (!is_wp_error($raw_response) && wp_remote_retrieve_response_code($raw_response) === 200) {
                $raw_data = json_decode(wp_remote_retrieve_body($raw_response), true);
                if (is_array($raw_data) && !empty($raw_data['tag_name'])) {
                    set_transient($transient_key, $raw_data, 1 * HOUR_IN_SECONDS);
                    return $raw_data;
                }
            }

            return false;
        }

        /**
         * 挂钩 WordPress 插件更新检测瞬态。
         */
        public function check_update($transient)
        {
            if (!is_object($transient)) {
                $transient = new stdClass();
            }
            if (!isset($transient->response)) {
                $transient->response = [];
            }

            $release = $this->get_release_info();
            if (!$release) {
                return $transient;
            }

            $new_version = ltrim($release['tag_name'], 'vV');
            if (version_compare($new_version, $this->version, '>')) {
                $package_url = '';
                if (!empty($release['assets']) && is_array($release['assets'])) {
                    foreach ($release['assets'] as $asset) {
                        if (isset($asset['name']) && preg_match('/\.zip$/i', $asset['name'])) {
                            $package_url = $asset['browser_download_url'];
                            break;
                        }
                    }
                }
                if (empty($package_url) && !empty($release['zipball_url'])) {
                    $package_url = $release['zipball_url'];
                }

                $item = (object) [
                    'id'            => $this->plugin_basename,
                    'slug'          => $this->slug,
                    'plugin'        => $this->plugin_basename,
                    'new_version'   => $new_version,
                    'url'           => $release['html_url'] ?? ('https://github.com/' . $this->github_repo),
                    'package'       => $package_url,
                    'icons'         => [],
                    'banners'       => [],
                    'banners_rtl'   => [],
                    'tested'        => '',
                    'requires_php'  => '7.4',
                    'compatibility' => new stdClass(),
                ];

                $transient->response[$this->plugin_basename] = $item;
                if (isset($transient->no_update[$this->plugin_basename])) {
                    unset($transient->no_update[$this->plugin_basename]);
                }
            }

            return $transient;
        }

        /**
         * 在插件操作链接追加「检查更新」。
         */
        public function add_check_link($links)
        {
            $url = wp_nonce_url(
                admin_url('plugins.php?wpgh_action=check&plugin=' . urlencode($this->plugin_basename)),
                'wpgh_check_' . $this->plugin_basename
            );
            $links['wpgh_check'] = '<a href="' . esc_url($url) . '" style="color: #2271b1;">' . esc_html__('检查更新', 'default') . '</a>';
            return $links;
        }

        /**
         * 处理手动点击「检查更新」请求。
         */
        public function handle_check_action()
        {
            if (!current_user_can('update_plugins')) {
                return;
            }

            if (isset($_GET['wpgh_action']) && $_GET['wpgh_action'] === 'check' && isset($_GET['plugin']) && $_GET['plugin'] === $this->plugin_basename) {
                check_admin_referer('wpgh_check_' . $this->plugin_basename);

                $transient_key = 'wpgh_update_' . md5($this->github_repo . '_' . $this->slug);
                delete_transient($transient_key);

                // 强制从远程多源获取最新 Release 信息
                $release = $this->get_release_info(true);

                $status = 'error';
                $new_version = '';

                if ($release && !empty($release['tag_name'])) {
                    $new_version = ltrim($release['tag_name'], 'vV');
                    if (version_compare($new_version, $this->version, '>')) {
                        $status = 'update_available';

                        // 立即更新 WordPress 的核心 update_plugins 瞬态，确保重定向回列表后立即显示黄色更新条
                        $current = get_site_transient('update_plugins');
                        if (!is_object($current)) {
                            $current = new stdClass();
                        }
                        if (!isset($current->response)) {
                            $current->response = [];
                        }
                        if (!isset($current->checked)) {
                            $current->checked = [];
                        }
                        $current->checked[$this->plugin_basename] = $this->version;

                        // 触发比对并写入
                        $current = $this->check_update($current);
                        set_site_transient('update_plugins', $current);
                    } else {
                        $status = 'latest';
                    }
                }

                $redirect_url = add_query_arg([
                    'wpgh_checked' => 1,
                    'wpgh_slug'    => $this->slug,
                    'wpgh_status'  => $status,
                    'wpgh_ver'     => $new_version ?: $this->version,
                ], admin_url('plugins.php'));

                wp_safe_redirect($redirect_url);
                exit;
            }

            if (isset($_GET['wpgh_checked']) && isset($_GET['wpgh_slug']) && $_GET['wpgh_slug'] === $this->slug) {
                $status = sanitize_key($_GET['wpgh_status'] ?? '');
                $ver    = sanitize_text_field($_GET['wpgh_ver'] ?? '');

                add_action('admin_notices', function () use ($status, $ver) {
                    if ($status === 'update_available') {
                        echo '<div class="notice notice-warning is-dismissible" style="border-left-color: #2563eb; background:#eff6ff;"><p>' .
                            sprintf(
                                '🚀 <strong>【WP Funnel Analytics】检测到远程新版本 v%s！</strong>下方列表中已出现黄色升级提示，请点击“<strong>现在更新</strong>”一键升级。',
                                esc_html($ver)
                            ) .
                            '</p></div>';
                    } elseif ($status === 'latest') {
                        echo '<div class="notice notice-success is-dismissible"><p>' .
                            sprintf(
                                '✓ <strong>【WP Funnel Analytics】已是最新版本 (v%s)</strong>，无需更新。',
                                esc_html($ver)
                            ) .
                            '</p></div>';
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>' .
                            '⚠️ <strong>【WP Funnel Analytics】检查更新失败：</strong>服务器连接 GitHub API 超时或被防火墙拦截。若服务器网络受限，可直接在【插件 -> 安装插件 -> 上传插件】上传最新 ZIP 包覆盖升级。' .
                            '</p></div>';
                    }
                });
            }
        }

        /**
         * 接管插件弹窗详情查看。
         */
        public function plugin_popup($result, $action, $args)
        {
            if ($action !== 'plugin_information') {
                return $result;
            }

            if (empty($args->slug) || ($args->slug !== $this->slug && $args->slug !== $this->plugin_basename)) {
                return $result;
            }

            $release = $this->get_release_info();
            if (!$release) {
                return $result;
            }

            $new_version = ltrim($release['tag_name'], 'vV');
            $changelog = !empty($release['body']) ? nl2br(esc_html($release['body'])) : '暂无详细更新说明。';

            $plugin_data = function_exists('get_plugin_data') ? get_plugin_data($this->plugin_file, false, false) : [];
            $plugin_name = !empty($plugin_data['Name']) ? $plugin_data['Name'] : $this->slug;
            $author_name = !empty($plugin_data['Author']) ? $plugin_data['Author'] : 'Wwnine';

            $res = new stdClass();
            $res->name           = $plugin_name;
            $res->slug           = $this->slug;
            $res->version        = $new_version;
            $res->author         = '<a href="https://github.com/' . esc_attr($this->github_repo) . '">' . esc_html($author_name) . '</a>';
            $res->homepage       = 'https://github.com/' . esc_attr($this->github_repo);
            $res->requires       = '5.4';
            $res->tested         = '6.7';
            $res->requires_php   = '7.4';
            $res->last_updated   = $release['published_at'] ?? gmdate('Y-m-d H:i:s');
            $res->sections       = [
                'description' => !empty($plugin_data['Description']) ? esc_html($plugin_data['Description']) : $plugin_name,
                'changelog'   => $changelog,
            ];

            return $res;
        }

        /**
         * 自动校验与校正解压目录（防止 GitHub zip 嵌套子文件夹或目录名不匹配导致安装失败）。
         */
        public function source_selection($source, $remote_source, $upgrader, $hook_extra = [])
        {
            global $wp_filesystem;

            if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
                return $source;
            }

            // 如果解压目录里嵌套了标准插件目录，提升该子目录
            $nested_dir = trailingslashit($source) . $this->slug;
            if ($wp_filesystem->is_dir($nested_dir)) {
                return trailingslashit($nested_dir);
            }

            // 纠偏解压根目录为插件标准 slug 目录名
            $corrected_source = trailingslashit($remote_source) . $this->slug . '/';
            if ($source !== $corrected_source) {
                $wp_filesystem->move($source, $corrected_source);
                return $corrected_source;
            }

            return $source;
        }

        /**
         * 升级完成后纠偏目录名称，防止升级后停用。
         */
        public function post_install($true, $hook_extra, $result)
        {
            global $wp_filesystem;

            if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
                return $result;
            }

            $proper_destination = trailingslashit(WP_PLUGIN_DIR) . $this->slug;
            if (trailingslashit($result['destination']) !== trailingslashit($proper_destination)) {
                $wp_filesystem->move($result['destination'], $proper_destination, true);
                $result['destination'] = $proper_destination;
            }

            activate_plugin($this->plugin_basename);
            return $result;
        }
    }
}
