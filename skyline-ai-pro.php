<?php
/**
 * Plugin Name: Skyline AI Pro
 * Plugin URI: https://www.lgwu.net
 * Description: 灵感屋(lgwu.net) 专属智能运营中台。集成 DeepSeek V3、可视化无感同步系统、智能去水印、AI 润色与 Redis 智能缓存。[V2.0.1 安全加固版]
 * Version: 2.0.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: LingGanWu
 * Author URI: https://www.lgwu.net
 * Text Domain: skyline-ai-pro
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

define('SKY_VERSION', '2.0.1');
define('SKY_MIN_PHP', '7.4');

// AI Models Configuration
define('SKY_AI_MODELS', json_encode([
    'deepseek-v3' => ['name' => 'DeepSeek V3', 'cost' => 0.01],
    'gpt-4' => ['name' => 'GPT-4', 'cost' => 0.03],
    'claude-3' => ['name' => 'Claude 3', 'cost' => 0.02]
]));

// Batch Processing Settings
define('SKY_BATCH_SIZE', 10);
define('SKY_MAX_RETRIES', 3);
define('SKY_CACHE_TIMEOUT', 3600);

define('SKY_PATH', plugin_dir_path(__FILE__));
define('SKY_URL', plugin_dir_url(__FILE__));

// PHP Version Check
if (version_compare(PHP_VERSION, SKY_MIN_PHP, '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        echo sprintf(
            __('Skyline AI Pro 需要 PHP %s 或更高版本，当前版本为 %s', 'skyline-ai-pro'),
            SKY_MIN_PHP,
            PHP_VERSION
        );
        echo '</p></div>';
    });
    return;
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'Skyline_') !== 0) return;
    $name = strtolower(str_replace('_', '-', $class));
    $paths = [
        SKY_PATH . 'inc/' . 'class-' . $name . '.php',
        SKY_PATH . 'inc/modules/' . 'class-' . $name . '.php',
        SKY_PATH . 'admin/' . 'class-' . $name . '.php'
    ];
    foreach ($paths as $file) {
        if (file_exists($file)) { require_once $file; return; }
    }
});

// 后台资源加载 - 使用标准钩子
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'skyline-pro') === false) return;
    
    wp_enqueue_style('skyline-admin-css', plugins_url('assets/css/admin.css', __FILE__), [], SKY_VERSION);
    wp_enqueue_script('skyline-admin-js', plugins_url('assets/js/admin.js', __FILE__), ['jquery'], SKY_VERSION, true);
    
    // 传递 AJAX 配置
    wp_localize_script('skyline-admin-js', 'skyline_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('skyline_ajax_nonce'),
        'i18n' => [
            'confirm_delete' => __('确定删除吗？此操作不可撤销。', 'skyline-ai-pro'),
            'saving' => __('保存中...', 'skyline-ai-pro'),
            'saved' => __('已保存', 'skyline-ai-pro'),
            'error' => __('操作失败', 'skyline-ai-pro'),
            'loading' => __('加载中...', 'skyline-ai-pro'),
        ]
    ]);
});

add_action('plugins_loaded', function() {
    load_plugin_textdomain('skyline-ai-pro', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    if (class_exists('Skyline_Core')) Skyline_Core::instance();
});

// 插件列表页添加"设置"链接
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=skyline-pro" style="font-weight:bold;color:#4f46e5;">' . 
                     __('设置', 'skyline-ai-pro') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// 全局辅助函数
if (!function_exists('skyline_get_opt')) {
    function skyline_get_opt($key, $def = false) { 
        return Skyline_Core::instance()->get_opt($key, $def); 
    }
    function skyline_log($msg, $type = 'info', $ctx = 'System') { 
        return Skyline_Core::instance()->log($msg, $type, $ctx); 
    }
    function skyline_stat_inc($key, $v = 1) { 
        return Skyline_Core::instance()->stat_inc($key, $v); 
    }
    function skyline_stat_get($key) { 
        return Skyline_Core::instance()->stat_get($key); 
    }
}

// 安全辅助函数
if (!function_exists('skyline_verify_nonce')) {
    function skyline_verify_nonce($nonce = null, $action = 'skyline_ajax_nonce') {
        if ($nonce === null) {
            $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        }
        return wp_verify_nonce($nonce, $action);
    }
    function skyline_check_rate_limit($action, $limit = 10, $window = 60) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = 'sky_rate_' . md5($action . $ip);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window);
        return true;
    }
}

// 兼容性辅助函数
if (!function_exists('skyline_ai_assess_quality')) {
    function skyline_ai_assess_quality($content) { return Skyline_Utils::assess_quality($content); }
}
if (!function_exists('skyline_batch_process')) {
    function skyline_batch_process($posts, $model = 'deepseek-v3') { return Skyline_Utils::batch_process($posts, $model); }
}
if (!function_exists('skyline_export_data')) {
    function skyline_export_data($format = 'json', $filters = []) { return Skyline_Utils::export_data($format, $filters); }
}
if (!function_exists('skyline_create_backup')) {
    function skyline_create_backup() { return Skyline_Utils::create_backup(); }
}
if (!function_exists('skyline_translate_content')) {
    function skyline_translate_content($content, $target_lang = 'en') { return Skyline_Utils::translate_content($content, $target_lang); }
}
if (!function_exists('skyline_deduplicate_content')) {
    function skyline_deduplicate_content($content) { return Skyline_Utils::deduplicate_content($content); }
}
if (!function_exists('skyline_manage_cache')) {
    function skyline_manage_cache() { return Skyline_Utils::manage_cache(); }
}
if (!function_exists('skyline_webhook_handler')) {
    function skyline_webhook_handler($data) { return Skyline_Utils::webhook_handler($data); }
}
if (!function_exists('skyline_help_docs')) {
    function skyline_help_docs() { return Skyline_Utils::get_help_docs(); }
}
if (!function_exists('skyline_sdk_integration')) {
    function skyline_sdk_integration() { return Skyline_Utils::sdk_integration(); }
}
