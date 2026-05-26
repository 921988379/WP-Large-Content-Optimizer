<?php
/**
 * Plugin Name: WP Large Content Optimizer
 * Plugin URI: https://www.seoyh.net/
 * Description: 针对文章量大导致 WordPress 变慢的问题，提供数据库体检、垃圾数据分批清理、索引检测/添加、后台文章列表加速、轻量页面缓存和定时维护。
 * Version: 3.5.0
 * Author: 一点优化
 * Author URI: https://www.seoyh.net/
 * Text Domain: wp-large-content-optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Large_Content_Optimizer {
    const VERSION = '3.5.0';
    const OPTION = 'wplco_settings';
    const LOG_OPTION = 'wplco_maintenance_logs';
    const PAGE_CACHE_META_OPTION = 'wplco_page_cache_meta';
    const PAGE_CACHE_STATS_OPTION = 'wplco_page_cache_stats';
    const GITHUB_OWNER = '921988379';
    const GITHUB_REPO = 'WP-Large-Content-Optimizer';
    const GITHUB_PLUGIN_FILE = 'wp-large-content-optimizer/wp-large-content-optimizer.php';
    const CRON_HOOK = 'wplco_daily_maintenance';

    private static $instance = null;
    private $page_cache_active = false;
    private $page_cache_file = '';
    private $page_cache_key = '';
    private $page_cache_started = 0;
    private $page_cache_buffer_level = 0;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'handle_post_actions'));
        add_action('wp_ajax_wplco_queue_start', array($this, 'ajax_queue_start'));
        add_action('wp_ajax_wplco_queue_step', array($this, 'ajax_queue_step'));
        add_action('wp_ajax_wplco_admin_months', array($this, 'ajax_admin_months'));
        add_action('wp_ajax_wplco_admin_found_posts', array($this, 'ajax_admin_found_posts'));
        add_filter('wp_revisions_to_keep', array($this, 'limit_revisions'), 10, 2);
        add_filter('manage_posts_columns', array($this, 'simplify_post_columns'), 999);
        add_filter('manage_pages_columns', array($this, 'simplify_post_columns'), 999);
        add_action('pre_get_posts', array($this, 'admin_list_fast_mode_query'), 20);
        add_action('admin_footer-edit.php', array($this, 'render_admin_lazy_stats_script'));
        add_action('admin_head-edit.php', array($this, 'admin_filter_slim_css'));
        add_filter('months_dropdown_results', array($this, 'admin_list_disable_months_dropdown'), 20, 2);
        add_filter('found_posts', array($this, 'admin_list_disable_found_posts'), 20, 2);
        add_filter('posts_pre_query', array($this, 'admin_query_cache_pre'), 10, 2);
        add_filter('the_posts', array($this, 'admin_query_cache_store'), 10, 2);
        add_action('save_post', array($this, 'flush_admin_query_cache'));
        add_action('deleted_post', array($this, 'flush_admin_query_cache'));
        add_action('trashed_post', array($this, 'flush_admin_query_cache'));
        add_action('save_post', array($this, 'flush_page_cache_on_content_change'), 20);
        add_action('deleted_post', array($this, 'flush_page_cache_on_content_change'), 20);
        add_action('trashed_post', array($this, 'flush_page_cache_on_content_change'), 20);
        add_action('comment_post', array($this, 'flush_page_cache_on_content_change'), 20);
        add_action('transition_comment_status', array($this, 'flush_page_cache_on_content_change'), 20);
        add_action('template_redirect', array($this, 'maybe_serve_page_cache'), 0);
        add_action('shutdown', array($this, 'maybe_store_page_cache'), 0);
        add_action('init', array($this, 'frontend_light_optimizations'));
        add_action('admin_enqueue_scripts', array($this, 'admin_heartbeat_control'));
        add_filter('heartbeat_settings', array($this, 'heartbeat_settings'));
        add_filter('xmlrpc_enabled', array($this, 'maybe_disable_xmlrpc'));
        add_filter('rest_authentication_errors', array($this, 'maybe_restrict_rest_api'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_github_update'));
        add_filter('plugins_api', array($this, 'github_plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'fix_github_update_folder'), 10, 3);
        add_action(self::CRON_HOOK, array($this, 'run_cron_maintenance'));
        add_filter('schedule_event', array($this, 'maybe_block_paused_cron_hook'), 10, 1);
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
            'cron_paused_hooks' => array(),
            'short_content_chars' => 120,
            'admin_fast_mode' => 1,
            'admin_fast_per_page' => 50,
            'admin_fast_title_search' => 1,
            'admin_fast_disable_months' => 1,
            'admin_fast_disable_found_rows' => 0,
            'admin_query_cache' => 0,
            'admin_query_cache_ttl' => 120,
            'admin_ajax_months' => 0,
            'admin_ajax_found_rows' => 0,
            'admin_stats_cache_ttl' => 600,
            'admin_filter_slim' => 0,
            'frontend_disable_emoji' => 1,
            'frontend_disable_embeds' => 0,
            'frontend_disable_dashicons' => 0,
            'frontend_disable_generator' => 1,
            'frontend_disable_feed_links' => 0,
            'frontend_disable_rest_links' => 1,
            'frontend_disable_xmlrpc' => 0,
            'frontend_restrict_rest_guests' => 0,
            'heartbeat_mode' => 'reduce',
            'heartbeat_interval' => 60,
            'page_cache_enabled' => 0,
            'page_cache_ttl' => 3600,
            'page_cache_home' => 1,
            'page_cache_singular' => 1,
            'page_cache_archive' => 1,
            'page_cache_mobile_variant' => 1,
            'page_cache_stats_enabled' => 0,
            'page_cache_exclude_paths' => '',
        );
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    private function sanitize_textarea_lines($value) {
        $value = is_string($value) ? wp_unslash($value) : '';
        $lines = preg_split('/\r\n|\r|\n/', $value);
        $clean = array();
        foreach ((array) $lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/[^A-Za-z0-9_\-\.\/\*\?=&:%]/', '', $line);
            $clean[] = substr($line, 0, 180);
        }
        return implode("\n", array_slice(array_unique($clean), 0, 50));
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


    private function admin_query_cache_enabled() {
        $settings = $this->settings();
        return !empty($settings['admin_query_cache']) && $this->is_admin_edit_posts_screen();
    }

    private function admin_query_cache_key($query) {
        $vars = $query->query_vars;
        unset($vars['cache_results'], $vars['update_post_meta_cache'], $vars['update_post_term_cache'], $vars['lazy_load_term_meta']);
        ksort($vars);
        return 'wplco_admin_q_' . md5(wp_json_encode($vars));
    }

    public function admin_query_cache_pre($posts, $query) {
        if (!$query->is_main_query() || !$this->admin_query_cache_enabled()) {
            return $posts;
        }
        if (!empty($query->query_vars['wplco_from_cache'])) {
            return $posts;
        }
        $key = $this->admin_query_cache_key($query);
        $cached = get_transient($key);
        if (!is_array($cached) || empty($cached['ids'])) {
            $query->query_vars['wplco_cache_key'] = $key;
            return $posts;
        }
        $query->query_vars['wplco_from_cache'] = true;
        $query->found_posts = isset($cached['found_posts']) ? intval($cached['found_posts']) : count($cached['ids']);
        $query->max_num_pages = isset($cached['max_num_pages']) ? intval($cached['max_num_pages']) : 1;
        $out = array();
        foreach ($cached['ids'] as $id) {
            $post = get_post(intval($id));
            if ($post) {
                $out[] = $post;
            }
        }
        return $out;
    }

    public function admin_query_cache_store($posts, $query) {
        if (!$query->is_main_query() || !$this->admin_query_cache_enabled()) {
            return $posts;
        }
        if (!empty($query->query_vars['wplco_from_cache'])) {
            return $posts;
        }
        $key = !empty($query->query_vars['wplco_cache_key']) ? $query->query_vars['wplco_cache_key'] : $this->admin_query_cache_key($query);
        $ids = wp_list_pluck($posts, 'ID');
        $settings = $this->settings();
        set_transient($key, array(
            'ids' => array_map('intval', $ids),
            'found_posts' => intval($query->found_posts),
            'max_num_pages' => intval($query->max_num_pages),
        ), min(600, max(30, intval($settings['admin_query_cache_ttl']))));
        $keys = get_option('wplco_admin_query_cache_keys', array());
        if (!is_array($keys)) {
            $keys = array();
        }
        $keys[$key] = time();
        $keys = array_slice($keys, -200, null, true);
        update_option('wplco_admin_query_cache_keys', $keys, false);
        return $posts;
    }

    public function flush_admin_query_cache() {
        $keys = get_option('wplco_admin_query_cache_keys', array());
        if (is_array($keys)) {
            foreach (array_keys($keys) as $key) {
                delete_transient($key);
            }
        }
        delete_option('wplco_admin_query_cache_keys');
        $this->flush_admin_lazy_stats_cache();
    }

    private function admin_lazy_stats_enabled() {
        $settings = $this->settings();
        return !empty($settings['admin_fast_mode']) && (!empty($settings['admin_ajax_months']) || !empty($settings['admin_ajax_found_rows']));
    }

    private function admin_lazy_stats_cache_ttl() {
        $settings = $this->settings();
        return min(3600, max(60, intval($settings['admin_stats_cache_ttl'])));
    }

    private function admin_lazy_stats_cache_set($key, $value) {
        set_transient($key, $value, $this->admin_lazy_stats_cache_ttl());
        $keys = get_option('wplco_admin_lazy_stats_cache_keys', array());
        if (!is_array($keys)) {
            $keys = array();
        }
        $keys[$key] = time();
        $keys = array_slice($keys, -200, null, true);
        update_option('wplco_admin_lazy_stats_cache_keys', $keys, false);
    }

    private function flush_admin_lazy_stats_cache() {
        $keys = get_option('wplco_admin_lazy_stats_cache_keys', array());
        if (is_array($keys)) {
            foreach (array_keys($keys) as $key) {
                delete_transient($key);
            }
        }
        delete_option('wplco_admin_lazy_stats_cache_keys');
    }

    private function admin_lazy_request_args() {
        $post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'post';
        if (!in_array($post_type, array('post', 'page'), true)) {
            $post_type = 'post';
        }
        $args = array(
            'post_type' => $post_type,
            'post_status' => isset($_POST['post_status']) ? sanitize_key(wp_unslash($_POST['post_status'])) : '',
            's' => isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '',
            'm' => isset($_POST['m']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['m'])) : '',
            'author' => isset($_POST['author']) ? intval($_POST['author']) : 0,
            'cat' => isset($_POST['cat']) ? intval($_POST['cat']) : 0,
        );
        return $args;
    }

    public function ajax_admin_months() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => '权限不足。'), 403);
        }
        check_ajax_referer('wplco_admin_lazy_stats', 'nonce');
        $settings = $this->settings();
        if (empty($settings['admin_ajax_months'])) {
            wp_send_json_error(array('message' => '未启用 AJAX 月份统计。'), 400);
        }
        $args = $this->admin_lazy_request_args();
        $key = 'wplco_admin_months_' . md5(wp_json_encode($args));
        $cached = get_transient($key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            wp_send_json_success($cached);
        }
        global $wpdb, $wp_locale;
        $where = $wpdb->prepare("post_type = %s AND post_status NOT IN ('auto-draft','trash') AND post_date <> '0000-00-00 00:00:00'", $args['post_type']);
        if ($args['post_status'] !== '') {
            $where .= $wpdb->prepare(' AND post_status = %s', $args['post_status']);
        }
        $rows = $wpdb->get_results("SELECT YEAR(post_date) AS year, MONTH(post_date) AS month, COUNT(ID) AS posts FROM {$wpdb->posts} WHERE {$where} GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY post_date DESC LIMIT 60", ARRAY_A);
        $months = array();
        foreach ((array) $rows as $row) {
            $year = intval($row['year']);
            $month = intval($row['month']);
            $months[] = array(
                'value' => sprintf('%04d%02d', $year, $month),
                'label' => sprintf('%s %d', $wp_locale->get_month($month), $year),
                'count' => intval($row['posts']),
            );
        }
        $payload = array('months' => $months, 'cached' => false, 'generated_at' => current_time('mysql'));
        $this->admin_lazy_stats_cache_set($key, $payload);
        wp_send_json_success($payload);
    }

    public function ajax_admin_found_posts() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => '权限不足。'), 403);
        }
        check_ajax_referer('wplco_admin_lazy_stats', 'nonce');
        $settings = $this->settings();
        if (empty($settings['admin_ajax_found_rows'])) {
            wp_send_json_error(array('message' => '未启用 AJAX 精确总数统计。'), 400);
        }
        $args = $this->admin_lazy_request_args();
        $key = 'wplco_admin_found_' . md5(wp_json_encode($args));
        $cached = get_transient($key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            wp_send_json_success($cached);
        }
        $query_args = array(
            'post_type' => $args['post_type'],
            'post_status' => $args['post_status'] !== '' ? $args['post_status'] : 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        $use_title_search = !empty($settings['admin_fast_title_search']) && $args['s'] !== '';
        if ($args['s'] !== '' && !$use_title_search) {
            $query_args['s'] = $args['s'];
        }
        if ($use_title_search) {
            $query_args['wplco_title_search'] = $args['s'];
            add_filter('posts_where', array($this, 'admin_title_search_where'), 20, 2);
        }
        if ($args['m'] !== '') {
            $query_args['m'] = $args['m'];
        }
        if ($args['author'] > 0) {
            $query_args['author'] = $args['author'];
        }
        if ($args['cat'] > 0 && $args['post_type'] === 'post') {
            $query_args['cat'] = $args['cat'];
        }
        $q = new WP_Query($query_args);
        if ($use_title_search) {
            remove_filter('posts_where', array($this, 'admin_title_search_where'), 20);
        }
        $total = intval($q->found_posts);
        $per_page = isset($_POST['per_page']) ? max(1, min(200, intval($_POST['per_page']))) : min(200, max(10, intval($settings['admin_fast_per_page'])));
        $payload = array(
            'total' => $total,
            'pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
            'per_page' => $per_page,
            'cached' => false,
            'generated_at' => current_time('mysql'),
        );
        $this->admin_lazy_stats_cache_set($key, $payload);
        wp_send_json_success($payload);
    }

    public function render_admin_lazy_stats_script() {
        if (!$this->is_admin_edit_posts_screen() || !$this->admin_lazy_stats_enabled()) {
            return;
        }
        $settings = $this->settings();
        $screen = get_current_screen();
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        $data = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplco_admin_lazy_stats'),
            'postType' => $post_type,
            'postStatus' => isset($_GET['post_status']) ? sanitize_key(wp_unslash($_GET['post_status'])) : '',
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'm' => isset($_GET['m']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_GET['m'])) : '',
            'author' => isset($_GET['author']) ? intval($_GET['author']) : 0,
            'cat' => isset($_GET['cat']) ? intval($_GET['cat']) : 0,
            'perPage' => min(200, max(10, intval($settings['admin_fast_per_page']))),
            'months' => !empty($settings['admin_ajax_months']),
            'foundRows' => !empty($settings['admin_ajax_found_rows']),
        );
        ?>
        <script>
        (function(){
            var cfg=<?php echo wp_json_encode($data); ?>;
            function post(action, extra){
                var body=new URLSearchParams();
                body.set('action', action); body.set('nonce', cfg.nonce);
                body.set('post_type', cfg.postType); body.set('post_status', cfg.postStatus || ''); body.set('s', cfg.s || ''); body.set('m', cfg.m || ''); body.set('author', cfg.author || 0); body.set('cat', cfg.cat || 0); body.set('per_page', cfg.perPage || 50);
                if(extra){Object.keys(extra).forEach(function(k){body.set(k, extra[k]);});}
                return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json();});
            }
            function currentUrlWith(name,value){
                var url=new URL(window.location.href); if(value){url.searchParams.set(name,value);}else{url.searchParams.delete(name);} url.searchParams.delete('paged'); return url.toString();
            }
            if(cfg.months){
                var actions=document.querySelector('.tablenav.top .alignleft.actions') || document.querySelector('.tablenav .alignleft.actions');
                if(actions && !actions.querySelector('select[name="m"]')){
                    var select=document.createElement('select'); select.name='m'; select.id='filter-by-date'; select.innerHTML='<option value="">月份加载中…</option>'; actions.insertBefore(select, actions.firstChild);
                    post('wplco_admin_months').then(function(res){
                        if(!res || !res.success){select.innerHTML='<option value="">月份加载失败</option>'; return;}
                        var html='<option value="">全部日期</option>';
                        (res.data.months||[]).forEach(function(row){html+='<option value="'+row.value+'"'+(cfg.m===row.value?' selected':'')+'>'+row.label+' ('+row.count+')</option>';});
                        select.innerHTML=html;
                        select.addEventListener('change', function(){window.location.href=currentUrlWith('m', select.value);});
                    }).catch(function(){select.innerHTML='<option value="">月份加载失败</option>';});
                }
            }
            if(cfg.foundRows){
                var nums=document.querySelectorAll('.displaying-num');
                nums.forEach(function(n){n.setAttribute('data-wplco-original', n.textContent); n.textContent='精确总数计算中…';});
                post('wplco_admin_found_posts').then(function(res){
                    if(!res || !res.success){nums.forEach(function(n){n.textContent=n.getAttribute('data-wplco-original') || '总数加载失败';}); return;}
                    var total=Number(res.data.total||0).toLocaleString(); var pages=Number(res.data.pages||0).toLocaleString();
                    nums.forEach(function(n){n.textContent=total+' 个项目，共 '+pages+' 页';});
                }).catch(function(){nums.forEach(function(n){n.textContent=n.getAttribute('data-wplco-original') || '总数加载失败';});});
            }
        })();
        </script>
        <?php
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
        } elseif ($action === 'clean_safe_postmeta') {
            $result = $this->clean_safe_postmeta();
        } elseif ($action === 'clean_duplicate_postmeta') {
            $result = $this->clean_duplicate_postmeta();
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
        } elseif ($action === 'clean_duplicate_cron') {
            $result = $this->clean_duplicate_cron_events();
        } elseif ($action === 'clear_page_cache') {
            $result = $this->clear_page_cache();
        } elseif ($action === 'clear_page_cache_stats') {
            $result = $this->clear_page_cache_stats();
        } elseif ($action === 'warm_page_cache') {
            $result = $this->warm_page_cache();
        } elseif ($action === 'pause_cron_hook') {
            $result = $this->pause_cron_hook();
        } elseif ($action === 'resume_cron_hook') {
            $result = $this->resume_cron_hook();
        } elseif ($action === 'unschedule_cron_hook') {
            $result = $this->unschedule_cron_hook();
        } elseif ($action === 'disable_autoload_option') {
            $result = $this->disable_autoload_option();
        } elseif ($action === 'restore_autoload_option') {
            $result = $this->restore_autoload_option();
        } elseif ($action === 'install_advanced_cache') {
            $result = $this->install_advanced_cache_dropin();
        } elseif ($action === 'uninstall_advanced_cache') {
            $result = $this->uninstall_advanced_cache_dropin();
        } elseif ($action === 'clear_trend_history') {
            delete_option('wplco_trend_history');
            $result = array('type' => 'success', 'message' => '性能趋势记录已清空。');
        } elseif ($action === 'clean_completed_actions') {
            $result = $this->clean_action_scheduler_actions('complete');
        } elseif ($action === 'clean_failed_actions') {
            $result = $this->clean_action_scheduler_actions('failed');
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
        $settings['admin_query_cache'] = empty($_POST['admin_query_cache']) ? 0 : 1;
        $settings['admin_query_cache_ttl'] = min(600, max(30, intval($_POST['admin_query_cache_ttl'] ?? 120)));
        $settings['admin_ajax_months'] = empty($_POST['admin_ajax_months']) ? 0 : 1;
        $settings['admin_ajax_found_rows'] = empty($_POST['admin_ajax_found_rows']) ? 0 : 1;
        $settings['admin_stats_cache_ttl'] = min(3600, max(60, intval($_POST['admin_stats_cache_ttl'] ?? 600)));
        $settings['admin_filter_slim'] = empty($_POST['admin_filter_slim']) ? 0 : 1;
        $settings['frontend_disable_emoji'] = empty($_POST['frontend_disable_emoji']) ? 0 : 1;
        $settings['frontend_disable_embeds'] = empty($_POST['frontend_disable_embeds']) ? 0 : 1;
        $settings['frontend_disable_dashicons'] = empty($_POST['frontend_disable_dashicons']) ? 0 : 1;
        $old_page_cache_enabled = !empty($settings['page_cache_enabled']);
        $old_page_cache_ttl = intval($settings['page_cache_ttl'] ?? 3600);
        $settings['frontend_disable_generator'] = empty($_POST['frontend_disable_generator']) ? 0 : 1;
        $settings['frontend_disable_feed_links'] = empty($_POST['frontend_disable_feed_links']) ? 0 : 1;
        $settings['frontend_disable_rest_links'] = empty($_POST['frontend_disable_rest_links']) ? 0 : 1;
        $settings['frontend_disable_xmlrpc'] = empty($_POST['frontend_disable_xmlrpc']) ? 0 : 1;
        $settings['frontend_restrict_rest_guests'] = empty($_POST['frontend_restrict_rest_guests']) ? 0 : 1;
        $heartbeat_mode = isset($_POST['heartbeat_mode']) ? sanitize_key(wp_unslash($_POST['heartbeat_mode'])) : 'reduce';
        $settings['heartbeat_mode'] = in_array($heartbeat_mode, array('keep','reduce','disable'), true) ? $heartbeat_mode : 'reduce';
        $settings['heartbeat_interval'] = min(120, max(15, intval($_POST['heartbeat_interval'] ?? 60)));
        $settings['page_cache_enabled'] = empty($_POST['page_cache_enabled']) ? 0 : 1;
        $settings['page_cache_ttl'] = min(DAY_IN_SECONDS, max(300, intval($_POST['page_cache_ttl'] ?? 3600)));
        $settings['page_cache_home'] = empty($_POST['page_cache_home']) ? 0 : 1;
        $settings['page_cache_singular'] = empty($_POST['page_cache_singular']) ? 0 : 1;
        $settings['page_cache_archive'] = empty($_POST['page_cache_archive']) ? 0 : 1;
        $settings['page_cache_mobile_variant'] = empty($_POST['page_cache_mobile_variant']) ? 0 : 1;
        $settings['page_cache_stats_enabled'] = empty($_POST['page_cache_stats_enabled']) ? 0 : 1;
        $settings['page_cache_exclude_paths'] = $this->sanitize_textarea_lines($_POST['page_cache_exclude_paths'] ?? '');
        $new_page_cache_enabled = !empty($settings['page_cache_enabled']);
        if ($old_page_cache_enabled !== $new_page_cache_enabled || $old_page_cache_ttl !== intval($settings['page_cache_ttl'])) {
            $this->clear_page_cache();
        }
        $settings['cron_enabled'] = empty($_POST['cron_enabled']) ? 0 : 1;
        $settings['cron_clean_revisions'] = empty($_POST['cron_clean_revisions']) ? 0 : 1;
        $settings['cron_clean_autodrafts'] = empty($_POST['cron_clean_autodrafts']) ? 0 : 1;
        $settings['cron_clean_trash'] = empty($_POST['cron_clean_trash']) ? 0 : 1;
        $settings['cron_clean_orphan_postmeta'] = empty($_POST['cron_clean_orphan_postmeta']) ? 0 : 1;
        $settings['cron_clean_expired_transients'] = empty($_POST['cron_clean_expired_transients']) ? 0 : 1;
        update_option(self::OPTION, $settings, false);
        delete_transient('wplco_diagnostic_report');
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


    private function queue_task_labels() {
        return array(
            'revisions' => '修订版本',
            'autodrafts' => '自动草稿',
            'trash' => '回收站文章',
            'orphan_postmeta' => '失效 postmeta',
            'orphan_terms' => '失效分类关系',
            'expired_transients' => '过期 transient',
            'failed_drafts' => '采集失败草稿',
            'duplicate_drafts' => '重复草稿',
        );
    }

    private function queue_task_count($task) {
        global $wpdb;
        switch ($task) {
            case 'revisions':
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", 'revision')));
            case 'autodrafts':
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status=%s", 'auto-draft')));
            case 'trash':
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status=%s", 'trash')));
            case 'orphan_postmeta':
                return intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"));
            case 'orphan_terms':
                return intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL"));
            case 'expired_transients':
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like('_transient_timeout_') . '%', time())));
            case 'failed_drafts':
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND (TRIM(post_content) = '' OR post_title = '' OR post_title LIKE %s OR post_content LIKE %s OR post_content LIKE %s)", 'post', '%采集失败%', '%采集失败%', '%failed%')));
            case 'duplicate_drafts':
                $rows = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','auto-draft','pending') AND post_title <> '' GROUP BY post_title HAVING total > 1", 'post'), ARRAY_A);
                $count = 0;
                foreach ((array) $rows as $row) {
                    $count += max(0, intval($row['total']) - 1);
                }
                return $count;
        }
        return 0;
    }

    private function run_queue_task_batch($task) {
        switch ($task) {
            case 'revisions': return $this->clean_revisions();
            case 'autodrafts': return $this->clean_autodrafts();
            case 'trash': return $this->clean_trash();
            case 'orphan_postmeta': return $this->clean_orphan_postmeta();
            case 'orphan_terms': return $this->clean_orphan_term_relationships();
            case 'expired_transients': return $this->clean_expired_transients();
            case 'failed_drafts': return $this->clean_failed_drafts();
            case 'duplicate_drafts': return $this->trash_duplicate_draft_titles();
        }
        return array('type' => 'error', 'message' => '未知队列任务。');
    }

    public function ajax_queue_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足。'), 403);
        }
        check_ajax_referer('wplco_queue', 'nonce');
        $labels = $this->queue_task_labels();
        $requested = isset($_POST['tasks']) && is_array($_POST['tasks']) ? array_map('sanitize_key', wp_unslash($_POST['tasks'])) : array();
        $tasks = array();
        foreach ($requested as $task) {
            if (!isset($labels[$task])) {
                continue;
            }
            $count = $this->queue_task_count($task);
            $tasks[] = array('key' => $task, 'label' => $labels[$task], 'total' => $count, 'remaining' => $count);
        }
        wp_send_json_success(array('tasks' => $tasks, 'batch_size' => $this->batch_size()));
    }

    public function ajax_queue_step() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足。'), 403);
        }
        check_ajax_referer('wplco_queue', 'nonce');
        $labels = $this->queue_task_labels();
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        if (!isset($labels[$task])) {
            wp_send_json_error(array('message' => '未知队列任务。'), 400);
        }
        $before = $this->queue_task_count($task);
        $result = $this->run_queue_task_batch($task);
        $after = $this->queue_task_count($task);
        $processed = max(0, $before - $after);
        if (is_array($result)) {
            $this->add_log('queue_' . $task, $result);
        }
        delete_transient('wplco_diagnostic_report');
        wp_send_json_success(array(
            'task' => $task,
            'label' => $labels[$task],
            'before' => $before,
            'after' => $after,
            'processed' => $processed,
            'done' => $after <= 0 || $processed <= 0,
            'message' => isset($result['message']) ? $result['message'] : '',
            'type' => isset($result['type']) ? $result['type'] : 'success',
        ));
    }


    public function admin_filter_slim_css() {
        $settings = $this->settings();
        if (empty($settings['admin_filter_slim']) || !$this->is_admin_edit_posts_screen()) {
            return;
        }
        ?>
        <style>
            .tablenav.top .alignleft.actions select[name="m"],
            .tablenav.top .alignleft.actions select[name="cat"],
            .tablenav.top .alignleft.actions select[name="author"],
            .tablenav.top .alignleft.actions select[name="seo_filter"],
            .tablenav.top .alignleft.actions select[name="readability_filter"]{max-width:1px!important;width:1px!important;min-width:1px!important;opacity:.2!important}
            .tablenav.top .alignleft.actions:after{content:' WLCO 已精简重筛选器入口，可在「工具 → 大站优化 → 设置」关闭';display:inline-block;margin-left:8px;color:#667085;font-size:12px;vertical-align:middle}
        </style>
        <?php
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
        $postmeta_deep_report = $report['postmeta_deep_report'];
        $autoload_optimizer_report = $report['autoload_optimizer_report'];
        $environment = $report['environment'];
        $wizard_steps = $report['wizard_steps'];
        $collector_stats = $report['collector_stats'];
        $duplicate_titles = $report['duplicate_titles'];
        $duplicate_draft_groups = $report['duplicate_draft_groups'];
        $published_duplicate_groups = $report['published_duplicate_groups'];
        $frontend_report = $report['frontend_report'];
        $object_cache_report = $report['object_cache_report'];
        $page_cache_report = $report['page_cache_report'];
        $slow_risk_report = $report['slow_risk_report'];
        $cron_report = $report['cron_report'];
        $ajax_report = $report['ajax_report'];
        $media_report = $report['media_report'];
        $advanced_cache_report = $report['advanced_cache_report'];
        $plugin_theme_report = $report['plugin_theme_report'];
        $trend_report = $report['trend_report'];
        $commerce_report = $report['commerce_report'];
        $explain_report = $report['explain_report'];
        $admin_filter_report = $report['admin_filter_report'];
        $multisite_report = isset($report['multisite_report']) ? $report['multisite_report'] : array('checks' => array(), 'recommendations' => array());
        $runtime_profile_report = isset($report['runtime_profile_report']) ? $report['runtime_profile_report'] : array('checks' => array(), 'recommendations' => array());
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
                    <button type="button" data-wplco-tab="cron">定时任务</button>
                    <button type="button" data-wplco-tab="frontend">前台优化</button>
                    <button type="button" data-wplco-tab="logs">日志</button>
                    <button type="button" data-wplco-tab="settings">设置</button>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
            <?php endif; ?>

            <style>
                .wplco-wrap{--wplco-bg:#f6f7fb;--wplco-card:#fff;--wplco-border:#e3e7ef;--wplco-text:#1d2327;--wplco-muted:#667085;--wplco-primary:#2563eb;--wplco-primary-dark:#1d4ed8;--wplco-shadow:0 8px 24px rgba(15,23,42,.06);max-width:1480px}.wplco-wrap *{box-sizing:border-box}.wplco-hero{display:flex;justify-content:space-between;gap:22px;align-items:center;margin:18px 0 14px;padding:24px;border:1px solid #dbe5ff;border-radius:18px;background:linear-gradient(135deg,#eef4ff 0%,#fff 55%,#f8fbff 100%);box-shadow:var(--wplco-shadow)}.wplco-hero h1{margin:0 0 8px;font-size:26px;font-weight:700;color:#0f172a}.wplco-hero p{max-width:900px;margin:0 0 6px;color:#475467;font-size:14px}.wplco-hero-meta{font-size:12px!important;color:#667085!important}.wplco-hero-score{min-width:112px;text-align:center;border-radius:16px;background:#fff;border:1px solid var(--wplco-border);padding:14px 18px;box-shadow:0 4px 14px rgba(15,23,42,.05)}.wplco-hero-score span{display:block;font-size:38px;line-height:1;font-weight:800}.wplco-hero-score small{display:block;margin-top:5px;color:#667085}.wplco-toolbar{position:sticky;top:32px;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 16px;padding:10px 12px;border:1px solid var(--wplco-border);border-radius:14px;background:rgba(255,255,255,.92);box-shadow:var(--wplco-shadow);backdrop-filter:blur(8px)}.wplco-toolbar-actions,.wplco-nav{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.wplco-toolbar form,.wplco-actions form{display:inline-block;margin:0}.wplco-toolbar .button,.wplco-actions .button{border-radius:8px}.wplco-nav button{border:0;border-radius:999px;background:#eef2ff;color:#1e40af;padding:7px 13px;font-size:12px;cursor:pointer;font-weight:600}.wplco-nav button:hover{background:#dbeafe;color:#1d4ed8}.wplco-nav button.is-active{background:var(--wplco-primary);color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.24)}.wplco-tab-hidden{display:none!important}.wplco-empty-group{display:none!important}.wplco-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px}.wplco-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;margin-top:16px}.wplco-card{position:relative;background:var(--wplco-card);border:1px solid var(--wplco-border);border-radius:16px;padding:18px;box-shadow:var(--wplco-shadow);overflow:hidden}.wplco-card:hover{border-color:#cbd5e1}.wplco-card h2{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:-18px -18px 14px;padding:15px 18px;border-bottom:1px solid #edf0f5;background:linear-gradient(180deg,#fff,#fafbff);font-size:16px}.wplco-card h3{color:#1f2937}.wplco-card-body{transition:opacity .16s ease}.wplco-card.is-collapsed .wplco-card-body{display:none}.wplco-toggle{margin-left:auto;border:1px solid #d0d7de;border-radius:8px;background:#fff;color:#475467;font-size:12px;padding:3px 8px;cursor:pointer}.wplco-toggle:hover{background:#f8fafc;color:#1d2327}.wplco-card.is-collapsed .wplco-toggle:after{content:' 展开'}.wplco-card:not(.is-collapsed) .wplco-toggle:after{content:' 收起'}.wplco-stat{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef1f5;padding:8px 0}.wplco-stat span{color:#475467}.wplco-stat strong{font-size:16px}.wplco-danger{color:#b42318}.wplco-ok{color:#067647}.wplco-warn{color:#b54708}.wplco-table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden}.wplco-table th,.wplco-table td{padding:9px 10px;border-bottom:1px solid #eef1f5;text-align:left;vertical-align:top}.wplco-table th{background:#f8fafc;color:#475467;font-weight:600}.wplco-table tr:hover td{background:#fbfdff}.wplco-table code{word-break:break-all}.wplco-small{color:#667085;font-size:12px}.wplco-settings label{display:block;margin:10px 0;padding:8px 10px;border-radius:10px;background:#fbfcff;border:1px solid #eef1f5}.wplco-settings h3{margin-top:18px;padding-top:8px;border-top:1px solid #edf0f5}.wplco-number{width:90px}.wplco-score{font-size:36px;font-weight:800;margin:6px 0}.wplco-pill{display:inline-block;padding:4px 9px;border-radius:999px;background:#f1f5f9;color:#475467;margin-left:6px;font-size:12px}.wplco-list{margin-left:18px;list-style:disc}.wplco-list li{margin-bottom:6px}.wplco-priority{border-left:5px solid #d92d20}.wplco-priority.medium{border-left-color:#f79009}.wplco-priority.low{border-left-color:#12b76a}.wplco-step{display:grid;grid-template-columns:86px 1fr;gap:10px;padding:11px 0;border-bottom:1px solid #eef1f5}.wplco-badge{display:inline-block;text-align:center;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}.wplco-risk-low{background:#ecfdf3;color:#067647}.wplco-risk-medium{background:#fffaeb;color:#b54708}.wplco-risk-high{background:#fef3f2;color:#b42318}.wplco-env{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.wplco-env div{background:#fbfcff;border:1px solid #eef1f5;border-radius:12px;padding:10px}.wplco-metric{font-size:26px;font-weight:800;margin-top:4px}.wplco-actions .button{margin:4px 6px 4px 0}.wplco-queue{margin-top:14px;padding:14px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc}.wplco-queue-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin:10px 0}.wplco-queue-options label{padding:8px 10px;background:#fff;border:1px solid #e5e7eb;border-radius:10px}.wplco-progress{height:14px;overflow:hidden;border-radius:999px;background:#e5e7eb;margin:10px 0}.wplco-progress-bar{height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#12b76a);transition:width .2s ease}.wplco-queue-log{max-height:160px;overflow:auto;background:#0f172a;color:#d1e7ff;border-radius:10px;padding:10px;font-family:monospace;font-size:12px;white-space:pre-wrap}.wplco-card .wplco-card{box-shadow:none;border-radius:12px}.wplco-card .wplco-card h3{margin-top:0}@media (max-width:782px){.wplco-hero,.wplco-toolbar{display:block}.wplco-hero-score{margin-top:14px}.wplco-toolbar{position:static}.wplco-nav{margin-top:10px}.wplco-grid,.wplco-two{grid-template-columns:1fr}.wplco-table{display:block;overflow-x:auto}.wplco-card h2{font-size:15px}.wplco-step{grid-template-columns:1fr}}
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
                    <?php $this->action_button('clean_orphan_postmeta', '清理失效 postmeta', '清理文章已不存在但仍残留的 postmeta？'); ?>
                    <?php $this->action_button('clean_orphan_term_relationships', '清理失效分类关系', '清理文章已不存在但仍残留的分类关系？'); ?>
                    <?php $this->action_button('clean_expired_transients', '清理过期 transient', '清理过期 transient 缓存？'); ?>
                    <div class="wplco-queue" data-wplco-queue>
                        <h3>队列清理</h3>
                        <p class="wplco-small">选择要清理的项目后自动分批执行。每批仍按上方“每批清理数量”处理，可随时暂停。</p>
                        <div class="wplco-queue-options">
                            <label><input type="checkbox" value="revisions" checked> 修订版本</label>
                            <label><input type="checkbox" value="autodrafts" checked> 自动草稿</label>
                            <label><input type="checkbox" value="trash"> 回收站文章</label>
                            <label><input type="checkbox" value="orphan_postmeta" checked> 失效 postmeta</label>
                            <label><input type="checkbox" value="orphan_terms" checked> 失效分类关系</label>
                            <label><input type="checkbox" value="expired_transients" checked> 过期 transient</label>
                            <label><input type="checkbox" value="failed_drafts"> 采集失败草稿</label>
                            <label><input type="checkbox" value="duplicate_drafts"> 重复草稿</label>
                        </div>
                        <p>
                            <button type="button" class="button button-primary" data-wplco-queue-start>开始队列清理</button>
                            <button type="button" class="button" data-wplco-queue-pause disabled>暂停</button>
                        </p>
                        <div class="wplco-progress" aria-hidden="true"><div class="wplco-progress-bar" data-wplco-queue-bar></div></div>
                        <p class="wplco-small" data-wplco-queue-status>未开始。</p>
                        <div class="wplco-queue-log" data-wplco-queue-log>等待开始…</div>
                    </div>
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

            <div class="wplco-two">
                <div class="wplco-card wplco-actions">
                    <h2>postmeta 深度治理</h2>
                    <div class="wplco-env">
                        <div><strong>安全可清理 meta</strong><br><span class="wplco-metric <?php echo $postmeta_deep_report['safe_total'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($postmeta_deep_report['safe_total'])); ?></span></div>
                        <div><strong>重复 postmeta</strong><br><span class="wplco-metric <?php echo $postmeta_deep_report['duplicate_removable'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($postmeta_deep_report['duplicate_removable'])); ?></span></div>
                        <div><strong>空 meta_value</strong><br><span class="wplco-metric <?php echo $postmeta_deep_report['empty_values'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($postmeta_deep_report['empty_values'])); ?></span></div>
                    </div>
                    <h3>安全候选字段</h3>
                    <table class="wplco-table"><thead><tr><th>meta_key</th><th>数量</th><th>体积</th><th>说明</th></tr></thead><tbody>
                    <?php foreach ($postmeta_deep_report['safe_keys'] as $row): ?>
                        <tr><td><code><?php echo esc_html($row['meta_key']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td><td><?php echo esc_html($this->format_bytes($row['bytes'])); ?></td><td><?php echo esc_html($row['hint']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <p>
                        <?php $this->action_button('clean_safe_postmeta', '分批清理安全 postmeta', '只清理 _edit_lock/_edit_last/_wp_old_slug/_oembed_* 这类低风险缓存/编辑痕迹字段。建议先备份数据库，确定继续？'); ?>
                        <?php $this->action_button('clean_duplicate_postmeta', '分批清理重复 postmeta', '只删除低风险字段中完全相同 post_id + meta_key + meta_value 的重复记录，每组保留最早一条。建议先备份数据库，确定继续？'); ?>
                    </p>
                    <?php if (!empty($postmeta_deep_report['huge_values'])): ?>
                        <h3>超大 meta_value TOP</h3>
                        <table class="wplco-table"><thead><tr><th>meta_id</th><th>post_id</th><th>meta_key</th><th>大小</th></tr></thead><tbody>
                        <?php foreach ($postmeta_deep_report['huge_values'] as $row): ?>
                            <tr><td><code><?php echo esc_html($row['meta_id']); ?></code></td><td><code><?php echo esc_html($row['post_id']); ?></code></td><td><code><?php echo esc_html($row['meta_key']); ?></code></td><td><?php echo esc_html($this->format_bytes($row['bytes'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                        <p class="wplco-small">超大 meta 只展示，不自动删除。通常要先判断来源插件和用途。</p>
                    <?php endif; ?>
                </div>

                <div class="wplco-card">
                    <h2>autoload 优化器</h2>
                    <div class="wplco-env">
                        <div><strong>autoload 总体积</strong><br><span class="wplco-metric <?php echo esc_attr($autoload_optimizer_report['total_class']); ?>"><?php echo esc_html($this->format_bytes($autoload_optimizer_report['total_bytes'])); ?></span></div>
                        <div><strong>可回滚记录</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n(count($autoload_optimizer_report['backups']))); ?></span></div>
                    </div>
                    <p class="wplco-small">仅展示大 autoload 项。点击“改为不自动加载”前会记录原 autoload 状态，可在下方回滚。核心 WordPress 关键 option 已保护。</p>
                    <table class="wplco-table"><thead><tr><th>option_name</th><th>大小</th><th>风险</th><th>操作</th></tr></thead><tbody>
                    <?php foreach ($autoload_optimizer_report['candidates'] as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($row['option_name']); ?></code></td>
                            <td><?php echo esc_html($this->format_bytes($row['bytes'])); ?></td>
                            <td><span class="<?php echo esc_attr($row['class']); ?>"><?php echo esc_html($row['risk']); ?></span></td>
                            <td><?php if (!$row['protected']): ?><form method="post" onsubmit="return confirm('将该 option 改为不自动加载？建议确认它不是每次请求都必需的配置。');"><?php wp_nonce_field('wplco_action', 'wplco_nonce'); ?><input type="hidden" name="wplco_action" value="disable_autoload_option"><input type="hidden" name="option_name" value="<?php echo esc_attr($row['option_name']); ?>"><?php submit_button('改为不自动加载', 'secondary small', 'submit', false); ?></form><?php else: ?><span class="wplco-small">已保护</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <?php if (!empty($autoload_optimizer_report['backups'])): ?>
                        <h3>autoload 回滚</h3>
                        <table class="wplco-table"><thead><tr><th>option_name</th><th>原状态</th><th>时间</th><th>操作</th></tr></thead><tbody>
                        <?php foreach ($autoload_optimizer_report['backups'] as $name => $backup): ?>
                            <tr><td><code><?php echo esc_html($name); ?></code></td><td><?php echo esc_html($backup['autoload']); ?></td><td><?php echo esc_html($backup['time']); ?></td><td><form method="post" onsubmit="return confirm('恢复该 option 的原 autoload 状态？');"><?php wp_nonce_field('wplco_action', 'wplco_nonce'); ?><input type="hidden" name="wplco_action" value="restore_autoload_option"><input type="hidden" name="option_name" value="<?php echo esc_attr($name); ?>"><?php submit_button('恢复', 'secondary small', 'submit', false); ?></form></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php endif; ?>
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

            <div class="wplco-two" style="margin-top:16px">
                <div class="wplco-card">
                    <h2>Multisite 兼容检测</h2>
                    <div class="wplco-env">
                        <?php foreach ($multisite_report['checks'] as $item): ?>
                            <div><strong><?php echo esc_html($item['label']); ?></strong><br><span class="<?php echo esc_attr($item['class']); ?>"><?php echo esc_html($item['value']); ?></span><?php if (!empty($item['hint'])): ?><p class="wplco-small" style="margin:4px 0 0"><?php echo esc_html($item['hint']); ?></p><?php endif; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($multisite_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($multisite_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                </div>
                <div class="wplco-card">
                    <h2>诊断页轻量 Profiling</h2>
                    <div class="wplco-env">
                        <?php foreach ($runtime_profile_report['checks'] as $item): ?>
                            <div><strong><?php echo esc_html($item['label']); ?></strong><br><span class="<?php echo esc_attr($item['class']); ?>"><?php echo esc_html($item['value']); ?></span><?php if (!empty($item['hint'])): ?><p class="wplco-small" style="margin:4px 0 0"><?php echo esc_html($item['hint']); ?></p><?php endif; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($runtime_profile_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($runtime_profile_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
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
                <h2>WP-Cron 与采集任务检测</h2>
                <p class="wplco-small">只读分析 WordPress 定时任务，帮助发现采集站常见的高频、过期、重复 cron 事件。清理按钮只删除完全重复的 cron 事件，每组保留一条。</p>
                <div class="wplco-env">
                    <div><strong>总事件数</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($cron_report['total_events'])); ?></span></div>
                    <div><strong>过期事件</strong><br><span class="wplco-metric <?php echo $cron_report['overdue_events'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($cron_report['overdue_events'])); ?></span></div>
                    <div><strong>重复事件</strong><br><span class="wplco-metric <?php echo $cron_report['duplicate_events'] ? 'wplco-danger' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($cron_report['duplicate_events'])); ?></span></div>
                    <div><strong>采集相关事件</strong><br><span class="wplco-metric <?php echo $cron_report['collector_events'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($cron_report['collector_events'])); ?></span></div>
                    <div><strong>暂停 Hook</strong><br><span class="wplco-metric <?php echo $cron_report['paused_count'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($cron_report['paused_count'])); ?></span></div>
                </div>
                <?php if (!empty($cron_report['recommendations'])): ?>
                    <h3>建议</h3>
                    <ul class="wplco-list">
                        <?php foreach ($cron_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <h3>高频/重复 Hook TOP</h3>
                <table class="wplco-table">
                    <thead><tr><th>Hook</th><th>事件数</th><th>下次运行</th><th>来源/回调</th><th>状态</th><th>风险</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($cron_report['hooks'] as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($row['hook']); ?></code></td>
                            <td><?php echo esc_html(number_format_i18n($row['count'])); ?></td>
                            <td><?php echo esc_html($row['next_run']); ?></td>
                            <td><span title="<?php echo esc_attr($row['source']['detail']); ?>"><?php echo esc_html($row['source']['label']); ?></span></td>
                            <td><span class="<?php echo esc_attr($row['paused'] ? 'wplco-warn' : 'wplco-ok'); ?>"><?php echo esc_html($row['paused'] ? '已暂停新调度' : '正常'); ?></span></td>
                            <td><span class="<?php echo esc_attr($row['class']); ?>"><?php echo esc_html($row['risk']); ?></span></td>
                            <td>
                                <?php if ($row['paused']): ?>
                                    <?php $this->action_button_for_cron_hook('resume_cron_hook', $row['hook'], '恢复', '恢复该 Hook 的新 Cron 调度？'); ?>
                                <?php else: ?>
                                    <?php $this->action_button_for_cron_hook('pause_cron_hook', $row['hook'], '暂停新调度', '只会阻止该 Hook 后续新增 Cron 调度，不会删除已存在事件。确定继续？'); ?>
                                <?php endif; ?>
                                <?php $this->action_button_for_cron_hook('unschedule_cron_hook', $row['hook'], '删除此 Hook 事件', '将删除该 Hook 当前已计划的全部 Cron 事件。这可能影响插件/主题任务，请确认来源后再继续。确定删除？', 'delete'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!empty($cron_report['collector_hooks'])): ?>
                    <h3>采集相关 Hook</h3>
                    <table class="wplco-table"><thead><tr><th>Hook</th><th>事件数</th><th>下次运行</th><th>来源/回调</th></tr></thead><tbody>
                    <?php foreach ($cron_report['collector_hooks'] as $row): ?><tr><td><code><?php echo esc_html($row['hook']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td><td><?php echo esc_html($row['next_run']); ?></td><td><span title="<?php echo esc_attr($row['source']['detail']); ?>"><?php echo esc_html($row['source']['label']); ?></span></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <p><?php $this->action_button('clean_duplicate_cron', '清理重复 Cron 事件', '只会清理完全重复的 cron 事件，每组保留一条。建议先确认没有任务正在运行，确定继续？'); ?></p>
            </div>

            <div class="wplco-card wplco-actions" style="margin-top:16px">
                <h2>WooCommerce/Action Scheduler 检测</h2>
                <p class="wplco-small">检测 WooCommerce 与 Action Scheduler 表/任务状态。维护按钮需要管理员手动确认，只清理 30 天前记录，单次最多 500 条。</p>
                <div class="wplco-env">
                    <div><strong>WooCommerce</strong><br><span class="<?php echo esc_attr($commerce_report['woocommerce_class']); ?>"><?php echo esc_html($commerce_report['woocommerce']); ?></span></div>
                    <div><strong>Action Scheduler</strong><br><span class="<?php echo esc_attr($commerce_report['scheduler_class']); ?>"><?php echo esc_html($commerce_report['scheduler']); ?></span></div>
                    <div><strong>待执行任务</strong><br><span class="wplco-metric <?php echo $commerce_report['pending_actions'] > 1000 ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($commerce_report['pending_actions'])); ?></span></div>
                    <div><strong>超 1 小时待执行</strong><br><span class="wplco-metric <?php echo $commerce_report['stale_pending_actions'] ? 'wplco-danger' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($commerce_report['stale_pending_actions'])); ?></span></div>
                    <div><strong>运行中</strong><br><span class="wplco-metric <?php echo $commerce_report['running_actions'] > 20 ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($commerce_report['running_actions'])); ?></span></div>
                    <div><strong>失败任务</strong><br><span class="wplco-metric <?php echo $commerce_report['failed_actions'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($commerce_report['failed_actions'])); ?></span></div>
                    <div><strong>已完成任务</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($commerce_report['complete_actions'])); ?></span></div>
                    <div><strong>30 天前已完成</strong><br><span class="wplco-metric <?php echo $commerce_report['old_complete_actions'] > 1000 ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($commerce_report['old_complete_actions'])); ?></span></div>
                    <div><strong>30 天前失败</strong><br><span class="wplco-metric <?php echo $commerce_report['old_failed_actions'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($commerce_report['old_failed_actions'])); ?></span></div>
                </div>
                <?php if (!empty($commerce_report['oldest_pending'])): ?><p class="wplco-small">最早待执行任务 GMT：<code><?php echo esc_html($commerce_report['oldest_pending']); ?></code></p><?php endif; ?>
                <?php if (!empty($commerce_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($commerce_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if (!empty($commerce_report['status_rows'])): ?>
                    <h3>任务状态分布</h3>
                    <table class="wplco-table"><thead><tr><th>状态</th><th>数量</th></tr></thead><tbody><?php foreach ($commerce_report['status_rows'] as $row): ?><tr><td><code><?php echo esc_html($row['status']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <?php if (!empty($commerce_report['group_rows'])): ?>
                    <h3>任务分组 TOP</h3>
                    <table class="wplco-table"><thead><tr><th>Group</th><th>数量</th></tr></thead><tbody><?php foreach ($commerce_report['group_rows'] as $row): ?><tr><td><code><?php echo esc_html($row['group']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <?php if (!empty($commerce_report['top_hooks'])): ?>
                    <h3>Action Scheduler Hook TOP</h3>
                    <table class="wplco-table"><thead><tr><th>Hook</th><th>状态</th><th>数量</th></tr></thead><tbody><?php foreach ($commerce_report['top_hooks'] as $row): ?><tr><td><code><?php echo esc_html($row['hook']); ?></code></td><td><code><?php echo esc_html($row['status']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <p>
                    <?php $this->action_button('clean_completed_actions', '清理 30 天前已完成任务', '只会清理 30 天前已完成的 Action Scheduler 记录和日志，单次最多 500 条。确定继续？'); ?>
                    <?php $this->action_button('clean_failed_actions', '清理 30 天前失败任务', '建议先确认失败原因。只会清理 30 天前失败的 Action Scheduler 记录和日志，单次最多 500 条。确定继续？', 'delete'); ?>
                </p>
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
                <h3>Redis/Object Cache 深度检测</h3>
                <div class="wplco-env">
                    <?php foreach ($object_cache_report['checks'] as $item): ?>
                        <div><strong><?php echo esc_html($item['label']); ?></strong><br><span class="<?php echo esc_attr($item['class']); ?>"><?php echo esc_html($item['value']); ?></span><?php if (!empty($item['hint'])): ?><p class="wplco-small" style="margin:4px 0 0"><?php echo esc_html($item['hint']); ?></p><?php endif; ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($object_cache_report['recommendations'])): ?>
                    <ul class="wplco-list">
                        <?php foreach ($object_cache_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($object_cache_report['global_groups']) || !empty($object_cache_report['non_persistent_groups'])): ?>
                    <h3>缓存分组可见性</h3>
                    <?php if (!empty($object_cache_report['global_groups'])): ?><p class="wplco-small">全局组：<code><?php echo esc_html(implode('</code> <code>', $object_cache_report['global_groups'])); ?></code></p><?php endif; ?>
                    <?php if (!empty($object_cache_report['non_persistent_groups'])): ?><p class="wplco-small">非持久组：<code><?php echo esc_html(implode('</code> <code>', $object_cache_report['non_persistent_groups'])); ?></code></p><?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="wplco-card wplco-actions" style="margin-top:16px">
                <h2>页面缓存</h2>
                <p class="wplco-small">轻量静态 HTML 页面缓存，默认关闭。适合内容站/采集站访客访问，不缓存登录用户、后台、搜索、预览、REST/AJAX、带查询参数 URL。</p>
                <div class="wplco-env">
                    <div><strong>缓存状态</strong><br><span class="<?php echo $page_cache_report['enabled'] ? 'wplco-ok' : 'wplco-warn'; ?>"><?php echo esc_html($page_cache_report['enabled'] ? '已开启' : '未开启'); ?></span><p class="wplco-small" style="margin:4px 0 0">命中响应头：<code>X-WLCO-Cache: HIT</code></p></div>
                    <div><strong>缓存时间</strong><br><span class="wplco-metric"><?php echo esc_html($page_cache_report['ttl_label']); ?></span></div>
                    <div><strong>缓存文件</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($page_cache_report['files'])); ?></span></div>
                    <div><strong>缓存体积</strong><br><span class="wplco-metric"><?php echo esc_html($this->format_bytes($page_cache_report['bytes'])); ?></span></div>
                    <div><strong>最后生成</strong><br><span><?php echo $page_cache_report['last_generated'] ? esc_html(date_i18n('Y-m-d H:i:s', $page_cache_report['last_generated'])) : '暂无'; ?></span></div>
                    <div><strong>最后清理</strong><br><span><?php echo $page_cache_report['last_cleared'] ? esc_html(date_i18n('Y-m-d H:i:s', $page_cache_report['last_cleared'])) : '暂无'; ?></span></div>
                    <div><strong>命中率</strong><br><span class="wplco-metric <?php echo $page_cache_report['stats']['hit_rate'] >= 50 ? 'wplco-ok' : 'wplco-warn'; ?>"><?php echo esc_html($page_cache_report['stats']['hit_rate']); ?>%</span><p class="wplco-small" style="margin:4px 0 0"><?php echo $page_cache_report['stats_enabled'] ? '统计已开启' : '统计未开启'; ?></p></div>
                    <div><strong>预热候选</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($page_cache_report['warm_candidates'])); ?></span></div>
                </div>
                <?php if (!empty($page_cache_report['stats_enabled'])): ?>
                    <h3>缓存观测</h3>
                    <div class="wplco-env">
                        <div><strong>HIT</strong><br><span class="wplco-metric wplco-ok"><?php echo esc_html(number_format_i18n($page_cache_report['stats']['hit'])); ?></span></div>
                        <div><strong>MISS</strong><br><span class="wplco-metric wplco-warn"><?php echo esc_html(number_format_i18n($page_cache_report['stats']['miss'])); ?></span></div>
                        <div><strong>BYPASS</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($page_cache_report['stats']['bypass'])); ?></span></div>
                        <div><strong>已写入</strong><br><span class="wplco-metric wplco-ok"><?php echo esc_html(number_format_i18n($page_cache_report['stats']['stored'])); ?></span></div>
                        <div><strong>跳过写入</strong><br><span class="wplco-metric <?php echo $page_cache_report['stats']['store_skip'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($page_cache_report['stats']['store_skip'])); ?></span></div>
                        <div><strong>最后统计</strong><br><span><?php echo $page_cache_report['stats']['updated'] ? esc_html(date_i18n('Y-m-d H:i:s', $page_cache_report['stats']['updated'])) : '暂无'; ?></span></div>
                    </div>
                    <?php if (!empty($page_cache_report['stats']['reasons'])): ?>
                        <h3>MISS / BYPASS 原因 TOP</h3>
                        <table class="wplco-table"><thead><tr><th>原因</th><th>次数</th></tr></thead><tbody><?php foreach ($page_cache_report['stats']['reasons'] as $row): ?><tr><td><code><?php echo esc_html($row['reason']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?></tbody></table>
                    <?php endif; ?>
                    <?php if (!empty($page_cache_report['stats']['samples'])): ?>
                        <h3>最近缓存 URL 样本</h3>
                        <table class="wplco-table"><thead><tr><th>时间</th><th>状态</th><th>变体</th><th>URL</th></tr></thead><tbody><?php foreach ($page_cache_report['stats']['samples'] as $row): ?><tr><td><?php echo esc_html(date_i18n('Y-m-d H:i:s', intval($row['time']))); ?></td><td><code><?php echo esc_html($row['status']); ?></code></td><td><?php echo esc_html($row['variant']); ?></td><td><code><?php echo esc_html($row['url']); ?></code></td></tr><?php endforeach; ?></tbody></table>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($page_cache_report['exclude_patterns'])): ?>
                    <h3>当前排除规则</h3>
                    <p class="wplco-small"><code><?php echo esc_html(implode('</code> <code>', $page_cache_report['exclude_patterns'])); ?></code></p>
                <?php endif; ?>
                <?php if (!empty($page_cache_report['recommendations'])): ?>
                    <h3>建议</h3>
                    <ul class="wplco-list">
                        <?php foreach ($page_cache_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p>
                    <?php $this->action_button('clear_page_cache', '清空页面缓存', '确定清空本插件生成的页面缓存？不会删除文章和数据库数据。'); ?>
                    <?php $this->action_button('clear_page_cache_stats', '清空命中统计', '确定清空页面缓存命中统计？不会删除缓存文件。'); ?>
                    <?php $this->action_button('warm_page_cache', '手动预热缓存', '将请求首页、最新文章/页面和热门分类标签以生成缓存。确定继续？'); ?>
                </p>
            </div>

            <div class="wplco-two">
                <div class="wplco-card">
                    <h2>高级缓存就绪检查</h2>
                    <div class="wplco-env">
                        <div><strong>WP_CACHE</strong><br><span class="<?php echo esc_attr($advanced_cache_report['wp_cache_class']); ?>"><?php echo esc_html($advanced_cache_report['wp_cache']); ?></span></div>
                        <div><strong>advanced-cache.php</strong><br><span class="<?php echo esc_attr($advanced_cache_report['dropin_class']); ?>"><?php echo esc_html($advanced_cache_report['dropin']); ?></span></div>
                        <div><strong>本插件轻量缓存</strong><br><span class="<?php echo $page_cache_report['enabled'] ? 'wplco-ok' : 'wplco-warn'; ?>"><?php echo esc_html($page_cache_report['enabled'] ? '已开启' : '未开启'); ?></span></div>
                    </div>
                    <?php if (!empty($advanced_cache_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($advanced_cache_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <p class="wplco-small">高级缓存 drop-in 属于高影响功能，只有当前不存在第三方 <code>advanced-cache.php</code> 时才允许安装 WLCO 自有 drop-in；卸载也只删除 WLCO 自己生成的文件。</p>
                </div>
                <div class="wplco-card wplco-actions">
                    <h2>高级缓存 Drop-in 管理</h2>
                    <div class="wplco-env">
                        <div><strong>Drop-in 来源</strong><br><span class="<?php echo esc_attr($advanced_cache_report['owner_class']); ?>"><?php echo esc_html($advanced_cache_report['owner']); ?></span></div>
                        <div><strong>可安装</strong><br><span class="<?php echo $advanced_cache_report['installable'] ? 'wplco-ok' : 'wplco-warn'; ?>"><?php echo esc_html($advanced_cache_report['installable'] ? '是' : '否'); ?></span></div>
                        <div><strong>WP_CACHE</strong><br><span class="<?php echo esc_attr($advanced_cache_report['wp_cache_class']); ?>"><?php echo esc_html($advanced_cache_report['wp_cache']); ?></span></div>
                    </div>
                    <p class="wplco-small">安装后仍需在 <code>wp-config.php</code> 中启用 <code>define('WP_CACHE', true);</code> 才能被 WordPress 加载。本插件不会自动改 wp-config.php。</p>
                    <p>
                        <?php if (!empty($advanced_cache_report['installable'])): ?><?php $this->action_button('install_advanced_cache', '安装 WLCO advanced-cache.php', '将安装 WLCO 自有 advanced-cache.php。若服务器已有页面缓存，请不要安装。确定继续？'); ?><?php endif; ?>
                        <?php if (!empty($advanced_cache_report['owned'])): ?><?php $this->action_button('uninstall_advanced_cache', '卸载 WLCO advanced-cache.php', '只会删除带 WLCO 标识的 advanced-cache.php，不会删除第三方缓存文件。确定继续？'); ?><?php endif; ?>
                    </p>
                </div>
                <div class="wplco-card">
                    <h2>admin-ajax 诊断</h2>
                    <div class="wplco-env">
                        <div><strong>登录 AJAX Hook</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($ajax_report['priv_count'])); ?></span></div>
                        <div><strong>访客 AJAX Hook</strong><br><span class="wplco-metric <?php echo $ajax_report['nopriv_count'] > 20 ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($ajax_report['nopriv_count'])); ?></span></div>
                        <div><strong>Heartbeat</strong><br><span class="<?php echo esc_attr($ajax_report['heartbeat_class']); ?>"><?php echo esc_html($ajax_report['heartbeat']); ?></span></div>
                    </div>
                    <?php if (!empty($ajax_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($ajax_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <h3>访客 AJAX Hook TOP</h3>
                    <table class="wplco-table"><thead><tr><th>Hook</th><th>回调数</th></tr></thead><tbody><?php foreach ($ajax_report['nopriv_hooks'] as $row): ?><tr><td><code><?php echo esc_html($row['hook']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['callbacks'])); ?></td></tr><?php endforeach; ?></tbody></table>
                </div>
            </div>

            <div class="wplco-two">
                <div class="wplco-card">
                    <h2>媒体库体检</h2>
                    <div class="wplco-env">
                        <div><strong>附件总数</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($media_report['attachments'])); ?></span></div>
                        <div><strong>未挂载附件</strong><br><span class="wplco-metric <?php echo $media_report['unattached'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($media_report['unattached'])); ?></span></div>
                        <div><strong>缺少元数据</strong><br><span class="wplco-metric <?php echo $media_report['missing_metadata'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($media_report['missing_metadata'])); ?></span></div>
                        <div><strong>缺少文件路径</strong><br><span class="wplco-metric <?php echo $media_report['missing_file'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($media_report['missing_file'])); ?></span></div>
                        <div><strong>大元数据附件</strong><br><span class="wplco-metric <?php echo $media_report['large_originals'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($media_report['large_originals'])); ?></span></div>
                    </div>
                    <?php if (!empty($media_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($media_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <?php if (!empty($media_report['mime_types'])): ?>
                        <h3>附件 MIME TOP</h3>
                        <table class="wplco-table"><thead><tr><th>MIME</th><th>数量</th></tr></thead><tbody><?php foreach ($media_report['mime_types'] as $row): ?><tr><td><code><?php echo esc_html($row['mime']); ?></code></td><td><?php echo esc_html(number_format_i18n($row['count'])); ?></td></tr><?php endforeach; ?></tbody></table>
                    <?php endif; ?>
                    <?php if (!empty($media_report['samples'])): ?>
                        <h3>待审查附件样本</h3>
                        <table class="wplco-table"><thead><tr><th>ID</th><th>标题</th><th>问题</th><th>编辑</th></tr></thead><tbody><?php foreach ($media_report['samples'] as $row): ?><tr><td><code><?php echo esc_html($row['id']); ?></code></td><td><?php echo esc_html($row['title']); ?></td><td><span class="wplco-warn"><?php echo esc_html($row['issue']); ?></span></td><td><a href="<?php echo esc_url($row['edit_url']); ?>" target="_blank" rel="noopener">编辑</a></td></tr><?php endforeach; ?></tbody></table>
                    <?php endif; ?>
                    <p class="wplco-small">媒体库只读体检，不自动删除附件。未挂载不一定是垃圾，可能被主题/字段引用；缺少元数据建议先单个再生缩略图确认。</p>
                </div>
                <div class="wplco-card">
                    <h2>插件/主题体检</h2>
                    <div class="wplco-env">
                        <div><strong>启用插件</strong><br><span class="wplco-metric"><?php echo esc_html(number_format_i18n($plugin_theme_report['active_plugins'])); ?></span></div>
                        <div><strong>可能影响缓存插件</strong><br><span class="wplco-metric <?php echo $plugin_theme_report['cache_plugins'] ? 'wplco-warn' : 'wplco-ok'; ?>"><?php echo esc_html(number_format_i18n($plugin_theme_report['cache_plugins'])); ?></span></div>
                        <div><strong>当前主题</strong><br><span><?php echo esc_html($plugin_theme_report['theme']); ?></span></div>
                    </div>
                    <?php if (!empty($plugin_theme_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($plugin_theme_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <table class="wplco-table"><thead><tr><th>插件</th><th>提示</th></tr></thead><tbody><?php foreach ($plugin_theme_report['notable_plugins'] as $row): ?><tr><td><code><?php echo esc_html($row['plugin']); ?></code></td><td><?php echo esc_html($row['hint']); ?></td></tr><?php endforeach; ?></tbody></table>
                </div>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>后台筛选器精简</h2>
                <p class="wplco-small">针对文章列表顶部筛选器很多、误触后触发重查询的问题。当前策略为安全视觉精简：不删除 WordPress 查询能力，只降低重筛选入口的干扰。</p>
                <div class="wplco-env">
                    <div><strong>精简模式</strong><br><span class="<?php echo $admin_filter_report['enabled'] ? 'wplco-ok' : 'wplco-warn'; ?>"><?php echo esc_html($admin_filter_report['enabled'] ? '已开启' : '未开启'); ?></span></div>
                    <div><strong>当前屏幕</strong><br><span><?php echo esc_html($admin_filter_report['screen']); ?></span></div>
                    <div><strong>策略</strong><br><span><?php echo esc_html($admin_filter_report['mode']); ?></span></div>
                </div>
                <ul class="wplco-list"><?php foreach ($admin_filter_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul>
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
                <h2>慢查询 EXPLAIN 采样</h2>
                <p class="wplco-small">只对固定的安全 SELECT 样本执行 EXPLAIN，不读取慢查询日志、不执行写操作。用于判断关键查询是否能使用索引。</p>
                <?php if (!empty($explain_report['recommendations'])): ?><ul class="wplco-list"><?php foreach ($explain_report['recommendations'] as $rec): ?><li><?php echo esc_html($rec); ?></li><?php endforeach; ?></ul><?php endif; ?>
                <table class="wplco-table"><thead><tr><th>样本</th><th>表</th><th>type</th><th>possible_keys</th><th>key</th><th>rows</th><th>Extra</th><th>建议</th><th>风险</th></tr></thead><tbody>
                <?php foreach ($explain_report['samples'] as $row): ?>
                    <tr><td><?php echo esc_html($row['label']); ?></td><td><code><?php echo esc_html($row['table']); ?></code></td><td><?php echo esc_html($row['type']); ?></td><td><code><?php echo esc_html($row['possible_keys']); ?></code></td><td><code><?php echo esc_html($row['key']); ?></code></td><td><?php echo esc_html($row['rows']); ?></td><td><?php echo esc_html($row['extra']); ?></td><td><?php echo esc_html($row['advice']); ?></td><td><span class="<?php echo esc_attr($row['class']); ?>"><?php echo esc_html($row['risk']); ?></span></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>

            <div class="wplco-card" style="margin-top:16px">
                <h2>性能趋势记录</h2>
                <p class="wplco-small">每次刷新诊断时记录关键指标，最多保留最近 30 次。用于观察清理/优化后数据是否真的下降。</p>
                <table class="wplco-table">
                    <thead><tr><th>时间</th><th>健康分</th><th>wp_posts</th><th>postmeta</th><th>autoload 数量</th><th>过期 transient</th></tr></thead>
                    <tbody>
                    <?php if (empty($trend_report['history'])): ?>
                        <tr><td colspan="6">暂无趋势记录。刷新诊断报告后会自动记录。</td></tr>
                    <?php else: ?>
                        <?php foreach ($trend_report['history'] as $row): ?>
                            <tr><td><?php echo esc_html($row['time']); ?></td><td><?php echo esc_html($row['score']); ?></td><td><?php echo esc_html(number_format_i18n($row['posts'])); ?></td><td><?php echo esc_html(number_format_i18n($row['postmeta'])); ?></td><td><?php echo esc_html(number_format_i18n($row['autoload'])); ?></td><td><?php echo esc_html(number_format_i18n($row['expired_transients'])); ?></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php if (!empty($trend_report['summary'])): ?><p class="wplco-small"><?php echo esc_html($trend_report['summary']); ?></p><?php endif; ?>
                <?php if (!empty($trend_report['deltas'])): ?>
                    <div class="wplco-env">
                        <?php foreach ($trend_report['deltas'] as $row): ?>
                            <div><strong><?php echo esc_html($row['label']); ?> 变化</strong><br><span class="<?php echo esc_attr($row['class']); ?>"><?php echo esc_html(number_format_i18n($row['delta'])); ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p><?php $this->action_button('clear_trend_history', '清空趋势记录', '确定清空性能趋势记录？不会影响数据库内容。'); ?></p>
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
                    <label><input type="checkbox" name="admin_query_cache" value="1" <?php checked($settings['admin_query_cache']); ?>> 启用后台文章列表查询缓存 <span class="wplco-small">缓存相同筛选条件下的文章 ID 列表；文章更新/删除后自动清理。默认关闭。</span></label>
                    <label>查询缓存时间：<input class="wplco-number" type="number" name="admin_query_cache_ttl" min="30" max="600" value="<?php echo esc_attr($settings['admin_query_cache_ttl']); ?>"> 秒 <span class="wplco-small">建议 60-300 秒。</span></label>
                    <label><input type="checkbox" name="admin_ajax_months" value="1" <?php checked($settings['admin_ajax_months']); ?>> AJAX 延迟加载月份筛选 <span class="wplco-small">首屏不跑月份聚合，页面打开后异步加载并缓存。</span></label>
                    <label><input type="checkbox" name="admin_ajax_found_rows" value="1" <?php checked($settings['admin_ajax_found_rows']); ?>> AJAX 延迟加载精确总数 <span class="wplco-small">配合“禁用精确总数统计”使用，首屏先快，随后异步显示总数。</span></label>
                    <label>延迟统计缓存时间：<input class="wplco-number" type="number" name="admin_stats_cache_ttl" min="60" max="3600" value="<?php echo esc_attr($settings['admin_stats_cache_ttl']); ?>"> 秒 <span class="wplco-small">建议 300-1800 秒。</span></label>
                    <hr>
                    <h3>前台轻量优化</h3>
                    <label><input type="checkbox" name="frontend_disable_emoji" value="1" <?php checked($settings['frontend_disable_emoji']); ?>> 禁用 WordPress Emoji 脚本</label>
                    <label><input type="checkbox" name="frontend_disable_embeds" value="1" <?php checked($settings['frontend_disable_embeds']); ?>> 禁用 oEmbed 发现与嵌入脚本 <span class="wplco-small">如果文章需要嵌入 YouTube/推文等，不建议开启。</span></label>
                    <label><input type="checkbox" name="frontend_disable_dashicons" value="1" <?php checked($settings['frontend_disable_dashicons']); ?>> 访客前台禁用 Dashicons</label>
                    <label><input type="checkbox" name="frontend_disable_generator" value="1" <?php checked($settings['frontend_disable_generator']); ?>> 移除 WordPress generator 版本标签</label>
                    <label><input type="checkbox" name="frontend_disable_feed_links" value="1" <?php checked($settings['frontend_disable_feed_links']); ?>> 移除 feed 自动发现链接 <span class="wplco-small">不关闭 feed 地址本身，只减少 head 输出。</span></label>
                    <label><input type="checkbox" name="frontend_disable_rest_links" value="1" <?php checked($settings['frontend_disable_rest_links']); ?>> 移除 REST API 发现链接</label>
                    <label><input type="checkbox" name="frontend_disable_xmlrpc" value="1" <?php checked($settings['frontend_disable_xmlrpc']); ?>> 禁用 XML-RPC <span class="wplco-small">若使用 Jetpack/App 远程发布，请不要开启。</span></label>
                    <label><input type="checkbox" name="frontend_restrict_rest_guests" value="1" <?php checked($settings['frontend_restrict_rest_guests']); ?>> 限制访客访问 REST API <span class="wplco-small">默认关闭；可能影响前端区块/小程序/API 调用。</span></label>
                    <hr>
                    <h3>Heartbeat 控制</h3>
                    <label>Heartbeat 模式：<select name="heartbeat_mode"><option value="keep" <?php selected($settings['heartbeat_mode'], 'keep'); ?>>保持默认</option><option value="reduce" <?php selected($settings['heartbeat_mode'], 'reduce'); ?>>降频</option><option value="disable" <?php selected($settings['heartbeat_mode'], 'disable'); ?>>非编辑页禁用</option></select></label>
                    <label>Heartbeat 间隔：<input class="wplco-number" type="number" name="heartbeat_interval" min="15" max="120" value="<?php echo esc_attr($settings['heartbeat_interval']); ?>"> 秒 <span class="wplco-small">降频模式建议 60-120 秒。</span></label>
                    <hr>
                    <h3>页面缓存</h3>
                    <label><input type="checkbox" name="page_cache_enabled" value="1" <?php checked($settings['page_cache_enabled']); ?>> 启用轻量页面缓存 <span class="wplco-small">默认关闭；如果服务器已有 Nginx FastCGI Cache、LiteSpeed Cache、WP Rocket 等页面缓存，请不要重复开启。</span></label>
                    <label>页面缓存时间：<input class="wplco-number" type="number" name="page_cache_ttl" min="300" max="86400" value="<?php echo esc_attr($settings['page_cache_ttl']); ?>"> 秒 <span class="wplco-small">建议 1800-21600 秒。</span></label>
                    <label><input type="checkbox" name="page_cache_home" value="1" <?php checked($settings['page_cache_home']); ?>> 缓存首页/文章列表首页</label>
                    <label><input type="checkbox" name="page_cache_singular" value="1" <?php checked($settings['page_cache_singular']); ?>> 缓存文章页/页面</label>
                    <label><input type="checkbox" name="page_cache_archive" value="1" <?php checked($settings['page_cache_archive']); ?>> 缓存分类/标签/归档页</label>
                    <label><input type="checkbox" name="page_cache_mobile_variant" value="1" <?php checked($settings['page_cache_mobile_variant']); ?>> 移动端与 PC 分开缓存 <span class="wplco-small">主题移动端输出不同时建议开启。</span></label>
                    <label><input type="checkbox" name="page_cache_stats_enabled" value="1" <?php checked($settings['page_cache_stats_enabled']); ?>> 开启页面缓存命中统计 <span class="wplco-small">记录 HIT/MISS/BYPASS 计数和少量 URL 样本，默认关闭。</span></label>
                    <label>缓存排除路径/模式：<br><textarea name="page_cache_exclude_paths" rows="4" style="width:100%;max-width:680px" placeholder="/checkout/*&#10;/cart/*&#10;/account/*"><?php echo esc_textarea($settings['page_cache_exclude_paths']); ?></textarea><br><span class="wplco-small">每行一条，支持 <code>*</code> 通配符。只匹配 URL 路径，不含域名。</span></label>
                    <hr>
                    <label><input type="checkbox" name="cron_enabled" value="1" <?php checked($settings['cron_enabled']); ?>> 开启每日自动维护</label>
                    <label><input type="checkbox" name="cron_clean_revisions" value="1" <?php checked($settings['cron_clean_revisions']); ?>> 自动清理修订版本</label>
                    <label><input type="checkbox" name="cron_clean_autodrafts" value="1" <?php checked($settings['cron_clean_autodrafts']); ?>> 自动清理自动草稿</label>
                    <label><input type="checkbox" name="cron_clean_trash" value="1" <?php checked($settings['cron_clean_trash']); ?>> 自动清理回收站文章</label>
                    <label><input type="checkbox" name="cron_clean_orphan_postmeta" value="1" <?php checked($settings['cron_clean_orphan_postmeta']); ?>> 自动清理失效 postmeta</label>
                    <label><input type="checkbox" name="cron_clean_expired_transients" value="1" <?php checked($settings['cron_clean_expired_transients']); ?>> 自动清理过期 transient</label>
                    <?php submit_button('保存设置'); ?>
                </form>
            </div>
            <script>
            (function(){
                var root=document.querySelector('.wplco-wrap');
                if(!root){return;}
                var ajaxUrl='<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                var queueNonce='<?php echo esc_js(wp_create_nonce('wplco_queue')); ?>';
                var tabMap={
                    '性能诊断评分':'overview','数据库体检':'overview','分批清理':'overview','安全优化向导':'overview','缓存与环境检查':'overview',
                    'Multisite 兼容检测':'overview','诊断页轻量 Profiling':'overview','数据表大小 TOP':'database','postmeta 热点字段 TOP':'database','autoload 体积 TOP':'database','postmeta 深度治理':'database','autoload 优化器':'database','推荐数据库索引':'database','数据库慢查询风险分析':'database','慢查询 EXPLAIN 采样':'database',
                    '采集站专项体检':'collector','重复标题 TOP':'collector','重复文章处理工具':'collector','已发布重复文章审查器':'collector',
                    'WP-Cron 与采集任务检测':'cron','WooCommerce/Action Scheduler 检测':'cron',
                    '前台性能与缓存检测':'frontend','页面缓存':'frontend','高级缓存就绪检查':'frontend','高级缓存 Drop-in 管理':'frontend','admin-ajax 诊断':'frontend','媒体库体检':'frontend','插件/主题体检':'frontend','性能趋势记录':'logs','数据库维护日志':'logs','设置':'settings'
                };
                var cards=root.querySelectorAll(':scope > .wplco-card, :scope > .wplco-grid > .wplco-card, :scope > .wplco-two > .wplco-card');
                var collapseByDefault=['推荐数据库索引','重复文章处理工具','已发布重复文章审查器','数据库慢查询风险分析','postmeta 深度治理','autoload 优化器','admin-ajax 诊断','媒体库体检','插件/主题体检','WooCommerce/Action Scheduler 检测','慢查询 EXPLAIN 采样'];
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
                var queueBox=root.querySelector('[data-wplco-queue]');
                if(queueBox){
                    var startBtn=queueBox.querySelector('[data-wplco-queue-start]');
                    var pauseBtn=queueBox.querySelector('[data-wplco-queue-pause]');
                    var bar=queueBox.querySelector('[data-wplco-queue-bar]');
                    var status=queueBox.querySelector('[data-wplco-queue-status]');
                    var log=queueBox.querySelector('[data-wplco-queue-log]');
                    var paused=false,running=false,tasks=[],current=0,totalInitial=0;
                    function appendLog(text){log.textContent += '\n' + text; log.scrollTop=log.scrollHeight;}
                    function post(action,data){
                        var form=new FormData(); form.append('action',action); form.append('nonce',queueNonce);
                        Object.keys(data||{}).forEach(function(k){
                            if(Array.isArray(data[k])){data[k].forEach(function(v){form.append(k+'[]',v);});}
                            else{form.append(k,data[k]);}
                        });
                        return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:form}).then(function(r){return r.json();});
                    }
                    function updateProgress(){
                        var remaining=tasks.reduce(function(sum,t){return sum+(parseInt(t.remaining,10)||0);},0);
                        var done=totalInitial>0?Math.max(0,Math.min(100,Math.round((totalInitial-remaining)*100/totalInitial))):100;
                        bar.style.width=done+'%';
                        status.textContent='进度：'+done+'% ｜ 剩余约 '+remaining+' 条';
                    }
                    function nextStep(){
                        if(paused||!running){return;}
                        while(current<tasks.length && parseInt(tasks[current].remaining,10)<=0){current++;}
                        if(current>=tasks.length){running=false;pauseBtn.disabled=true;startBtn.disabled=false;status.textContent='队列清理完成。';appendLog('✅ 队列清理完成');return;}
                        var task=tasks[current];
                        post('wplco_queue_step',{task:task.key}).then(function(res){
                            if(!res||!res.success){throw new Error((res&&res.data&&res.data.message)||'请求失败');}
                            task.remaining=parseInt(res.data.after,10)||0;
                            appendLog('['+res.data.label+'] '+res.data.message+' 剩余：'+task.remaining);
                            updateProgress();
                            if(res.data.done){current++;}
                            window.setTimeout(nextStep,250);
                        }).catch(function(err){running=false;pauseBtn.disabled=true;startBtn.disabled=false;appendLog('❌ '+err.message);status.textContent='队列中断：'+err.message;});
                    }
                    startBtn.addEventListener('click',function(){
                        if(running){return;}
                        var selected=Array.prototype.map.call(queueBox.querySelectorAll('.wplco-queue-options input:checked'),function(i){return i.value;});
                        if(!selected.length){alert('请至少选择一个清理项目。');return;}
                        if(!confirm('开始队列清理？建议已完成数据库备份。')){return;}
                        paused=false;running=true;current=0;startBtn.disabled=true;pauseBtn.disabled=false;bar.style.width='0%';log.textContent='正在统计待清理数量…';
                        post('wplco_queue_start',{tasks:selected}).then(function(res){
                            if(!res||!res.success){throw new Error((res&&res.data&&res.data.message)||'启动失败');}
                            tasks=res.data.tasks||[];totalInitial=tasks.reduce(function(sum,t){return sum+(parseInt(t.total,10)||0);},0);
                            appendLog('批次大小：'+res.data.batch_size);
                            tasks.forEach(function(t){appendLog('['+t.label+'] 待处理：'+t.total);});
                            updateProgress(); nextStep();
                        }).catch(function(err){running=false;pauseBtn.disabled=true;startBtn.disabled=false;appendLog('❌ '+err.message);status.textContent='启动失败：'+err.message;});
                    });
                    pauseBtn.addEventListener('click',function(){
                        paused=!paused; pauseBtn.textContent=paused?'继续':'暂停';
                        appendLog(paused?'⏸ 已暂停':'▶ 继续执行');
                        if(!paused){nextStep();}
                    });
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

    private function action_button($action, $label, $confirm, $button_class = 'secondary') {
        ?>
        <form method="post" onsubmit="return confirm('<?php echo esc_js($confirm); ?>');">
            <?php wp_nonce_field('wplco_action', 'wplco_nonce'); ?>
            <input type="hidden" name="wplco_action" value="<?php echo esc_attr($action); ?>">
            <?php submit_button($label, $button_class, 'submit', false); ?>
        </form>
        <?php
    }

    private function action_button_for_cron_hook($action, $hook, $label, $confirm, $button_class = 'secondary') {
        ?>
        <form method="post" style="display:inline-block;margin:0 4px 4px 0" onsubmit="return confirm('<?php echo esc_js($confirm); ?>');">
            <?php wp_nonce_field('wplco_action', 'wplco_nonce'); ?>
            <input type="hidden" name="wplco_action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="cron_hook" value="<?php echo esc_attr($hook); ?>">
            <?php submit_button($label, $button_class, 'submit', false); ?>
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

        if (!empty($settings['frontend_disable_feed_links'])) {
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        if (!empty($settings['frontend_disable_rest_links'])) {
            remove_action('wp_head', 'rest_output_link_wp_head');
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('template_redirect', 'rest_output_link_header', 11);
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

    public function admin_heartbeat_control($hook_suffix) {
        $settings = $this->settings();
        if (($settings['heartbeat_mode'] ?? 'reduce') !== 'disable') {
            return;
        }
        if (in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
            return;
        }
        wp_deregister_script('heartbeat');
    }

    public function heartbeat_settings($settings) {
        $plugin_settings = $this->settings();
        if (($plugin_settings['heartbeat_mode'] ?? 'reduce') === 'reduce') {
            $settings['interval'] = min(120, max(15, intval($plugin_settings['heartbeat_interval'] ?? 60)));
        }
        return $settings;
    }

    public function maybe_disable_xmlrpc($enabled) {
        $settings = $this->settings();
        return !empty($settings['frontend_disable_xmlrpc']) ? false : $enabled;
    }

    public function maybe_restrict_rest_api($result) {
        if (!empty($result)) {
            return $result;
        }
        $settings = $this->settings();
        if (empty($settings['frontend_restrict_rest_guests']) || is_user_logged_in()) {
            return $result;
        }
        return new WP_Error('wplco_rest_restricted', 'REST API 已限制访客访问。', array('status' => 401));
    }

    private function advanced_cache_path() {
        return WP_CONTENT_DIR . '/advanced-cache.php';
    }

    private function advanced_cache_signature() {
        return 'WLCO_ADVANCED_CACHE_DROPIN';
    }

    private function is_wlco_advanced_cache_dropin($file = '') {
        $file = $file ? $file : $this->advanced_cache_path();
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $head = file_get_contents($file, false, null, 0, 4096);
        return is_string($head) && strpos($head, $this->advanced_cache_signature()) !== false;
    }

    private function advanced_cache_dropin_content() {
        return <<<'PHP'
<?php
/**
 * WLCO_ADVANCED_CACHE_DROPIN
 * Generated by WP Large Content Optimizer. Remove from plugin settings only.
 */
