<?php
/*
Plugin Name: Skyline AI Pro
Description: 灵感屋(lgwu.net) 专属智能运营中台。集成 DeepSeek V3、可视化无感同步系统、智能去水印、AI 润色与 Redis 智能缓存。[V1.4.1 架构升级版]
Version: 1.5.0
Author:      LingGanWu
Text Domain: skyline-ai-pro
*/

if (!defined('ABSPATH')) exit;

define('SKY_VERSION', '1.4.1');

// AI Models Configuration
define('SKY_AI_MODELS', json_encode([
    'deepseek-v3' => ['name' => 'DeepSeek V3', 'cost' => 0.01],
    'gpt-4' => ['name' => 'GPT-4', 'cost' => 0.03],
    'claude-3' => ['name' => 'Claude 3', 'cost' => 0.02]
]));

// Batch Processing Settings
define('SKY_BATCH_SIZE', 10);
define('SKY_MAX_RETRIES', 3);
define('SKY_CACHE_TIMEOUT', 3600); // 1 hour

define('SKY_PATH', plugin_dir_path(__FILE__));
define('SKY_URL', plugin_dir_url(__FILE__));

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

add_action('plugins_loaded', function() {
    load_plugin_textdomain('skyline-ai-pro', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    
    if (class_exists('Skyline_Core')) Skyline_Core::instance();
});

// 插件列表页添加“设置”链接
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=skyline-pro" style="font-weight:bold;color:#4f46e5;">设置</a>';
    array_unshift($links, $settings_link);
    return $links;
});

if (!function_exists('skyline_get_opt')) {
    function skyline_get_opt($key, $def = false) { return Skyline_Core::instance()->get_opt($key, $def); }
    function skyline_log($msg) { return Skyline_Core::instance()->log($msg); }
    function skyline_stat_inc($key, $v=1) { return Skyline_Core::instance()->stat_inc($key, $v); }
    function skyline_stat_get($key) { return Skyline_Core::instance()->stat_get($key); }
}

// 兼容性辅助函数 - 指向新的 Utils 类
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
