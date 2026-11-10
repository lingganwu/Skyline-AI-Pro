<?php
/*
Plugin Name: Skyline AI Pro
Plugin URI: https://github.com/lingganwu/Skyline-AI-Pro
Description: AI 运营中台：集成主流 AI 模型，提供访客聊天助手、AI 绘图、SEO 自动补全、图片同步与缓存优化。
Version: 2.2.6
Requires at least: 5.8
Requires PHP: 7.4
Author:      LingGanWu
Author URI: https://www.lgwu.net
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: skyline-ai-pro
*/

if (!defined('ABSPATH')) exit;

define('SKY_VERSION', '2.2.6');

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

// v2.2.3（X-3）：四个单次事件回调在加载期直接注册，与类实例化解耦。
// 之前 purge/retry 的 add_action 在 Skyline_OSS_Mod::__construct 里，
// spider/seo 的在 Skyline_Content::__construct 里，而两个类只在 Core
// 的 load_modules() 里实例化；Infra 初始化若抛错被 try/catch 吞掉，
// 对应的 cron 回调会全程不注册，已排期的事件会被 WP 静默消费。
// 回调里再懒加载取单例（事件触发时类必然已加载，autoloader 已注册；
// Skyline_Content::__construct 与 Skyline_OSS_Mod::__construct 里的
// 旧注册由其他工人删除，集成时 grep 核对每个 hook 只注册一次）。
// 四个回调签名均为单参（cron 事件带的参数）：auto_spider($pid)、
// auto_seo_meta($pid, $only=null, $reschedule_on_fail=true)（只传 $pid，
// 其余取默认）、purge_pending_deletes($attachment_id)、
// retry_failed_sizes($attachment_id)。
add_action('skyline_async_spider', function ($pid) {
    if (class_exists('Skyline_Content')) Skyline_Content::instance()->auto_spider($pid);
});
add_action('skyline_async_seo', function ($pid) {
    if (class_exists('Skyline_Content')) Skyline_Content::instance()->auto_seo_meta($pid);
});
add_action('skyline_oss_purge_pending', function ($attachment_id) {
    if (class_exists('Skyline_OSS_Mod')) Skyline_OSS_Mod::instance()->purge_pending_deletes($attachment_id);
});
add_action('skyline_oss_retry_failed', function ($attachment_id) {
    if (class_exists('Skyline_OSS_Mod')) Skyline_OSS_Mod::instance()->retry_failed_sizes($attachment_id);
});

// v2.2.3（X-3）：停用时清掉上述四个 hook 的已排期单次事件，残留事件
// 不会在下次启用时对着已删附件触发。wp_clear_scheduled_hook() 不带
// 参数即清掉该 hook 的全部实例（本站的单次事件都带文章/附件 id 参数）。
register_deactivation_hook(__FILE__, function () {
    foreach (['skyline_async_spider', 'skyline_async_seo', 'skyline_oss_purge_pending', 'skyline_oss_retry_failed'] as $hook) {
        if (function_exists('wp_clear_scheduled_hook')) wp_clear_scheduled_hook($hook);
    }
});

// 修复：遵守 WP 规范，在正确的钩子加载后台 CSS/JS 资源
// v2.2.2：admin.js 必须页头加载（$in_footer=false）。三张批量卡片
// （WebP/蜘蛛/SEO）的内联脚本在正文里直接调 SkyBatchRunner.create()，
// 页脚加载时内联脚本执行时 SkyBatchRunner 尚不存在 → ReferenceError，
// 整段内联脚本中断、点击静默失败（连“正在扫描…”都不出）。页头加载
// 保证内联脚本执行前 SkyBatchRunner 已定义。
// skyline_ajax 的 localize 也随脚本挂到这个钩子——页头打印前就得挂好，
// 否则脚本已打出、localize 数据打不出来。
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'skyline-pro') !== false) {
        wp_enqueue_style('skyline-admin-css', plugins_url('assets/css/admin.css', __FILE__), array(), SKY_VERSION);
        wp_enqueue_script('skyline-admin-js', plugins_url('assets/js/admin.js', __FILE__), array('jquery'), SKY_VERSION, false);
        wp_localize_script('skyline-admin-js', 'skyline_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('skyline_ajax_nonce')
        ]);
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
    function skyline_batch_process($posts, $model = '') { return Skyline_Utils::batch_process($posts, $model); }
}
if (!function_exists('skyline_create_backup')) {
    function skyline_create_backup() { return Skyline_Utils::create_backup(); }
}
if (!function_exists('skyline_translate_content')) {
    function skyline_translate_content($content, $target_lang = 'en') { return Skyline_Utils::translate_content($content, $target_lang); }
}
if (!function_exists('skyline_manage_cache')) {
    function skyline_manage_cache() { return Skyline_Utils::manage_cache(); }
}
if (!function_exists('skyline_help_docs')) {
    function skyline_help_docs() { return Skyline_Utils::get_help_docs(); }
}
if (!function_exists('skyline_sdk_integration')) {
    function skyline_sdk_integration() { return Skyline_Utils::sdk_integration(); }
}