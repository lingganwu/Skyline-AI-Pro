<?php
/*
Plugin Name: Skyline AI Pro
Description: 灵感屋(lgwu.net) 专属智能运营中台。集成 DeepSeek V3、可视化无感采集系统、智能去水印、AI 润色与 Redis 智能缓存。[V1.3.2 完美采集版]
Version:     1.3.2
Author:      LingGanWu
Text Domain: skyline-ai-pro
*/

if (!defined('ABSPATH')) exit;

define('SKY_VERSION', '1.3.2');
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
    // 优化：加载语言包，符合 WP 标准
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
// 文件结束