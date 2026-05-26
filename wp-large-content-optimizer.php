<?php
/**
 * Plugin Name: WP Large Content Optimizer
 * Plugin URI: https://www.seoyh.net/
 * Description: 针对文章量大导致 WordPress 变慢的问题，提供数据库体检、垃圾数据分批清理、索引检测/添加、后台文章列表加速和定时维护。
 * Version: 2.3.0
 * Author: 一点优化
 * Author URI: https://www.seoyh.net/
 * Text Domain: wp-large-content-optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Large_Content_Optimizer {
    const VERSION = '2.3.0';
    const OPTION = 'wplco_settings';
    const LOG_OPTION = 'wplco_maintenance_logs';
    const GITHUB_OWNER = '921988379';
    const GITHUB_REPO = 'WP-Large-Content-Optimizer';
    const GITHUB_PLUGIN_FILE = 'wp-large-content-optimizer/wp-large-content-optimizer.php';
    const CRON_HOOK = 'wplco_daily_maintenance';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'handle_post_actions'));
        add_filter('wp_revisions_to_keep', array($this, 'limit_revisions'), 10, 2);
        add_filter('manage_posts_columns', array($this, 'simplify_post_columns'), 999);
        add_filter('manage_pages_columns', array($this, 'simplify_post_columns'), 999);
        add_action('pre_get_posts', array($this, 'admin_list_fast_mode_query'), 20);
        add_filter('months_dropdown_results', array($this, 'admin_list_disable_months_dropdown'), 20, 2);
        add_filter('found_posts', array($this, 'admin_list_disable_found_posts'), 20, 2);
        add_action('init', array($this, 'frontend_light_optimizations'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_github_update'));
        add_filter('plugins_api', array($this, 'github_plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'fix_github_update_folder'), 10, 3);
        add_action(self::CRON_HOOK, array($this, 'run_cron_maintenance'));
    }

    public static function activate() {
        $defaults = self::defaults();
        $settings = get_option(self::OPTION, array());
        update_option(self::OPTION, wp_parse_args($settings, $defaults), false);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function defaults() {
        return array(
            'batch_size' => 500,
            'revision_limit' => 3,
            'disable_revisions' => 0,
            'admin_list_light' => 1,
            'cron_enabled' => 0,
            'cron_clean_revisions' => 0,
            'cron_clean_autodrafts' => 1,
            'cron_clean_trash' => 0,
            'cron_clean_orphan_postmeta' => 1,
            'cron_clean_expired_transients' => 1,
            'short_content_chars' => 120,
            'admin_fast_mode' => 1,
            'admin_fast_per_page' => 50,
            'admin_fast_title_search' => 1,
            'admin_fast_disable_months' => 1,
            'admin_fast_disable_found_rows' => 0,
            'frontend_disable_emoji' => 1,
            'frontend_disable_embeds' => 0,
            'frontend_disable_dashicons' => 0,
            'frontend_disable_generator' => 1,
        );
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    public function admin_menu() {
        add_management_page(
            '大站优化',
            '大站优化',
            'manage_options',
            'wp-large-content-optimizer',
            array($this, 'render_page')
        );
    }

    public function limit_revisions($num, $post) {
        $settings = $this->settings();
        if (!empty($settings['disable_revisions'])) {
            return 0;
        }
        return max(0, intval($settings['revision_limit']));
    }

    public function simplify_post_columns($columns) {
        if (!is_admin()) {
            return $columns;
        }
        $settings = $this->settings();
        if (empty($settings['admin_list_light'])) {
            return $columns;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, array('edit'), true)) {
            return $columns;
        }

        $keep = array('cb', 'title', 'author', 'categories', 'tags', 'date');
        $new = array();
        foreach ($columns as $key => $label) {
            if (in_array($key, $keep, true)) {
                $new[$key] = $label;
            }
        }
        return $new;
    }

    private function is_admin_edit_posts_screen() {
        if (!is_admin() || wp_doing_ajax()) {
            return false;
        }
        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return false;
        }
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        return in_array($post_type, array('post', 'page'), true);
    }

    public function admin_list_fast_mode_query($query) {
        if (!$query->is_main_query() || !$this->is_admin_edit_posts_screen()) {
            return;
        }
        $settings = $this->settings();
        if (empty($settings['admin_fast_mode'])) {
            return;
        }

        $query->set('posts_per_page', min(200, max(10, intval($settings['admin_fast_per_page']))));
        $query->set('update_post_meta_cache', false);
        $query->set('update_post_term_cache', false);

        if (!empty($settings['admin_fast_disable_found_rows'])) {
            $query->set('no_found_rows', true);
        }

        if (!empty($settings['admin_fast_title_search']) && !empty($_GET['s'])) {
            $search = sanitize_text_field(wp_unslash($_GET['s']));
            $query->set('s', '');
            $query->set('wplco_title_search', $search);
            add_filter('posts_where', array($this, 'admin_title_search_where'), 20, 2);
        }
    }

    public function admin_title_search_where($where, $query) {
        $search = $query->get('wplco_title_search');
        if ($search === '') {
            return $where;
        }
        global $wpdb;
        $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        return $where;
    }

    public function admin_list_disable_months_dropdown($months, $post_type) {
        if (!$this->is_admin_edit_posts_screen()) {
            return $months;
        }
        $settings = $this->settings();
        if (empty($settings['admin_fast_mode']) || empty($settings['admin_fast_disable_months'])) {
            return $months;
        }
        return array();
    }

    public function admin_list_disable_found_posts($found_posts, $query) {
        if (!$query->is_main_query() || !$this->is_admin_edit_posts_screen()) {
            return $found_posts;
        }
        $settings = $this->settings();
        if (empty($settings['admin_fast_mode']) || empty($settings['admin_fast_disable_found_rows'])) {
            return $found_posts;
        }
        return min(intval($found_posts), intval($query->get('posts_per_page')) + intval($query->get('offset')));
    }


    public function handle_post_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (empty($_POST['wplco_action'])) {
            return;
        }
        check_admin_referer('wplco_action', 'wplco_nonce');

        $action = sanitize_key(wp_unslash($_POST['wplco_action']));
        if ($action === 'export_report') {
            $this->export_report();
        }
        $redirect = add_query_arg(array('page' => 'wp-large-content-optimizer'), admin_url('tools.php'));
        $result = null;

        if ($action === 'save_settings') {
            $this->save_settings();
            $result = array('type' => 'success', 'message' => '设置已保存。');
        } elseif ($action === 'clean_revisions') {
            $result = $this->clean_revisions();
        } elseif ($action === 'clean_autodrafts') {
            $result = $this->clean_autodrafts();
        } elseif ($action === 'clean_trash') {
            $result = $this->clean_trash();
        } elseif ($action === 'clean_orphan_postmeta') {
            $result = $this->clean_orphan_postmeta();
        } elseif ($action === 'clean_orphan_term_relationships') {
            $result = $this->clean_orphan_term_relationships();
        } elseif ($action === 'clean_expired_transients') {
            $result = $this->clean_expired_transients();
        } elseif ($action === 'add_indexes') {
            $result = $this->add_recommended_indexes();
        } elseif ($action === 'refresh_report') {
            delete_transient('wplco_diagnostic_report');
            $result = array('type' => 'success', 'message' => '诊断报告缓存已刷新。');
        } elseif ($action === 'clean_failed_drafts') {
            $result = $this->clean_failed_drafts();
        } elseif ($action === 'trash_duplicate_draft_titles') {
            $result = $this->trash_duplicate_draft_titles();
        } elseif ($action === 'clear_logs') {
            delete_option(self::LOG_OPTION);
            $result = array('type' => 'success', 'message' => '维护日志已清空。');
        }

        if (is_array($result)) {
            if (!in_array($action, array('refresh_report', 'save_settings'), true)) {
                $this->add_log($action, $result);
            }
            if ($action !== 'refresh_report' && $action !== 'save_settings') {
                delete_transient('wplco_diagnostic_report');
            }
            set_transient('wplco_admin_notice_' . get_current_user_id(), $result, 60);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    private function save_settings() {
        $settings = $this->settings();
        $settings['batch_size'] = min(5000, max(50, intval($_POST['batch_size'] ?? 500)));
        $settings['revision_limit'] = min(50, max(0, intval($_POST['revision_limit'] ?? 3)));
        $settings['short_content_chars'] = min(1000, max(20, intval($_POST['short_content_chars'] ?? 120)));
        $settings['disable_revisions'] = empty($_POST['disable_revisions']) ? 0 : 1;
        $settings['admin_list_light'] = empty($_POST['admin_list_light']) ? 0 : 1;
        $settings['admin_fast_mode'] = empty($_POST['admin_fast_mode']) ? 0 : 1;
        $settings['admin_fast_per_page'] = min(200, max(10, intval($_POST['admin_fast_per_page'] ?? 50)));
        $settings['admin_fast_title_search'] = empty($_POST['admin_fast_title_search']) ? 0 : 1;
        $settings['admin_fast_disable_months'] = empty($_POST['admin_fast_disable_months']) ? 0 : 1;
        $settings['admin_fast_disable_found_rows'] = empty($_POST['admin_fast_disable_found_rows']) ? 0 : 1;
        $settings['frontend_disable_emoji'] = empty($_POST['frontend_disable_emoji']) ? 0 : 1;
        $settings['frontend_disable_embeds'] = empty($_POST['frontend_disable_embeds']) ? 0 : 1;
        $settings['frontend_disable_dashicons'] = empty($_POST['frontend_disable_dashicons']) ? 0 : 1;
        $settings['frontend_disable_generator'] = empty($_POST['frontend_disable_generator']) ? 0 : 1;
        $settings['cron_enabled'] = empty($_POST['cron_enabled']) ? 0 : 1;
        $settings['cron_clean_revisions'] = empty($_POST['cron_clean_revisions']) ? 0 : 1;
        $settings['cron_clean_autodrafts'] = empty($_POST['cron_clean_autodrafts']) ? 0 : 1;
        $settings['cron_clean_trash'] = empty($_POST['cron_clean_trash']) ? 0 : 1;
        $settings['cron_clean_orphan_postmeta'] = empty($_POST['cron_clean_orphan_postmeta']) ? 0 : 1;
        $settings['cron_clean_expired_transients'] = empty($_POST['cron_clean_expired_transients']) ? 0 : 1;
        update_option(self::OPTION, $settings, false);
    }

    private function github_api_url($path) {
        return 'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . $path;
    }

    private function get_github_release($force = false) {
        $cache_key = 'wplco_github_release';
        if (!$force) {
            $cached = get_site_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_remote_get($this->github_api_url('/releases/latest'), array(
            'timeout' => 12,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WP-Large-Content-Optimizer/' . self::VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || empty($release['tag_name'])) {
            return false;
        }

        set_site_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);
        return $release;
    }

    private function release_version($release) {
        $tag = isset($release['tag_name']) ? (string) $release['tag_name'] : '';
        return ltrim($tag, 'vV');
    }

    private function release_zip_url($release) {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && $asset['name'] === 'wp-large-content-optimizer.zip' && !empty($asset['browser_download_url'])) {
                    return $asset['browser_download_url'];
                }
            }
        }
        return 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/releases/latest/download/wp-large-content-optimizer.zip';
    }

    public function check_github_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = $this->release_version($release);
        if (!$remote_version || !version_compare($remote_version, self::VERSION, '>')) {
            return $transient;
        }

        $plugin_data = new stdClass();
        $plugin_data->slug = 'wp-large-content-optimizer';
        $plugin_data->plugin = self::GITHUB_PLUGIN_FILE;
        $plugin_data->new_version = $remote_version;
        $plugin_data->url = 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
        $plugin_data->package = $this->release_zip_url($release);
        $plugin_data->tested = '6.8';
        $plugin_data->requires = '5.8';
        $plugin_data->requires_php = '7.4';

        $transient->response[self::GITHUB_PLUGIN_FILE] = $plugin_data;
        return $transient;
    }

    public function github_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wp-large-content-optimizer') {
            return $result;
        }

        $release = $this->get_github_release(true);
        if (!$release) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'WP Large Content Optimizer';
        $info->slug = 'wp-large-content-optimizer';
        $info->version = $this->release_version($release);
        $info->author = '<a href="https://www.seoyh.net/">一点优化</a>';
        $info->homepage = 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
        $info->requires = '5.8';
        $info->tested = '6.8';
        $info->requires_php = '7.4';
        $info->download_link = $this->release_zip_url($release);
        $info->last_updated = !empty($release['published_at']) ? $release['published_at'] : '';
        $body = !empty($release['body']) ? $release['body'] : '请查看 GitHub Release 更新说明。';
        $info->sections = array(
            'description' => '针对文章量大、采集站、后台文章列表变慢、数据库垃圾数据膨胀等问题的 WordPress 大站性能优化工具。',
            'changelog' => wp_kses_post(nl2br($body)),
        );
        return $info;
    }

    public function fix_github_update_folder($response, $hook_extra, $result) {
        global $wp_filesystem;
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::GITHUB_PLUGIN_FILE) {
            return $response;
        }
        if (empty($result['destination']) || empty($result['remote_destination']) || empty($wp_filesystem)) {
            return $response;
        }

        $proper_destination = trailingslashit($result['remote_destination']) . 'wp-large-content-optimizer/';
        if ($result['destination'] !== $proper_destination && $wp_filesystem->exists($result['destination'])) {
            if ($wp_filesystem->exists($proper_destination)) {
                $wp_filesystem->delete($proper_destination, true);
            }
            $wp_filesystem->move($result['destination'], $proper_destination, true);
            $result['destination'] = $proper_destination;
            $response['destination'] = $proper_destination;
        }
        return $response;
    }


    private function batch_size() {
        $settings = $this->settings();
        return min(5000, max(50, intval($settings['batch_size'])));
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足。');
        }

        $report = $this->get_diagnostic_report();
        $stats = $report['stats'];
        $indexes = $report['indexes'];
        $diagnosis = $report['diagnosis'];
        $table_sizes = $report['table_sizes'];
        $meta_hotspots = $report['meta_hotspots'];
        $autoload_heavy = $report['autoload_heavy'];
        $environment = $report['environment'];
        $wizard_steps = $report['wizard_steps'];
        $collector_stats = $report['collector_stats'];
        $duplicate_titles = $report['duplicate_titles'];
        $duplicate_draft_groups = $report['duplicate_draft_groups'];
        $published_duplicate_groups = $report['published_duplicate_groups'];
        $frontend_report = $report['frontend_report'];
        $slow_risk_report = $report['slow_risk_report'];
        $settings = $this->settings();
        $logs = $this->get_logs();
        $notice = get_transient('wplco_admin_notice_' . get_current_user_id());
        delete_transient('wplco_admin_notice_' . get_current_user_id());
        ?>
        <div class="wrap wplco-wrap">
            <div class="wplco-hero">
                <div>
                    <h1>WP 大站性能优化器</h1>
                    <p>面向文章量大、采集站、后台列表变慢、数据库膨胀等问题。默认只检测；清理和加索引都需要手动确认。</p>
                    <p class="wplco-hero-meta">诊断数据缓存 10 分钟，可手动刷新。当前版本：<?php echo esc_html(self::VERSION); ?></p>
                </div>
                <div class="wplco-hero-score <?php echo esc_attr($diagnosis['score_class']); ?>">
                    <span><?php echo esc_html($diagnosis['score']); ?></span>
                    <small>健康评分</small>
                </div>
            </div>
            <div class="wplco-toolbar">
                <div class="wplco-toolbar-actions">
                    <?php $this->action_button('refresh_report', '刷新诊断报告', '刷新诊断报告会重新统计数据库，数据量很大时可能需要等待，确定继续？'); ?>
                    <?php $this->action_button('export_report', '导出 JSON 诊断报告', '导出当前诊断报告？'); ?>
                </div>
                <div class="wplco-nav" role="tablist" aria-label="大站优化模块">
                    <button type="button" class="is-active" data-wplco-tab="overview">概览</button>
                    <button type="button" data-wplco-tab="database">数据库</button>
                    <button type="button" data-wplco-tab="collector">采集站</button>
                    <button type="button" data-wplco-tab="frontend">前台优化</button>
                    <button type="button" data-wplco-tab="logs">日志</button>
                    <button type="button" data-wplco-tab="settings">设置</button>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
            <?php endif; ?>

            <style>
                .wplco-wrap{--wplco-bg:#f6f7fb;--wplco-card:#fff;--wplco-border:#e3e7ef;--wplco-text:#1d2327;--wplco-muted:#667085;--wplco-primary:#2563eb;--wplco-primary-dark:#1d4ed8;--wplco-shadow:0 8px 24px rgba(15,23,42,.06);max-width:1480px}.wplco-wrap *{box-sizing:border-box}.wplco-hero{display:flex;justify-content:space-between;gap:22px;align-items:center;margin:18px 0 14px;padding:24px;border:1px solid #dbe5ff;border-radius:18px;background:linear-gradient(135deg,#eef4ff 0%,#fff 55%,#f8fbff 100%);box-shadow:var(--wplco-shadow)}.wplco-hero h1{margin:0 0 8px;font-size:26px;font-weight:700;color:#0f172a}.wplco-hero p{max-width:900px;margin:0 0 6px;color:#475467;font-size:14px}.wplco-hero-meta{font-size:12px!important;color:#667085!important}.wplco-hero-score{min-width:112px;text-align:center;border-radius:16px;background:#fff;border:1px solid var(--wplco-border);padding:14px 18px;box-shadow:0 4px 14px rgba(15,23,42,.05)}.wplco-hero-score span{display:block;font-size:38px;line-height:1;font-weight:800}.wplco-hero-score small{display:block;margin-top:5px;color:#667085}.wplco-toolbar{position:sticky;top:32px;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 16px;padding:10px 12px;border:1px solid var(--wplco-border);border-radius:14px;background:rgba(255,255,255,.92);box-shadow:var(--wplco-shadow);backdrop-filter:blur(8px)}.wplco-toolbar-actions,.wplco-nav{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.wplco-toolbar form,.wplco-actions form{display:inline-block;margin:0}.wplco-toolbar .button,.wplco-actions .button{border-radius:8px}.wplco-nav button{border:0;border-radius:999px;background:#eef2ff;color:#1e40af;padding:7px 13px;font-size:12px;cursor:pointer;font-weight:600}.wplco-nav button:hover{background:#dbeafe;color:#1d4ed8}.wplco-nav button.is-active{background:var(--wplco-primary);color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.24)}.wplco-tab-hidden{display:none!important}.wplco-empty-group{display:none!important}.wplco-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px}.wplco-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;margin-top:16px}.wplco-card{position:relative;background:var(--wplco-card);border:1px solid var(--wplco-border);border-radius:16px;padding:18px;box-shadow:var(--wplco-shadow);overflow:hidden}.wplco-card:hover{border-color:#cbd5e1}.wplco-card h2{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:-18px -18px 14px;padding:15px 18px;border-bottom:1px solid #edf0f5;background:linear-gradient(180deg,#fff,#fafbff);font-size:16px}.wplco-card h3{color:#1f2937}.wplco-card-body{transition:opacity .16s ease}.wplco-card.is-collapsed .wplco-card-body{display:none}.wplco-toggle{margin-left:auto;border:1px solid #d0d7de;border-radius:8px;background:#fff;color:#475467;font-size:12px;padding:3px 8px;cursor:pointer}.wplco-toggle:hover{background:#f8fafc;color:#1d2327}.wplco-card.is-collapsed .wplco-toggle:after{content:' 展开'}.wplco-card:not(.is-collapsed) .wplco-toggle:after{content:' 收起'}.wplco-stat{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef1f5;padding:8px 0}.wplco-stat span{color:#475467}.wplco-stat strong{font-size:16px}.wplco-danger{color:#b42318}.wplco-ok{color:#067647}.wplco-warn{color:#b54708}.wplco-table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden}.wplco-table th,.wplco-table td{padding:9px 10px;border-bottom:1px solid #eef1f5;text-align:left;vertical-align:top}.wplco-table th{background:#f8fafc;color:#475467;font-weight:600}.wplco-table tr:hover td{background:#fbfdff}.wplco-table code{word-break:break-all}.wplco-small{color:#667085;font-size:12px}.wplco-settings label{display:block;margin:10px 0;padding:8px 10px;border-radius:10px;background:#fbfcff;border:1px solid #eef1f5}.wplco-settings h3{margin-top:18px;padding-top:8px;border-top:1px solid #edf0f5}.wplco-number{width:90px}.wplco-score{font-size:36px;font-weight:800;margin:6px 0}.wplco-pill{display:inline-block;padding:4px 9px;border-radius:999px;background:#f1f5f9;color:#475467;margin-left:6px;font-size:12px}.wplco-list{margin-left:18px;list-style:disc}.wplco-list li{margin-bottom:6px}.wplco-priority{border-left:5px solid #d92d20}.wplco-priority.medium{border-left-color:#f79009}.wplco-priority.low{border-left-color:#12b76a}.wplco-step{display:grid;grid-template-columns:86px 1fr;gap:10px;padding:11px 0;border-bottom:1px solid #eef1f5}.wplco-badge{display:inline-block;text-align:center;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}.wplco-risk-low{background:#ecfdf3;color:#067647}.wplco-risk-medium{background:#fffaeb;color:#b54708}.wplco-risk-high{background:#fef3f2;color:#b42318}.wplco-env{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.wplco-env div{background:#fbfcff;border:1px solid #eef1f5;border-radius:12px;padding:10px}.wplco-metric{font-size:26px;font-weight:800;margin-top:4px}.wplco-actions .button{margin:4px 6px 4px 0}.wplco-card .wplco-card{box-shadow:none;border-radius:12px}.wplco-card .wplco-card h3{margin-top:0}@media (max-width:782px){.wplco-hero,.wplco-toolbar{display:block}.wplco-hero-score{margin-top:14px}.wplco-toolbar{position:static}.wplco-nav{margin-top:10px}.wplco-grid,.wplco-two{grid-template-columns:1fr}.wplco-table{display:block;overflow-x:auto}.wplco-card h2{font-size:15px}.wplco-step{grid-template-columns:1fr}}
            </style>

            <div class="wplco-grid">
                <div class="wplco-card wplco-priority <?php echo esc_attr($diagnosis['level_class']); ?>">
                    <h2>性能诊断评分</h2>
                    <div class="wplco-score <?php echo esc_attr($diagnosis['score_class']); ?>"><?php echo esc_html($diagnosis['score']); ?>/100</div>
                    <p><strong><?php echo esc_html($diagnosis['level']); ?></strong><span class="wplco-pill"><?php echo esc_html($diagnosis['summary']); ?></span></p>
                    <?php if (!empty($diagnosis['recommendations'])): ?>
                        <ul class="wplco-list">
                            <?php foreach ($diagnosis['recommendations'] as $item): ?>
                                <li><?php echo esc_html($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="wplco-card">
                    <h2>数据库体检</h2>
                    <?php foreach ($stats as $label => $item): ?>
                        <div class="wplco-stat"><span><?php echo esc_html($label); ?></span><strong class="<?php echo esc_attr($item['class']); ?>"><?php echo esc_html(number_format_i18n($item['value'])); ?></strong></div>
                    <?php endforeach; ?>
                </div>

                <div class="wplco-card wplco-actions">
                    <h2>分批清理</h2>
                    <p class="wplco-small">每次最多处理 <?php echo esc_html(number_format_i18n($this->batch_size())); ?> 条，避免一次性操作卡死数据库。建议先备份数据库。</p>
                    <?php $this->action_button('clean_revisions', '清理修订版本', '清理本批次 revision？'); ?>
                    <?php $this->action_button('clean_autodrafts', '清理自动草稿', '清理本批次 auto-draft？'); ?>
                    <?php $this->action_button('clean_trash', '清理回收站文章', '清理本批次回收站内容？'); ?>
                    <?php $this->action_button('clean_orphan_postmeta', '清理孤儿 postmeta', '清理没有对应文章的 postmeta？'); ?>
                    <?php $this->action_button('clean_orphan_term_relationships', '清理孤儿分类关系', '清理没有对应文章的 term relationships？'); ?>
                    <?php $this->action_button('clean_expired_transients', '清理过期 transient', '清理过期 transient 缓存？'); ?>
                </div>
            </div>

            <div class="wplco-grid">
                <div class="wplco-card">
                    <h2>数据表大小 TOP</h2>
                    <table class="wplco-table">
                        <thead><tr><th>表</th><th>行数</th><th>大小</th></tr></thead>
                        <tbody>
                        <?php foreach ($table_sizes as $row): ?>
                            <tr><td><?php echo esc_html($row['table']); ?></td><td><?php echo esc_html(number_format_i18n($row['rows'])); ?></td><td><?php echo esc_html($this->format_bytes($row['bytes'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="wplco-small">如果 wp_postmeta 明显大于 wp_posts，通常说明插件/主题写入了大量自定义字段，是大站变慢的重点排查对象。</p>
                </div>

                <div class="wplco-card">
                    <h2>postmeta 热点字段 TOP</h2>
                    <table class="wplco-table">
                        <thead><tr><th>meta_key</th><th>数量</th></tr></thead>
                        <tbody>
                        <?php foreach ($meta_hotspots as $row): ?>
                            <tr><td><code><?php echo esc_html($row['meta_key']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="wplco-small">数量异常大的 meta_key，可能来自 SEO、采集、编辑器或统计插件。不要盲删，先判断来源。</p>
                </div>

                <div class="wplco-card">
                    <h2>autoload 体积 TOP</h2>
                    <table class="wplco-table">
                        <thead><tr><th>option_name</th><th>大小</th></tr></thead>
                        <tbody>
                        <?php foreach ($autoload_heavy as $row): ?>
                            <tr><td><code><?php echo esc_html($row['option_name']); ?></code></td><td><?php echo esc_html($this->format_bytes($row['bytes'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="wplco-small">autoload 会在几乎每次请求加载。单项很大或总量很大，会直接拖慢前后台。</p>
                </div>
            </div>

            <div class="wplco-grid">
                <div class="wplco-card">
                    <h2>安全优化向导</h2>
                    <p class="wplco-small">按下面顺序处理，风险从低到高，尽量避免一上来就做重操作。</p>
                    <?php foreach ($wizard_steps as $step): ?>
                        <div class="wplco-step">
                            <div><span class="wplco-badge wplco-risk-<?php echo esc_attr($step['risk']); ?>"><?php echo esc_html($step['risk_label']); ?></span></div>
                            <div>
                                <strong><?php echo esc_html($step['title']); ?></strong>
                                <p style="margin:4px 0"><?php echo esc_html($step['detail']); ?></p>
                                <?php if (!empty($step['action'])): ?><p class="wplco-small">建议操作：<?php echo esc_html($step['action']); ?></p><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="wplco-card">
                    <h2>缓存与环境检查</h2>
                    <div class="wplco-env">
                        <?php foreach ($environment as $item): ?>
                            <div>
                                <strong><?php echo esc_html($item['label']); ?></strong><br>
                                <span class="<?php echo esc_attr($item['class']); ?>"><?php echo esc_html($item['value']); ?></span>
                                <?php if (!empty($item['hint'])): ?><p class="wplco-small" style="margin:4px 0 0"><?php echo esc_html($item['hint']); ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>推荐数据库索引</h2>
                <p class="wplco-small">索引可改善文章列表、分类页、按状态/时间排序、postmeta 查询。添加索引会短暂占用数据库资源，建议低峰期执行。</p>
                <table class="wplco-table">
                    <thead><tr><th>数据表</th><th>索引名</th><th>字段</th><th>状态</th></tr></thead>
                    <tbody>
                    <?php foreach ($indexes as $idx): ?>
                        <tr>
                            <td><?php echo esc_html($idx['table']); ?></td>
                            <td><?php echo esc_html($idx['name']); ?></td>
                            <td><code><?php echo esc_html($idx['columns']); ?></code></td>
                            <td><?php echo $idx['exists'] ? '<span class="wplco-ok">已存在</span>' : '<span class="wplco-warn">缺失</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><?php $this->action_button('add_indexes', '添加缺失索引', '确定添加缺失索引？建议先备份数据库，并在访问低峰期执行。'); ?></p>
            </div>

            <div class="wplco-two">
                <div class="wplco-card">
                    <h2>采集站专项体检</h2>
                    <div class="wplco-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-top:0">
                        <?php foreach ($collector_stats as $item): ?>
                            <div style="background:#f6f7f7;border-radius:6px;padding:10px">
                                <span class="wplco-small"><?php echo esc_html($item['label']); ?></span>
                                <div class="wplco-metric <?php echo esc_attr($item['class']); ?>"><?php echo esc_html(number_format_i18n($item['value'])); ?></div>
                                <?php if (!empty($item['hint'])): ?><p class="wplco-small" style="margin:4px 0 0"><?php echo esc_html($item['hint']); ?></p><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="wplco-small">这里主要检查采集站常见脏数据。默认不会删除已发布文章；“采集失败草稿”只清理草稿/自动草稿/待审核里的空内容或疑似失败内容。</p>
                    <p><?php $this->action_button('clean_failed_drafts', '清理采集失败草稿', '只会删除草稿/自动草稿/待审核中的空内容或疑似采集失败文章，不会删除已发布文章。确定继续？'); ?></p>
                </div>

                <div class="wplco-card">
                    <h2>重复标题 TOP</h2>
                    <table class="wplco-table">
                        <thead><tr><th>标题</th><th>重复数</th></tr></thead>
                        <tbody>
                        <?php if (empty($duplicate_titles)): ?>
                            <tr><td colspan="2">暂未发现明显重复标题。</td></tr>
                        <?php else: ?>
                            <?php foreach ($duplicate_titles as $row): ?>
                                <tr><td><?php echo esc_html($row['title']); ?></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="wplco-small">重复标题不一定都是垃圾，但采集站如果重复数很高，通常说明去重规则需要加强。</p>
                </div>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>重复文章处理工具</h2>
                <p class="wplco-small">安全策略：只处理草稿、自动草稿、待审核里的重复标题；每组保留最早的一篇，其余移动到回收站。不会处理已发布文章，不会永久删除。</p>
                <table class="wplco-table">
                    <thead><tr><th>重复标题</th><th>草稿/待审核数量</th><th>将移入回收站</th><th>文章 ID</th></tr></thead>
                    <tbody>
                    <?php if (empty($duplicate_draft_groups)): ?>
                        <tr><td colspan="4">暂未发现可安全处理的重复草稿标题。</td></tr>
                    <?php else: ?>
                        <?php foreach ($duplicate_draft_groups as $group): ?>
                            <tr>
                                <td><?php echo esc_html($group['title']); ?></td>
                                <td><?php echo esc_html(number_format_i18n($group['count'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($group['trash_count'])); ?></td>
                                <td><code><?php echo esc_html(implode(', ', $group['ids'])); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <p><?php $this->action_button('trash_duplicate_draft_titles', '重复草稿移入回收站', '只会把草稿/自动草稿/待审核中的重复标题文章移入回收站，每组保留最早一篇，不会影响已发布文章。确定继续？'); ?></p>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>已发布重复文章审查器</h2>
                <p class="wplco-small">只展示已发布文章中的重复标题，不自动移动、不删除。请人工打开编辑链接判断是否需要合并、改标题、301 或移入回收站。</p>
                <?php if (empty($published_duplicate_groups)): ?>
                    <p>暂未发现已发布重复标题文章。</p>
                <?php else: ?>
                    <?php foreach ($published_duplicate_groups as $group): ?>
                        <h3 style="margin-top:18px"><?php echo esc_html($group['title']); ?> <span class="wplco-pill"><?php echo esc_html(number_format_i18n(count($group['posts']))); ?> 篇</span></h3>
                        <table class="wplco-table">
                            <thead><tr><th>ID</th><th>发布时间</th><th>字数</th><th>缩略图</th><th>查看</th><th>编辑</th></tr></thead>
                            <tbody>
                            <?php foreach ($group['posts'] as $post): ?>
                                <tr>
                                    <td><code><?php echo esc_html($post['id']); ?></code></td>
                                    <td><?php echo esc_html($post['date']); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($post['chars'])); ?></td>
                                    <td><?php echo $post['thumbnail'] ? '<span class="wplco-ok">有</span>' : '<span class="wplco-warn">无</span>'; ?></td>
                                    <td><a href="<?php echo esc_url($post['view_url']); ?>" target="_blank" rel="noopener">查看</a></td>
                                    <td><a href="<?php echo esc_url($post['edit_url']); ?>" target="_blank" rel="noopener">编辑</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="wplco-small">建议处理原则：保留内容最长/质量最好/收录最好的文章；其他文章优先改标题或设置跳转，不建议直接批量删除。</p>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>前台性能与缓存检测</h2>
                <div class="wplco-env">
                    <?php foreach ($frontend_report['checks'] as $item): ?>
                        <div>
                            <strong><?php echo esc_html($item['label']); ?></strong><br>
                            <span class="<?php echo esc_attr($item['class']); ?>"><?php echo esc_html($item['value']); ?></span>
                            <?php if (!empty($item['hint'])): ?><p class="wplco-small" style="margin:4px 0 0"><?php echo esc_html($item['hint']); ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($frontend_report['recommendations'])): ?>
                    <h3>前台优化建议</h3>
                    <ul class="wplco-list">
                        <?php foreach ($frontend_report['recommendations'] as $rec): ?>
                            <li><?php echo esc_html($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>数据库慢查询风险分析</h2>
                <p class="wplco-small">只读分析：不读取慢查询日志、不修改数据库。用于定位哪些数据分布最可能导致 WP 查询变慢。</p>
                <?php if (!empty($slow_risk_report['recommendations'])): ?>
                    <h3>风险建议</h3>
                    <ul class="wplco-list">
                        <?php foreach ($slow_risk_report['recommendations'] as $rec): ?>
                            <li><?php echo esc_html($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="wplco-grid">
                    <div class="wplco-card" style="padding:10px">
                        <h3>post_type 分布</h3>
                        <table class="wplco-table"><thead><tr><th>post_type</th><th>数量</th></tr></thead><tbody>
                        <?php foreach ($slow_risk_report['post_types'] as $row): ?><tr><td><code><?php echo esc_html($row['name']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <div class="wplco-card" style="padding:10px">
                        <h3>post_status 分布</h3>
                        <table class="wplco-table"><thead><tr><th>status</th><th>数量</th></tr></thead><tbody>
                        <?php foreach ($slow_risk_report['post_statuses'] as $row): ?><tr><td><code><?php echo esc_html($row['name']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <div class="wplco-card" style="padding:10px">
                        <h3>taxonomy 关系 TOP</h3>
                        <table class="wplco-table"><thead><tr><th>taxonomy</th><th>关系数</th></tr></thead><tbody>
                        <?php foreach ($slow_risk_report['taxonomies'] as $row): ?><tr><td><code><?php echo esc_html($row['name']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <div class="wplco-card" style="padding:10px">
                        <h3>autoload 总体积</h3>
                        <div class="wplco-score <?php echo esc_attr($slow_risk_report['autoload_class']); ?>"><?php echo esc_html($this->format_bytes($slow_risk_report['autoload_bytes'])); ?></div>
                        <p class="wplco-small">autoload 数据会在多数请求中加载，过大时会拖慢前后台。</p>
                    </div>
                </div>
                <h3>低选择性 meta_key 风险</h3>
                <table class="wplco-table">
                    <thead><tr><th>meta_key</th><th>总数</th><th>不同值数量</th><th>风险</th></tr></thead>
                    <tbody>
                    <?php if (empty($slow_risk_report['meta_selectivity'])): ?>
                        <tr><td colspan="4">暂无可展示数据。</td></tr>
                    <?php else: ?>
                        <?php foreach ($slow_risk_report['meta_selectivity'] as $row): ?>
                            <tr><td><code><?php echo esc_html($row['meta_key']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['total'])); ?></td><td><?php echo esc_html(number_format_i18n($row['distinct_values'])); ?></td><td><span class="<?php echo esc_attr($row['class']); ?>"><?php echo esc_html($row['risk']); ?></span></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>数据库维护日志</h2>
                <p class="wplco-small">记录最近 100 条维护操作，包括清理、加索引、移动重复草稿等。日志保存在 WordPress options 中。</p>
                <table class="wplco-table">
                    <thead><tr><th>时间</th><th>管理员</th><th>操作</th><th>结果</th></tr></thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="4">暂无维护日志。</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['time']); ?></td>
                                <td><?php echo esc_html($log['user']); ?></td>
                                <td><code><?php echo esc_html($log['action']); ?></code></td>
                                <td><span class="<?php echo esc_attr($log['class']); ?>"><?php echo esc_html($log['message']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <p><?php $this->action_button('clear_logs', '清空维护日志', '确定清空维护日志？这不会影响数据库内容，只删除操作记录。'); ?></p>
            </div>

            <div class="wplco-card wplco-settings" style="margin-top:16px">
                <h2>设置</h2>
                <form method="post">
                    <?php wp_nonce_field('wplco_action', 'wplco_nonce'); ?>
                    <input type="hidden" name="wplco_action" value="save_settings">
                    <label>每批清理数量：<input class="wplco-number" type="number" name="batch_size" min="50" max="5000" value="<?php echo esc_attr($settings['batch_size']); ?>"> <span class="wplco-small">建议 200-1000；数据量很大时不要太高。</span></label>
                    <label>每篇文章保留修订版本数：<input class="wplco-number" type="number" name="revision_limit" min="0" max="50" value="<?php echo esc_attr($settings['revision_limit']); ?>"></label>
                    <label>短内容阈值：<input class="wplco-number" type="number" name="short_content_chars" min="20" max="1000" value="<?php echo esc_attr($settings['short_content_chars']); ?>"> 字 <span class="wplco-small">用于采集站体检，不会自动删除已发布短文章。</span></label>
                    <label><input type="checkbox" name="disable_revisions" value="1" <?php checked($settings['disable_revisions']); ?>> 禁用新的文章修订版本</label>
                    <label><input type="checkbox" name="admin_list_light" value="1" <?php checked($settings['admin_list_light']); ?>> 后台文章/页面列表轻量化，只保留核心列</label>
                    <hr>
                    <h3>后台大列表快速模式</h3>
                    <label><input type="checkbox" name="admin_fast_mode" value="1" <?php checked($settings['admin_fast_mode']); ?>> 开启后台文章列表快速模式</label>
                    <label>快速模式每页显示：<input class="wplco-number" type="number" name="admin_fast_per_page" min="10" max="200" value="<?php echo esc_attr($settings['admin_fast_per_page']); ?>"> 条 <span class="wplco-small">文章很多时建议 20-50。</span></label>
                    <label><input type="checkbox" name="admin_fast_title_search" value="1" <?php checked($settings['admin_fast_title_search']); ?>> 后台文章搜索优先按标题搜索，减少全文 LIKE 压力</label>
                    <label><input type="checkbox" name="admin_fast_disable_months" value="1" <?php checked($settings['admin_fast_disable_months']); ?>> 禁用文章列表月份下拉统计</label>
                    <label><input type="checkbox" name="admin_fast_disable_found_rows" value="1" <?php checked($settings['admin_fast_disable_found_rows']); ?>> 禁用精确总数统计 <span class="wplco-small">速度更快，但分页总数可能不准确；默认关闭。</span></label>
                    <hr>
                    <h3>前台轻量优化</h3>
                    <label><input type="checkbox" name="frontend_disable_emoji" value="1" <?php checked($settings['frontend_disable_emoji']); ?>> 禁用 WordPress Emoji 脚本</label>
                    <label><input type="checkbox" name="frontend_disable_embeds" value="1" <?php checked($settings['frontend_disable_embeds']); ?>> 禁用 oEmbed 发现与嵌入脚本 <span class="wplco-small">如果文章需要嵌入 YouTube/推文等，不建议开启。</span></label>
                    <label><input type="checkbox" name="frontend_disable_dashicons" value="1" <?php checked($settings['frontend_disable_dashicons']); ?>> 访客前台禁用 Dashicons</label>
                    <label><input type="checkbox" name="frontend_disable_generator" value="1" <?php checked($settings['frontend_disable_generator']); ?>> 移除 WordPress generator 版本标签</label>
                    <hr>
                    <label><input type="checkbox" name="cron_enabled" value="1" <?php checked($settings['cron_enabled']); ?>> 开启每日自动维护</label>
                    <label><input type="checkbox" name="cron_clean_revisions" value="1" <?php checked($settings['cron_clean_revisions']); ?>> 自动清理修订版本</label>
                    <label><input type="checkbox" name="cron_clean_autodrafts" value="1" <?php checked($settings['cron_clean_autodrafts']); ?>> 自动清理自动草稿</label>
                    <label><input type="checkbox" name="cron_clean_trash" value="1" <?php checked($settings['cron_clean_trash']); ?>> 自动清理回收站文章</label>
                    <label><input type="checkbox" name="cron_clean_orphan_postmeta" value="1" <?php checked($settings['cron_clean_orphan_postmeta']); ?>> 自动清理孤儿 postmeta</label>
                    <label><input type="checkbox" name="cron_clean_expired_transients" value="1" <?php checked($settings['cron_clean_expired_transients']); ?>> 自动清理过期 transient</label>
                    <?php submit_button('保存设置'); ?>
                </form>
            </div>
            <script>
            (function(){
                var root=document.querySelector('.wplco-wrap');
                if(!root){return;}
                var tabMap={
                    '性能诊断评分':'overview','数据库体检':'overview','分批清理':'overview','安全优化向导':'overview','缓存与环境检查':'overview',
                    '数据表大小 TOP':'database','postmeta 热点字段 TOP':'database','autoload 体积 TOP':'database','推荐数据库索引':'database','数据库慢查询风险分析':'database',
                    '采集站专项体检':'collector','重复标题 TOP':'collector','重复文章处理工具':'collector','已发布重复文章审查器':'collector',
                    '前台性能与缓存检测':'frontend','数据库维护日志':'logs','设置':'settings'
                };
                var cards=root.querySelectorAll(':scope > .wplco-card, :scope > .wplco-grid > .wplco-card, :scope > .wplco-two > .wplco-card');
                var collapseByDefault=['推荐数据库索引','重复文章处理工具','已发布重复文章审查器','数据库慢查询风险分析'];
                cards.forEach(function(card){
                    var h2=card.querySelector(':scope > h2');
                    if(!h2 || card.classList.contains('wplco-js-ready')){return;}
                    card.classList.add('wplco-js-ready');
                    var body=document.createElement('div');
                    body.className='wplco-card-body';
                    var node=h2.nextSibling;
                    while(node){
                        var next=node.nextSibling;
                        body.appendChild(node);
                        node=next;
                    }
                    card.appendChild(body);
                    var btn=document.createElement('button');
                    btn.type='button';
                    btn.className='wplco-toggle';
                    btn.setAttribute('aria-label','折叠或展开模块');
                    h2.appendChild(btn);
                    var title=h2.textContent.replace(/\s*(展开|收起)\s*$/,'').trim();
                    card.setAttribute('data-wplco-panel', tabMap[title] || 'overview');
                    if(collapseByDefault.indexOf(title)!==-1){card.classList.add('is-collapsed');}
                    btn.addEventListener('click',function(e){e.preventDefault();card.classList.toggle('is-collapsed');});
                });
                function updateGroupVisibility(){
                    root.querySelectorAll(':scope > .wplco-grid, :scope > .wplco-two').forEach(function(group){
                        var visible=Array.prototype.some.call(group.children,function(child){return !child.classList.contains('wplco-tab-hidden');});
                        group.classList.toggle('wplco-empty-group',!visible);
                    });
                }
                function setTab(tab){
                    root.querySelectorAll('[data-wplco-panel]').forEach(function(card){
                        card.classList.toggle('wplco-tab-hidden',card.getAttribute('data-wplco-panel')!==tab);
                    });
                    root.querySelectorAll('[data-wplco-tab]').forEach(function(btn){
                        btn.classList.toggle('is-active',btn.getAttribute('data-wplco-tab')===tab);
                        btn.setAttribute('aria-selected',btn.getAttribute('data-wplco-tab')===tab?'true':'false');
                    });
                    updateGroupVisibility();
                    try{window.localStorage.setItem('wplco_active_tab',tab);}catch(e){}
                }
                root.querySelectorAll('[data-wplco-tab]').forEach(function(btn){
                    btn.addEventListener('click',function(){setTab(btn.getAttribute('data-wplco-tab'));});
                });
                var initial='overview';
                try{initial=window.localStorage.getItem('wplco_active_tab')||initial;}catch(e){}
                if(!root.querySelector('[data-wplco-tab="'+initial+'"]')){initial='overview';}
                setTab(initial);
            })();
            </script>
        </div>
        <?php
    }

    private function action_button($action, $label, $confirm) {
        ?>
        <form method="post" onsubmit="return confirm('<?php echo esc_js($confirm); ?>');">
            <?php wp_nonce_field('wplco_action', 'wplco_nonce'); ?>
            <input type="hidden" name="wplco_action" value="<?php echo esc_attr($action); ?>">
            <?php submit_button($label, 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    public function frontend_light_optimizations() {
        $settings = $this->settings();

        if (!empty($settings['frontend_disable_emoji'])) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        }

        if (!empty($settings['frontend_disable_embeds'])) {
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
            add_action('wp_footer', array($this, 'dequeue_embed_script'), 20);
        }

        if (!empty($settings['frontend_disable_dashicons'])) {
            add_action('wp_enqueue_scripts', array($this, 'dequeue_dashicons_for_guests'), 20);
        }

        if (!empty($settings['frontend_disable_generator'])) {
            remove_action('wp_head', 'wp_generator');
        }
    }

    public function dequeue_embed_script() {
        wp_deregister_script('wp-embed');
    }

    public function dequeue_dashicons_for_guests() {
        if (!is_user_logged_in()) {
            wp_dequeue_style('dashicons');
            wp_deregister_style('dashicons');
        }
    }

    private function collect_frontend_report() {
        $settings = $this->settings();
        $checks = array();
        $recommendations = array();

        $has_object_cache = wp_using_ext_object_cache();
        $checks[] = array('label' => '对象缓存', 'value' => $has_object_cache ? '已启用' : '未启用', 'class' => $has_object_cache ? 'wplco-ok' : 'wplco-warn', 'hint' => $has_object_cache ? '对分类页、文章页和后台都有帮助。' : '建议安装并启用 Redis Object Cache。');
        if (!$has_object_cache) {
            $recommendations[] = '优先启用 Redis/Object Cache，文章和 postmeta 很多时收益明显。';
        }

        $page_cache = defined('WP_CACHE') && WP_CACHE;
        $checks[] = array('label' => '页面缓存 WP_CACHE', 'value' => $page_cache ? '已开启' : '未开启/未定义', 'class' => $page_cache ? 'wplco-ok' : 'wplco-warn', 'hint' => $page_cache ? '页面缓存插件通常已接管前台缓存。' : '建议开启页面缓存插件，前台访问会更稳。');
        if (!$page_cache) {
            $recommendations[] = '前台慢时优先配置页面缓存/CDN，数据库优化不能替代页面缓存。';
        }

        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $checks[] = array('label' => 'WP-Cron', 'value' => $cron_disabled ? '已禁用内置触发' : '访问触发中', 'class' => $cron_disabled ? 'wplco-ok' : 'wplco-warn', 'hint' => $cron_disabled ? '建议确认服务器 crontab 已定时调用 wp-cron.php。' : '流量大时建议禁用访问触发，改服务器定时任务。');
        if (!$cron_disabled) {
            $recommendations[] = '高流量站建议设置 DISABLE_WP_CRON，并用服务器计划任务定时调用 wp-cron.php。';
        }

        $autosave = defined('AUTOSAVE_INTERVAL') ? intval(AUTOSAVE_INTERVAL) : 60;
        $checks[] = array('label' => '自动保存间隔', 'value' => $autosave . ' 秒', 'class' => $autosave >= 120 ? 'wplco-ok' : 'wplco-warn', 'hint' => '后台编辑频繁时可适当调大。');

        $revisions = defined('WP_POST_REVISIONS') ? WP_POST_REVISIONS : '默认';
        $checks[] = array('label' => 'WP_POST_REVISIONS', 'value' => is_bool($revisions) ? ($revisions ? 'true' : 'false') : strval($revisions), 'class' => 'wplco-ok', 'hint' => '本插件也会通过过滤器限制 revision。');

        $checks[] = array('label' => 'Emoji 脚本', 'value' => empty($settings['frontend_disable_emoji']) ? '未禁用' : '已禁用', 'class' => empty($settings['frontend_disable_emoji']) ? 'wplco-warn' : 'wplco-ok', 'hint' => '通常可安全禁用。');
        $checks[] = array('label' => 'oEmbed 脚本', 'value' => empty($settings['frontend_disable_embeds']) ? '保留' : '已禁用', 'class' => empty($settings['frontend_disable_embeds']) ? 'wplco-ok' : 'wplco-warn', 'hint' => '依赖文章嵌入功能的网站不要禁用。');
        $checks[] = array('label' => '访客 Dashicons', 'value' => empty($settings['frontend_disable_dashicons']) ? '保留' : '已禁用', 'class' => empty($settings['frontend_disable_dashicons']) ? 'wplco-warn' : 'wplco-ok', 'hint' => '多数前台访客不需要加载。');

        $recommendations[] = '如果分类页/标签页慢，优先确认主题是否在循环中额外查询 meta、相关文章、浏览量或缩略图。';
        $recommendations[] = '如果文章页慢，建议用 Query Monitor 查看最慢 SQL，再决定是否添加专用索引或关闭相关插件功能。';

        return array('checks' => $checks, 'recommendations' => array_slice($recommendations, 0, 6));
    }


    private function collect_slow_risk_report() {
        global $wpdb;
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $term_relationships = $wpdb->term_relationships;
        $term_taxonomy = $wpdb->term_taxonomy;
        $options = $wpdb->options;

        $post_types = $this->name_count_rows($wpdb->get_results("SELECT post_type AS name, COUNT(*) AS total FROM {$posts} GROUP BY post_type ORDER BY total DESC LIMIT 12", ARRAY_A));
        $post_statuses = $this->name_count_rows($wpdb->get_results("SELECT post_status AS name, COUNT(*) AS total FROM {$posts} GROUP BY post_status ORDER BY total DESC LIMIT 12", ARRAY_A));
        $taxonomies = $this->name_count_rows($wpdb->get_results("SELECT tt.taxonomy AS name, COUNT(*) AS total FROM {$term_relationships} tr INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id GROUP BY tt.taxonomy ORDER BY total DESC LIMIT 12", ARRAY_A));
        $autoload_bytes = intval($wpdb->get_var("SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$options} WHERE autoload IN ('yes','on','auto-on','auto')"));

        $hot_keys = $wpdb->get_col("SELECT meta_key FROM {$postmeta} WHERE meta_key <> '' GROUP BY meta_key HAVING COUNT(*) > 1000 ORDER BY COUNT(*) DESC LIMIT 8");
        $meta_selectivity = array();
        foreach ((array) $hot_keys as $key) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT meta_value) AS distinct_values FROM {$postmeta} WHERE meta_key=%s", $key), ARRAY_A);
            if (!$row) {
                continue;
            }
            $total = max(0, intval($row['total']));
            $distinct = max(0, intval($row['distinct_values']));
            $ratio = $total > 0 ? ($distinct / $total) : 1;
            $risk = '低';
            $class = 'wplco-ok';
            if ($total > 10000 && $ratio < 0.05) {
                $risk = '高：大量重复值，按此字段筛选可能很慢';
                $class = 'wplco-danger';
            } elseif ($total > 5000 && $ratio < 0.2) {
                $risk = '中：选择性偏低';
                $class = 'wplco-warn';
            }
            $meta_selectivity[] = array(
                'meta_key' => $key,
                'total' => $total,
                'distinct_values' => $distinct,
                'risk' => $risk,
                'class' => $class,
            );
        }

        $recommendations = array();
        $largest_type = !empty($post_types) ? $post_types[0] : null;
        if ($largest_type && $largest_type['count'] > 100000) {
            $recommendations[] = 'post_type `' . $largest_type['name'] . '` 数据量超过 10 万，相关列表页/归档页需要页面缓存和对象缓存配合。';
        }
        foreach ($post_statuses as $row) {
            if (in_array($row['name'], array('revision', 'auto-draft', 'trash'), true) && $row['count'] > 1000) {
                $recommendations[] = '状态 `' . $row['name'] . '` 数量较多，建议使用分批清理降低 wp_posts 体积。';
            }
        }
        foreach ($taxonomies as $row) {
            if ($row['count'] > 100000) {
                $recommendations[] = 'taxonomy `' . $row['name'] . '` 关系数超过 10 万，分类/标签页建议加页面缓存，避免实时复杂查询。';
            }
        }
        if ($autoload_bytes > 5 * 1024 * 1024) {
            $recommendations[] = 'autoload 总体积超过 5MB，建议排查 autoload 大对象并关闭不必要插件的自动加载配置。';
        } elseif ($autoload_bytes > 1024 * 1024) {
            $recommendations[] = 'autoload 总体积超过 1MB，建议关注 wp_options 中的大 autoload 项。';
        }
        foreach ($meta_selectivity as $row) {
            if ($row['class'] === 'wplco-danger') {
                $recommendations[] = 'meta_key `' . $row['meta_key'] . '` 低选择性且数据量大，如果主题/插件经常按它筛选，可能造成慢查询。';
                break;
            }
        }
        if (empty($recommendations)) {
            $recommendations[] = '当前数据分布未发现特别明显的慢查询风险；若仍然慢，建议打开 Query Monitor 查看具体 SQL。';
        }

        return array(
            'post_types' => $post_types,
            'post_statuses' => $post_statuses,
            'taxonomies' => $taxonomies,
            'autoload_bytes' => $autoload_bytes,
            'autoload_class' => $autoload_bytes > 5 * 1024 * 1024 ? 'wplco-danger' : ($autoload_bytes > 1024 * 1024 ? 'wplco-warn' : 'wplco-ok'),
            'meta_selectivity' => $meta_selectivity,
            'recommendations' => array_slice($recommendations, 0, 8),
        );
    }

    private function name_count_rows($rows) {
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'name' => isset($row['name']) && $row['name'] !== '' ? $row['name'] : '(empty)',
                'count' => intval($row['total']),
            );
        }
        return $out;
    }


    private function export_report() {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足。');
        }

        $report = $this->get_diagnostic_report();
        $payload = array(
            'plugin' => array(
                'name' => 'WP Large Content Optimizer',
                'version' => self::VERSION,
                'exported_at' => current_time('mysql'),
                'site_url' => home_url('/'),
            ),
            'report' => $report,
            'settings' => $this->settings(),
            'logs' => $this->get_logs(),
        );

        $this->add_log('export_report', array('type' => 'success', 'message' => '已导出 JSON 诊断报告。'));

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wplco-diagnostic-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }


    private function add_log($action, $result) {
        $logs = get_option(self::LOG_OPTION, array());
        if (!is_array($logs)) {
            $logs = array();
        }
        $user = wp_get_current_user();
        $logs[] = array(
            'time' => current_time('mysql'),
            'user' => $user && $user->exists() ? $user->user_login : 'system',
            'action' => sanitize_key($action),
            'type' => isset($result['type']) ? sanitize_key($result['type']) : 'info',
            'message' => isset($result['message']) ? wp_strip_all_tags($result['message']) : '',
        );
        $logs = array_slice($logs, -100);
        update_option(self::LOG_OPTION, $logs, false);
    }

    private function get_logs() {
        $logs = get_option(self::LOG_OPTION, array());
        if (!is_array($logs)) {
            return array();
        }
        $logs = array_reverse($logs);
        foreach ($logs as &$log) {
            $type = isset($log['type']) ? $log['type'] : 'info';
            $log['class'] = $type === 'error' ? 'wplco-danger' : ($type === 'success' ? 'wplco-ok' : 'wplco-warn');
            $log['time'] = isset($log['time']) ? $log['time'] : '';
            $log['user'] = isset($log['user']) ? $log['user'] : '';
            $log['action'] = isset($log['action']) ? $log['action'] : '';
            $log['message'] = isset($log['message']) ? $log['message'] : '';
        }
        unset($log);
        return $logs;
    }


    private function get_diagnostic_report() {
        $cached = get_transient('wplco_diagnostic_report');
        if (is_array($cached)) {
            return $cached;
        }

        $stats = $this->collect_stats();
        $indexes = $this->recommended_indexes_status();
        $report = array(
            'stats' => $stats,
            'indexes' => $indexes,
            'diagnosis' => $this->build_diagnosis($stats, $indexes),
            'table_sizes' => $this->collect_table_sizes(),
            'meta_hotspots' => $this->collect_meta_hotspots(),
            'autoload_heavy' => $this->collect_autoload_heavy_options(),
            'environment' => $this->collect_environment(),
            'wizard_steps' => $this->build_wizard_steps($stats, $indexes),
            'collector_stats' => $this->collect_collector_stats(),
            'duplicate_titles' => $this->collect_duplicate_titles(),
            'duplicate_draft_groups' => $this->collect_duplicate_draft_groups(),
            'published_duplicate_groups' => $this->collect_published_duplicate_groups(),
            'frontend_report' => $this->collect_frontend_report(),
            'slow_risk_report' => $this->collect_slow_risk_report(),
        );
        set_transient('wplco_diagnostic_report', $report, 10 * MINUTE_IN_SECONDS);
        return $report;
    }


    private function build_diagnosis($stats, $indexes) {
        $score = 100;
        $recommendations = array();

        $get = function($label) use ($stats) {
            return isset($stats[$label]['value']) ? intval($stats[$label]['value']) : 0;
        };

        $posts = $get('文章/页面/附件总数 wp_posts');
        $postmeta = $get('postmeta 总数');
        $revisions = $get('修订版本 revision');
        $autodrafts = $get('自动草稿 auto-draft');
        $trash = $get('回收站文章 trash');
        $orphan_meta = $get('孤儿 postmeta');
        $orphan_terms = $get('孤儿分类关系');
        $autoload = $get('autoload options 数量');
        $expired_transients = $get('过期 transient');
        $missing_indexes = 0;

        foreach ($indexes as $idx) {
            if (empty($idx['exists'])) {
                $missing_indexes++;
            }
        }

        if ($posts > 100000) {
            $score -= 10;
            $recommendations[] = '文章/附件总量已超过 10 万，建议开启页面缓存、对象缓存，并重点优化后台列表查询。';
        } elseif ($posts > 30000) {
            $score -= 5;
            $recommendations[] = '文章量已进入中大型站规模，建议尽早启用 Redis/Object Cache 和页面缓存。';
        }

        if ($postmeta > 0 && $posts > 0) {
            $ratio = $postmeta / max(1, $posts);
            if ($ratio > 30) {
                $score -= 20;
                $recommendations[] = 'postmeta 与文章比例过高，优先查看“postmeta 热点字段 TOP”，排查采集/SEO/编辑器插件写入过多字段。';
            } elseif ($ratio > 12) {
                $score -= 10;
                $recommendations[] = 'postmeta 数量偏多，建议减少无用自定义字段并定期清理孤儿 postmeta。';
            }
        }

        if ($revisions > 1000) {
            $score -= 10;
            $recommendations[] = 'revision 较多，建议先清理修订版本，并把每篇文章 revision 限制为 0-3 个。';
        }
        if (($autodrafts + $trash) > 1000) {
            $score -= 8;
            $recommendations[] = 'auto-draft/回收站数据较多，建议分批清理，采集站尤其容易积累这些垃圾数据。';
        }
        if ($orphan_meta > 0 || $orphan_terms > 0) {
            $score -= 10;
            $recommendations[] = '存在孤儿数据，建议先备份数据库，再分批清理孤儿 postmeta 和孤儿分类关系。';
        }
        if ($autoload > 800) {
            $score -= 8;
            $recommendations[] = 'autoload options 数量较多，请查看“autoload 体积 TOP”，大对象会拖慢几乎所有请求。';
        }
        if ($expired_transients > 1000) {
            $score -= 6;
            $recommendations[] = '过期 transient 较多，可以安全分批清理。';
        }
        if ($missing_indexes > 0) {
            $score -= min(20, $missing_indexes * 4);
            $recommendations[] = '存在缺失推荐索引。建议低峰期备份数据库后添加，可改善列表页、分类页和 meta 查询。';
        }

        $score = max(0, min(100, $score));
        if ($score >= 85) {
            $level = '状态良好';
            $level_class = 'low';
            $score_class = 'wplco-ok';
            $summary = '暂未发现明显高风险项';
        } elseif ($score >= 65) {
            $level = '需要优化';
            $level_class = 'medium';
            $score_class = 'wplco-warn';
            $summary = '已有若干会拖慢大站的因素';
        } else {
            $level = '高风险';
            $level_class = 'high';
            $score_class = 'wplco-danger';
            $summary = '建议尽快处理数据库膨胀和索引问题';
        }

        if (empty($recommendations)) {
            $recommendations[] = '目前基础数据较健康。下一步重点确认是否已开启页面缓存、Redis 对象缓存和 CDN。';
        }

        return array(
            'score' => $score,
            'level' => $level,
            'level_class' => $level_class,
            'score_class' => $score_class,
            'summary' => $summary,
            'recommendations' => array_slice($recommendations, 0, 6),
        );
    }

    private function collect_table_sizes() {
        global $wpdb;
        $tables = array($wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms, $wpdb->options, $wpdb->comments, $wpdb->commentmeta);
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $sql = $wpdb->prepare(
            "SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows, (DATA_LENGTH + INDEX_LENGTH) AS total_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders) ORDER BY total_bytes DESC",
            $tables
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            $out = array();
            foreach ($tables as $table) {
                $out[] = array('table' => $table, 'rows' => 0, 'bytes' => 0);
            }
            return $out;
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'table' => $row['table_name'],
                'rows' => intval($row['table_rows']),
                'bytes' => intval($row['total_bytes']),
            );
        }
        return array_slice($out, 0, 8);
    }

    private function collect_meta_hotspots() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT meta_key, COUNT(*) AS total FROM {$wpdb->postmeta} GROUP BY meta_key ORDER BY total DESC LIMIT 12", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'meta_key' => $row['meta_key'] === '' ? '(empty)' : $row['meta_key'],
                'count' => intval($row['total']),
            );
        }
        return $out;
    }

    private function collect_autoload_heavy_options() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 12", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'option_name' => $row['option_name'],
                'bytes' => intval($row['bytes']),
            );
        }
        return $out;
    }

    private function format_bytes($bytes) {
        $bytes = max(0, intval($bytes));
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }


    private function build_wizard_steps($stats, $indexes) {
        $steps = array();
        $get = function($label) use ($stats) {
            return isset($stats[$label]['value']) ? intval($stats[$label]['value']) : 0;
        };

        $posts = $get('文章/页面/附件总数 wp_posts');
        $postmeta = $get('postmeta 总数');
        $revisions = $get('修订版本 revision');
        $autodrafts = $get('自动草稿 auto-draft');
        $trash = $get('回收站文章 trash');
        $orphan_meta = $get('孤儿 postmeta');
        $orphan_terms = $get('孤儿分类关系');
        $expired_transients = $get('过期 transient');
        $missing_indexes = 0;
        foreach ($indexes as $idx) {
            if (empty($idx['exists'])) {
                $missing_indexes++;
            }
        }

        $steps[] = array(
            'risk' => 'low',
            'risk_label' => '低风险',
            'title' => '先做完整数据库备份',
            'detail' => '清理和加索引通常安全，但大站数据量大，任何数据库操作前都建议先备份。',
            'action' => '在宝塔/数据库管理工具中备份当前数据库。',
        );

        if ($expired_transients > 0) {
            $steps[] = array('risk' => 'low', 'risk_label' => '低风险', 'title' => '清理过期 transient', 'detail' => '过期 transient 属于缓存垃圾，一般可以优先清理。', 'action' => '点击“清理过期 transient”，可重复执行到数量明显下降。');
        }
        if (($autodrafts + $trash) > 0) {
            $steps[] = array('risk' => 'low', 'risk_label' => '低风险', 'title' => '清理自动草稿和回收站', 'detail' => '采集站容易积累 auto-draft、trash，会增加 posts 表体积。', 'action' => '确认无用后分批点击“清理自动草稿”“清理回收站文章”。');
        }
        if ($revisions > 0) {
            $steps[] = array('risk' => 'medium', 'risk_label' => '中风险', 'title' => '限制并清理 revision', 'detail' => 'revision 很多会让 wp_posts 膨胀。清理后无法恢复旧版本内容。', 'action' => '设置每篇保留 0-3 个 revision，再分批清理修订版本。');
        }
        if ($orphan_meta > 0 || $orphan_terms > 0) {
            $steps[] = array('risk' => 'medium', 'risk_label' => '中风险', 'title' => '清理孤儿数据', 'detail' => '孤儿 postmeta/分类关系没有对应文章，通常是删除文章或插件遗留。', 'action' => '备份后分批清理孤儿 postmeta 和孤儿分类关系。');
        }
        if ($postmeta > 0 && $posts > 0 && ($postmeta / max(1, $posts)) > 12) {
            $steps[] = array('risk' => 'medium', 'risk_label' => '中风险', 'title' => '排查 postmeta 热点字段', 'detail' => 'postmeta 比例过高是大文章站后台慢的常见原因。不要直接删除，先确认字段来源。', 'action' => '查看“postmeta 热点字段 TOP”，定位采集/SEO/编辑器插件产生的字段。');
        }
        if ($missing_indexes > 0) {
            $steps[] = array('risk' => 'high', 'risk_label' => '高风险', 'title' => '低峰期添加推荐索引', 'detail' => '索引能改善查询，但 ALTER TABLE 可能短暂占用数据库资源。数据越大越要低峰期执行。', 'action' => '备份后在访问低峰点击“添加缺失索引”。');
        }
        $steps[] = array(
            'risk' => 'medium',
            'risk_label' => '中风险',
            'title' => '开启页面缓存和对象缓存',
            'detail' => '数据库清理只能减负，真正的大站性能还需要页面缓存、Redis/Object Cache 和 CDN。',
            'action' => '确认服务器安装 Redis，并在 WordPress 启用对象缓存插件；前台再配页面缓存/CDN。',
        );

        return array_slice($steps, 0, 8);
    }

    private function collect_environment() {
        global $wpdb;
        $items = array();
        $items[] = array(
            'label' => '对象缓存',
            'value' => wp_using_ext_object_cache() ? '已启用' : '未启用',
            'class' => wp_using_ext_object_cache() ? 'wplco-ok' : 'wplco-warn',
            'hint' => wp_using_ext_object_cache() ? 'Redis/Memcached 对大文章站很有帮助。' : '建议启用 Redis Object Cache。',
        );
        $items[] = array(
            'label' => 'WP_CACHE',
            'value' => (defined('WP_CACHE') && WP_CACHE) ? '已开启' : '未开启/未定义',
            'class' => (defined('WP_CACHE') && WP_CACHE) ? 'wplco-ok' : 'wplco-warn',
            'hint' => '页面缓存插件通常会定义 WP_CACHE。',
        );
        $items[] = array(
            'label' => 'PHP 版本',
            'value' => PHP_VERSION,
            'class' => version_compare(PHP_VERSION, '8.0', '>=') ? 'wplco-ok' : 'wplco-warn',
            'hint' => version_compare(PHP_VERSION, '8.0', '>=') ? '' : '建议升级到 PHP 8.x。',
        );
        $items[] = array(
            'label' => 'PHP 内存限制',
            'value' => ini_get('memory_limit'),
            'class' => 'wplco-ok',
            'hint' => '大站后台建议至少 256M。',
        );
        $items[] = array(
            'label' => 'MySQL 版本',
            'value' => $wpdb->db_version(),
            'class' => 'wplco-ok',
            'hint' => '',
        );
        $items[] = array(
            'label' => '定时维护',
            'value' => wp_next_scheduled(self::CRON_HOOK) ? '已计划' : '未计划',
            'class' => wp_next_scheduled(self::CRON_HOOK) ? 'wplco-ok' : 'wplco-warn',
            'hint' => '启用插件时会注册每日维护任务。',
        );
        return $items;
    }


    private function collect_stats() {
        global $wpdb;
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $terms = $wpdb->term_relationships;
        $options = $wpdb->options;

        $data = array(
            '文章/页面/附件总数 wp_posts' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$posts}")),
            '已发布文章' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type=%s AND post_status=%s", 'post', 'publish'))),
            '附件数量' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type=%s", 'attachment'))),
            '修订版本 revision' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type=%s", 'revision'))),
            '自动草稿 auto-draft' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_status=%s", 'auto-draft'))),
            '回收站文章 trash' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_status=%s", 'trash'))),
            'postmeta 总数' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$postmeta}")),
            '孤儿 postmeta' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$postmeta} pm LEFT JOIN {$posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL")),
            '孤儿分类关系' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$terms} tr LEFT JOIN {$posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL")),
            'autoload options 数量' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$options} WHERE autoload IN ('yes','on','auto-on','auto')")),
            '过期 transient' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like('_transient_timeout_') . '%', time()))),
        );

        $out = array();
        foreach ($data as $label => $value) {
            $class = 'wplco-ok';
            if ($value > 100000) {
                $class = 'wplco-warn';
            }
            if (in_array($label, array('修订版本 revision', '自动草稿 auto-draft', '回收站文章 trash', '孤儿 postmeta', '孤儿分类关系', '过期 transient'), true) && $value > 0) {
                $class = 'wplco-danger';
            }
            $out[$label] = array('value' => $value, 'class' => $class);
        }
        return $out;
    }

    private function collect_collector_stats() {
        global $wpdb;
        $settings = $this->settings();
        $short = min(1000, max(20, intval($settings['short_content_chars'])));
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;

        $duplicate_titles = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT post_title FROM {$posts} WHERE post_type=%s AND post_status IN ('publish','draft','pending','future') AND post_title <> '' GROUP BY post_title HAVING COUNT(*) > 1) t", 'post')));
        $duplicate_guids = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT guid FROM {$posts} WHERE post_type=%s AND guid <> '' GROUP BY guid HAVING COUNT(*) > 1) g", 'post')));
        $empty_content = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type=%s AND post_status IN ('publish','draft','pending') AND TRIM(post_content) = ''", 'post')));
        $short_content = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type=%s AND post_status IN ('publish','draft','pending') AND TRIM(post_content) <> '' AND CHAR_LENGTH(TRIM(post_content)) < %d", 'post', $short)));
        $no_thumbnail = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} p LEFT JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type=%s AND p.post_status=%s AND pm.post_id IS NULL", '_thumbnail_id', 'post', 'publish')));
        $failed_drafts = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND (TRIM(post_content) = '' OR post_title = '' OR post_title LIKE %s OR post_content LIKE %s OR post_content LIKE %s)", 'post', '%采集失败%', '%采集失败%', '%failed%')));

        return array(
            array('label' => '重复标题组', 'value' => $duplicate_titles, 'class' => $duplicate_titles ? 'wplco-warn' : 'wplco-ok', 'hint' => '同标题文章组数'),
            array('label' => '重复 GUID 组', 'value' => $duplicate_guids, 'class' => $duplicate_guids ? 'wplco-warn' : 'wplco-ok', 'hint' => '可能存在重复来源'),
            array('label' => '空内容文章', 'value' => $empty_content, 'class' => $empty_content ? 'wplco-danger' : 'wplco-ok', 'hint' => '含已发布/草稿/待审核'),
            array('label' => '短内容文章', 'value' => $short_content, 'class' => $short_content ? 'wplco-warn' : 'wplco-ok', 'hint' => '少于 ' . $short . ' 字'),
            array('label' => '发布无缩略图', 'value' => $no_thumbnail, 'class' => $no_thumbnail ? 'wplco-warn' : 'wplco-ok', 'hint' => '不一定是问题'),
            array('label' => '采集失败草稿', 'value' => $failed_drafts, 'class' => $failed_drafts ? 'wplco-danger' : 'wplco-ok', 'hint' => '可安全分批清理'),
        );
    }

    private function collect_duplicate_titles() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_title, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('publish','draft','pending','future') AND post_title <> '' GROUP BY post_title HAVING total > 1 ORDER BY total DESC LIMIT 12", 'post'), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'title' => wp_strip_all_tags($row['post_title']),
                'count' => intval($row['total']),
            );
        }
        return $out;
    }

    private function clean_failed_drafts() {
        global $wpdb;
        $limit = $this->batch_size();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND (TRIM(post_content) = '' OR post_title = '' OR post_title LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT %d", 'post', '%采集失败%', '%采集失败%', '%failed%', $limit));
        return $this->delete_posts_by_ids($ids, '已清理采集失败草稿');
    }


    private function collect_duplicate_draft_groups() {
        global $wpdb;
        $limit_groups = 10;
        $titles = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND post_title <> '' GROUP BY post_title HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT %d", 'post', $limit_groups));
        $groups = array();
        foreach ((array) $titles as $title) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND post_title=%s ORDER BY post_date ASC, ID ASC LIMIT 20", 'post', $title), ARRAY_A);
            $ids = array_map('intval', wp_list_pluck($rows, 'ID'));
            if (count($ids) < 2) {
                continue;
            }
            $groups[] = array(
                'title' => wp_strip_all_tags($title),
                'count' => count($ids),
                'trash_count' => max(0, count($ids) - 1),
                'ids' => $ids,
            );
        }
        return $groups;
    }

    private function trash_duplicate_draft_titles() {
        global $wpdb;
        $limit = $this->batch_size();
        $titles = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND post_title <> '' GROUP BY post_title HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 50", 'post'));
        $trashed = 0;
        foreach ((array) $titles as $title) {
            if ($trashed >= $limit) {
                break;
            }
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND post_title=%s ORDER BY post_date ASC, ID ASC", 'post', $title));
            $ids = array_map('intval', $ids);
            if (count($ids) < 2) {
                continue;
            }
            array_shift($ids); // 每组保留最早的一篇
            foreach ($ids as $id) {
                if ($trashed >= $limit) {
                    break 2;
                }
                wp_trash_post($id);
                $trashed++;
            }
        }
        if ($trashed <= 0) {
            return array('type' => 'success', 'message' => '没有可移入回收站的重复草稿。');
        }
        return array('type' => 'success', 'message' => '已将重复草稿移入回收站：' . number_format_i18n($trashed) . ' 篇。已发布文章未受影响。');
    }


    private function collect_published_duplicate_groups() {
        global $wpdb;
        $titles = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND post_title <> '' GROUP BY post_title HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 8", 'post', 'publish'));
        $groups = array();
        foreach ((array) $titles as $title) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_date, post_content FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND post_title=%s ORDER BY post_date ASC, ID ASC LIMIT 12", 'post', 'publish', $title), ARRAY_A);
            if (count($rows) < 2) {
                continue;
            }
            $posts = array();
            foreach ($rows as $row) {
                $id = intval($row['ID']);
                $content = wp_strip_all_tags(strip_shortcodes($row['post_content']));
                $posts[] = array(
                    'id' => $id,
                    'date' => $row['post_date'],
                    'chars' => function_exists('mb_strlen') ? mb_strlen(trim($content), 'UTF-8') : strlen(trim($content)),
                    'thumbnail' => has_post_thumbnail($id),
                    'view_url' => get_permalink($id),
                    'edit_url' => get_edit_post_link($id, 'raw'),
                );
            }
            $groups[] = array(
                'title' => wp_strip_all_tags($title),
                'posts' => $posts,
            );
        }
        return $groups;
    }


    private function clean_revisions() {
        global $wpdb;
        $limit = $this->batch_size();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s LIMIT %d", 'revision', $limit));
        return $this->delete_posts_by_ids($ids, '已清理修订版本');
    }

    private function clean_autodrafts() {
        global $wpdb;
        $limit = $this->batch_size();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status=%s LIMIT %d", 'auto-draft', $limit));
        return $this->delete_posts_by_ids($ids, '已清理自动草稿');
    }

    private function clean_trash() {
        global $wpdb;
        $limit = $this->batch_size();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status=%s LIMIT %d", 'trash', $limit));
        return $this->delete_posts_by_ids($ids, '已清理回收站文章');
    }

    private function delete_posts_by_ids($ids, $message) {
        if (empty($ids)) {
            return array('type' => 'success', 'message' => '没有需要清理的数据。');
        }
        $count = 0;
        foreach ($ids as $id) {
            wp_delete_post(intval($id), true);
            $count++;
        }
        return array('type' => 'success', 'message' => $message . '：' . number_format_i18n($count) . ' 条。');
    }

    private function clean_orphan_postmeta() {
        global $wpdb;
        $limit = $this->batch_size();
        $rows = $wpdb->query($wpdb->prepare("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL LIMIT %d", $limit));
        return array('type' => 'success', 'message' => '已清理孤儿 postmeta：' . number_format_i18n(max(0, intval($rows))) . ' 条。');
    }

    private function clean_orphan_term_relationships() {
        global $wpdb;
        $limit = $this->batch_size();
        $rows = $wpdb->query($wpdb->prepare("DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL LIMIT %d", $limit));
        return array('type' => 'success', 'message' => '已清理孤儿分类关系：' . number_format_i18n(max(0, intval($rows))) . ' 条。');
    }

    private function clean_expired_transients() {
        global $wpdb;
        $limit = $this->batch_size();
        $timeout_like = $wpdb->esc_like('_transient_timeout_') . '%';
        $timeouts = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d", $timeout_like, time(), $limit));
        if (empty($timeouts)) {
            return array('type' => 'success', 'message' => '没有过期 transient 需要清理。');
        }

        $deleted = 0;
        foreach ($timeouts as $timeout_name) {
            $transient = str_replace('_transient_timeout_', '', $timeout_name);
            delete_transient($transient);
            $deleted++;
        }
        return array('type' => 'success', 'message' => '已清理过期 transient：' . number_format_i18n($deleted) . ' 组。');
    }

    private function recommended_indexes() {
        global $wpdb;
        return array(
            array('table' => $wpdb->posts, 'name' => 'wplco_type_status_date', 'columns' => 'post_type, post_status, post_date, ID', 'sql' => 'ADD INDEX wplco_type_status_date (post_type, post_status, post_date, ID)'),
            array('table' => $wpdb->posts, 'name' => 'wplco_status_type_modified', 'columns' => 'post_status, post_type, post_modified, ID', 'sql' => 'ADD INDEX wplco_status_type_modified (post_status, post_type, post_modified, ID)'),
            array('table' => $wpdb->postmeta, 'name' => 'wplco_postid_metakey', 'columns' => 'post_id, meta_key(191)', 'sql' => 'ADD INDEX wplco_postid_metakey (post_id, meta_key(191))'),
            array('table' => $wpdb->term_relationships, 'name' => 'wplco_term_object', 'columns' => 'term_taxonomy_id, object_id', 'sql' => 'ADD INDEX wplco_term_object (term_taxonomy_id, object_id)'),
            array('table' => $wpdb->options, 'name' => 'wplco_autoload', 'columns' => 'autoload', 'sql' => 'ADD INDEX wplco_autoload (autoload)'),
        );
    }

    private function recommended_indexes_status() {
        $items = array();
        foreach ($this->recommended_indexes() as $idx) {
            $idx['exists'] = $this->index_exists($idx['table'], $idx['name']);
            $items[] = $idx;
        }
        return $items;
    }

    private function index_exists($table, $name) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $name));
        return !empty($found);
    }

    private function add_recommended_indexes() {
        global $wpdb;
        $added = 0;
        $skipped = 0;
        $errors = array();

        foreach ($this->recommended_indexes() as $idx) {
            if ($this->index_exists($idx['table'], $idx['name'])) {
                $skipped++;
                continue;
            }
            $sql = "ALTER TABLE `{$idx['table']}` {$idx['sql']}";
            $result = $wpdb->query($sql);
            if ($result === false) {
                $errors[] = $idx['name'];
            } else {
                $added++;
            }
        }

        if (!empty($errors)) {
            return array('type' => 'error', 'message' => '部分索引添加失败：' . implode(', ', $errors) . '。已添加 ' . number_format_i18n($added) . ' 个，跳过 ' . number_format_i18n($skipped) . ' 个。');
        }
        return array('type' => 'success', 'message' => '索引处理完成：新增 ' . number_format_i18n($added) . ' 个，已存在跳过 ' . number_format_i18n($skipped) . ' 个。');
    }

    public function run_cron_maintenance() {
        $settings = $this->settings();
        if (empty($settings['cron_enabled'])) {
            return;
        }
        if (!empty($settings['cron_clean_revisions'])) {
            $this->clean_revisions();
        }
        if (!empty($settings['cron_clean_autodrafts'])) {
            $this->clean_autodrafts();
        }
        if (!empty($settings['cron_clean_trash'])) {
            $this->clean_trash();
        }
        if (!empty($settings['cron_clean_orphan_postmeta'])) {
            $this->clean_orphan_postmeta();
        }
        if (!empty($settings['cron_clean_expired_transients'])) {
            $this->clean_expired_transients();
        }
    }
}

register_activation_hook(__FILE__, array('WP_Large_Content_Optimizer', 'activate'));
register_deactivation_hook(__FILE__, array('WP_Large_Content_Optimizer', 'deactivate'));
WP_Large_Content_Optimizer::instance();
