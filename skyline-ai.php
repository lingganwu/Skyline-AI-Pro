<?php
if (!defined('ABSPATH')) exit;

// 核心 API 调用
function skyline_call_api($m, $temp = 0.7) {
    // --- 核心修复 1：增加 PHP 执行时间 ---
    // 防止处理长文章时 PHP 脚本超时（设置为5分钟）
    if(function_exists('set_time_limit')) @set_time_limit(300);
    @ini_set('max_execution_time', 300);

    $k = skyline_get_opt('api_key'); if(!$k) return '请在后台配置 API Key';
    skyline_stat_inc('api_calls');
    $model = skyline_get_opt('chat_model', 'deepseek-ai/DeepSeek-V3');
    
    // 如果没有 System Prompt，自动注入默认人设
    $sp = skyline_get_opt('system_prompt', '');
    if(!$sp){
        $sp = "你是 Skyline AI，是灵感屋(lgwu.net)站长的专业写作与运营助手：\n"
            ."- 你的目标是：提高网站内容质量、提升用户体验、优化 SEO 与转化率；\n"
            ."- 写作风格：中文优先，表达清晰、有逻辑，必要时分点呈现；\n"
            ."- 回答时尽量结合 WordPress、SEO、内容营销等实战经验；\n"
            ."- 如用户问题不完整，先用简短反问补充关键信息，再给出建议。\n";
    }

    $body = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sp],
            ['role' => 'user', 'content' => $m],
        ],
        'temperature' => $temp,
        'max_tokens'  => 2000,
    ], JSON_UNESCAPED_UNICODE);

    $resp = wp_remote_post('https://api.deepseek.com/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $k,
            'Content-Type'  => 'application/json',
        ],
        'body' => $body,
        'timeout' => 60,
    ]);

    if (is_wp_error($resp)) return '请求失败：' . $resp->get_error_message();
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return '接口异常：HTTP ' . $code;

    $json = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($json) || empty($json['choices'][0]['message']['content'])) return '解析失败：返回数据异常';

    return trim($json['choices'][0]['message']['content']);
}

// 简单日志
function skyline_log($msg) {
    if (!is_string($msg)) $msg = print_r($msg, true);
    $logs = get_option('skyline_ai_logs', []);
    if(!is_array($logs)) $logs = [];
    $time = current_time('m-d H:i:s');
    array_unshift($logs, ['time' => $time, 'msg' => $msg]);
    if(count($logs) > 200) array_splice($logs, 200);
    update_option('skyline_ai_logs', $logs);
}

/* ---------------------------------------------------------
 * 1. 模块加载
 * --------------------------------------------------------- */
$skyline_modules = ['skyline-ai.php', 'skyline-spider.php', 'skyline-turbo.php', 'skyline-oss.php', 'skyline-redis.php'];
foreach ($skyline_modules as $file) {
    if (file_exists(SKYLINE_AI_PATH . $file)) require_once SKYLINE_AI_PATH . $file;
}
