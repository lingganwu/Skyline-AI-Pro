<?php
/*
Plugin Name: Skyline AI Pro
Description: 灵感屋(lgwu.net) 专属智能运营中台。集成 DeepSeek V3、可视化无感采集系统、智能去水印、AI 润色与 Redis 智能缓存。[V1.3.2 完美采集版]
Version:     1.3.2
Author:      LingGanWu
Text Domain: skyline-ai-pro
*/

if (!defined('ABSPATH')) exit;

define('SKY_VERSION', '1.4.0');
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

// AI Quality Assessment Function
function skyline_ai_assess_quality($content) {
    $readability_score = calculate_readability($content);
    $seo_score = calculate_seo($content);
    return [
        'readability' => $readability_score,
        'seo' => $seo_score,
        'overall' => round(($readability_score + $seo_score) / 2, 2)
    ];
}

// Batch Processing Function
function skyline_batch_process($posts, $model = 'deepseek-v3') {
    $results = [];
    foreach ($posts as $post) {
        $results[] = skyline_generate_content($post['content'], $model);
    }
    return $results;
}

// Data Export Function
function skyline_export_data($format = 'json', $filters = []) {
    $data = get_option('sky_ai_data', []);
    switch ($format) {
        case 'json':
            return json_encode($data, JSON_PRETTY_PRINT);
        case 'csv':
            return convert_to_csv($data);
        case 'excel':
            return convert_to_excel($data);
        default:
            return json_encode($data);
    }
}

// Backup Function
function skyline_create_backup() {
    $backup_data = [
        'settings' => get_option('skyline_ai_settings', []),
        'data' => get_option('sky_ai_data', []),
        'timestamp' => current_time('mysql')
    ];
    update_option('skyline_backup_' . time(), $backup_data);
    return true;
}

// Multi-language Translation Function
function skyline_translate_content($content, $target_lang = 'en') {
    $api_key = get_option('sky_ai_api_key');
    $response = wp_remote_post('https://api.translation-service.com/translate', [
        'body' => json_encode([
            'text' => $content,
            'target_lang' => $target_lang,
            'api_key' => $api_key
        ])
    ]);
    return json_decode(wp_remote_retrieve_body($response), true)['translated_text'];
}

// Content Deduplication
function skyline_deduplicate_content($content) {
    $existing_posts = get_posts(['post_content' => $content]);
    if (empty($existing_posts)) {
        return true; // Content is unique
    }
    return false; // Content is duplicate
}

// Advanced Cache Management
function skyline_manage_cache() {
    if (wp_using_ext_object_cache()) {
        wp_cache_delete('sky_ai_cache', 'skyline');
    }
    return true;
}

// Webhook Handler
function skyline_webhook_handler($data) {
    $webhook_url = get_option('sky_webhook_url');
    if ($webhook_url) {
        wp_remote_post($webhook_url, [
            'body' => json_encode($data),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    }
}

// API Endpoint
function skyline_api_endpoint($wp_rest) {
    register_rest_route('skyline/v1', '/generate', [
        'methods' => 'POST',
        'callback' => 'skyline_api_generate_content'
    ]);
    register_rest_route('skyline/v1', '/batch-process', [
        'methods' => 'POST',
        'callback' => 'skyline_api_batch_process'
    ]);
}

// Dark Mode Support
function skyline_dark_mode_support() {
    add_theme_support('dark-mode');
    add_action('wp_enqueue_scripts', 'skyline_enqueue_dark_mode_styles');
}

function skyline_enqueue_dark_mode_styles() {
    wp_enqueue_style('dark-mode', plugin_dir_url(__FILE__) . 'assets/css/dark-mode.css');
}

// Help Documentation
function skyline_help_docs() {
    $help_content = file_get_contents(plugin_dir_path(__FILE__) . 'README.md');
    return $help_content;
}

// SDK Integration
function skyline_sdk_integration() {
    require_once plugin_dir_path(__FILE__) . 'sdk/Skyline_SDK.php';
    return new Skyline_SDK();
}
