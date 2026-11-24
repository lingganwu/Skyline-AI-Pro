<?php
/*
Plugin Name: Skyline AI Pro
Plugin URI: https://github.com/lingganwu/Skyline-AI-Pro
Description: AI 运营中台：集成主流 AI 模型，提供访客聊天助手、AI 绘图、SEO 自动补全、图片同步与缓存优化。
Version: 2.2.10
Requires at least: 5.8
Requires PHP: 7.4
Author:      LingGanWu
Author URI: https://www.lgwu.net
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: skyline-ai-pro
*/

if (!defined('ABSPATH')) exit;

define('SKYLAIPR_VERSION', '2.2.10');

define('SKYLAIPR_PATH', plugin_dir_path(__FILE__));
define('SKYLAIPR_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class) {
    if (strpos($class, 'Skylaipr_') !== 0) return;
    $name = strtolower(str_replace('_', '-', $class));
    $paths = [
        SKYLAIPR_PATH . 'inc/' . 'class-' . $name . '.php',
        SKYLAIPR_PATH . 'inc/modules/' . 'class-' . $name . '.php',
        SKYLAIPR_PATH . 'admin/' . 'class-' . $name . '.php'
    ];
    foreach ($paths as $file) {
        if (file_exists($file)) { require_once $file; return; }
    }
});

// v2.2.3（X-3）：四个单次事件回调在加载期直接注册，与类实例化解耦。
// 之前 purge/retry 的 add_action 在 Skylaipr_OSS_Mod::__construct 里，
// spider/seo 的在 Skylaipr_Content::__construct 里，而两个类只在 Core
// 的 load_modules() 里实例化；Infra 初始化若抛错被 try/catch 吞掉，
// 对应的 cron 回调会全程不注册，已排期的事件会被 WP 静默消费。
// 回调里再懒加载取单例（事件触发时类必然已加载，autoloader 已注册；
// Skylaipr_Content::__construct 与 Skylaipr_OSS_Mod::__construct 里的
// 旧注册由其他工人删除，集成时 grep 核对每个 hook 只注册一次）。
// 四个回调签名均为单参（cron 事件带的参数）：auto_spider($pid)、
// auto_seo_meta($pid, $only=null, $reschedule_on_fail=true)（只传 $pid，
// 其余取默认）、purge_pending_deletes($attachment_id)、
// retry_failed_sizes($attachment_id)。
add_action('skylaipr_async_spider', function ($pid) {
    if (class_exists('Skylaipr_Content')) Skylaipr_Content::instance()->auto_spider($pid);
});
add_action('skylaipr_async_seo', function ($pid) {
    if (class_exists('Skylaipr_Content')) Skylaipr_Content::instance()->auto_seo_meta($pid);
});
add_action('skylaipr_oss_purge_pending', function ($attachment_id) {
    if (class_exists('Skylaipr_OSS_Mod')) Skylaipr_OSS_Mod::instance()->purge_pending_deletes($attachment_id);
});
add_action('skylaipr_oss_retry_failed', function ($attachment_id) {
    if (class_exists('Skylaipr_OSS_Mod')) Skylaipr_OSS_Mod::instance()->retry_failed_sizes($attachment_id);
});

// v2.2.3（X-3）：停用时清掉上述四个 hook 的已排期单次事件，残留事件
// 不会在下次启用时对着已删附件触发。wp_clear_scheduled_hook() 不带
// 参数即清掉该 hook 的全部实例（本站的单次事件都带文章/附件 id 参数）。
register_deactivation_hook(__FILE__, function () {
    // v2.2.10 前缀改名：旧 hook 名也清一遍（升级前排队的单次事件）。
    foreach (['skylaipr_async_spider', 'skylaipr_async_seo', 'skylaipr_oss_purge_pending', 'skylaipr_oss_retry_failed', 'skyline_async_spider', 'skyline_async_seo', 'skyline_oss_purge_pending', 'skyline_oss_retry_failed'] as $hook) {
        if (function_exists('wp_clear_scheduled_hook')) wp_clear_scheduled_hook($hook);
    }
});