if (!defined('ABSPATH')) {
    // WordPress loads this file before plugins. Keep it standalone and conservative.
}
if (!empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET)) {
    $cookie = isset($_SERVER['HTTP_COOKIE']) ? (string) $_SERVER['HTTP_COOKIE'] : '';
    if ($cookie === '' || !preg_match('/wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart|wp_woocommerce_session_/i', $cookie)) {
        $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', $_SERVER['HTTP_HOST']) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/';
        if ($host && $uri && !preg_match('#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php)#i', $uri)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $variant = preg_match('/Mobile|Android|iPhone|iPad|Opera Mini|IEMobile/i', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') ? 'mobile' : 'desktop';
            $key = md5($variant . '|' . $scheme . $host . $uri);
            $base = dirname(__FILE__) . '/wplco-page-cache';
            $file = $base . '/' . substr($key, 0, 2) . '/' . $key . '.html';
            if (is_file($file) && is_readable($file) && filemtime($file) >= (time() - 3600)) {
                header('Content-Type: text/html; charset=UTF-8');
                header('X-WLCO-Advanced-Cache: HIT');
                header('Cache-Control: public, max-age=60');
                readfile($file);
                exit;
            }
        }
    }
}
PHP;
    }

    private function install_advanced_cache_dropin() {
        $file = $this->advanced_cache_path();
        if (file_exists($file) && !$this->is_wlco_advanced_cache_dropin($file)) {
            return array('type' => 'error', 'message' => '已存在第三方 advanced-cache.php，已拒绝覆盖。');
        }
        if (!is_writable(WP_CONTENT_DIR)) {
            return array('type' => 'error', 'message' => 'wp-content 目录不可写，无法安装 advanced-cache.php。');
        }
        $written = file_put_contents($file, $this->advanced_cache_dropin_content(), LOCK_EX);
        if ($written === false) {
            return array('type' => 'error', 'message' => '写入 advanced-cache.php 失败。');
        }
        @chmod($file, 0644);
        return array('type' => 'success', 'message' => '已安装 WLCO advanced-cache.php。请确认 wp-config.php 中已设置 WP_CACHE=true，且没有其他页面缓存冲突。');
    }

    private function uninstall_advanced_cache_dropin() {
        $file = $this->advanced_cache_path();
        if (!file_exists($file)) {
            return array('type' => 'success', 'message' => 'advanced-cache.php 不存在，无需卸载。');
        }
        if (!$this->is_wlco_advanced_cache_dropin($file)) {
            return array('type' => 'error', 'message' => '当前 advanced-cache.php 不是 WLCO 生成，已拒绝删除。');
        }
        if (!@unlink($file)) {
            return array('type' => 'error', 'message' => '删除 advanced-cache.php 失败，请检查文件权限。');
        }
        return array('type' => 'success', 'message' => '已卸载 WLCO advanced-cache.php。');
    }

    private function page_cache_dir() {
        return trailingslashit(WP_CONTENT_DIR) . 'wplco-page-cache';
    }

    private function page_cache_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : parse_url(home_url(), PHP_URL_HOST);
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return $scheme . strtolower($host) . $uri;
    }

    private function page_cache_variant() {
        $settings = $this->settings();
        if (!empty($settings['page_cache_mobile_variant']) && function_exists('wp_is_mobile') && wp_is_mobile()) {
            return 'mobile';
        }
        return 'desktop';
    }

    private function page_cache_file_for_key($key) {
        $safe = preg_replace('/[^a-f0-9]/', '', $key);
        $sub = substr($safe, 0, 2);
        return trailingslashit($this->page_cache_dir()) . $sub . '/' . $safe . '.html';
    }

    private function page_cache_key() {
        return md5($this->page_cache_variant() . '|' . $this->page_cache_url());
    }

    private function page_cache_exclude_patterns() {
        $settings = $this->settings();
        $raw = isset($settings['page_cache_exclude_paths']) ? (string) $settings['page_cache_exclude_paths'] : '';
        $patterns = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $patterns[] = $line;
            }
        }
        return array_slice(array_unique($patterns), 0, 50);
    }

    private function page_cache_url_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $path ? $path : '/';
    }

    private function is_page_cache_path_excluded() {
        $path = $this->page_cache_url_path();
        foreach ($this->page_cache_exclude_patterns() as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $quoted = preg_quote($pattern, '#');
            $regex = '#^' . str_replace('\\*', '.*', $quoted) . '#i';
            if (@preg_match($regex, $path)) {
                if (preg_match($regex, $path)) {
                    return true;
                }
            } elseif (stripos($path, $pattern) === 0) {
                return true;
            }
        }
        return false;
    }

    private function page_cache_request_bypass_reason() {
        $settings = $this->settings();
        if (empty($settings['page_cache_enabled'])) {
            return 'disabled';
        }
        if (is_admin()) {
            return 'admin';
        }
        if (wp_doing_ajax()) {
            return 'ajax';
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if ($method !== 'GET') {
            return 'method';
        }
        if (is_user_logged_in()) {
            return 'logged_in';
        }
        if (is_preview()) {
            return 'preview';
        }
        if (is_search()) {
            return 'search';
        }
        if (is_feed()) {
            return 'feed';
        }
        if (is_404()) {
            return '404';
        }
        if (is_trackback() || is_robots() || is_embed()) {
            return 'special_request';
        }
        if (!empty($_GET)) {
            return 'query_string';
        }
        foreach ($_COOKIE as $name => $value) {
            if (preg_match('/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_|wp_woocommerce_session_)/', (string) $name)) {
                return 'cookie';
            }
        }
        if ($this->is_page_cache_path_excluded()) {
            return 'excluded_path';
        }
        if ((is_front_page() || is_home()) && !empty($settings['page_cache_home'])) {
            return '';
        }
        if (is_singular() && !empty($settings['page_cache_singular'])) {
            if (post_password_required()) {
                return 'password_protected';
            }
            return '';
        }
        if ((is_category() || is_tag() || is_tax() || is_date() || is_author() || is_post_type_archive()) && !empty($settings['page_cache_archive'])) {
            return '';
        }
        return 'unsupported_template';
    }

    private function is_page_cache_request_allowed() {
        return $this->page_cache_request_bypass_reason() === '';
    }

    private function record_page_cache_stat($status, $reason = '', $key = '') {
        $settings = $this->settings();
        if (empty($settings['page_cache_stats_enabled']) || empty($settings['page_cache_enabled'])) {
            return;
        }
        $status = sanitize_key($status);
        $reason = $reason ? sanitize_key($reason) : '';
        $stats = get_option(self::PAGE_CACHE_STATS_OPTION, array());
        if (!is_array($stats)) {
            $stats = array();
        }
        $now = time();
        $stats['started'] = isset($stats['started']) ? intval($stats['started']) : $now;
        $stats['updated'] = $now;
        foreach (array('hit','miss','bypass','stored','store_skip','advanced_hit') as $name) {
            $stats[$name] = isset($stats[$name]) ? intval($stats[$name]) : 0;
        }
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
        if ($reason) {
            if (empty($stats['reasons']) || !is_array($stats['reasons'])) {
                $stats['reasons'] = array();
            }
            $stats['reasons'][$reason] = isset($stats['reasons'][$reason]) ? intval($stats['reasons'][$reason]) + 1 : 1;
        }
        if (!in_array($status, array('bypass', 'store_skip'), true)) {
            if (empty($stats['samples']) || !is_array($stats['samples'])) {
                $stats['samples'] = array();
            }
            array_unshift($stats['samples'], array(
                'time' => $now,
                'status' => $status,
                'url' => $this->page_cache_url(),
                'variant' => $this->page_cache_variant(),
                'key' => $key,
            ));
            $stats['samples'] = array_slice($stats['samples'], 0, 20);
        }
        update_option(self::PAGE_CACHE_STATS_OPTION, $stats, false);
    }

    public function maybe_serve_page_cache() {
        $bypass_reason = $this->page_cache_request_bypass_reason();
        if ($bypass_reason !== '') {
            $this->record_page_cache_stat('bypass', $bypass_reason);
            if (!headers_sent()) {
                header('X-WLCO-Cache: BYPASS');
                header('X-WLCO-Cache-Reason: ' . $bypass_reason);
            }
            return;
        }
        $settings = $this->settings();
        $ttl = min(DAY_IN_SECONDS, max(300, intval($settings['page_cache_ttl'])));
        $key = $this->page_cache_key();
        $file = $this->page_cache_file_for_key($key);
        if (is_readable($file) && (time() - filemtime($file)) <= $ttl) {
            $this->record_page_cache_stat('hit', '', $key);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
                header('X-WLCO-Cache: HIT');
                header('X-WLCO-Cache-Key: ' . $key);
            }
            readfile($file);
            exit;
        }
        $this->record_page_cache_stat('miss', 'not_found_or_expired', $key);
        if (!headers_sent()) {
            header('X-WLCO-Cache: MISS');
            header('X-WLCO-Cache-Key: ' . $key);
        }
        $this->ensure_page_cache_dir();
        $this->page_cache_active = true;
        $this->page_cache_key = $key;
        $this->page_cache_file = $file;
        $this->page_cache_started = time();
        $this->page_cache_buffer_level = ob_get_level();
        ob_start();
    }

    public function maybe_store_page_cache() {
        if (!$this->page_cache_active || empty($this->page_cache_file) || !ob_get_level()) {
            return;
        }
        if (ob_get_level() !== ($this->page_cache_buffer_level + 1)) {
            return;
        }
        $this->store_page_cache_html(ob_get_contents());
    }

    private function store_page_cache_html($html) {
        if (!is_string($html) || strlen($html) < 512 || stripos($html, '<html') === false) {
            $this->record_page_cache_stat('store_skip', 'invalid_html', $this->page_cache_key);
            return false;
        }
        if (function_exists('http_response_code')) {
            $code = http_response_code();
            if ($code && intval($code) !== 200) {
                $this->record_page_cache_stat('store_skip', 'status_' . intval($code), $this->page_cache_key);
                return false;
            }
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0 || (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false)) {
                $this->record_page_cache_stat('store_skip', 'unsafe_header', $this->page_cache_key);
                return false;
            }
        }
        $dir = dirname($this->page_cache_file);
        if (!$this->ensure_page_cache_dir($dir) || !is_writable($dir)) {
            $this->record_page_cache_stat('store_skip', 'dir_not_writable', $this->page_cache_key);
            return false;
        }
        $meta = "\n<!-- WLCO page cache: " . esc_html(gmdate('c', $this->page_cache_started)) . ' key=' . esc_html($this->page_cache_key) . " -->\n";
        $stored = file_put_contents($this->page_cache_file, $html . $meta, LOCK_EX);
        if ($stored !== false) {
            $this->update_page_cache_meta(array(
                'last_generated' => time(),
                'last_generated_key' => $this->page_cache_key,
            ));
            $this->record_page_cache_stat('stored', '', $this->page_cache_key);
            return true;
        }
        return false;
    }

    private function ensure_page_cache_dir($dir = '') {
        $base = $this->page_cache_dir();
        $target = $dir ? $dir : $base;
        if (!wp_mkdir_p($target)) {
            return false;
        }
        if (!file_exists(trailingslashit($base) . 'index.html')) {
            file_put_contents(trailingslashit($base) . 'index.html', '', LOCK_EX);
        }
        $htaccess = trailingslashit($base) . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n", LOCK_EX);
        }
        return true;
    }

    private function update_page_cache_meta($data) {
        $meta = get_option(self::PAGE_CACHE_META_OPTION, array());
        if (!is_array($meta)) {
            $meta = array();
        }
        $meta = array_merge($meta, $data);
        update_option(self::PAGE_CACHE_META_OPTION, $meta, false);
    }

    public function flush_page_cache_on_content_change() {
        $this->clear_page_cache(false);
    }

    private function clear_page_cache_stats() {
        delete_option(self::PAGE_CACHE_STATS_OPTION);
        return array('type' => 'success', 'message' => '页面缓存命中统计已清空。');
    }

    private function page_cache_warm_urls() {
        $settings = $this->settings();
        $urls = array(home_url('/'));
        if (!empty($settings['page_cache_singular'])) {
            $posts = get_posts(array(
                'post_type' => array('post', 'page'),
                'post_status' => 'publish',
                'posts_per_page' => 20,
                'orderby' => 'modified',
                'order' => 'DESC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));
            foreach ($posts as $post_id) {
                $url = get_permalink($post_id);
                if ($url) {
                    $urls[] = $url;
                }
            }
        }
        if (!empty($settings['page_cache_archive'])) {
            $terms = get_terms(array(
                'taxonomy' => array('category', 'post_tag'),
                'hide_empty' => true,
                'number' => 10,
                'orderby' => 'count',
                'order' => 'DESC',
            ));
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $url = get_term_link($term);
                    if (!is_wp_error($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }
        $urls = array_values(array_unique(array_filter($urls)));
        return array_slice($urls, 0, 30);
    }

    private function warm_page_cache() {
        $settings = $this->settings();
        if (empty($settings['page_cache_enabled'])) {
            return array('type' => 'error', 'message' => '页面缓存未开启，无法预热。');
        }
        $urls = $this->page_cache_warm_urls();
        $ok = 0;
        $fail = 0;
        foreach ($urls as $url) {
            $response = wp_remote_get($url, array(
                'timeout' => 8,
                'redirection' => 2,
                'headers' => array(
                    'User-Agent' => 'WLCO-Cache-Warmer/' . self::VERSION,
                    'Cache-Control' => 'no-cache',
                ),
            ));
            if (is_wp_error($response)) {
                $fail++;
                continue;
            }
            $code = intval(wp_remote_retrieve_response_code($response));
            if ($code >= 200 && $code < 400) {
                $ok++;
            } else {
                $fail++;
            }
        }
        delete_transient('wplco_diagnostic_report');
        return array('type' => $ok ? 'success' : 'error', 'message' => '页面缓存预热完成：成功请求 ' . number_format_i18n($ok) . ' 个 URL，失败 ' . number_format_i18n($fail) . ' 个。');
    }

    private function clear_page_cache($notice = true) {
        $dir = $this->page_cache_dir();
        $removed = 0;
        $bytes = 0;
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $name = $item->getFilename();
                    if (in_array($name, array('index.html', '.htaccess'), true)) {
                        continue;
                    }
                    $bytes += $item->getSize();
                    if (@unlink($item->getPathname())) {
                        $removed++;
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getPathname());
                }
            }
        }
        $this->ensure_page_cache_dir();
        $this->update_page_cache_meta(array(
            'last_cleared' => time(),
            'last_cleared_files' => $removed,
            'last_cleared_bytes' => $bytes,
        ));
        if (!$notice) {
            return array('type' => 'success', 'message' => '页面缓存已清理。');
        }
        return array('type' => 'success', 'message' => '页面缓存已清空：删除 ' . number_format_i18n($removed) . ' 个文件，释放 ' . $this->format_bytes($bytes) . '。');
    }

    private function collect_page_cache_report() {
        $settings = $this->settings();
        $dir = $this->page_cache_dir();
        $files = 0;
        $bytes = 0;
        $oldest = 0;
        $newest = 0;
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if (!$item->isFile() || in_array($item->getFilename(), array('index.html', '.htaccess'), true)) {
                    continue;
                }
                $files++;
                $bytes += $item->getSize();
                $mtime = $item->getMTime();
                $oldest = $oldest ? min($oldest, $mtime) : $mtime;
                $newest = max($newest, $mtime);
            }
        }
        $ttl = min(DAY_IN_SECONDS, max(300, intval($settings['page_cache_ttl'])));
        $meta = get_option(self::PAGE_CACHE_META_OPTION, array());
        if (!is_array($meta)) {
            $meta = array();
        }
        $stats = get_option(self::PAGE_CACHE_STATS_OPTION, array());
        if (!is_array($stats)) {
            $stats = array();
        }
        $hit = isset($stats['hit']) ? intval($stats['hit']) : 0;
        $miss = isset($stats['miss']) ? intval($stats['miss']) : 0;
        $stored = isset($stats['stored']) ? intval($stats['stored']) : 0;
        $bypass = isset($stats['bypass']) ? intval($stats['bypass']) : 0;
        $store_skip = isset($stats['store_skip']) ? intval($stats['store_skip']) : 0;
        $cacheable = $hit + $miss;
        $hit_rate = $cacheable > 0 ? round(($hit / $cacheable) * 100, 1) : 0;
        $reasons = isset($stats['reasons']) && is_array($stats['reasons']) ? $stats['reasons'] : array();
        arsort($reasons);
        $reason_rows = array();
        foreach (array_slice($reasons, 0, 8, true) as $reason => $count) {
            $reason_rows[] = array('reason' => $reason, 'count' => intval($count));
        }
        $sample_rows = isset($stats['samples']) && is_array($stats['samples']) ? array_slice($stats['samples'], 0, 10) : array();
        $recommendations = array();
        if (empty($settings['page_cache_enabled'])) {
            $recommendations[] = '当前未开启本插件页面缓存；如果没有使用服务器级页面缓存，可在“设置”里开启。';
        } else {
            $recommendations[] = '页面缓存已开启。发布、删除文章或评论状态变化时会自动清空缓存，避免旧内容长期保留。';
            $recommendations[] = '当前为轻量页面缓存模式，命中点在 template_redirect；如需更高收益，后续可升级 advanced-cache 高级模式。';
            $recommendations[] = '如服务器已有 Nginx FastCGI Cache、LiteSpeed Cache、WP Rocket 等页面缓存，建议二选一，避免重复缓存。';
            if (!empty($settings['page_cache_stats_enabled']) && $cacheable >= 20 && $hit_rate < 50) {
                $recommendations[] = '当前缓存命中率偏低，建议查看 MISS/BYPASS 原因和排除规则，确认缓存是否被查询参数、Cookie 或模板条件绕过。';
            }
        }
        if (!is_dir($dir)) {
            $recommendations[] = '缓存目录尚未生成，第一次有访客访问可缓存页面后会自动创建。';
        } elseif (!is_writable($dir)) {
            $recommendations[] = '缓存目录不可写，请检查 wp-content/wplco-page-cache 权限。';
        }
        return array(
            'enabled' => !empty($settings['page_cache_enabled']),
            'ttl' => $ttl,
            'ttl_label' => human_time_diff(0, $ttl),
            'files' => $files,
            'bytes' => $bytes,
            'oldest' => $oldest,
            'newest' => $newest,
            'last_generated' => isset($meta['last_generated']) ? intval($meta['last_generated']) : 0,
            'last_cleared' => isset($meta['last_cleared']) ? intval($meta['last_cleared']) : 0,
            'dir' => $dir,
            'stats_enabled' => !empty($settings['page_cache_stats_enabled']),
            'stats' => array(
                'hit' => $hit,
                'miss' => $miss,
                'bypass' => $bypass,
                'stored' => $stored,
                'store_skip' => $store_skip,
                'hit_rate' => $hit_rate,
                'updated' => isset($stats['updated']) ? intval($stats['updated']) : 0,
                'reasons' => $reason_rows,
                'samples' => $sample_rows,
            ),
            'exclude_patterns' => $this->page_cache_exclude_patterns(),
            'warm_candidates' => count($this->page_cache_warm_urls()),
            'recommendations' => array_slice($recommendations, 0, 8),
        );
    }

    private function collect_object_cache_report() {
        global $wp_object_cache;
        $checks = array();
        $recommendations = array();
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $using = wp_using_ext_object_cache();
        $dropin_file = WP_CONTENT_DIR . '/object-cache.php';
        $dropin = file_exists($dropin_file);
        $dropin_size = $dropin ? filesize($dropin_file) : 0;
        $class = is_object($wp_object_cache) ? get_class($wp_object_cache) : 'none';
        $redis_plugin = is_plugin_active('redis-cache/redis-cache.php');
        $memcached_plugin = is_plugin_active('memcached-redux/object-cache.php') || is_plugin_active('w3-total-cache/w3-total-cache.php');
        $supports_flush_runtime = function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime');
        $supports_flush_group = function_exists('wp_cache_supports') && wp_cache_supports('flush_group');
        $supports_get_multiple = function_exists('wp_cache_supports') && wp_cache_supports('get_multiple');
        $global_groups = array();
        $non_persistent_groups = array();
        if (is_object($wp_object_cache)) {
            if (isset($wp_object_cache->global_groups) && is_array($wp_object_cache->global_groups)) {
                $global_groups = array_slice(array_values($wp_object_cache->global_groups), 0, 12);
            }
            if (isset($wp_object_cache->non_persistent_groups) && is_array($wp_object_cache->non_persistent_groups)) {
                $non_persistent_groups = array_slice(array_values($wp_object_cache->non_persistent_groups), 0, 12);
            }
        }

        $checks[] = array('label' => '持久对象缓存', 'value' => $using ? '已启用' : '未启用', 'class' => $using ? 'wplco-ok' : 'wplco-warn', 'hint' => $using ? 'WordPress 正在使用外部对象缓存。' : '文章多时建议启用 Redis Object Cache。');
        $checks[] = array('label' => 'object-cache.php drop-in', 'value' => $dropin ? '存在（' . $this->format_bytes($dropin_size) . '）' : '不存在', 'class' => $dropin ? 'wplco-ok' : 'wplco-warn', 'hint' => $dropin ? '已安装对象缓存 drop-in。' : '未检测到对象缓存 drop-in。');
        $checks[] = array('label' => '对象缓存类', 'value' => $class, 'class' => $using ? 'wplco-ok' : 'wplco-warn', 'hint' => '用于判断当前对象缓存实现。');
        $checks[] = array('label' => 'Redis Cache 插件', 'value' => $redis_plugin ? '已启用' : '未启用/未安装', 'class' => $redis_plugin ? 'wplco-ok' : 'wplco-warn', 'hint' => '推荐大文章站使用 Redis Object Cache。');
        $checks[] = array('label' => '缓存可写测试', 'value' => '待测试', 'class' => 'wplco-warn', 'hint' => '写入/读取同一 key，判断对象缓存基础 API 是否正常。');
        $checks[] = array('label' => 'flush_runtime 支持', 'value' => $supports_flush_runtime ? '支持' : '不支持/未知', 'class' => $supports_flush_runtime ? 'wplco-ok' : 'wplco-warn', 'hint' => '支持时可只清本次请求内存缓存，低风险。');
        $checks[] = array('label' => 'flush_group 支持', 'value' => $supports_flush_group ? '支持' : '不支持/未知', 'class' => $supports_flush_group ? 'wplco-ok' : 'wplco-warn', 'hint' => '支持分组清理时维护更安全。');
        $checks[] = array('label' => 'get_multiple 支持', 'value' => $supports_get_multiple ? '支持' : '不支持/未知', 'class' => $supports_get_multiple ? 'wplco-ok' : 'wplco-warn', 'hint' => '批量读取 API 有助于降低循环查询开销。');

        $test_key = 'wplco_object_cache_test_' . wp_generate_password(8, false);
        wp_cache_set($test_key, 'ok', 'wplco', 30);
        $test = wp_cache_get($test_key, 'wplco');
        $checks[4]['value'] = $test === 'ok' ? '通过' : '失败';
        $checks[4]['class'] = $test === 'ok' ? 'wplco-ok' : 'wplco-danger';

        if (!$using) {
            $recommendations[] = '建议启用 Redis Object Cache。WordPress 的 post、postmeta、term 查询会更容易命中对象缓存。';
        }
        if (!$dropin) {
            $recommendations[] = '未检测到 object-cache.php drop-in，说明持久对象缓存大概率没有真正接管。';
        }
        if (!$redis_plugin && !$memcached_plugin) {
            $recommendations[] = '未检测到常见 Redis/Memcached 缓存插件，建议安装 Redis Object Cache 或使用服务器提供的对象缓存方案。';
        }
        if ($using && $test === 'ok') {
            $recommendations[] = '对象缓存基础状态正常。后续可关注 Redis 命中率、内存占用和 key 数量。';
        }
        if (!$supports_get_multiple) {
            $recommendations[] = '当前对象缓存实现未声明 get_multiple 支持；高并发站点可考虑升级 drop-in 或缓存插件。';
        }
        return array(
            'checks' => $checks,
            'global_groups' => $global_groups,
            'non_persistent_groups' => $non_persistent_groups,
            'recommendations' => array_slice($recommendations, 0, 8),
        );
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
        $local_page_cache = !empty($settings['page_cache_enabled']);
        $checks[] = array('label' => '页面缓存 WP_CACHE', 'value' => $page_cache ? '已开启' : '未开启/未定义', 'class' => $page_cache ? 'wplco-ok' : ($local_page_cache ? 'wplco-ok' : 'wplco-warn'), 'hint' => $page_cache ? '页面缓存插件通常已接管前台缓存。' : ($local_page_cache ? '本插件轻量页面缓存已开启。' : '建议开启页面缓存，前台访问会更稳。'));
        $checks[] = array('label' => '本插件页面缓存', 'value' => $local_page_cache ? '已开启' : '未开启', 'class' => $local_page_cache ? 'wplco-ok' : 'wplco-warn', 'hint' => $local_page_cache ? '访客 HTML 会写入 wp-content/wplco-page-cache。' : '如未使用其他页面缓存，可在设置中开启。');
        if (!$page_cache && !$local_page_cache) {
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


    private function cron_hook_sources($hook) {
        global $wp_filter;
        if (empty($wp_filter[$hook]) || !isset($wp_filter[$hook]->callbacks) || !is_array($wp_filter[$hook]->callbacks)) {
            return array('label' => '未识别', 'detail' => '当前请求中没有注册回调，可能由插件按需注册或已停用。');
        }
        $sources = array();
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $fn = isset($callback['function']) ? $callback['function'] : null;
                if (is_string($fn)) {
                    $sources[] = $fn;
                } elseif (is_array($fn)) {
                    $owner = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];
                    $sources[] = $owner . '::' . (string) $fn[1];
                } elseif ($fn instanceof Closure) {
                    $sources[] = 'Closure';
                }
            }
        }
        $sources = array_values(array_unique(array_filter($sources)));
        if (empty($sources)) {
            return array('label' => '未识别', 'detail' => '未能解析回调来源。');
        }
        return array('label' => implode(', ', array_slice($sources, 0, 2)), 'detail' => implode(', ', array_slice($sources, 0, 6)));
    }

    private function collect_cron_report() {
        $cron = _get_cron_array();
        $now = time();
        $hooks = array();
        $signatures = array();
        $total = 0;
        $overdue = 0;
        $duplicates = 0;
        $collector_events = 0;
        $paused_hooks = $this->paused_cron_hooks();

        foreach ((array) $cron as $timestamp => $events) {
            foreach ((array) $events as $hook => $instances) {
                foreach ((array) $instances as $key => $event) {
                    $total++;
                    if (intval($timestamp) < ($now - 300)) {
                        $overdue++;
                    }
                    $schedule = isset($event['schedule']) ? $event['schedule'] : '';
                    $args = isset($event['args']) ? $event['args'] : array();
                    $sig = $hook . '|' . $schedule . '|' . md5(wp_json_encode($args));
                    if (!isset($signatures[$sig])) {
                        $signatures[$sig] = 0;
                    }
                    $signatures[$sig]++;
                    if (!isset($hooks[$hook])) {
                        $hooks[$hook] = array('hook' => $hook, 'count' => 0, 'next' => intval($timestamp), 'collector' => false);
                    }
                    $hooks[$hook]['count']++;
                    $hooks[$hook]['next'] = min($hooks[$hook]['next'], intval($timestamp));
                    if (preg_match('/caiji|collect|crawl|spider|fetch|采集/i', $hook)) {
                        $hooks[$hook]['collector'] = true;
                        $collector_events++;
                    }
                }
            }
        }

        foreach ($signatures as $count) {
            if ($count > 1) {
                $duplicates += ($count - 1);
            }
        }

        usort($hooks, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $hook_rows = array();
        $collector_rows = array();
        foreach ($hooks as $row) {
            $risk = '低';
            $class = 'wplco-ok';
            if ($row['count'] >= 20) {
                $risk = '高：事件数量较多';
                $class = 'wplco-danger';
            } elseif ($row['count'] >= 5) {
                $risk = '中：可能偏多';
                $class = 'wplco-warn';
            }
            $item = array(
                'hook' => $row['hook'],
                'count' => intval($row['count']),
                'next_run' => $row['next'] ? date_i18n('Y-m-d H:i:s', $row['next']) : '-',
                'risk' => $risk,
                'class' => $class,
                'paused' => in_array($row['hook'], $paused_hooks, true),
                'source' => $this->cron_hook_sources($row['hook']),
            );
            $hook_rows[] = $item;
            if (!empty($row['collector'])) {
                $collector_rows[] = $item;
            }
        }

        $recommendations = array();
        if ($overdue > 0) {
            $recommendations[] = '存在过期未执行的 Cron 事件，可能说明 WP-Cron 触发不稳定，建议检查访问触发或改用服务器计划任务。';
        }
        if ($duplicates > 0) {
            $recommendations[] = '检测到完全重复的 Cron 事件，可使用“清理重复 Cron 事件”每组保留一条。';
        }
        if ($collector_events > 0) {
            $recommendations[] = '检测到采集相关 Cron 事件，请确认采集频率不要过高，建议使用随机延迟或错峰执行。';
        }
        if (!empty($paused_hooks)) {
            $recommendations[] = '已有 ' . count($paused_hooks) . ' 个 Hook 被暂停新调度；请定期确认是否仍需要暂停，避免影响插件正常任务。';
        }
        if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            $recommendations[] = '当前 WP-Cron 可能仍由访问触发。大站建议设置 DISABLE_WP_CRON，并用服务器 crontab 定时调用 wp-cron.php。';
        }
        if (empty($recommendations)) {
            $recommendations[] = '当前 Cron 任务未发现明显风险。';
        }

        return array(
            'total_events' => $total,
            'overdue_events' => $overdue,
            'duplicate_events' => $duplicates,
            'collector_events' => $collector_events,
            'paused_count' => count($paused_hooks),
            'paused_hooks' => $paused_hooks,
            'hooks' => array_slice($hook_rows, 0, 15),
            'collector_hooks' => array_slice($collector_rows, 0, 10),
            'recommendations' => array_slice($recommendations, 0, 6),
        );
    }

    private function paused_cron_hooks() {
        $settings = $this->settings();
        $hooks = isset($settings['cron_paused_hooks']) && is_array($settings['cron_paused_hooks']) ? $settings['cron_paused_hooks'] : array();
        $hooks = array_values(array_unique(array_filter(array_map('strval', $hooks))));
        return $hooks;
    }

    private function requested_cron_hook() {
        $hook = isset($_POST['cron_hook']) ? sanitize_text_field(wp_unslash($_POST['cron_hook'])) : '';
        $hook = preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', $hook);
        return substr($hook, 0, 191);
    }

    public function maybe_block_paused_cron_hook($event) {
        if (!is_object($event) || empty($event->hook)) {
            return $event;
        }
        if (in_array($event->hook, $this->paused_cron_hooks(), true)) {
            return false;
        }
        return $event;
    }

    private function pause_cron_hook() {
        $hook = $this->requested_cron_hook();
        if ($hook === '' || $hook === self::CRON_HOOK) {
            return array('type' => 'error', 'message' => 'Hook 无效或不允许暂停本插件维护任务。');
        }
        $settings = $this->settings();
        $hooks = $this->paused_cron_hooks();
        if (!in_array($hook, $hooks, true)) {
            $hooks[] = $hook;
        }
        $settings['cron_paused_hooks'] = array_values(array_unique($hooks));
        update_option(self::OPTION, $settings, false);
        return array('type' => 'success', 'message' => '已暂停 Hook 的新 Cron 调度：' . $hook . '。已存在事件不会自动删除。');
    }

    private function resume_cron_hook() {
        $hook = $this->requested_cron_hook();
        if ($hook === '') {
            return array('type' => 'error', 'message' => 'Hook 无效。');
        }
        $settings = $this->settings();
        $settings['cron_paused_hooks'] = array_values(array_diff($this->paused_cron_hooks(), array($hook)));
        update_option(self::OPTION, $settings, false);
        return array('type' => 'success', 'message' => '已恢复 Hook 的新 Cron 调度：' . $hook . '。');
    }

    private function unschedule_cron_hook() {
        $hook = $this->requested_cron_hook();
        if ($hook === '' || $hook === self::CRON_HOOK) {
            return array('type' => 'error', 'message' => 'Hook 无效或不允许删除本插件维护任务。');
        }
        $cron = _get_cron_array();
        $removed = 0;
        foreach ((array) $cron as $timestamp => $events) {
            if (empty($events[$hook])) {
                continue;
            }
            foreach ((array) $events[$hook] as $event) {
                $args = isset($event['args']) ? $event['args'] : array();
                if (wp_unschedule_event(intval($timestamp), $hook, $args)) {
                    $removed++;
                }
            }
        }
        delete_transient('wplco_diagnostic_report');
        return array('type' => 'success', 'message' => '已删除 Hook `' . $hook . '` 当前计划事件：' . number_format_i18n($removed) . ' 个。');
    }

    private function clean_duplicate_cron_events() {
        $cron = _get_cron_array();
        $seen = array();
        $removed = 0;
        foreach ((array) $cron as $timestamp => $events) {
            foreach ((array) $events as $hook => $instances) {
                foreach ((array) $instances as $key => $event) {
                    $schedule = isset($event['schedule']) ? $event['schedule'] : '';
                    $args = isset($event['args']) ? $event['args'] : array();
                    $sig = $hook . '|' . $schedule . '|' . md5(wp_json_encode($args));
                    if (isset($seen[$sig])) {
                        wp_unschedule_event(intval($timestamp), $hook, $args);
                        $removed++;
                    } else {
                        $seen[$sig] = true;
                    }
                }
            }
        }
        delete_transient('wplco_diagnostic_report');
        if ($removed <= 0) {
            return array('type' => 'success', 'message' => '未发现需要清理的重复 Cron 事件。');
        }
        return array('type' => 'success', 'message' => '已清理重复 Cron 事件：' . number_format_i18n($removed) . ' 个。');
    }


    private function collect_trend_report($stats) {
        $history = get_option('wplco_trend_history', array());
        if (!is_array($history)) {
            $history = array();
        }
        $get = function($label) use ($stats) {
            return isset($stats[$label]['value']) ? intval($stats[$label]['value']) : 0;
        };
        $score = $this->build_diagnosis($stats, $this->recommended_indexes_status());
        $snapshot = array(
            'time' => current_time('mysql'),
            'score' => intval($score['score']),
            'posts' => $get('文章/页面/附件总数 wp_posts'),
            'postmeta' => $get('postmeta 总数'),
            'autoload' => $get('autoload options 数量'),
            'expired_transients' => $get('过期 transient'),
        );
        $last = !empty($history) ? end($history) : null;
        if (!$last || substr($last['time'], 0, 16) !== substr($snapshot['time'], 0, 16)) {
            $history[] = $snapshot;
            $history = array_slice($history, -30);
            update_option('wplco_trend_history', $history, false);
        }
        $summary = '';
        $deltas = array();
        if (count($history) >= 2) {
            $first = reset($history);
            $latest = end($history);
            $metrics = array('score' => '健康分', 'posts' => 'wp_posts', 'postmeta' => 'postmeta', 'autoload' => 'autoload 数量', 'expired_transients' => '过期 transient');
            foreach ($metrics as $key => $label) {
                $delta = intval($latest[$key]) - intval($first[$key]);
                $deltas[] = array('label' => $label, 'delta' => $delta, 'class' => (($key === 'score' && $delta >= 0) || ($key !== 'score' && $delta <= 0)) ? 'wplco-ok' : 'wplco-warn');
            }
            $delta_meta = intval($latest['postmeta']) - intval($first['postmeta']);
            $delta_posts = intval($latest['posts']) - intval($first['posts']);
            $summary = '最近 ' . count($history) . ' 次记录：wp_posts 变化 ' . number_format_i18n($delta_posts) . '，postmeta 变化 ' . number_format_i18n($delta_meta) . '。';
        }
        return array('history' => array_reverse($history), 'summary' => $summary, 'deltas' => $deltas);
    }

    private function clean_action_scheduler_actions($status) {
        global $wpdb;
        $status = $status === 'failed' ? 'failed' : 'complete';
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $actions_table)) !== $actions_table) {
            return array('type' => 'error', 'message' => '未检测到 Action Scheduler 数据表。');
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT action_id FROM {$actions_table} WHERE status=%s AND scheduled_date_gmt < %s ORDER BY scheduled_date_gmt ASC LIMIT 500", $status, $cutoff));
        $ids = array_map('intval', (array) $ids);
        if (empty($ids)) {
            return array('type' => 'success', 'message' => ($status === 'failed' ? '失败' : '已完成') . ' Action Scheduler 任务中没有 30 天前的可清理记录。');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs_table)) === $logs_table) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$logs_table} WHERE action_id IN ($placeholders)", $ids));
        }
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$actions_table} WHERE action_id IN ($placeholders)", $ids));
        return array('type' => 'success', 'message' => '已清理 ' . number_format_i18n(intval($deleted)) . ' 条 30 天前的 ' . ($status === 'failed' ? '失败' : '已完成') . ' Action Scheduler 任务记录（单次最多 500 条）。');
    }

    private function collect_commerce_report() {
        global $wpdb;
        $woocommerce = class_exists('WooCommerce') || in_array('woocommerce/woocommerce.php', (array) get_option('active_plugins', array()), true);
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $groups_table = $wpdb->prefix . 'actionscheduler_groups';
        $has_actions = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $actions_table)) === $actions_table;
        $pending = 0;
        $failed = 0;
        $complete = 0;
        $running = 0;
        $stale_pending = 0;
        $old_complete = 0;
        $old_failed = 0;
        $top_hooks = array();
        $status_rows = array();
        $group_rows = array();
        $oldest_pending = '';
        if ($has_actions) {
            $pending = intval($wpdb->get_var("SELECT COUNT(*) FROM {$actions_table} WHERE status='pending'"));
            $failed = intval($wpdb->get_var("SELECT COUNT(*) FROM {$actions_table} WHERE status='failed'"));
            $complete = intval($wpdb->get_var("SELECT COUNT(*) FROM {$actions_table} WHERE status='complete'"));
            $running = intval($wpdb->get_var("SELECT COUNT(*) FROM {$actions_table} WHERE status='in-progress'"));
            $stale_pending = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$actions_table} WHERE status='pending' AND scheduled_date_gmt < %s", gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS))));
            $old_complete = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$actions_table} WHERE status='complete' AND scheduled_date_gmt < %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))));
            $old_failed = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$actions_table} WHERE status='failed' AND scheduled_date_gmt < %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))));
            $oldest_pending = (string) $wpdb->get_var("SELECT scheduled_date_gmt FROM {$actions_table} WHERE status='pending' ORDER BY scheduled_date_gmt ASC LIMIT 1");
            $rows = $wpdb->get_results("SELECT hook, status, COUNT(*) AS total FROM {$actions_table} WHERE status IN ('pending','failed','in-progress') GROUP BY hook, status ORDER BY total DESC LIMIT 12", ARRAY_A);
            foreach ((array) $rows as $row) {
                $top_hooks[] = array('hook' => $row['hook'], 'status' => $row['status'], 'count' => intval($row['total']));
            }
            $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$actions_table} GROUP BY status ORDER BY total DESC", ARRAY_A);
            foreach ((array) $rows as $row) {
                $status_rows[] = array('status' => $row['status'], 'count' => intval($row['total']));
            }
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $groups_table)) === $groups_table) {
                $rows = $wpdb->get_results("SELECT g.slug, COUNT(*) AS total FROM {$actions_table} a LEFT JOIN {$groups_table} g ON a.group_id=g.group_id WHERE a.status IN ('pending','failed','in-progress') GROUP BY g.slug ORDER BY total DESC LIMIT 8", ARRAY_A);
                foreach ((array) $rows as $row) {
                    $group_rows[] = array('group' => $row['slug'] ? $row['slug'] : '(none)', 'count' => intval($row['total']));
                }
            }
        }
        $recommendations = array();
        if (!$woocommerce && !$has_actions) {
            $recommendations[] = '未检测到 WooCommerce / Action Scheduler，可忽略本模块。';
        }
        if ($pending > 1000) {
            $recommendations[] = 'Action Scheduler 待执行任务超过 1000，建议检查队列是否堵塞、WP-Cron 是否稳定、采集/同步任务是否过频。';
        }
        if ($stale_pending > 0) {
            $recommendations[] = '存在超过 1 小时仍未执行的待执行任务，建议检查 WP-Cron、服务器计划任务或 Action Scheduler runner。';
        }
        if ($failed > 0) {
            $recommendations[] = '存在失败 Action Scheduler 任务，建议先查看失败原因；30 天前的失败记录可手动清理。';
        }
        if ($old_complete > 1000) {
            $recommendations[] = '30 天前已完成 Action Scheduler 记录较多，可分批清理以降低数据表体积。';
        }
        if ($woocommerce) {
            $recommendations[] = 'WooCommerce 站点建议重点关注订单表、Action Scheduler、购物车 fragments 与对象缓存命中率。';
        }
        if (empty($recommendations)) {
            $recommendations[] = 'Action Scheduler 基础状态正常。';
        }
        return array(
            'woocommerce' => $woocommerce ? '已检测到' : '未检测到',
            'woocommerce_class' => $woocommerce ? 'wplco-warn' : 'wplco-ok',
            'scheduler' => $has_actions ? '已检测到' : '未检测到',
            'scheduler_class' => $has_actions ? 'wplco-ok' : 'wplco-warn',
            'pending_actions' => $pending,
            'failed_actions' => $failed,
            'complete_actions' => $complete,
            'running_actions' => $running,
            'stale_pending_actions' => $stale_pending,
            'old_complete_actions' => $old_complete,
            'old_failed_actions' => $old_failed,
            'oldest_pending' => $oldest_pending,
            'top_hooks' => $top_hooks,
            'status_rows' => $status_rows,
            'group_rows' => $group_rows,
            'recommendations' => array_slice($recommendations, 0, 8),
        );
    }

    private function collect_explain_report() {
        global $wpdb;
        $samples = array();
        $queries = array(
            array('label' => '后台文章列表', 'advice' => '应尽量使用 type_status_date 或类似 post_type/post_status/post_date 索引。', 'sql' => "EXPLAIN SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 20"),
            array('label' => '后台分页偏移', 'advice' => 'OFFSET 很大时仍会扫描/排序较多行，建议后台快速模式限制每页数量。', 'sql' => "EXPLAIN SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 1000, 20"),
            array('label' => 'postmeta 按文章读取', 'advice' => '应使用 post_id 索引；文章详情页和后台编辑页依赖此查询。', 'sql' => "EXPLAIN SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=1 LIMIT 20"),
            array('label' => 'postmeta 按 meta_key 扫描', 'advice' => 'meta_key 数据量大且选择性低时容易慢，避免前台按低选择性字段筛选。', 'sql' => "EXPLAIN SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' LIMIT 20"),
            array('label' => 'postmeta key/value 筛选', 'advice' => 'meta_value 常难以有效索引，采集站应避免复杂 meta_query 作为前台主筛选。', 'sql' => "EXPLAIN SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value<>'' LIMIT 20"),
            array('label' => '分类关系读取', 'advice' => '分类页应使用 term_taxonomy_id 索引，并配合页面缓存。', 'sql' => "EXPLAIN SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=1 LIMIT 20"),
            array('label' => 'autoload options', 'advice' => 'autoload 过大时影响所有请求；索引只能减轻查找，关键仍是减少体积。', 'sql' => "EXPLAIN SELECT option_name, option_value FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')"),
            array('label' => '附件列表', 'advice' => '媒体库很大时后台列表需要对象缓存，并避免无谓筛选。', 'sql' => "EXPLAIN SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' ORDER BY post_date DESC LIMIT 20"),
        );
        foreach ($queries as $query) {
            $rows = $wpdb->get_results($query['sql'], ARRAY_A);
            if (empty($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $type = isset($row['type']) ? $row['type'] : '';
                $key = isset($row['key']) && $row['key'] !== null ? $row['key'] : '';
                $possible = isset($row['possible_keys']) && $row['possible_keys'] !== null ? $row['possible_keys'] : '';
                $extra = isset($row['Extra']) ? $row['Extra'] : '';
                $examined = isset($row['rows']) ? intval($row['rows']) : 0;
                $risk = '低';
                $class = 'wplco-ok';
                if ($type === 'ALL' && $examined > 10000) {
                    $risk = '高：全表扫描且预估行数较多';
                    $class = 'wplco-danger';
                } elseif ($type === 'ALL' || $examined > 50000 || stripos($extra, 'Using filesort') !== false) {
                    $risk = '中：可能扫描/排序较多行';
                    $class = 'wplco-warn';
                }
                $samples[] = array(
                    'label' => $query['label'],
                    'table' => isset($row['table']) ? $row['table'] : '',
                    'type' => $type ?: '-',
                    'possible_keys' => $possible ?: '-',
                    'key' => $key ?: '-',
                    'rows' => $examined ? number_format_i18n($examined) : '-',
                    'extra' => $extra ?: '-',
                    'advice' => $query['advice'],
                    'risk' => $risk,
                    'class' => $class,
                );
            }
        }
        $recommendations = array();
        foreach ($samples as $sample) {
            if ($sample['class'] === 'wplco-danger') {
                $recommendations[] = 'EXPLAIN 发现高风险全表扫描：' . $sample['label'] . '。建议检查推荐索引、后台快速模式和相关插件查询。';
                break;
            }
        }
        foreach ($samples as $sample) {
            if ($sample['class'] === 'wplco-warn' && stripos($sample['extra'], 'Using filesort') !== false) {
                $recommendations[] = 'EXPLAIN 发现 filesort：' . $sample['label'] . '。数据量大时建议配合页面缓存/对象缓存，后台列表减少深分页。';
                break;
            }
        }
        if (empty($recommendations)) {
            $recommendations[] = '固定样本 EXPLAIN 未发现明显高风险；真实慢 SQL 仍建议结合 Query Monitor 或 MySQL 慢查询日志。';
        }
        return array('samples' => $samples, 'recommendations' => array_slice($recommendations, 0, 6));
    }

    private function collect_admin_filter_report() {
        $settings = $this->settings();
        $enabled = !empty($settings['admin_filter_slim']);
        $recommendations = array();
        if ($enabled) {
            $recommendations[] = '已开启精简模式：文章列表顶部日期、分类、作者等重筛选器会被视觉压缩，降低误触重查询概率。';
            $recommendations[] = '如果管理员仍需要频繁使用这些筛选器，可随时在设置里关闭。';
        } else {
            $recommendations[] = '未开启精简模式。文章量很大时，顶部筛选器可能触发月份、分类或作者维度的重查询。';
        }
        return array(
            'enabled' => $enabled,
            'screen' => 'edit.php 文章/页面列表',
            'mode' => 'CSS 视觉精简，不改变已有查询参数',
            'recommendations' => $recommendations,
        );
    }

    private function collect_ajax_report() {
        global $wp_filter;
        $count_callbacks = function($hook) use ($wp_filter) {
            if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
                return 0;
            }
            $total = 0;
            foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                $total += count($callbacks);
            }
            return $total;
        };
        $priv = array();
        $nopriv = array();
        foreach (array_keys((array) $wp_filter) as $hook) {
            if (strpos($hook, 'wp_ajax_nopriv_') === 0) {
                $nopriv[] = array('hook' => substr($hook, 15), 'callbacks' => $count_callbacks($hook));
            } elseif (strpos($hook, 'wp_ajax_') === 0) {
                $priv[] = array('hook' => substr($hook, 8), 'callbacks' => $count_callbacks($hook));
            }
        }
        usort($nopriv, function($a, $b) { return $b['callbacks'] <=> $a['callbacks']; });
        $settings = $this->settings();
        $mode = $settings['heartbeat_mode'] ?? 'reduce';
        $recommendations = array();
        if (count($nopriv) > 20) {
            $recommendations[] = '访客 admin-ajax hook 较多，建议排查统计、弹窗、表单、采集或前端插件是否频繁请求 admin-ajax.php。';
        }
        if ($mode === 'keep') {
            $recommendations[] = 'Heartbeat 仍保持默认。后台用户较多或编辑页长期打开时，建议改为降频。';
        } elseif ($mode === 'disable') {
            $recommendations[] = 'Heartbeat 已设置为非编辑页禁用，可降低后台空闲 AJAX 压力。';
        } else {
            $recommendations[] = 'Heartbeat 已降频，适合大站后台常驻页面。';
        }
        return array(
            'priv_count' => count($priv),
            'nopriv_count' => count($nopriv),
            'nopriv_hooks' => array_slice($nopriv, 0, 12),
            'heartbeat' => $mode === 'keep' ? '默认' : ($mode === 'disable' ? '非编辑页禁用' : '降频'),
            'heartbeat_class' => $mode === 'keep' ? 'wplco-warn' : 'wplco-ok',
            'recommendations' => $recommendations,
        );
    }

    private function collect_media_report() {
        global $wpdb;
        $attachments = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", 'attachment')));
        $unattached = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_parent=0", 'attachment')));
        $missing_metadata = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type=%s AND pm.meta_id IS NULL", '_wp_attachment_metadata', 'attachment')));
        $missing_file = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type=%s AND pm.meta_id IS NULL", '_wp_attached_file', 'attachment')));
        $large_originals = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type=%s AND pm.meta_key=%s AND LENGTH(pm.meta_value) > %d", 'attachment', '_wp_attachment_metadata', 50000)));
        $mime_rows = $wpdb->get_results($wpdb->prepare("SELECT post_mime_type AS mime, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_type=%s GROUP BY post_mime_type ORDER BY total DESC LIMIT 8", 'attachment'), ARRAY_A);
        $mime_types = array();
        foreach ((array) $mime_rows as $row) {
            $mime_types[] = array('mime' => $row['mime'] !== '' ? $row['mime'] : '(empty)', 'count' => intval($row['total']));
        }
        $samples = array();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, p.post_title, CASE WHEN filepm.meta_id IS NULL THEN %s WHEN metapm.meta_id IS NULL THEN %s WHEN p.post_parent=0 THEN %s ELSE %s END AS issue FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} metapm ON metapm.post_id=p.ID AND metapm.meta_key=%s LEFT JOIN {$wpdb->postmeta} filepm ON filepm.post_id=p.ID AND filepm.meta_key=%s WHERE p.post_type=%s AND (p.post_parent=0 OR metapm.meta_id IS NULL OR filepm.meta_id IS NULL) ORDER BY p.ID DESC LIMIT 12", '缺少文件路径', '缺少元数据', '未挂载', '需审查', '_wp_attachment_metadata', '_wp_attached_file', 'attachment'), ARRAY_A);
        foreach ((array) $rows as $row) {
            $samples[] = array(
                'id' => intval($row['ID']),
                'title' => $row['post_title'] !== '' ? $row['post_title'] : '(无标题)',
                'issue' => $row['issue'],
                'edit_url' => get_edit_post_link(intval($row['ID']), ''),
            );
        }
        $recommendations = array();
        if ($attachments > 50000) {
            $recommendations[] = '附件数量很高，媒体库查询和备份会变慢，建议启用对象缓存/CDN，并定期审查未使用媒体。';
        }
        if ($unattached > 1000) {
            $recommendations[] = '未挂载附件较多，但不能直接视为垃圾；建议结合内容字段和主题引用人工审查。';
        }
        if ($missing_metadata > 0) {
            $recommendations[] = '存在缺少附件元数据的媒体，可能影响缩略图/响应式图片生成。建议抽样再生缩略图，不建议批量删除。';
        }
        if ($missing_file > 0) {
            $recommendations[] = '存在缺少 _wp_attached_file 的附件，可能是导入/迁移异常；建议先抽样确认文件是否存在。';
        }
        if ($large_originals > 1000) {
            $recommendations[] = '较多附件元数据很大，可能由超多缩略图尺寸或图片处理插件造成；建议审查缩略图尺寸策略。';
        }
        if (empty($recommendations)) {
            $recommendations[] = '媒体库基础状态正常。';
        }
        return array('attachments' => $attachments, 'unattached' => $unattached, 'missing_metadata' => $missing_metadata, 'missing_file' => $missing_file, 'large_originals' => $large_originals, 'mime_types' => $mime_types, 'samples' => $samples, 'recommendations' => $recommendations);
    }

    private function collect_advanced_cache_report() {
        $dropin = $this->advanced_cache_path();
        $has_dropin = file_exists($dropin);
        $owned = $this->is_wlco_advanced_cache_dropin($dropin);
        $wp_cache = defined('WP_CACHE') && WP_CACHE;
        $recommendations = array();
        if (!$wp_cache) {
            $recommendations[] = 'WP_CACHE 未开启；高级页面缓存 drop-in 即使存在也通常不会生效。';
        }
        if ($has_dropin && !$owned) {
            $recommendations[] = '检测到第三方 advanced-cache.php，本插件不会覆盖或删除它。请继续使用现有缓存方案或先人工确认来源。';
        } elseif ($owned) {
            $recommendations[] = '当前 advanced-cache.php 由 WLCO 生成，可在本页安全卸载。';
        } else {
            $recommendations[] = '未检测到 advanced-cache.php。如没有服务器级页面缓存，可考虑安装 WLCO drop-in；默认不自动安装。';
        }
        $recommendations[] = '如服务器已有 Nginx FastCGI Cache、LiteSpeed Cache、Cloudflare APO 或 WP Rocket，不建议再叠加高级 drop-in。';
        return array(
            'wp_cache' => $wp_cache ? '已开启' : '未开启',
            'wp_cache_class' => $wp_cache ? 'wplco-ok' : 'wplco-warn',
            'dropin' => $has_dropin ? '已存在' : '未发现',
            'dropin_class' => $has_dropin ? ($owned ? 'wplco-ok' : 'wplco-warn') : 'wplco-ok',
            'owner' => $has_dropin ? ($owned ? 'WLCO' : '第三方/未知') : '无',
            'owner_class' => $has_dropin ? ($owned ? 'wplco-ok' : 'wplco-warn') : 'wplco-ok',
            'owned' => $owned,
            'installable' => !$has_dropin || $owned,
            'recommendations' => $recommendations,
        );
    }

    private function collect_plugin_theme_report() {
        $active = (array) get_option('active_plugins', array());
        $cache_keywords = array('cache','rocket','litespeed','redis','w3-total','wp-super-cache','autoptimize','sg-cachepress','breeze','cloudflare');
        $notable = array();
        foreach ($active as $plugin) {
            $lower = strtolower($plugin);
            foreach ($cache_keywords as $keyword) {
                if (strpos($lower, $keyword) !== false) {
                    $notable[] = array('plugin' => $plugin, 'hint' => '可能影响缓存/资源优化，请避免功能重复开启。');
                    break;
                }
            }
        }
        $recommendations = array();
        if (count($active) > 40) {
            $recommendations[] = '启用插件超过 40 个，建议排查慢插件、重复功能插件和前台加载资源。';
        }
        if (!empty($notable)) {
            $recommendations[] = '检测到缓存/优化类插件，请避免与本插件页面缓存、前台瘦身功能重复。';
        }
        if (empty($recommendations)) {
            $recommendations[] = '插件数量和缓存冲突风险暂未发现明显异常。';
        }
        $theme = wp_get_theme();
        return array('active_plugins' => count($active), 'cache_plugins' => count($notable), 'notable_plugins' => array_slice($notable, 0, 12), 'theme' => $theme->get('Name') . ' ' . $theme->get('Version'), 'recommendations' => $recommendations);
    }

    private function collect_multisite_report() {
        global $wpdb;
        $is_multi = is_multisite();
        $site_count = 1;
        $current_blog_id = function_exists('get_current_blog_id') ? intval(get_current_blog_id()) : 1;
        if ($is_multi && function_exists('get_sites')) {
            $site_count = intval(get_sites(array('count' => true)));
        }
        $checks = array();
        $checks[] = array('label' => 'Multisite', 'value' => $is_multi ? '已启用' : '单站点', 'class' => $is_multi ? 'wplco-warn' : 'wplco-ok', 'hint' => $is_multi ? '当前诊断主要针对当前站点表，网络级治理需逐站评估。' : '当前为单站点。');
        $checks[] = array('label' => '当前 Blog ID', 'value' => (string) $current_blog_id, 'class' => 'wplco-ok', 'hint' => '用于确认当前诊断对应哪个站点。');
        $checks[] = array('label' => '站点数量', 'value' => number_format_i18n($site_count), 'class' => ($is_multi && $site_count > 20) ? 'wplco-warn' : 'wplco-ok', 'hint' => $is_multi ? '站点很多时建议分站点检查文章量、缓存和 Cron。' : '');
        $checks[] = array('label' => '当前表前缀', 'value' => $wpdb->prefix, 'class' => 'wplco-ok', 'hint' => '本插件清理/诊断使用当前站点表前缀。');
        $network_active = false;
        if ($is_multi) {
            if (!function_exists('is_plugin_active_for_network')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (function_exists('is_plugin_active_for_network')) {
                $network_active = is_plugin_active_for_network(plugin_basename(__FILE__));
            }
        }
        $checks[] = array('label' => '网络启用', 'value' => $network_active ? '是' : '否/不适用', 'class' => $network_active ? 'wplco-warn' : 'wplco-ok', 'hint' => $network_active ? '网络启用时请谨慎使用清理按钮，确认当前站点上下文。' : '');
        $recommendations = array();
        if ($is_multi) {
            $recommendations[] = 'Multisite 环境下，本页只诊断当前站点数据表；不要把当前站点结果直接套用到全网络。';
            $recommendations[] = '页面缓存、Cron 暂停、Action Scheduler 清理建议逐站确认，避免影响其他子站业务。';
        } else {
            $recommendations[] = '当前为单站点，现有清理和诊断范围较明确。';
        }
        return array('is_multisite' => $is_multi, 'checks' => $checks, 'recommendations' => $recommendations);
    }

    private function collect_runtime_profile_report($started, $start_queries, $cached) {
        global $wpdb;
        $elapsed = max(0, microtime(true) - floatval($started));
        $queries = max(0, intval($wpdb->num_queries) - intval($start_queries));
        $memory = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : 0;
        $checks = array(
            array('label' => '诊断来源', 'value' => $cached ? '缓存报告' : '实时生成', 'class' => $cached ? 'wplco-ok' : 'wplco-warn', 'hint' => $cached ? '使用 10 分钟 transient 缓存，避免每次重查。' : '本次重新采集诊断数据。'),
            array('label' => '诊断耗时', 'value' => number_format_i18n($elapsed, 3) . ' 秒', 'class' => $elapsed > 3 ? 'wplco-warn' : 'wplco-ok', 'hint' => '这是插件诊断页自身的轻量 profiling，不代表前台真实 TTFB。'),
            array('label' => '诊断 SQL 数', 'value' => number_format_i18n($queries), 'class' => $queries > 80 ? 'wplco-warn' : 'wplco-ok', 'hint' => '仅统计本次诊断过程增加的 $wpdb 查询数。'),
            array('label' => 'PHP 峰值内存', 'value' => $this->format_bytes($memory), 'class' => $memory > 128 * 1024 * 1024 ? 'wplco-warn' : 'wplco-ok', 'hint' => '用于判断后台诊断是否过重。'),
        );
        $recommendations = array();
        if (!$cached && ($elapsed > 3 || $queries > 80)) {
            $recommendations[] = '诊断页本身消耗偏高，建议保持诊断缓存，不要频繁刷新；后续可按模块拆分刷新。';
        } else {
            $recommendations[] = '诊断页轻量 profiling 正常。真实前台性能仍建议用浏览器瀑布图、Query Monitor 或服务器 APM 交叉验证。';
        }
        return array('checks' => $checks, 'recommendations' => $recommendations);
    }

    private function get_diagnostic_report() {
        $profile_started = microtime(true);
        $profile_start_queries = isset($GLOBALS['wpdb']->num_queries) ? intval($GLOBALS['wpdb']->num_queries) : 0;
        $cached = get_transient('wplco_diagnostic_report');
        if (is_array($cached)) {
            if (empty($cached['runtime_profile_report'])) {
                $cached['runtime_profile_report'] = $this->collect_runtime_profile_report($profile_started, $profile_start_queries, true);
            }
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
            'postmeta_deep_report' => $this->collect_postmeta_deep_report(),
            'autoload_optimizer_report' => $this->collect_autoload_optimizer_report(),
            'environment' => $this->collect_environment(),
            'wizard_steps' => $this->build_wizard_steps($stats, $indexes),
            'collector_stats' => $this->collect_collector_stats(),
            'duplicate_titles' => $this->collect_duplicate_titles(),
            'duplicate_draft_groups' => $this->collect_duplicate_draft_groups(),
            'published_duplicate_groups' => $this->collect_published_duplicate_groups(),
            'frontend_report' => $this->collect_frontend_report(),
            'object_cache_report' => $this->collect_object_cache_report(),
            'page_cache_report' => $this->collect_page_cache_report(),
            'slow_risk_report' => $this->collect_slow_risk_report(),
            'cron_report' => $this->collect_cron_report(),
            'ajax_report' => $this->collect_ajax_report(),
            'media_report' => $this->collect_media_report(),
            'advanced_cache_report' => $this->collect_advanced_cache_report(),
            'plugin_theme_report' => $this->collect_plugin_theme_report(),
            'trend_report' => $this->collect_trend_report($stats),
            'commerce_report' => $this->collect_commerce_report(),
            'explain_report' => $this->collect_explain_report(),
            'admin_filter_report' => $this->collect_admin_filter_report(),
            'multisite_report' => $this->collect_multisite_report(),
        );
        $report['runtime_profile_report'] = $this->collect_runtime_profile_report($profile_started, $profile_start_queries, false);
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
        $orphan_meta = $get('失效 postmeta');
        $orphan_terms = $get('失效分类关系');
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
                $recommendations[] = 'postmeta 数量偏多，建议减少无用自定义字段并定期清理失效 postmeta。';
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
            $recommendations[] = '存在失效数据，建议先备份数据库，再分批清理失效 postmeta 和失效分类关系。';
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
        $orphan_meta = $get('失效 postmeta');
        $orphan_terms = $get('失效分类关系');
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
            $steps[] = array('risk' => 'medium', 'risk_label' => '中风险', 'title' => '清理失效数据', 'detail' => '失效 postmeta/分类关系没有对应文章，通常是删除文章或插件遗留。', 'action' => '备份后分批清理失效 postmeta 和失效分类关系。');
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

    private function safe_postmeta_key_hints() {
        return array(
            '_edit_lock' => '编辑锁记录，历史残留可清理。',
            '_edit_last' => '最后编辑者记录，通常不影响前台。',
            '_wp_old_slug' => '旧别名记录，过多会膨胀 postmeta；若依赖旧链接自动跳转需谨慎。',
            '_oembed_%' => 'oEmbed 缓存字段，可由 WordPress 重新生成。',
        );
    }

    private function collect_postmeta_deep_report() {
        global $wpdb;
        $safe_keys = array();
        $safe_total = 0;
        foreach ($this->safe_postmeta_key_hints() as $key => $hint) {
            if (strpos($key, '%') !== false) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(meta_value)),0) AS bytes FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $key), ARRAY_A);
            } else {
                $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(meta_value)),0) AS bytes FROM {$wpdb->postmeta} WHERE meta_key=%s", $key), ARRAY_A);
            }
            $count = $row ? intval($row['total']) : 0;
            $safe_total += $count;
            $safe_keys[] = array('meta_key' => $key, 'count' => $count, 'bytes' => $row ? intval($row['bytes']) : 0, 'hint' => $hint);
        }
        $duplicate_removable = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm2 INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = pm2.post_id AND pm1.meta_key = pm2.meta_key AND pm1.meta_value = pm2.meta_value AND pm1.meta_id < pm2.meta_id WHERE (pm2.meta_key IN ('_edit_lock','_edit_last','_wp_old_slug') OR pm2.meta_key LIKE %s)", '_oembed_%')));
        $empty_values = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value = ''"));
        $huge_rows = $wpdb->get_results("SELECT meta_id, post_id, meta_key, LENGTH(meta_value) AS bytes FROM {$wpdb->postmeta} WHERE LENGTH(meta_value) > 262144 ORDER BY bytes DESC LIMIT 10", ARRAY_A);
        $huge_values = array();
        foreach ((array) $huge_rows as $row) {
            $huge_values[] = array('meta_id' => intval($row['meta_id']), 'post_id' => intval($row['post_id']), 'meta_key' => $row['meta_key'], 'bytes' => intval($row['bytes']));
        }
        return array('safe_keys' => $safe_keys, 'safe_total' => $safe_total, 'duplicate_removable' => $duplicate_removable, 'empty_values' => $empty_values, 'huge_values' => $huge_values);
    }

    private function clean_safe_postmeta() {
        global $wpdb;
        $limit = $this->batch_size();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_edit_lock','_edit_last','_wp_old_slug') OR meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d", '_oembed_%', $limit));
        $ids = array_map('intval', (array) $ids);
        if (empty($ids)) {
            return array('type' => 'success', 'message' => '没有发现可安全清理的 postmeta。');
        }
        $deleted = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode(',', $ids) . ")");
        return array('type' => 'success', 'message' => '已清理安全 postmeta：' . number_format_i18n(intval($deleted)) . ' 条。');
    }

    private function clean_duplicate_postmeta() {
        global $wpdb;
        $limit = $this->batch_size();
        $delete_ids = $wpdb->get_col($wpdb->prepare("SELECT pm2.meta_id FROM {$wpdb->postmeta} pm2 INNER JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = pm2.post_id AND pm1.meta_key = pm2.meta_key AND pm1.meta_value = pm2.meta_value AND pm1.meta_id < pm2.meta_id WHERE (pm2.meta_key IN ('_edit_lock','_edit_last','_wp_old_slug') OR pm2.meta_key LIKE %s) ORDER BY pm2.meta_id ASC LIMIT %d", '_oembed_%', $limit));
        $delete_ids = array_values(array_unique(array_filter($delete_ids)));
        if (empty($delete_ids)) {
            return array('type' => 'success', 'message' => '没有发现可清理的完全重复 postmeta。');
        }
        $deleted = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (" . implode(',', $delete_ids) . ")");
        return array('type' => 'success', 'message' => '已清理低风险重复 postmeta：' . number_format_i18n(intval($deleted)) . ' 条。');
    }

    private function protected_autoload_options() {
        return array('siteurl','home','blogname','blogdescription','users_can_register','admin_email','template','stylesheet','current_theme','active_plugins','rewrite_rules','cron','sidebars_widgets','widget_pages','widget_text','widget_categories','widget_nav_menu','theme_mods_' . get_option('stylesheet'));
    }

    private function collect_autoload_optimizer_report() {
        global $wpdb;
        $total_bytes = intval($wpdb->get_var("SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')"));
        $rows = $wpdb->get_results("SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 20", ARRAY_A);
        $protected = $this->protected_autoload_options();
        $candidates = array();
        foreach ((array) $rows as $row) {
            $name = $row['option_name'];
            $bytes = intval($row['bytes']);
            $is_protected = in_array($name, $protected, true) || strpos($name, 'theme_mods_') === 0;
            $risk = $bytes > 1048576 ? '高：单项超过 1MB' : ($bytes > 262144 ? '中：单项较大' : '低');
            $class = $bytes > 1048576 ? 'wplco-danger' : ($bytes > 262144 ? 'wplco-warn' : 'wplco-ok');
            $candidates[] = array('option_name' => $name, 'autoload' => $row['autoload'], 'bytes' => $bytes, 'risk' => $risk, 'class' => $class, 'protected' => $is_protected);
        }
        $backups = get_option('wplco_autoload_backups', array());
        if (!is_array($backups)) {
            $backups = array();
        }
        return array('total_bytes' => $total_bytes, 'total_class' => $total_bytes > 5 * 1024 * 1024 ? 'wplco-danger' : ($total_bytes > 1024 * 1024 ? 'wplco-warn' : 'wplco-ok'), 'candidates' => $candidates, 'backups' => $backups);
    }

    private function requested_option_name() {
        return isset($_POST['option_name']) ? sanitize_text_field(wp_unslash($_POST['option_name'])) : '';
    }

    private function disable_autoload_option() {
        global $wpdb;
        $name = $this->requested_option_name();
        if ($name === '') {
            return array('type' => 'error', 'message' => '缺少 option_name。');
        }
        if (in_array($name, $this->protected_autoload_options(), true) || strpos($name, 'theme_mods_') === 0) {
            return array('type' => 'error', 'message' => '该 option 属于保护项，未修改。');
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name=%s", $name), ARRAY_A);
        if (!$row) {
            return array('type' => 'error', 'message' => '未找到该 option。');
        }
        $backups = get_option('wplco_autoload_backups', array());
        if (!is_array($backups)) {
            $backups = array();
        }
        if (!isset($backups[$name])) {
            $backups[$name] = array('autoload' => $row['autoload'], 'bytes' => intval($row['bytes']), 'time' => current_time('mysql'));
            update_option('wplco_autoload_backups', array_slice($backups, -50, null, true), false);
        }
        $updated = $wpdb->update($wpdb->options, array('autoload' => 'no'), array('option_name' => $name), array('%s'), array('%s'));
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        if ($updated === false) {
            return array('type' => 'error', 'message' => '修改 autoload 失败：' . $name);
        }
        return array('type' => 'success', 'message' => '已将 option 改为不自动加载：' . $name . '。如异常可在 autoload 回滚区恢复。');
    }

    private function restore_autoload_option() {
        global $wpdb;
        $name = $this->requested_option_name();
        $backups = get_option('wplco_autoload_backups', array());
        if ($name === '' || !is_array($backups) || !isset($backups[$name])) {
            return array('type' => 'error', 'message' => '未找到该 option 的回滚记录。');
        }
        $autoload = sanitize_key($backups[$name]['autoload']);
        $updated = $wpdb->update($wpdb->options, array('autoload' => $autoload), array('option_name' => $name), array('%s'), array('%s'));
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        if ($updated === false) {
            return array('type' => 'error', 'message' => '恢复 autoload 失败：' . $name);
        }
        unset($backups[$name]);
        update_option('wplco_autoload_backups', $backups, false);
        return array('type' => 'success', 'message' => '已恢复 option 的 autoload 状态：' . $name . '。');
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
            '失效 postmeta' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$postmeta} pm LEFT JOIN {$posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL")),
            '失效分类关系' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$terms} tr LEFT JOIN {$posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL")),
            'autoload options 数量' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$options} WHERE autoload IN ('yes','on','auto-on','auto')")),
            '过期 transient' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like('_transient_timeout_') . '%', time()))),
        );

        $out = array();
        foreach ($data as $label => $value) {
            $class = 'wplco-ok';
            if ($value > 100000) {
                $class = 'wplco-warn';
            }
            if (in_array($label, array('修订版本 revision', '自动草稿 auto-draft', '回收站文章 trash', '失效 postmeta', '失效分类关系', '过期 transient'), true) && $value > 0) {
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
        return array('type' => 'success', 'message' => '已清理失效 postmeta：' . number_format_i18n(max(0, intval($rows))) . ' 条。');
    }

    private function clean_orphan_term_relationships() {
        global $wpdb;
        $limit = $this->batch_size();
        $rows = $wpdb->query($wpdb->prepare("DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL LIMIT %d", $limit));
        return array('type' => 'success', 'message' => '已清理失效分类关系：' . number_format_i18n(max(0, intval($rows))) . ' 条。');
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