// 修复：遵守 WP 规范，在正确的钩子加载后台 CSS/JS 资源
// v2.2.2：admin.js 必须页头加载（$in_footer=false）。三张批量卡片
// （WebP/蜘蛛/SEO）的初始化原来是页面正文里的内联脚本，直接调
// SkyBatchRunner.create()；页脚加载时内联脚本执行时 SkyBatchRunner
// 尚不存在 → ReferenceError，整段内联脚本中断、点击静默失败
// （连“正在扫描…”都不出）。页头加载保证卡片初始化执行前
// SkyBatchRunner 已定义。
// v2.2.9（WP.org 审核）：后台页面原先直接输出的 script 标签块
// （测试连接/日志清理/模板库/三张批量卡片初始化）已全部移入
// assets/js/admin.js；脚本所需的 nonce 与供应商列表改由
// wp_localize_script() 传入，不再在页面正文输出 script 标签。
// skylaipr_ajax 的 localize 也随脚本挂到这个钩子——页头打印前就得挂好，
// 否则脚本已打出、localize 数据打不出来。
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'skylaipr-pro') !== false) {
        wp_enqueue_style('skylaipr-admin-css', plugins_url('assets/css/admin.css', __FILE__), array(), SKYLAIPR_VERSION);
        wp_enqueue_script('skylaipr-admin-js', plugins_url('assets/js/admin.js', __FILE__), array('jquery'), SKYLAIPR_VERSION, false);
        wp_localize_script('skylaipr-admin-js', 'skylaipr_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('skylaipr_ajax_nonce')
        ]);
        // v2.2.9：原内联脚本里的三个 nonce，改走本地化对象传入。
        wp_localize_script('skylaipr-admin-js', 'skylaiprAdminData', [
            'ajax_url'         => admin_url('admin-ajax.php'),
            'test_nonce'       => wp_create_nonce('skylaipr_ai_test_nonce'),
            'prompt_nonce'     => wp_create_nonce('skylaipr_prompt_nonce'),
            'clear_logs_nonce' => wp_create_nonce('skylaipr_clear_logs_nonce'),
        ]);
        // v2.2.9：原内联 JSON 数据块（id 为 sky-api-vendors）
        // 块里的供应商预设列表，改走本地化对象传入。
        if (class_exists('Skylaipr_Core') && method_exists('Skylaipr_Core', 'get_api_vendors')) {
            wp_localize_script('skylaipr-admin-js', 'skylaiprApiVendors', Skylaipr_Core::get_api_vendors());
        }
    }
});

add_action('plugins_loaded', function() {
    // v2.2.9（WP.org 审核）：自 WP 4.6 起 load_plugin_textdomain() 已无需
    // 手动调用，WordPress 会按插件头的 Text Domain 自动加载语言包。
    if (class_exists('Skylaipr_Core')) Skylaipr_Core::instance();
});

// 插件列表页添加“设置”链接
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="admin.php?page=skylaipr-pro" style="font-weight:bold;color:#4f46e5;">设置</a>';
    array_unshift($links, $settings_link);
    return $links;
});

if (!function_exists('skylaipr_get_opt')) {
    function skylaipr_get_opt($key, $def = false) { return Skylaipr_Core::instance()->get_opt($key, $def); }
    function skylaipr_log($msg) { return Skylaipr_Core::instance()->log($msg); }
    function skylaipr_stat_inc($key, $v=1) { return Skylaipr_Core::instance()->stat_inc($key, $v); }
    function skylaipr_stat_get($key) { return Skylaipr_Core::instance()->stat_get($key); }
}

// 兼容性辅助函数 - 指向新的 Utils 类
if (!function_exists('skylaipr_ai_assess_quality')) {
    function skylaipr_ai_assess_quality($content) { return Skylaipr_Utils::assess_quality($content); }
}
if (!function_exists('skylaipr_batch_process')) {
    function skylaipr_batch_process($posts, $model = '') { return Skylaipr_Utils::batch_process($posts, $model); }
}
if (!function_exists('skylaipr_create_backup')) {
    function skylaipr_create_backup() { return Skylaipr_Utils::create_backup(); }
}
if (!function_exists('skylaipr_translate_content')) {
    function skylaipr_translate_content($content, $target_lang = 'en') { return Skylaipr_Utils::translate_content($content, $target_lang); }
}
if (!function_exists('skylaipr_manage_cache')) {
    function skylaipr_manage_cache() { return Skylaipr_Utils::manage_cache(); }
}
if (!function_exists('skylaipr_help_docs')) {
    function skylaipr_help_docs() { return Skylaipr_Utils::get_help_docs(); }
}
if (!function_exists('skylaipr_sdk_integration')) {
    function skylaipr_sdk_integration() { return Skylaipr_Utils::sdk_integration(); }
}